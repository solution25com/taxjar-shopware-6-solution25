<?php

namespace solu1TaxJar\Core\TaxJar;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GRequest;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Cache\CacheItemPoolInterface;
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
     * @var EntityRepository
     */
    private $taxJarLogRepository;

    /**
     * @var EntityRepository
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
     * @param EntityRepository $taxJarLogRepository
     * @param EntityRepository $productRepository
     * @param CacheItemPoolInterface $cache
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
        $this->taxJarLogRepository = $taxJarLogRepository;
        $this->productRepository = $productRepository;
        $this->cache = $cache;
    }

    public function supports(string $baseClass): bool
    {
        return static::class === $baseClass;
    }

    public function calculate(array $lineItems, SalesChannelContext $context, Cart $original): array
    {
        $result = $this->calculateTax($lineItems, $context, $original);

        return is_array($result) ? $result : [];
    }

    /**
     * @param $lineItems
     * @param SalesChannelContext $context
     * @param Cart $cart
     * @return array|false
     */
    public function calculateTax($lineItems, SalesChannelContext $context, Cart $cart): false|array
    {
        $this->salesChannelId = $context->getSalesChannelId();
        if ($this->_isActive()) {
            if (!$context->getCustomer() || !$context->getCustomer()->getActiveShippingAddress()) {
                return [];
            }

            $customerGroupToExempt = $this->_getCustomerGroupToExempt() ?? [];
            $customerGroup = $context->getCustomer()->getGroupId();

            $shippingAddress = $context->getCustomer()->getActiveShippingAddress();

            $stateCode = $shippingAddress->getCountryState() ?
                explode('-', $shippingAddress->getCountryState()->getShortCode()) : '';
            $stateName = $shippingAddress->getCountryState() ?
                $shippingAddress->getCountryState()->getName() : '';
            $shippingFromAddress = $this->getShippingOriginAddress();
            $this->cartTotal = 0;
            $lineItems = $this->processLinceItems($lineItems, $context);
            $priceAfterProcessLineItems = $this->cartTotal;

            $customFields = $context->getCustomer()->getCustomFields() ?? [];
            $taxjarCustomerId = $customFields['taxjar_customer_id'] ?? null;

            $cartInfo = [
              "to_country" => $shippingAddress->getCountry()->getIso(),
              "to_zip" => $shippingAddress->getZipcode(),
              "to_state" => $stateCode[1] ?? $stateName,
              "to_city" => $shippingAddress->getCity(),
              "to_street" => $shippingAddress->getStreet(),
              "amount" => ($priceAfterProcessLineItems > 0)
                ? $this->cartTotal
                : $cart->getPrice()->getTotalPrice(),
              "shipping" => $this->useIncludeShippingCostForTaxCalculation()
                ? $cart->getShippingCosts()->getUnitPrice()
                : 0,
              "line_items" => $lineItems,
              "customer_id" => $taxjarCustomerId,
              ];

          // If customer ID is null, then check for a customer group, which means if customer is registered on TaxJar,
          // the customer group rule should not be applied

          if ($taxjarCustomerId === null && in_array($customerGroup, $customerGroupToExempt, true)) {
            $cartInfo["exemption_type"] = "other";
          }

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
     * @param $lineItems
     * @param SalesChannelContext $context
     * @return array
     */
    private function processLinceItems($lineItems, SalesChannelContext $context)
    {
        $useGrossPriceForCalculation = $this->useGrossPriceForTaxCalculation();
        foreach ($lineItems as $key => $productInfo) {
            if (isset($productInfo['id']) && $productInfo['id']) {
                $quantity = $lineItems[$key]['quantity'];
                $product = $this->getProduct($productInfo['id'], $context);
                if ($useGrossPriceForCalculation) {
                    $priceCollection = $product->getPrice();
                    foreach ($priceCollection as $price) {
                        if ($price->getGross()) {
                            $lineItems[$key]['unit_price'] = $price->getGross();
                            $this->cartTotal = $this->cartTotal + ($price->getGross() * $quantity);
                        }
                    }
                } else {
                    $this->cartTotal = $this->cartTotal + ($lineItems[$key]['unit_price'] * $quantity);
                }

                if($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
                    $productTaxCode = $product->getCustomFields()['product_tax_code_value'];
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
     * @param SalesChannelContext $context
     * @return ProductEntity
     */
    private function getProduct(string $productId, SalesChannelContext $context) : ProductEntity
    {
        return $this->productRepository
            ->search(new Criteria([$productId]), $context->getContext())
            ->get($productId);
    }

    /**
     * @param $dataToLog
     * @param SalesChannelContext $context
     * @return void
     */
    private function logRequestResponse($dataToLog, SalesChannelContext $context)
    {
        if (!empty($dataToLog)) {
            $this->taxJarLogRepository->create(
                [$dataToLog], $context->getContext());
        }
    }

    /**
     * @param SalesChannelContext $context
     * @param array $orderDetail
     * @return array|mixed|ResponseInterface|string
     * @throws InvalidArgumentException
     * @throws GuzzleException
     */
    private function _getTaxRateWithHttpRequest(SalesChannelContext $context, array $orderDetail = [])
    {
        $response = [];
        $debugMode = $this->isDebugModeOn();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->_taxJarApiToken(),
            "X-CSRF-Token" => $this->_taxJarApiToken()
        ];
        $customer = $context->getCustomer();
        $request = new GRequest(
            'POST',
            $this->_getApiEndPoint() . '/taxes',
            $headers,
            json_encode($orderDetail)
        );
        if ($debugMode) {
            $logInfo = [
                'requestKey' => serialize($orderDetail),
                'customerName' => $customer->getFirstName() . ' ' . $customer->getLastName(),
                'customerEmail' => $customer->getEmail(),
                'remoteIp' => !is_null($customer->getRemoteAddress()) ? $customer->getRemoteAddress() : '',
                'request' => json_encode($orderDetail),
                'type' => self::REQUEST_TYPE
            ];
        }
        try {
            $response = $this->restClient->send($request);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            if ($debugMode) {
                $logInfo['response'] = $responseBodyAsString;
                $this->logRequestResponse($logInfo, $context);
            }
            return ['error' => json_decode($responseBodyAsString, true)];
        }
        try {
            $response = $response->getBody()->getContents();
            if ($debugMode) {
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

    private function _getCustomerGroupToExempt(): array|null
    {
        return $this->systemConfigService->get('solu1TaxJar.setting.exemptCustomerGroup', $this->salesChannelId);
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
    private function _taxJarApiToken()
    {
        if ($this->_isSandboxMode()) {
            return $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId);
        }
        return $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);
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
     * @return array
     */
    private function getShippingOriginAddress(): array
    {
        return [
            "from_country" => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCountry', $this->salesChannelId),
            "from_zip" => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromZip', $this->salesChannelId),
            "from_state" => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromState', $this->salesChannelId),
            "from_city" => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCity', $this->salesChannelId),
            "from_street" => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromStreet', $this->salesChannelId),
        ];
    }

    /**
     * @return string
     */
    private function getDefaultProductTaxCode(): string
    {
        return $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
    }

    /**
     * @return int
     */
    private function isDebugModeOn(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.debug', $this->salesChannelId);
    }

    /**
     * @param $response
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

    private function addRate(mixed $taxInformation, array $processedResponse): array
    {
        if (isset($taxInformation['rate'])) {
            $processedResponse['rate'] = $taxInformation['rate'];
        }

        return $processedResponse;
    }

}
