<?php

namespace solu1TaxJar\Core\TaxJar;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request as GRequest;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use solu1TaxJar\Core\Tax\TaxCalculatorInterface;

class Calculator implements TaxCalculatorInterface
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
     * @var mixed
     */
    private $salesChannelId;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepository<EntityCollection<Entity>>
     */
    private $taxJarLogRepository;

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
    private $cartTotal = 0.0;

    /**
     * @param SystemConfigService                         $systemConfigService
     * @param EntityRepository<EntityCollection<Entity>>  $taxJarLogRepository
     * @param EntityRepository<ProductCollection>         $productRepository
     * @param CacheItemPoolInterface                      $cache
     */
    public function __construct(
        SystemConfigService    $systemConfigService,
        EntityRepository       $taxJarLogRepository,
        EntityRepository       $productRepository,
        CacheItemPoolInterface $cache
    )
    {
        $this->restClient = new Client();
        $this->systemConfigService = $systemConfigService;
        /** @var EntityRepository<EntityCollection<Entity>> $taxJarLogRepository */
        $this->taxJarLogRepository = $taxJarLogRepository;
        /** @var EntityRepository<ProductCollection> $productRepository */
        $this->productRepository = $productRepository;
        $this->cache = $cache;
    }

    public function supports(string $baseClass): bool
    {
        return static::class === $baseClass;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @return array<string, float|int>
     */
    // @phpstan-ignore-next-line
    public function calculate(array $lineItems, SalesChannelContext $context, Cart $original): array
    {
        $result = $this->calculateTax($lineItems, $context, $original);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @return array<string, float|int>|false
     */
    public function calculateTax(array $lineItems, SalesChannelContext $context, Cart $cart): false|array
    {
        $this->salesChannelId = $context->getSalesChannelId();
        if ($this->_isActive()) {
            if (!$context->getCustomer() || !$context->getCustomer()->getActiveShippingAddress()) {
                return [];
            }

            $shippingAddress = $context->getCustomer()->getActiveShippingAddress();

            $stateCode = $shippingAddress->getCountryState() ?
                explode('-', (string) $shippingAddress->getCountryState()->getShortCode()) : '';
            $stateName = $shippingAddress->getCountryState() ?
                (string) $shippingAddress->getCountryState()->getName() : '';
            $shippingFromAddress = $this->getShippingOriginAddress();
            $this->cartTotal = 0.0;
            $lineItems = $this->processLinceItems($lineItems, $context);
            $priceAfterProcessLineItems = $this->cartTotal;

            $customFields = $context->getCustomer()->getCustomFields() ?? [];
            $taxjarCustomerId = $customFields['taxjar_customer_id'] ?? null;

            $cartInfo = [
                "to_country" => $shippingAddress->getCountry()?->getIso(),
                "to_zip" => $shippingAddress->getZipcode(),
                "to_state" => $stateCode[1] ?? $stateName,
                "to_city" => $shippingAddress->getCity(),
                "to_street" => $shippingAddress->getStreet(),
                "amount" => max((float) $this->cartTotal, (float) $cart->getPrice()->getTotalPrice()),
                "shipping" => $this->useIncludeShippingCostForTaxCalculation() ?
                    (float) $cart->getShippingCosts()->getUnitPrice() : 0.0,
                "line_items" => $lineItems,
                "customer_id" => $taxjarCustomerId
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
                    if (isset($lineItem['id'], $lineItem['tax_collectable'])) {
                        $processedResponse[(string) $lineItem['id']] = (float) $lineItem['tax_collectable'];
                    }
                }
                if ($this->useIncludeShippingCostForTaxCalculation()) {
                    if (isset($taxInformation['breakdown']['shipping']) &&
                        ($shippingTax = $taxInformation['breakdown']['shipping'])) {
                        $processedResponse['shippingTax'] = (float) ($shippingTax['tax_collectable'] ?? 0);
                    }
                }
                /** @var array<string, float|int> $processedResponse */
                return $processedResponse;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @return array<int, array<string, mixed>>
     */
    private function processLinceItems(array $lineItems, SalesChannelContext $context): array
    {
        $useGrossPriceForCalculation = $this->useGrossPriceForTaxCalculation();
        foreach ($lineItems as $key => $productInfo) {
            if (isset($productInfo['id']) && $productInfo['id']) {
                $quantity = (int) ($lineItems[$key]['quantity'] ?? 0);
                $product = $this->getProduct((string) $productInfo['id'], $context);
                if ($product === null) {
                    continue;
                }
                if ($useGrossPriceForCalculation) {
                    $priceCollection = $product->getPrice();
                    if ($priceCollection instanceof PriceCollection) {
                        foreach ($priceCollection as $price) {
                            if ($price->getGross()) {
                                $lineItems[$key]['unit_price'] = $price->getGross();
                                $this->cartTotal = $this->cartTotal + ((float) $price->getGross() * $quantity);
                            }
                        }
                    }
                } else {
                    $unitPrice = (float) ($lineItems[$key]['unit_price'] ?? 0.0);
                    $this->cartTotal = $this->cartTotal + ($unitPrice * $quantity);
                }

                if ($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
                    /** @var array<string, mixed> $cfs */
                    $cfs = $product->getCustomFields();
                    $productTaxCode = $cfs['product_tax_code_value'];
                } else {
                    // let the value to get default from configs check that in subscriber
                    $productTaxCode = $this->getDefaultProductTaxCode();
                }

                $lineItems[$key]['product_tax_code'] = $productTaxCode;
            }
        }
        return $lineItems;
    }

    /**
     * @param string $productId
     */
    private function getProduct(string $productId, SalesChannelContext $context): ?ProductEntity
    {
        /** @var ProductEntity|null $entity */
        $entity = $this->productRepository
            ->search(new Criteria([$productId]), $context->getContext())
            ->get($productId);

        return $entity;
    }

    /**
     * @param array<string, mixed> $dataToLog
     * @return void
     */
    private function logRequestResponse(array $dataToLog, SalesChannelContext $context): void
    {
        if (!empty($dataToLog)) {
            $this->taxJarLogRepository->create(
                [$dataToLog], $context->getContext());
        }
    }

    /**
     * @param array<string, mixed> $orderDetail
     * @return array|mixed|ResponseInterface|string
     * @throws InvalidArgumentException
     * @throws GuzzleException
     */
    private function _getTaxRateWithHttpRequest(SalesChannelContext $context, array $orderDetail = [])
    {
        $responsePayload = [];
        $debugMode = $this->isDebugModeOn();
        $token = (string) ($this->_taxJarApiToken() ?? '');
        /** @var array<string, array<string>|string> $headers */
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'X-CSRF-Token'  => $token,
        ];
        $customer = $context->getCustomer();
        $request = new GRequest(
            'POST',
            $this->_getApiEndPoint() . '/taxes',
            $headers,
            json_encode($orderDetail) ?: ''
        );
        if ($debugMode) {
            $logInfo = [
                'requestKey'    => serialize($orderDetail),
                'customerName'  => trim(($customer?->getFirstName() ?? '') . ' ' . ($customer?->getLastName() ?? '')),
                'customerEmail' => $customer?->getEmail() ?? '',
                'remoteIp'      => $customer?->getRemoteAddress() ?? '',
                'request'       => json_encode($orderDetail),
                'type'          => self::REQUEST_TYPE
            ];
        }
        try {
            $httpResponse = $this->restClient->send($request);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $httpResponse = $e->getResponse();
            $responseBodyAsString = $httpResponse->getBody()->getContents();
            if ($debugMode) {
                $logInfo['response'] = $responseBodyAsString;
                $this->logRequestResponse($logInfo, $context);
            }
            return ['error' => json_decode($responseBodyAsString, true)];
        }
        try {
            /** @var ResponseInterface $httpResponse */
            $body = $httpResponse->getBody()->getContents();
            if ($debugMode) {
                $logInfo['response'] = $body;
                $this->logRequestResponse($logInfo, $context);
            }
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true);
            $this->setResponseIntoCache($decoded, serialize($orderDetail));
            if (isset($decoded['tax'])) {
                return $decoded['tax'];
            }
            return $decoded;
        } catch (\Exception $e) {
            $responsePayload = ['error' => $e->getMessage()];
        }
        return $responsePayload;
    }

    /**
     * @return int
     */
    private function _isSandboxMode(): int
    {
        /** @var int $val */
        $val = (int) $this->systemConfigService->get('solu1TaxJar.setting.sandboxMode', $this->salesChannelId);
        return $val;
    }

    /**
     * @return int
     */
    private function useIncludeShippingCostForTaxCalculation(): int
    {
        /** @var int $val */
        $val = (int) $this->systemConfigService->get('solu1TaxJar.setting.includeShippingCost', $this->salesChannelId);
        return $val;
    }

    /**
     * @return int
     */
    private function useGrossPriceForTaxCalculation(): int
    {
        /** @var int $val */
        $val = (int) $this->systemConfigService->get('solu1TaxJar.setting.useGrossPrice', $this->salesChannelId);
        return $val;
    }

    /**
     * @return int
     */
    private function _isActive(): int
    {
        /** @var int $val */
        $val = (int) $this->systemConfigService->get('solu1TaxJar.setting.active', $this->salesChannelId);
        return $val;
    }

    /**
     * @return string|null
     */
    private function _taxJarApiToken(): ?string
    {
        $key = $this->_isSandboxMode()
            ? 'solu1TaxJar.setting.sandboxApiToken'
            : 'solu1TaxJar.setting.liveApiToken';

        $val = $this->systemConfigService->get($key, $this->salesChannelId);

        return is_string($val) && $val !== '' ? $val : null;
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
     * @return array<string, string|null>
     */
    private function getShippingOriginAddress(): array
    {
        $country = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCountry', $this->salesChannelId);
        $zip     = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromZip', $this->salesChannelId);
        $state   = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromState', $this->salesChannelId);
        $city    = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCity', $this->salesChannelId);
        $street  = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromStreet', $this->salesChannelId);

        return [
            "from_country" => is_string($country) ? $country : null,
            "from_zip"     => is_string($zip) ? $zip : null,
            "from_state"   => is_string($state) ? $state : null,
            "from_city"    => is_string($city) ? $city : null,
            "from_street"  => is_string($street) ? $street : null,
        ];
    }

    /**
     * @return string
     */
    private function getDefaultProductTaxCode(): string
    {
        $val = $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
        return is_string($val) ? $val : '';
    }

    /**
     * @return int
     */
    private function isDebugModeOn(): int
    {
        /** @var int $val */
        $val = (int) $this->systemConfigService->get('solu1TaxJar.setting.debug', $this->salesChannelId);
        return $val;
    }

    /**
     * @param array<string, mixed> $response
     * @param string $cacheId
     * @return void
     * @throws InvalidArgumentException
     */
    private function setResponseIntoCache(array $response, string $cacheId): void
    {
        $item = $this->cache->getItem(self::CACHE_ID . hash('sha256', $cacheId));
        $item->set(\serialize($response));
        $this->cache->save($item);
    }

    /**
     * @param string $cacheId
     * @return array|mixed
     * @throws InvalidArgumentException
     */
    private function getResponseFromCache(string $cacheId): mixed
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
     * @param mixed $taxInformation
     * @param array<string, float|int> $processedResponse
     * @return array<string, float|int>
     */
    private function addRate(mixed $taxInformation, array $processedResponse): array
    {
        if (isset($taxInformation['rate'])) {
            $processedResponse['rate'] = (float) $taxInformation['rate'];
        }

        return $processedResponse;
    }
}
