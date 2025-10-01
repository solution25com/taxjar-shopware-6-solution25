<?php declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Order;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GRequest;
use Psr\Http\Message\StreamInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class TransactionSubscriber implements EventSubscriberInterface
{

    public const ORDER_CREATE_REQUEST_TYPE = 'Order Create Transaction';

    public const ORDER_UPDATE_REQUEST_TYPE = 'Order Update Transaction';

    public const ORDER_REFUND_REQUEST_TYPE = 'Order Refund Transaction';

    public const ORDER_DELETE_REQUEST_TYPE = 'Order Delete Transaction';

    public const ORDER_CANCEL_REQUEST_TYPE = 'Order Cancel Transaction';

    public const VERSION = '1.10.4';

    public const LIVE_API_URL = 'https://api.taxjar.com/v2';

    public const SANDBOX_API_URL = 'https://api.sandbox.taxjar.com/v2';

    public const PREFIX = 'SW';

    /**
     * @var bool
     */
    protected $dispatched = false;

    /**
     * @var string|null
     */
    protected $existTransactionId = null;

    /**
     * @var string|null
     */
    protected $salesChannelId = null;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var SystemConfigService
     */
    private  $systemConfigService;

    /**
     * @var EntityRepository<TaxLogCollection>
     */
    private  $taxJarLogRepository;

    /**
     * @var EntityRepository<EntityCollection<OrderEntity>>
     */
    private  $orderRepository;

    /**
     * @var EntityRepository<EntityCollection<ProductEntity>>
     */
    private  $productRepository;

    /**
     * @var EntityRepository<EntityCollection<CountryEntity>>
     */
    private  $countryRepository;

    /**
     * @var EntityRepository<EntityCollection<CountryStateEntity>>
     */
    private  $stateRepository;

    /**
     * @var EntityRepository<EntityCollection<OrderTransactionEntity>>
     */
    private  $orderTransactionRepository;

    /**
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository<TaxLogCollection> $taxJarLogRepository
     * @param EntityRepository<EntityCollection<OrderEntity>> $orderRepository
     * @param EntityRepository<EntityCollection<ProductEntity>> $productRepository
     * @param EntityRepository<EntityCollection<CountryEntity>> $countryRepository
     * @param EntityRepository<EntityCollection<CountryStateEntity>> $stateRepository
     * @param EntityRepository<EntityCollection<OrderTransactionEntity>> $orderTransactionRepository
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository    $taxJarLogRepository,
        EntityRepository    $orderRepository,
        EntityRepository    $productRepository,
        EntityRepository    $countryRepository,
        EntityRepository    $stateRepository,
        EntityRepository $orderTransactionRepository
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->taxJarLogRepository = $taxJarLogRepository;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->countryRepository = $countryRepository;
        $this->stateRepository = $stateRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransition',
            OrderEvents::ORDER_DELETED_EVENT => 'onOrderDeleted',
            'state_enter.order_transaction.state.refunded' => 'onOrderStateChange',
            'state_enter.order_transaction.state.cancelled' => 'onOrderStateCancel'
        ];
    }

    /**
     * @param StateMachineTransitionEvent $event
     * @return void
     */

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        $this->context = $event->getContext();
        $nextState = $event->getToPlace()->getTechnicalName();
        $fromPlace = $event->getFromPlace()->getTechnicalName();
        $entityName = $event->getEntityName();
        $transactionId = $event->getEntityId();

        if ($entityName !== "order_transaction") {
            return;
        }

        $criteria = (new Criteria([$transactionId]))
            ->addAssociation('paymentMethod')
            ->addAssociation('order');

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $this->context)->first();

        if ($orderTransaction === null) {
            return;
        }

        $order = $orderTransaction->getOrder();
        if ($order === null) {
            return;
        }

        $orderId = $order->getId();

        if($nextState === 'paid'){
            $this->createUpdateOrderTransaction($orderId);
        }
        if($fromPlace == 'paid' && $nextState === 'cancelled'){
            $this->onOrderStateCancel($event, $orderId);

        }
        if($fromPlace == 'paid' && $nextState === 'refunded'){
            $this->onOrderStateChange($event, $orderId);
        }

    }

    /**
     * @param EntityWrittenEvent $event
     * @return void
     * @throws GuzzleException
     */
    public function onOrderDeleted(EntityWrittenEvent $event): void
    {
        if (!$this->dispatched) {
            try {
                $this->context = $event->getContext();
                $method = 'DELETE';
                if($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION){
                    return;
                }

                foreach ($event->getIds() as $orderId) {
                    $existTransactionId = $this->getExistTransactionId($orderId);
                    $logInfo = $this->getDeleteLogInfo($orderId);
                    $transactionId = $existTransactionId ?: $orderId;
                    $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders' . '/' . $transactionId;

                    $request = new GRequest(
                        $method,
                        $apiEndpointUrl,
                        $this->getHeaders(),
                        json_encode(['orderId' => $transactionId]) ?: ''
                    );
                    try {
                        $response = (new Client())->send($request);
                        $response = $response->getBody()->getContents();
                        $logInfo['response'] = $response;
                        $this->logRequestResponse($logInfo);
                    } catch (ClientException $e) {
                        $response = $e->getResponse();
                        $responseBodyAsString = $response->getBody()->getContents();
                        $logInfo['response'] = $responseBodyAsString;
                        $this->logRequestResponse($logInfo);
                    }
                }
            } catch (\Exception $e) {
                return;
            }
            $this->dispatched = true;
        }
    }

    /**
     * @param StateMachineTransitionEvent $event
     * @param string $orderId
     * @return void
     */
    public function onOrderStateCancel(StateMachineTransitionEvent $event, string $orderId): void
    {
        try {
            $this->context = $event->getContext();

            $order = $this->getOrder($orderId);
            if (!$order) {
                return;
            }
            $existTransactionId = $this->getExistTransactionId($orderId);
            $logInfo = $this->getDeleteLogInfo($orderId);
            $transactionId = $existTransactionId ?: $orderId;

            $method = 'DELETE';
            $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders' . '/' . $transactionId;

            $request = new GRequest(
                $method,
                $apiEndpointUrl,
                $this->getHeaders(),
                json_encode(['orderId' => $transactionId]) ?: ''
            );
            try {
                $response = (new Client())->send($request);
                $response = $response->getBody()->getContents();
                $logInfo['response'] = $response;
                $this->logRequestResponse($logInfo);
            } catch (ClientException $e) {
                $response = $e->getResponse();
                $responseBodyAsString = $response->getBody()->getContents();
                $logInfo['response'] = $responseBodyAsString;
                $this->logRequestResponse($logInfo);
            }
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @param StateMachineTransitionEvent $event
     * @param string $orderId
     * @return void
     */
    public function onOrderStateChange(StateMachineTransitionEvent $event, string $orderId): void
    {
        try {
            $this->context = $event->getContext();
            $method = 'POST';
            $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/refunds';
            $order = $this->getOrder($orderId);

            if (!$order) {
                return;
            }

            $this->salesChannelId = $order->getSalesChannelId();

            $orderDetail = $this->getOrderDetail($order);
            $orderDetail['transaction_id'] .= '_refund';

            $logInfo = $this->getLogInfo($order, $orderDetail, self::ORDER_REFUND_REQUEST_TYPE);

            $request = new GRequest(
                $method,
                $apiEndpointUrl,
                $this->getHeaders(),
                json_encode($orderDetail) ?: ''
            );
            try {
                $response = (new Client())->send($request);
                $response = $response->getBody()->getContents();
                $logInfo['response'] = $response;
                $this->logRequestResponse($logInfo);
            } catch (ClientException $e) {
                $response = $e->getResponse();
                $responseBodyAsString = $response->getBody()->getContents();
                $logInfo['response'] = $responseBodyAsString;
                $this->logRequestResponse($logInfo);
            }
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @param string $orderId
     * @return void
     */
    protected function createUpdateOrderTransaction(string $orderId): void
    {
        try {
            $order = $this->getOrder($orderId);
            if (!$order) {
                return;
            }

            $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders';
            $method = 'POST';
            $requestType = self::ORDER_CREATE_REQUEST_TYPE;

            $this->salesChannelId = $order->getSalesChannelId();

            $orderDetail = $this->getOrderDetail($order);

            if ($this->isDuplicateRequest(serialize($orderDetail) ?: '')) {
                return;
            }

            $logInfo = $this->getLogInfo($order, $orderDetail, $requestType);

            $request = new GRequest(
                $method,
                $apiEndpointUrl,
                $this->getHeaders(),
                json_encode($orderDetail) ?: ''
            );

            try {
                $client = new Client();
                $response = $client->send($request);
                $response = $response->getBody()->getContents();
                $logInfo['response'] = $response;
                $this->logRequestResponse($logInfo);
            } catch (GuzzleException $e) {
                $response = $e->getMessage();
                $logInfo['response'] = $response;
                $this->logRequestResponse($logInfo);
            }
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @param string $countryId
     * @return CountryEntity|false
     */
    protected function getCountry(string $countryId)
    {
        try {
            /** @var CountryEntity|null $country */
            $country = $this->countryRepository
                ->search(new Criteria([$countryId]), $this->context)
                ->get($countryId);
            return $country ?: false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $stateId
     * @return CountryStateEntity|null
     */
    protected function getCountryState(string $stateId): ?CountryStateEntity
    {
        /** @var CountryStateEntity|null $countryState */
        $countryState = $this->stateRepository
            ->search(new Criteria([$stateId]), $this->context)
            ->get($stateId);
        return $countryState;
    }

    /**
     * @param string $requestKey
     * @return bool
     */
    protected function isDuplicateRequest(string $requestKey): bool
    {
        if ($requestKey) {
            $iterator = new RepositoryIterator(
                $this->taxJarLogRepository,
                $this->context,
                (new Criteria())->addFilter(new EqualsFilter('requestKey', $requestKey))
            );
            $records = $iterator->fetch();
            if (!is_null($records)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param EntityWrittenEvent $event
     * @return string|null
     */
    protected function getOperation(EntityWrittenEvent $event): ?string
    {
        $writingResults = $event->getWriteResults();
        if (count($writingResults) > 0 && isset($writingResults[0])) {
            return $writingResults[0]->getOperation();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $dataToLog
     * @return void
     */
    protected function logRequestResponse(array $dataToLog): void
    {
        if (!empty($dataToLog)) {
            $this->taxJarLogRepository->create(
                [$dataToLog], $this->context);
        }
    }

    /**
     * @return int
     */
    protected function _isActive(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.active', $this->salesChannelId);
    }

    /**
     * @return string
     */
    protected function _taxJarApiToken(): ?string
    {
        $token = null;
        if ($this->_isSandboxMode()) {
            $token = $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId);
        } else {
            $token = $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);
        }

        return is_string($token) ? $token : null;
    }

    /**
     * @return string
     */
    protected function _getApiEndPoint(): string
    {
        if ($this->_isSandboxMode()) {
            return self::SANDBOX_API_URL;
        }
        return self::LIVE_API_URL;
    }

    /**
     * @return int
     */
    protected function _isSandboxMode(): int
    {
        return (int)$this->systemConfigService->get('solu1TaxJar.setting.sandboxMode', $this->salesChannelId);
    }

    /**
     * @return string
     */
    private function getDefaultProductTaxCode(): string
    {
        $taxCode = $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
        return is_string($taxCode) ? $taxCode : '';
    }

    private function getTransactionId(OrderEntity $order): string
    {
        $configOrderId = $this->systemConfigService->get('solu1TaxJar.setting.orderId');

        if ($configOrderId === 'orderId') {
            $orderId = $order->getId();
        } else {
            $orderId = $order->getOrderNumber() ?? $order->getId();
        }
        return self::PREFIX . $orderId;
    }

    private function getExistTransactionId(string $orderId): ?string
    {
        $taxJarLog = $this->getCreateLog($orderId);

        $transactionId = null;
        if ($taxJarLog) {
            $createRequest = $taxJarLog->getRequest();
            if ($createRequest) {
                $createRequest = json_decode($createRequest, true);
                if (isset($createRequest['transaction_id'])) {
                    $transactionId = $createRequest['transaction_id'];
                }
            }
        }
        return $transactionId;
    }

    private function getOrder(string $orderId): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->getAssociation('lineItems');
        $criteria->getAssociation('salesChannel');
        $criteria->getAssociation('billingAddress');
        $criteria->getAssociation('addresses');
        $criteria->getAssociation('deliveries');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('orderCustomer');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository
            ->search($criteria, $this->context)
            ->get($orderId);
        return $order;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        $apiToken = $this->_taxJarApiToken();

        if ($apiToken === null) {
            throw new \RuntimeException('TaxJar API token is not configured. Please check your TaxJar settings.');
        }

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiToken,
            'X-CSRF-Token' => $apiToken
        ];
    }

    /**
     * @param OrderEntity $order
     * @param array<string, mixed> $orderDetail
     * @param string $requestType
     * @return array<string, mixed>
     */
    private function getLogInfo(OrderEntity $order, array $orderDetail, string $requestType): array
    {
        $orderCustomer = $order->getOrderCustomer();
        $customerName = 'Unknown';
        $customerEmail = '';
        $remoteIp = '';

        if ($orderCustomer instanceof OrderCustomerEntity) {
            $firstName = $orderCustomer->getFirstName();
            $lastName = $orderCustomer->getLastName();
            $customerName = trim($firstName . ' ' . $lastName);
            $customerEmail = $orderCustomer->getEmail();
            $remoteIp = $orderCustomer->getRemoteAddress();
        }

        return [
            'requestKey' => serialize($orderDetail),
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'remoteIp' => $remoteIp,
            'request' => json_encode($orderDetail),
            'type' => $requestType,
            'orderNumber' => self::PREFIX . ($order->getOrderNumber()),
            'orderId' => $order->getId()
        ];
    }

    /**
     * @param OrderEntity $order
     * @return array<string, mixed>
     */
    private function getOrderDetail(OrderEntity $order): array
    {
        $amounts = $this->getAmounts($order);
        $orderTotalAmount = $amounts['orderTotalAmount'];
        $orderTaxAmount = $amounts['orderTaxAmount'];

        $lineItems = $this->getLineItems($order);

        $shippingAddress = $order->getBillingAddress();
        $billingAddress = $order->getBillingAddress();

        $country = $billingAddress?->getCountry()?->getIso() ?? '';
        $shortCode = $billingAddress?->getCountryState()?->getShortCode() ?? '';
        $state = $shortCode ? (explode('-', $shortCode)[1] ?? '') : '';

        $orderTotalAmount += $order->getShippingTotal();

        /** @todo Maybe should use just orderNumber $transactionId */
        $transactionId = $this->getTransactionId($order);
        $shippingFromAddress = $this->getShippingOriginAddress();

        $shippingCity = $shippingAddress?->getCity() ?? '';
        $shippingStreet = $shippingAddress?->getStreet() ?? '';
        $shippingZip = $shippingAddress?->getZipcode() ?? $billingAddress?->getZipcode() ?? '';

        return array_merge(
            $shippingFromAddress,
            [
                'transaction_id' => $transactionId,
                'transaction_date' => $order->getOrderDate()->format('Y/m/d'),
                'to_country' => $country,
                'to_zip' => $shippingZip,
                'to_state' => $state,
                'to_city' => $shippingCity,
                'to_street' => $shippingStreet,
                'amount' => $orderTotalAmount,
                'shipping' => $order->getShippingTotal(),
                'sales_tax' => $orderTaxAmount,
                'line_items' => $lineItems
            ]
        );
    }

    /**
     * @param OrderEntity $order
     * @return array<int, array<string, mixed>>
     */
    private function getLineItems(OrderEntity $order): array
    {
        $lineItems = [];
        $orderLineItems = $order->getLineItems();

        if (!$orderLineItems instanceof OrderLineItemCollection) {
            return $lineItems;
        }

        foreach ($orderLineItems->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $parentProduct = null;
            $productId = $lineItem->getProductId();

            if ($productId === null) {
                continue;
            }

            /** @var ProductEntity|null $product */
            $product = $this->productRepository
                ->search(new Criteria([$productId]), $this->context)
                ->get($productId);

            if ($product === null) {
                continue;
            }

            $productTaxCode = $product->getCustomFields() ?
                ($product->getCustomFields()['product_tax_code_value'] ?? null) : null;

            if ($product->getParentId()) {
                /** @var ProductEntity|null $parentProduct */
                $parentProduct = $this->productRepository
                    ->search(new Criteria([$product->getParentId()]), $this->context)
                    ->get($product->getParentId());

                if ($parentProduct instanceof ProductEntity) {
                    $productTaxCode = $parentProduct->getCustomFields() ?
                        ($parentProduct->getCustomFields()['product_tax_code_value'] ?? null) : null;
                }
            }

            if (!$productTaxCode) {
                $productTaxCode = $this->getDefaultProductTaxCode();
            }

            $productNumber = $parentProduct ? $parentProduct->getProductNumber() : $product->getProductNumber();
            $productName = $parentProduct ?
                ($parentProduct->getTranslation('name')) :
                ($product->getTranslation('name'));

            $lineItems[] = [
                'quantity' => $lineItem->getQuantity(),
                'product_identifier' => $productNumber,
                'description' => $productName,
                'unit_price' => $lineItem->getUnitPrice(),
                'product_tax_code' => $productTaxCode,
                'sales_tax' => $lineItem->getPrice()?->getCalculatedTaxes()->getAmount() ?? 0.0
            ];
        }
        return $lineItems;
    }

    /**
     * @param OrderEntity $order
     * @return array<string, float>
     */
    private function getAmounts(OrderEntity $order): array
    {
        $orderTotalAmount = 0.0;
        $orderTaxAmount = 0.0;

        $orderLineItems = $order->getLineItems();

        if (!$orderLineItems instanceof OrderLineItemCollection) {
            return [
                'orderTotalAmount' => $orderTotalAmount,
                'orderTaxAmount' => $orderTaxAmount
            ];
        }

        foreach ($orderLineItems->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $orderTotalAmount += $lineItem->getUnitPrice() * $lineItem->getQuantity();
            $orderTaxAmount += $lineItem->getPrice()?->getCalculatedTaxes()->getAmount() ?? 0.0;
        }

        return [
            'orderTotalAmount' => $orderTotalAmount,
            'orderTaxAmount' => $orderTaxAmount
        ];
    }

    /**
     * @param string $orderId
     * @return array<string, mixed>
     */
    private function getDeleteLogInfo(string $orderId): array
    {
        return [
            'requestKey' => serialize(['orderId' => $orderId]) ?: '',
            'customerName' => 'Admin',
            'customerEmail' => '',
            'remoteIp' => '',
            'request' => json_encode(['orderId' => $orderId]) ?: '',
            'type' => self::ORDER_DELETE_REQUEST_TYPE,
            'orderNumber' => '',
            'orderId' => $orderId
        ];
    }

    private function getCreateLog(string $orderId): ?TaxLogEntity
    {
        $iterator = new RepositoryIterator(
            $this->taxJarLogRepository,
            $this->context,
            (new Criteria())->addFilter(
                new EqualsFilter('orderId', $orderId),
                new EqualsFilter('type', self::ORDER_CREATE_REQUEST_TYPE)
            )
        );

        $result = $iterator->fetch();
        if ($result === null) {
            return null;
        }

        $first = $result->first();
        return $first instanceof TaxLogEntity ? $first : null;
    }
    /**
     * @return array<string, string>
     */
    private function getShippingOriginAddress(): array
    {
        $country = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCountry', $this->salesChannelId);
        $zip = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromZip', $this->salesChannelId);
        $state = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromState', $this->salesChannelId);
        $city = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCity', $this->salesChannelId);
        $street = $this->systemConfigService->get('solu1TaxJar.setting.shippingFromStreet', $this->salesChannelId);

        return [
            "from_country" => is_string($country) ? $country : '',
            "from_zip" => is_string($zip) ? $zip : '',
            "from_state" => is_string($state) ? $state : '',
            "from_city" => is_string($city) ? $city : '',
            "from_street" => is_string($street) ? $street : '',
        ];
    }
}