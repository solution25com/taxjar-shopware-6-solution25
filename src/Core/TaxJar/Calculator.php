<?php

namespace solu1TaxJar\Core\TaxJar;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GRequest;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class Calculator
{
    private const CACHE_ID = 's25_tax_jar_response_';

    public const REQUEST_TYPE = 'Tax Calculation';

    public const VERSION = '1.10.4';

    public const LIVE_API_URL = 'https://api.taxjar.com/v2';
    public const SANDBOX_API_URL = 'https://api.sandbox.taxjar.com/v2';

    /**
     * @var Client
     */
    private $restClient;

    /**
     * @var string|null
     */
    private $salesChannelId;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var TaxLogEntity
     */
    private $taxLogRepository;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private $productRepository;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var float
     */
    private $cartTotal = 0;

    /**
     * @param SystemConfigService $systemConfigService
     * @param TaxLogEntity $taxLogRepository
     * @param EntityRepository<ProductCollection> $productRepository
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(
        SystemConfigService    $systemConfigService,
        TaxLogEntity       $taxLogRepository,
        EntityRepository       $productRepository,
        CacheItemPoolInterface $cache
    )
    {
        $this->restClient = new Client();
        $this->systemConfigService = $systemConfigService;
        $this->taxLogRepository = $taxLogRepository;
        $this->productRepository = $productRepository;
        $this->cache = $cache;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @param SalesChannelContext $context
     * @param Cart $cart
     * @return array<string, mixed>|false
     */
    public function calculate(array $lineItems, SalesChannelContext $context, Cart $cart)
    {
        $this->salesChannelId = $context->getSalesChannelId();
        if ($this->_isActive()) {
            $customer = $context->getCustomer();
            if (!$customer instanceof CustomerEntity || !$customer->getActiveShippingAddress()) {
                return [];
            }

            $shippingAddress = $customer->getActiveShippingAddress();
            $country = $shippingAddress->getCountry();

            if (!$country instanceof CountryEntity) {
                return [];
            }

            $countryState = $shippingAddress->getCountryState();

            $stateCode = [];
            $stateName = '';
            if ($countryState) {
                $stateCode = explode('-', $countryState->getShortCode());
                $stateName = $countryState->getName();
            }

            $shippingFromAddress = $this->getShippingOriginAddress();
            $this->cartTotal = 0;
            $lineItems = $this->processLinceItems($lineItems, $context);

            $cartInfo = [
                "to_country" => $country->getIso(),
                "to_zip" => $shippingAddress->getZipcode(),
                "to_state" => isset($stateCode[1]) ? $stateCode[1] : $stateName,
                "to_city" => $shippingAddress->getCity(),
                "to_street" => $shippingAddress->getStreet(),
                "amount" => $cart->getPrice()->getTotalPrice(),
                "shipping" => $this->useIncludeShippingCostForTaxCalculation() ?
                    $cart->getShippingCosts()->getUnitPrice() : 0,
                "line_items" => $lineItems
            ];
            $request = array_merge($shippingFromAddress, $cartInfo);
            $storedResponse = $this->getResponseFromCache(serialize($request));
            if (!empty($storedResponse)) {
                $taxInformation = $storedResponse;
            } else {
                $taxInformation = $this->_getTaxRateWithHttpRequest($context, $request);
            }
            if (isset($taxInformation['breakdown']['line_items'])) {
                $lineItems = $taxInformation['breakdown']['line_items'];
                $processedResponse = [];

                $processedResponse = $this->addRate($taxInformation, $processedResponse);
                foreach ($lineItems as $lineItem) {
                    if (isset($lineItem['id']) && isset($lineItem['tax_collectable'])) {
                        $processedResponse[$lineItem['id']] = $lineItem['tax_collectable'];
                    }
                }
                if ($this->useIncludeShippingCostForTaxCalculation()) {
                    if (isset($taxInformation['breakdown']['shipping']) &&
                        ($shippingTax = $taxInformation['breakdown']['shipping'])) {
                        $processedResponse['shippingTax'] = $shippingTax['tax_collectable'] ?? 0;
                    }
                }
                return $processedResponse;
            }
        }
        return false;
    }


    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @param SalesChannelContext $context
     * @return array<int, array<string, mixed>>
     */
    private function processLinceItems(array $lineItems, SalesChannelContext $context): array
    {
        $useGrossPriceForCalculation = $this->useGrossPriceForTaxCalculation();
        foreach ($lineItems as $key => $productInfo) {
            if (isset($productInfo['id']) && $productInfo['id']) {
                $quantity = $lineItems[$key]['quantity'];
                $product = $this->getProduct($productInfo['id'], $context);
                if ($useGrossPriceForCalculation) {
                    $priceCollection = $product->getPrice();
                    if ($priceCollection instanceof PriceCollection) {
                        foreach ($priceCollection as $price) {
                            if ($price->getGross()) {
                                $lineItems[$key]['unit_price'] = $price->getGross();
                                $this->cartTotal = $this->cartTotal + ($price->getGross() * $quantity);
                            }
                        }
                    }
                } else {
                    $this->cartTotal = $this->cartTotal + ($lineItems[$key]['unit_price'] * $quantity);
                }

                $productTaxCode = $product->getCustomFields() ?
                    ($product->getCustomFields()['product_tax_code_value'] ?? null) : null;
                if (!$productTaxCode) {
                    $productTaxCode = $this->getDefaultProductTaxCode();
                }
                $lineItems[$key]['product_tax_code'] = $productTaxCode;
            }
        }
        return $lineItems;
    }

