<?php declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Order;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
use solu1TaxJar\Core\TaxJar\Request\Request;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
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

  /**
   * @var bool
   */
  protected $dispatched = false;

  /**
   * @var mixed
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
   * @var EntityRepository
   */
  private  $taxJarLogRepository;

  /**
   * @var EntityRepository
   */
  private  $orderRepository;

  /**
   * @var EntityRepository
   */
  private  $productRepository;

  /**
   * @var EntityRepository
   */
  private  $countryRepository;

  /**
   * @var EntityRepository
   */
  private  $stateRepository;

  /**
   * @var ClientApiService
   */
  private ClientApiService $clientApiService;


    /**
   * @param SystemConfigService $systemConfigService
   * @param EntityRepository $taxJarLogRepository
   * @param EntityRepository $orderRepository
   * @param EntityRepository $productRepository
   * @param EntityRepository $countryRepository
   * @param EntityRepository $stateRepository
     * @param ClientApiService $clientApiService
   */
  public function __construct(
    SystemConfigService $systemConfigService,
    EntityRepository    $taxJarLogRepository,
    EntityRepository    $orderRepository,
    EntityRepository    $productRepository,
    EntityRepository    $countryRepository,
    EntityRepository    $stateRepository,
    ClientApiService    $clientApiService
  )
  {
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
      CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
      'state_enter.order_delivery.state.shipped' => 'onOrderShipped',
      'state_enter.order_transaction.state.cancelled' => 'onOrderStateCancel',
      'state_enter.order_transaction.state.paid' => 'onOrderStatePaid',
      'state_enter.order_transaction.state.refunded' => 'onOrderRefund',
    ];
  }

  /**
   * @param CheckoutOrderPlacedEvent $event
   * @return void
   */
  public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
  {
    $order = $event->getOrder();
    $context = $event->getContext();
    $lineItems = $order->getLineItems();

    $hasTaxJar = false;

    foreach ($lineItems as $item) {
      $payload = $item->getPayload();

      if (isset($payload['taxJarRate'])) {
        $hasTaxJar = true;
        break;
      }
    }

    if ($hasTaxJar) {
      $this->orderRepository->update([[
        'id' => $order->getId(),
        'customFields' => array_merge(
          $order->getCustomFields() ?? [],
          ['taxJar' => true]
        ),
      ]], $context);
    }
  }


  /**
   * @param OrderStateMachineStateChangeEvent $event
   * @return void
   */
  public function onOrderShipped(OrderStateMachineStateChangeEvent $event): void
  {
    $this->context = $event->getContext();
    if (!$this->dispatched) {
      $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);
      if($selectedFlow == 'ship'){
        $this->createOrderTransaction($event->getOrderId(), $event);
      }
      $this->dispatched = true;
    }
  }

  public function onOrderStatePaid(OrderStateMachineStateChangeEvent $event): void
  {
      $this->context = $event->getContext();
      if (!$this->dispatched) {
      $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);
      if($selectedFlow == 'paid'){
        $this->createOrderTransaction($event->getOrderId(), $event);
      }
      $this->dispatched = true;
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
        if($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION){
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

  /**
   * @param OrderStateMachineStateChangeEvent $event
   * @return void
   */
  public function onOrderStateCancel(OrderStateMachineStateChangeEvent $event): void
  {
    try {
      $this->context = $event->getContext();
      $orderId = $event->getOrderId();

      $order = $this->getOrder($orderId);
      if (!$order) {
        return;
      }

      if (!$this->hasTaxJarProvider($order)) {
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

  /**
   * @param OrderStateMachineStateChangeEvent $event
   * @return void
   */
  public function onOrderRefund(OrderStateMachineStateChangeEvent $event): void
  {
    try {
      $this->context = $event->getContext();
      $orderId = $event->getOrderId();
      $order = $this->getOrder($orderId);
      if (!$order) {
        return;
      }

      if (!$this->hasTaxJarProvider($order)) {
        return;
      }

      if($order->getDeliveries()?->first()->getStateMachineState()->getTechnicalName() != 'shipped'){
          return;
      }


      $this->salesChannelId = $order->getSalesChannelId();

      $existTransactionId = $this->getExistTransactionId($orderId);
      if ($existTransactionId) {
        $orderId = $existTransactionId;
      }

      $orderDetail = $this->getOrderDetail($order);
      $orderDetail['transaction_id'] = $orderId . '_refund';

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
    /**
     * @param string $orderId
     * @param OrderStateMachineStateChangeEvent $event
     * @return void
     */
  protected function createOrderTransaction(string $orderId, OrderStateMachineStateChangeEvent $event): void
  {
    try {
      $order = $this->getOrder($orderId);
      if (!$order) {
        return;
      }

      if (!$this->hasTaxJarProvider($order)) {
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
   * @param $countryId
   * @return CountryEntity|false
   */
  protected function getCountry($countryId): CountryEntity|false
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

  /**
   * @param $requestKey
   * @return bool
   */
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

  protected function getOperation($event): ?string
  {
    $writingResults = $event->getWriteResults();
    if (is_array($writingResults) && isset($writingResults[0])) {
      return $writingResults[0]->getOperation();
    }
    return null;
  }

  /**
   * @param $dataToLog
   * @return void
   */
  protected function logRequestResponse($dataToLog): void
  {
    if (!empty($dataToLog)) {
      $this->taxJarLogRepository->create(
        [$dataToLog], $this->context);
    }
  }

  protected function _taxJarApiToken(): array|string
  {
    if ($this->_isSandboxMode()) {
      return $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId);
    }
    return $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);
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
    return $this->systemConfigService->get('solu1TaxJar.setting.defaultProductTaxCode', $this->salesChannelId);
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

  private function getOrder($orderId): ?OrderEntity
  {
    $criteria = new Criteria([$orderId]);
    $criteria->getAssociation('lineItems');
    $criteria->getAssociation('salesChannel');
    $criteria->getAssociation('billingAddress');
    $criteria->getAssociation('addresses');
    $criteria->getAssociation('deliveries');
    $criteria->getAssociation('deliveries.shippingOrderAddress');
    $criteria->addAssociation('deliveries.shippingOrderAddress.country');
    $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
    $criteria->addAssociation('billingAddress.country');
    $criteria->addAssociation('billingAddress.countryState');
    $criteria->addAssociation('stateMachineState');
    return $this->orderRepository
      ->search($criteria, $this->context)
      ->get($orderId);
  }

  private function getHeaders(): array
  {
    return [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $this->_taxJarApiToken(),
      "X-CSRF-Token" => $this->_taxJarApiToken()
    ];
  }

  private function getLogInfo(OrderEntity $order, array $orderDetail, string $requestType): array
  {
    return [
      'requestKey' => serialize($orderDetail),
      'customerName' => $order->getOrderCustomer()->getFirstName() . ' ' . $order->getOrderCustomer()->getLastName(),
      'customerEmail' => $order->getOrderCustomer()->getEmail(),
      'remoteIp' => $order->getOrderCustomer()->getRemoteAddress() ?: '',
      'request' => json_encode($orderDetail),
      'type' => $requestType,
      'orderNumber' => self::PREFIX . $order->getOrderNumber(),
      'orderId' => $order->getId()
    ];
  }

  private function getOrderDetail(OrderEntity $order): array
  {
    $amounts = $this->getAmounts($order);
    $orderTotalAmount = $amounts['orderTotalAmount'];
    $orderTaxAmount = $amounts['orderTaxAmount'];

    $lineItems = $this->getLineItems($order);

    $shippingOrderAddress = null;
    if ($order->getDeliveries() && $order->getDeliveries()->count() > 0) {
      $firstDelivery = $order->getDeliveries()->first();
      if ($firstDelivery && method_exists($firstDelivery, 'getShippingOrderAddress')) {
        $shippingOrderAddress = $firstDelivery->getShippingOrderAddress();
      }
    }

    $billingAddress = $order->getBillingAddress();
    $destinationAddress = $shippingOrderAddress ?: $billingAddress;

    $countryIso = $destinationAddress?->getCountry()?->getIso();
    $shortCode = $destinationAddress?->getCountryState()?->getShortCode();
    $state = null;
    if ($shortCode) {
      $parts = explode('-', $shortCode);
      $state = $parts[1] ?? null;
    }
    if (!$state && $countryIso) {
      $countryParts = explode('-', $countryIso);
      $state = $countryParts[1] ?? null;
    }

    $orderTotalAmount += $order->getShippingTotal();

    $shippingTaxAmount = 0;
    if($this->useIncludeShippingCostForTaxCalculation()) {
      $shippingMethodCalculatedTax = $order->getShippingCosts()->getCalculatedTaxes();
      foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
        $shippingTaxAmount = $shippingTaxAmount + $methodCalculatedTax->getTax();
      }
    }

    $customerCustomFields = $order->getOrderCustomer()->getCustomFields() ?? [];
    $taxjarCustomerId = $customerCustomFields['taxjar_customer_id'] ?? null;

    /** @todo Maybe should use just orderNumber $transactionId */
    $transactionId = $this->getTransactionId($order);
    $shippingFromAddress = $this->getShippingOriginAddress();
    return array_merge(
      $shippingFromAddress,
      [
        'transaction_id' => $transactionId,
        'transaction_date' => $order->getOrderDate()->format('Y/m/d'),
        'customer_id' => $taxjarCustomerId,
        'to_country' => $countryIso,
        'to_zip' => $destinationAddress?->getZipcode(),
        'to_state' => $state,
        'to_city' => $destinationAddress?->getCity(),
        'to_street' => $destinationAddress?->getStreet(),
        'amount' => $orderTotalAmount,
        'shipping' => $order->getShippingTotal(),
        'sales_tax' => $orderTaxAmount + $shippingTaxAmount,
        'line_items' => $lineItems
      ]
    );
  }

  private function getLineItems(OrderEntity $order): array
  {
    $lineItems = [];
    foreach ($order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
      $parentProduct = null;
      /** @var ProductEntity $product */
      $product = $this->productRepository
        ->search(new Criteria([$lineItem->getProductId()]), $this->context)
        ->get($lineItem->getProductId());

      $productTaxCode = null;
      if($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
        $productTaxCode = $product->getCustomFields()['product_tax_code_value'];
      }

      if ($product->getParentId()) {
        $parentProduct = $this->productRepository
          ->search(new Criteria([$product->getParentId()]), $this->context)
          ->get($product->getParentId());

        if($parentProduct->getCustomFields() && isset($parentProduct->getCustomFields()['product_tax_code_value'])) {
          $productTaxCode = $parentProduct->getCustomFields()['product_tax_code_value'];
        }
      }
      if (!$productTaxCode) {
        $productTaxCode = $this->getDefaultProductTaxCode();
      }

      $lineItem = [
        'quantity' => $lineItem->getQuantity(),
        'product_identifier' => $parentProduct ? $parentProduct->getProductNumber() : $product->getProductNumber(),
        'description' => $parentProduct ? $parentProduct->getTranslation('name') : $product->getTranslation('name'),
        'unit_price' => $lineItem->getUnitPrice(),
        'sales_tax' => $lineItem->getPrice()?->getCalculatedTaxes()->getAmount()
      ];

      // only send the code if it's set and it's not 'none'
      if($productTaxCode && !strtolower($productTaxCode) == 'none') {
        $lineItem['product_tax_code'] = $productTaxCode;
      }

      $lineItems[] = $lineItem;

    }
    return $lineItems;
  }
    private function getAmounts(OrderEntity $order): array
  {
    $orderTotalAmount = 0;
    $orderTaxAmount = 0;

    foreach ($order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
      $orderTotalAmount += $lineItem->getUnitPrice() * $lineItem->getQuantity();
      $orderTaxAmount += $lineItem->getPrice()?->getCalculatedTaxes()->getAmount();
    }

    return [
      'orderTotalAmount' => $orderTotalAmount,
      'orderTaxAmount' => $orderTaxAmount
    ];
  }

  private function getDeleteLogInfo(string $orderId): array
  {
    return [
      'requestKey' => serialize(['orderId' => $orderId]),
      'customerName' => 'Admin',
      'customerEmail' => '',
      'remoteIp' => '',
      'request' => json_encode(['orderId' => $orderId]),
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
    return $iterator->fetch()?->first();
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

  private function useIncludeShippingCostForTaxCalculation(): int
  {
    return (int)$this->systemConfigService->get('solu1TaxJar.setting.includeShippingCost', $this->salesChannelId);
  }

  protected function hasTaxJarProvider(OrderEntity $order): bool
  {
    return true;
//    $customFields = $order->getCustomFields() ?? [];
//    return !empty($customFields['taxJarProvider']);
  }
}