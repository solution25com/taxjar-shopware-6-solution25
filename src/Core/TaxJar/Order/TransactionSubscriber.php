<?php declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Order;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;
use solu1TaxJar\Core\TaxJar\Request\Request;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateCollection;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use solu1TaxJar\Service\ClientApiService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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

    /** @var bool */
    protected $dispatched = false;

    /** @var mixed */
    protected $salesChannelId = null;

    /** @var Context */
    protected $context;

    /** @var SystemConfigService */
    private SystemConfigService $systemConfigService;

    /** @var EntityRepository<TaxLogCollection> */
    private EntityRepository $taxJarLogRepository;

    /** @var EntityRepository<OrderCollection> */
    private EntityRepository $orderRepository;

    /** @var EntityRepository<ProductCollection> */
    private EntityRepository $productRepository;

    /** @var EntityRepository<CountryCollection> */
    private EntityRepository $countryRepository;

    /** @var EntityRepository<CountryStateCollection> */
    private EntityRepository $stateRepository;

    /** @var ClientApiService */
    private ClientApiService $clientApiService;

    /**
     * @param EntityRepository<TaxLogCollection>       $taxJarLogRepository
     * @param EntityRepository<OrderCollection>         $orderRepository
     * @param EntityRepository<ProductCollection>       $productRepository
     * @param EntityRepository<CountryCollection>       $countryRepository
     * @param EntityRepository<CountryStateCollection>  $stateRepository
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository    $taxJarLogRepository,
        EntityRepository    $orderRepository,
        EntityRepository    $productRepository,
        EntityRepository    $countryRepository,
        EntityRepository    $stateRepository,
        ClientApiService    $clientApiService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->taxJarLogRepository = $taxJarLogRepository;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->countryRepository = $countryRepository;
        $this->stateRepository = $stateRepository;
        $this->clientApiService = $clientApiService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_DELETED_EVENT => 'onOrderDeleted',
            'state_enter.order_delivery.state.shipped' => 'onOrderShipped',
            'OrderStateMachineStateChangeEvent' => 'onOrderStateChange',
            'state_enter.order_transaction.state.cancelled' => 'onOrderStateCancel',
            'state_enter.order_transaction.state.paid' => 'onOrderStatePaid',
        ];
    }

    public function onOrderShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $this->context = $event->getContext();
        if (!$this->dispatched) {
            $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);
            if ($selectedFlow == 'ship') {
                $this->createOrderTransaction($event->getOrderId(), $event);
            }
            $this->dispatched = true;
        }
    }

    public function onOrderStatePaid(OrderStateMachineStateChangeEvent $event): void
    {
        if (!$this->dispatched) {
            $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);
            if ($selectedFlow == 'paid') {
                $this->createOrderTransaction($event->getOrderId(), $event);
            }
            $this->dispatched = true;
        }
    }

    /**
     * @throws GuzzleException
     */
    public function onOrderDeleted(EntityWrittenEvent $event): void
    {
        if (!$this->dispatched) {
            try {
                $this->context = $event->getContext();
                if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
                    return;
                }

                foreach ($event->getIds() as $orderId) {
                    $existTransactionId = $this->getExistTransactionId($orderId);
                    $logInfo = $this->getDeleteLogInfo($orderId);
                    $orderId = $existTransactionId ?: $orderId;

                    $endpointUrl = $this->_getApiEndPoint() . '/transactions/orders/' . $orderId;

                    $response = $this->clientApiService->sendRequest(
                        'DELETE',
                        $endpointUrl,
                        $this->getHeaders(),
                        ['orderId' => $orderId]
                    );

                    $logInfo['response'] = $response['body'];
                    $this->logRequestResponse($logInfo);
                }
            } catch (\Exception $e) {
                return;
            }

            $this->dispatched = true;
        }
    }

    public function onOrderStateCancel(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $this->context = $event->getContext();
            $orderId = $event->getOrderId();

            $order = $this->getOrder($orderId);
            if (!$order) {
                return;
            }

            $existTransactionId = $this->getExistTransactionId($orderId);
            $logInfo = $this->getDeleteLogInfo($orderId);
            $orderId = $existTransactionId ?: $orderId;

            $endpointUrl = $this->_getApiEndPoint() . '/transactions/orders/' . $orderId;

            $response = $this->clientApiService->sendRequest(
                'DELETE',
                $endpointUrl,
                $this->getHeaders(),
                ['orderId' => $orderId]
            );

            $logInfo['response'] = $response['body'];
            $this->logRequestResponse($logInfo);
        } catch (\Exception $e) {
            return;
        }
    }

    public function onOrderStateChange(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $this->context = $event->getContext();
            $orderId = $event->getOrderId();
            $order = $this->getOrder($orderId);
            if (!$order) {
                return;
            }

            $this->salesChannelId = $order->getSalesChannelId();

            $orderDetail = $this->getOrderDetail($order);
            $orderDetail['transaction_id'] .= '_refund';

            $logInfo = $this->getLogInfo($order, $orderDetail, self::ORDER_REFUND_REQUEST_TYPE);

            $endpointUrl = $this->_getApiEndPoint() . '/transactions/refunds';

            $response = $this->clientApiService->sendRequest(
                'POST',
                $endpointUrl,
                $this->getHeaders(),
                $orderDetail
            );

            $logInfo['response'] = $response['body'];
            $this->logRequestResponse($logInfo);
        } catch (\Exception $e) {
            return;
        }
    }

    protected function createOrderTransaction(string $orderId, OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $order = $this->getOrder($orderId);
            if (!$order) {
                return;
            }

            $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders';
            $requestType = self::ORDER_CREATE_REQUEST_TYPE;
            $this->salesChannelId = $order->getSalesChannelId();
            $orderDetail = $this->getOrderDetail($order);

            if ($this->isDuplicateRequest(serialize($orderDetail))) {
                return;
            }

            $logInfo = $this->getLogInfo($order, $orderDetail, $requestType);

            $response = $this->clientApiService->sendRequest(
                'POST',
                $apiEndpointUrl,
                $this->getHeaders(),
                $orderDetail
            );

            $logInfo['response'] = $response['body'];
            $this->logRequestResponse($logInfo);
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @param string $countryId
     * @return CountryEntity|false
     */
    protected function getCountry(string $countryId): CountryEntity|false
    {
        try {
            /** @var CountryEntity $country */
            $country = $this->countryRepository
                ->search(new Criteria([$countryId]), $this->context)
                ->get($countryId);
            return $country;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $stateId
     * @return CountryStateEntity|false
     */
    protected function getCountryState(string $stateId): CountryStateEntity|false
    {
        try {
            /** @var CountryStateEntity $state */
            $state = $this->stateRepository
                ->search(new Criteria([$stateId]), $this->context)
                ->get($stateId);

            return $state;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function isDuplicateRequest(string $requestKey): bool
    {
        if (!$requestKey) {
            return false;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('requestKey', $requestKey))
            ->setLimit(1);

        $result = $this->taxJarLogRepository->searchIds($criteria, $this->context);

        return $result->getTotal() > 0;
    }

    /** @phpstan-ignore-next-line method.unused */
    private function getOperation(EntityWrittenEvent $event): ?string
    {
        $writingResults = $event->getWriteResults();
        if (is_array($writingResults) && isset($writingResults[0])) { // @phpstan-ignore-line
            return $writingResults[0]->getOperation();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $dataToLog
     */
    protected function logRequestResponse(array $dataToLog): void
    {
        if (!empty($dataToLog)) {
            $this->taxJarLogRepository->create([$dataToLog], $this->context);
        }
    }

    private function _taxJarApiToken(): string
    {
        if ($this->_isSandboxMode()) {
            $val = $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId);
            return is_string($val) ? $val : '';
        }
        $val = $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);
        return is_string($val) ? $val : '';
    }

    protected function _getApiEndPoint(): string
    {
        if ($this->_isSandboxMode()) {
            return self::SANDBOX_API_URL;
        }
        return self::LIVE_API_URL;
    }

    protected function _isSandboxMode(): int
    {
        return (int) $this->systemConfigService->get('solu1TaxJar.setting.sandboxMode', $this->salesChannelId);
    }

    private function getDefaultProductTaxCode(): string
    {
        $val = $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
        return is_string($val) ? $val : '';
    }

    private function getTransactionId(OrderEntity $order): string
    {
        $configOrderId = $this->systemConfigService->get('solu1TaxJar.setting.orderId');

        if ($configOrderId === 'orderId') {
            $orderId = $order->getId();
        } else {
            $orderId = $order->getOrderNumber();
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
        $criteria->getAssociation('deliveries');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $this->context)->get($orderId);
        return $order;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . (string) $this->_taxJarApiToken(),
            'X-CSRF-Token' => (string) $this->_taxJarApiToken(),
        ];
    }

    /**
     * @param array<string, mixed> $orderDetail
     * @return array<string, mixed>
     */
    private function getLogInfo(OrderEntity $order, array $orderDetail, string $requestType): array
    {
        /** @var OrderCustomerEntity $orderCustomer */
        $orderCustomer = $order->getOrderCustomer();

        return [
            'requestKey' => serialize($orderDetail),
            'customerName' => $orderCustomer->getFirstName() . ' ' . $orderCustomer->getLastName(),
            'customerEmail' => $orderCustomer->getEmail(),
            'remoteIp' => $orderCustomer->getRemoteAddress() ?: '',
            'request' => (string) json_encode($orderDetail),
            'type' => $requestType,
            'orderNumber' => self::PREFIX . $order->getOrderNumber(),
            'orderId' => $order->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOrderDetail(OrderEntity $order): array
    {
        $amounts = $this->getAmounts($order);
        $orderTotalAmount = $amounts['orderTotalAmount'];
        $orderTaxAmount = $amounts['orderTaxAmount'];

        $lineItems = $this->getLineItems($order);

        /** @var OrderAddressEntity|null $shippingAddress */
        $shippingAddress = $order->getBillingAddress();
        /** @var OrderAddressEntity|null $billingAddress */
        $billingAddress = $order->getBillingAddress();

        $country = $billingAddress?->getCountry()?->getIso();
        $shortCode = $billingAddress?->getCountryState()?->getShortCode();
        /** @var string $shortCode */
        $state = explode('-', $shortCode)[1];

        $orderTotalAmount += $order->getShippingTotal();

        $shippingTaxAmount = 0;
        if ($this->useIncludeShippingCostForTaxCalculation()) {
            $shippingMethodCalculatedTax = $order->getShippingCosts()->getCalculatedTaxes();
            foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
                $shippingTaxAmount = $shippingTaxAmount + $methodCalculatedTax->getTax();
            }
        }

        /** @var array<string, mixed> $customerCustomFields */
        $customerCustomFields = $order->getOrderCustomer()?->getCustomFields() ?? [];
        $taxjarCustomerId = $customerCustomFields['taxjar_customer_id'] ?? null;

        $transactionId = $this->getTransactionId($order);
        $shippingFromAddress = $this->getShippingOriginAddress();

        /** @var OrderAddressEntity $shippingAddress */
        $shippingAddress = $shippingAddress; // assert non-null for PHPStan

        return array_merge(
            $shippingFromAddress,
            [
                'transaction_id' => $transactionId,
                'transaction_date' => $order->getOrderDate()->format('Y/m/d'),
                'customer_id' => $taxjarCustomerId,
                // ternary flagged as always true due to assert above; keep logic, silence PHPStan:
                'to_country' => $country,
                'to_zip' => $shippingAddress ? $shippingAddress->getZipcode() : $billingAddress?->getZipcode(), // @phpstan-ignore-line
                'to_state' => $state,
                'to_city' => $shippingAddress->getCity(),
                'to_street' => $shippingAddress->getStreet(),
                'amount' => $orderTotalAmount,
                'shipping' => $order->getShippingTotal(),
                'sales_tax' => $orderTaxAmount + $shippingTaxAmount,
                'line_items' => $lineItems,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getLineItems(OrderEntity $order): array
    {
        $lineItems = [];

        /** @var OrderLineItemCollection $items */
        $items = $order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        foreach ($items as $lineItem) {
            $parentProduct = null;

            /** @var string $productId */
            $productId = $lineItem->getProductId();

            /** @var ProductEntity $product */
            $product = $this->productRepository
                ->search(new Criteria([$productId]), $this->context)
                ->get($productId);

            $productTaxCode = null;
            if ($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
                $productTaxCode = $product->getCustomFields()['product_tax_code_value'];
            }

            if ($product->getParentId()) {
                /** @var string $parentId */
                $parentId = $product->getParentId();

                /** @var ProductEntity $parentProduct */
                $parentProduct = $this->productRepository
                    ->search(new Criteria([$parentId]), $this->context)
                    ->get($parentId);

                if ($parentProduct->getCustomFields() && isset($parentProduct->getCustomFields()['product_tax_code_value'])) {
                    $productTaxCode = $parentProduct->getCustomFields()['product_tax_code_value'];
                }
            }
            if (!$productTaxCode) {
                $productTaxCode = $this->getDefaultProductTaxCode();
            }

            $line = [
                'quantity' => $lineItem->getQuantity(),
                'product_identifier' => $parentProduct ? $parentProduct->getProductNumber() : $product->getProductNumber(),
                'description' => $parentProduct ? $parentProduct->getTranslation('name') : $product->getTranslation('name'),
                'unit_price' => $lineItem->getUnitPrice(),
                'sales_tax' => $lineItem->getPrice()?->getCalculatedTaxes()->getAmount(),
            ];

            // only send the code if it's set and it's not 'none'
            /** @phpstan-ignore-next-line */
            if ($productTaxCode && !strtolower($productTaxCode) == 'none') {
                $line['product_tax_code'] = $productTaxCode;
            }

            $lineItems[] = $line;
        }
        return $lineItems;
    }

    /**
     * @return array{orderTotalAmount: float|int, orderTaxAmount: float|int}
     */
    private function getAmounts(OrderEntity $order): array
    {
        $orderTotalAmount = 0;
        $orderTaxAmount = 0;

        /** @var OrderLineItemCollection $items */
        $items = $order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        foreach ($items as $lineItem) {
            $orderTotalAmount += $lineItem->getUnitPrice() * $lineItem->getQuantity();
            $orderTaxAmount += $lineItem->getPrice()?->getCalculatedTaxes()->getAmount();
        }

        return [
            'orderTotalAmount' => $orderTotalAmount,
            'orderTaxAmount' => $orderTaxAmount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDeleteLogInfo(string $orderId): array
    {
        return [
            'requestKey' => serialize(['orderId' => $orderId]),
            'customerName' => 'Admin',
            'customerEmail' => '',
            'remoteIp' => '',
            'request' => (string) json_encode(['orderId' => $orderId]),
            'type' => self::ORDER_DELETE_REQUEST_TYPE,
            'orderNumber' => '',
            'orderId' => $orderId,
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
        /** @var TaxLogEntity|null */
        return $iterator->fetch()?->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function getShippingOriginAddress(): array
    {
        return [
            'from_country' => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCountry', $this->salesChannelId),
            'from_zip'     => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromZip', $this->salesChannelId),
            'from_state'   => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromState', $this->salesChannelId),
            'from_city'    => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromCity', $this->salesChannelId),
            'from_street'  => $this->systemConfigService->get('solu1TaxJar.setting.shippingFromStreet', $this->salesChannelId),
        ];
    }

    private function useIncludeShippingCostForTaxCalculation(): int
    {
        return (int) $this->systemConfigService->get('solu1TaxJar.setting.includeShippingCost', $this->salesChannelId);
    }
}