    /**
     * @param string $productId
     * @param SalesChannelContext $context
     * @return ProductEntity
     */
    private function getProduct(string $productId, SalesChannelContext $context): ProductEntity
    {
        $product = $this->productRepository
            ->search(new Criteria([$productId]), $context->getContext())
            ->get($productId);

        if (!$product instanceof ProductEntity) {
            throw new \RuntimeException("Product with ID $productId not found.");
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $dataToLog
     * @param SalesChannelContext $context
     * @return void
     */
    private function logRequestResponse(array $dataToLog, SalesChannelContext $context): void
    {
        if (!empty($dataToLog)) {
            $this->taxLogRepository->create(
                [$dataToLog],
                $context->getContext()
            );
        }
    }

    /**
     * @param SalesChannelContext $context
     * @param array<string, mixed> $orderDetail
     * @return array<array<string>|string>
     * @throws InvalidArgumentException
     * @throws GuzzleException
     */
    private function _getTaxRateWithHttpRequest(SalesChannelContext $context, array $orderDetail = [])
    {
        $response = [];
        $debugMode = $this->isDebugModeOn();
        $apiToken = $this->_taxJarApiToken();

        // Ensure all header values are strings
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . ($apiToken ?? ''),
            "X-CSRF-Token" => $apiToken ?? ''
        ];

        $customer = $context->getCustomer();

        $request = new GRequest(
            'POST',
            $this->_getApiEndPoint() . '/taxes',
            $headers,
            json_encode($orderDetail) ?: ''
        );

        if ($debugMode && $customer instanceof CustomerEntity) {
            $logInfo = [
                'requestKey' => serialize($orderDetail),
                'customerName' => $customer->getFirstName() . ' ' . $customer->getLastName(),
                'customerEmail' => $customer->getEmail(),
                'remoteIp' => $customer->getRemoteAddress() ?? '',
                'request' => json_encode($orderDetail),
                'type' => self::REQUEST_TYPE
            ];
        }
        try {
            $response = $this->restClient->send($request);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            if ($debugMode && isset($logInfo)) {
                $logInfo['response'] = $responseBodyAsString;
                $this->logRequestResponse($logInfo, $context);
            }
            return ['error' => json_decode($responseBodyAsString, true)];
        }
        try {
            $response = $response->getBody()->getContents();
            if ($debugMode && isset($logInfo)) {
                $logInfo['response'] = $response;
                $this->logRequestResponse($logInfo, $context);
            }
            $response = json_decode($response, true);
            $this->setResponseIntoCache($response, serialize($orderDetail));
            if (isset($response['tax'])) {
                return $response['tax'];
            }
        } catch (\Exception $e) {
            $response['error'] = $e->getMessage();
        }
        return $response;
    }

    /**
     * @return int
     */
    private function _isSandboxMode(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.sandboxMode', $this->salesChannelId);
    }

    /**
     * @return int
     */
    private function useIncludeShippingCostForTaxCalculation(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.includeShippingCost', $this->salesChannelId);
    }

    /**
     * @return int
     */
    private function useGrossPriceForTaxCalculation(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.useGrossPrice', $this->salesChannelId);
    }

    /**
     * @return int
     */
    private function _isActive(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.active', $this->salesChannelId);
    }

    /**
     * @return string|null
     */
    private function _taxJarApiToken(): ?string
    {
        $token = $this->_isSandboxMode()
            ? $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId)
            : $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);

        return $token && is_string($token) ? trim($token) : null;
    }

    /**
     * @return string
     */
    private function _getApiEndPoint(): string
    {
        if ($this->_isSandboxMode()) {
            return self::SANDBOX_API_URL;
        }
        return self::LIVE_API_URL;
    }

    /**
     * @return array<string, string>
     */
    private function getShippingOriginAddress(): array
    {
        $fromCountry = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCountry', $this->salesChannelId);
        $fromZip = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromZip', $this->salesChannelId);
        $fromState = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromState', $this->salesChannelId);
        $fromCity = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCity', $this->salesChannelId);
        $fromStreet = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromStreet', $this->salesChannelId);

        return [
            "from_country" => is_string($fromCountry) ? $fromCountry : '',
            "from_zip" => is_string($fromZip) ? $fromZip : '',
            "from_state" => is_string($fromState) ? $fromState : '',
            "from_city" => is_string($fromCity) ? $fromCity : '',
            "from_street" => is_string($fromStreet) ? $fromStreet : '',
        ];
    }

    /**
     * @return string
     */
    private function getDefaultProductTaxCode(): string
    {
        $taxCode = $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
        return is_string($taxCode) ? $taxCode : '';
    }

    /**
     * @return int
     */
    private function isDebugModeOn(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.debug', $this->salesChannelId);
    }

    /**
     * @param mixed $response
     * @param string $cacheId
     * @return void
     * @throws InvalidArgumentException
     */
    private function setResponseIntoCache($response, string $cacheId): void
    {
        $item = $this->cache->getItem(self::CACHE_ID . hash('sha256', $cacheId));
        $item->set(\serialize($response));
        $this->cache->save($item);
    }


    /**
     * @param string $cacheId
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    private function getResponseFromCache(string $cacheId): array
    {
        $response = $this->cache->getItem(self::CACHE_ID . hash('sha256', $cacheId))->get();
        if ($response === null) {
            return [];
        }
        $response = \unserialize($response, ['allowed_classes' => [\DateTime::class]]);
        if (is_array($response) && !empty($response)) {
            if (isset($response['tax'])) {
                return $response['tax'];
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $taxInformation
     * @param array<string, mixed> $processedResponse
     * @return array<string, mixed>
     */
    private function addRate(array $taxInformation, array $processedResponse): array
    {
        if (isset($taxInformation['rate'])) {
            $processedResponse['rate'] = $taxInformation['rate'];
        }

        return $processedResponse;
    }
}