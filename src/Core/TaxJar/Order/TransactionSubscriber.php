<?php declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Order;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
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
use Psr\Log\LoggerInterface;

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

  private const API_CONNECT_TIMEOUT = 5;

  private const API_REQUEST_TIMEOUT = 15;

  /**
   * @var bool
   */
  protected $dispatched = false;

  protected array $dispatchedOrderIds = [];

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
  private ?EntityRepository $orderReturnRepository;
  private LoggerInterface $logger;


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
    ClientApiService    $clientApiService,
    ?EntityRepository $orderReturnRepository,
    LoggerInterface $logger,
  )
  {
    $this->systemConfigService = $systemConfigService;
    $this->taxJarLogRepository = $taxJarLogRepository;
    $this->orderRepository = $orderRepository;
    $this->productRepository = $productRepository;
    $this->countryRepository = $countryRepository;
    $this->stateRepository = $stateRepository;
    $this->clientApiService = $clientApiService;
    $this->orderReturnRepository = $orderReturnRepository;
    $this->logger = $logger;
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
      'state_enter.order_transaction.state.refunded_partially' => 'onPartiallyOrderRefund',
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
    $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $event->getSalesChannelId());

    $this->logOrderTransactionDiagnostic('state_event_entered', [
      'shopwareEventName' => $event->getName(),
      'orderId' => $event->getOrderId(),
      'salesChannelId' => $event->getSalesChannelId(),
      'selectedCommitFlow' => $selectedFlow,
    ]);

    if (isset($this->dispatchedOrderIds[$event->getOrderId()])) {
      $this->logOrderTransactionDiagnostic('state_event_skipped', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
        'earlyReturnReason' => 'already_dispatched',
      ]);
      return;
    }

    if($selectedFlow == 'ship'){
      $this->logOrderTransactionDiagnostic('create_order_transaction_about_to_be_called', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
      ]);
      $this->createOrderTransaction($event->getOrderId(), $event);
    } else {
      $this->logOrderTransactionDiagnostic('state_event_skipped', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
        'earlyReturnReason' => 'selected_commit_flow_mismatch',
      ]);
    }

    $this->dispatchedOrderIds[$event->getOrderId()] = true;
  }

  public function onOrderStatePaid(OrderStateMachineStateChangeEvent $event): void
  {
    $this->context = $event->getContext();
    $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $event->getSalesChannelId());

    $this->logOrderTransactionDiagnostic('state_event_entered', [
      'shopwareEventName' => $event->getName(),
      'orderId' => $event->getOrderId(),
      'salesChannelId' => $event->getSalesChannelId(),
      'selectedCommitFlow' => $selectedFlow,
    ]);

    if (isset($this->dispatchedOrderIds[$event->getOrderId()])) {
      $this->logOrderTransactionDiagnostic('state_event_skipped', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
        'earlyReturnReason' => 'already_dispatched',
      ]);
      return;
    }

    if($selectedFlow == 'paid'){
      $this->logOrderTransactionDiagnostic('create_order_transaction_about_to_be_called', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
      ]);
      $this->createOrderTransaction($event->getOrderId(), $event);
    } else {
      $this->logOrderTransactionDiagnostic('state_event_skipped', [
        'shopwareEventName' => $event->getName(),
        'orderId' => $event->getOrderId(),
        'salesChannelId' => $event->getSalesChannelId(),
        'selectedCommitFlow' => $selectedFlow,
        'earlyReturnReason' => 'selected_commit_flow_mismatch',
      ]);
    }

    $this->dispatchedOrderIds[$event->getOrderId()] = true;
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
          $transactionId = $this->getExistTransactionId($orderId);

          if (!$transactionId) {
            $this->logOrderTransactionDiagnostic('taxjar_operation_failed', [
              'operation' => self::ORDER_DELETE_REQUEST_TYPE,
              'shopwareEventName' => $event->getName(),
              'orderId' => $orderId,
              'success' => false,
              'earlyReturnReason' => 'transaction_id_unresolvable',
            ], 'error');

            continue;
          }

          $logInfo = $this->getDeleteLogInfo($transactionId);

          $endpointUrl = $this->_getApiEndPoint() . '/transactions/orders/' . $transactionId;

          $response = $this->callTaxJar(self::ORDER_DELETE_REQUEST_TYPE, 'DELETE', $endpointUrl, ['orderId' => $transactionId], ['orderId' => $orderId]);

          $logInfo['response'] = $response['body'];
          $this->logRequestResponse($logInfo);
        }

      } catch (\Throwable $e) {
        $this->logTaxJarException(self::ORDER_DELETE_REQUEST_TYPE, $e, ['orderId' => implode(',', $event->getIds())]);
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

      $orderId = $this->getExistTransactionId($orderId, $order) ?: $this->getTransactionId($order);
      $logInfo = $this->getDeleteLogInfo($orderId);

      $endpointUrl = $this->_getApiEndPoint() . '/transactions/orders/' . $orderId;

      $response = $this->callTaxJar(self::ORDER_CANCEL_REQUEST_TYPE, 'DELETE', $endpointUrl, ['orderId' => $orderId], ['orderId' => $event->getOrderId(), 'orderNumber' => $order->getOrderNumber()]);

      $logInfo['response'] = $response['body'];
      $this->logRequestResponse($logInfo);
    } catch (\Throwable $e) {
      $this->logTaxJarException(self::ORDER_CANCEL_REQUEST_TYPE, $e, ['orderId' => $event->getOrderId()]);
      return;
    }
  }

  public function onPartiallyOrderRefund(OrderStateMachineStateChangeEvent $event)
  {
    try {
      $this->context = $event->getContext();

      if ($this->orderReturnRepository === null) {
        return;
      }

      $orderId = $event->getOrderId();
      $order = $this->getOrder($orderId);

      if (!$order) {
        return;
      }

      if (!$this->hasTaxJarProvider($order)) {
        return;
      }

      if($order->getDeliveries()?->first()?->getStateMachineState()?->getTechnicalName() != 'shipped') {
        $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);

        if ($selectedFlow === 'ship') {
          if ($order->getDeliveries()?->first()->getStateMachineState()->getTechnicalName() !== 'shipped') {
            return;
          }
        }
      }

      $this->salesChannelId = $order->getSalesChannelId();

      $criteria = new Criteria();
      $criteria->addFilter(new EqualsFilter('orderId', $orderId));
      $criteria->addAssociation('order');
      $criteria->addAssociation('lineItems');

      $orderReturn = $this->orderReturnRepository
        ->search($criteria, $this->context)
        ->first();

      if (!$orderReturn) {
        return;
      }

      $refundData = $this->calculatePartialRefundTax($order, $orderReturn);

      if (empty($refundData)) {
        return;
      }

      $this->createPartialRefundTransaction($order, $refundData);

    } catch (\Throwable $e) {
      $this->logTaxJarException(self::ORDER_REFUND_REQUEST_TYPE, $e, ['orderId' => $event->getOrderId()]);
      return;
    }
  }

  /**
   * Calculate tax for partial refund using TaxJar API
   * @param OrderEntity $order
   * @param mixed $orderReturn
   * @return array|null
   */
  private function calculatePartialRefundTax(OrderEntity $order, $orderReturn): ?array
  {
    try {
      $returnedLineItems = $orderReturn->getLineItems();
      if (!$returnedLineItems || $returnedLineItems->count() === 0) {
        return null;
      }

      $taxLineItems = [];
      $totalAmount = 0;
      $totalShipping = 0;

      foreach ($returnedLineItems as $returnedItem) {
        $originalLineItem = $order->getLineItems()->get($returnedItem->getOrderLineItemId());
        if (!$originalLineItem) {
          continue;
        }

        $product = $this->getProductOrFail($originalLineItem->getProductId(), $originalLineItem->getId());

        $productTaxCode = null;
        if ($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
          $productTaxCode = $product->getCustomFields()['product_tax_code_value'];
        }

        if ($product->getParentId()) {
          $parentProduct = $this->productRepository
            ->search(new Criteria([$product->getParentId()]), $this->context)
            ->get($product->getParentId());

          if ($parentProduct?->getCustomFields() && isset($parentProduct->getCustomFields()['product_tax_code_value'])) {
            $productTaxCode = $parentProduct->getCustomFields()['product_tax_code_value'];
          }
        }

        if (!$productTaxCode) {
          $productTaxCode = $this->getDefaultProductTaxCode();
        }

        $unitPrice = $returnedItem->getPrice()->getUnitPrice();
        $quantity = $returnedItem->getQuantity();
        $lineTotal = $unitPrice * $quantity;

        $taxLineItem = [
          'quantity' => $quantity,
          'product_identifier' => $product->getProductNumber(),
          'description' => $product->getTranslation('name'),
          'unit_price' => $unitPrice,
        ];

        if (!empty($productTaxCode) && strtolower((string) $productTaxCode) !== 'none') {
          $taxLineItem['product_tax_code'] = $productTaxCode;
        }

        $taxLineItems[] = $taxLineItem;
        $totalAmount += $lineTotal;
      }

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

      $originalTotal = 0;
      foreach ($order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
        $originalTotal += $lineItem->getUnitPrice() * $lineItem->getQuantity();
      }
      
      if ($originalTotal > 0) {
        $totalShipping = ($totalAmount / $originalTotal) * $order->getShippingTotal();
      }

      $taxRequest = [
        'to_country' => $countryIso,
        'to_zip' => $destinationAddress?->getZipcode(),
        'to_state' => $state,
        'to_city' => $destinationAddress?->getCity(),
        'to_street' => $destinationAddress?->getStreet(),
        'amount' => $totalAmount,
        'shipping' => $this->useIncludeShippingCostForTaxCalculation() ? $totalShipping : 0,
        'line_items' => $taxLineItems
      ];

      $shippingFromAddress = $this->getShippingOriginAddress();
      $taxRequest = array_merge($shippingFromAddress, $taxRequest);

      $taxResponse = $this->_getTaxRateWithHttpRequest($taxRequest);

      if (isset($taxResponse['error'])) {
        $this->logOrderTransactionDiagnostic('taxjar_operation_failed', [
          'operation' => 'Partial Refund Tax Calculation',
          'orderId' => $order->getId(),
          'orderNumber' => $order->getOrderNumber(),
          'success' => false,
          'responseBody' => substr((string) json_encode($taxResponse['error']), 0, 2000),
        ], 'error');

        return null;
      }

      return [
        'return_id' => $orderReturn->getId(),
        'tax_response' => $taxResponse,
        'line_items' => $taxLineItems,
        'total_amount' => $totalAmount,
        'total_shipping' => $totalShipping,
        'total_tax' => $taxResponse['amount_to_collect'] ?? 0
      ];

    } catch (\Throwable $e) {
      $this->logTaxJarException('Partial Refund Tax Calculation', $e, ['orderId' => $order->getId()]);
      return null;
    }
  }

  /**
   * Similar method like We have onCalculator(Checkout)
   * @param array $orderDetail
   * @return array|mixed
   */
  private function _getTaxRateWithHttpRequest(array $orderDetail = [])
  {
    $response = [];
    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $this->_taxJarApiToken(),
      "X-CSRF-Token" => $this->_taxJarApiToken()
    ];

    $request = new Request(
      'POST',
      $this->_getApiEndPoint() . '/taxes',
      $headers,
      json_encode($orderDetail)
    );

    try {
      $client = new Client(['connect_timeout' => self::API_CONNECT_TIMEOUT, 'timeout' => self::API_REQUEST_TIMEOUT]);
      $response = $client->send($request);
    } catch (\Throwable $e) {
      $this->logTaxJarException('Partial Refund Tax Calculation', $e, []);

      return ['error' => $e instanceof BadResponseException
        ? json_decode($e->getResponse()->getBody()->getContents(), true)
        : ['message' => $e->getMessage()]];
    }

    try {
      $response = $response->getBody()->getContents();
      $response = json_decode($response, true);
      
      if (isset($response['tax'])) {
        return $response['tax'];
      }
    } catch (\Throwable $e) {
      $this->logTaxJarException('Partial Refund Tax Calculation', $e, []);
      $response['error'] = $e->getMessage();
    }

    return $response;
  }

  /**
   * Create partial refund transaction in TaxJar
   * @param OrderEntity $order
   * @param array $refundData
   * @return void
   */
  private function createPartialRefundTransaction(OrderEntity $order, array $refundData): void
  {
    try {
      $existTransactionId = $this->getExistTransactionId($order->getId());
      $originalTransactionId = $existTransactionId ?: $this->getTransactionId($order);
      
      $refundTransactionId = $originalTransactionId . '_partial_refund_' . ($refundData['return_id'] ?? 'unknown');

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

      $refundLineItems = [];
      if (isset($refundData['tax_response']['breakdown']['line_items'])) {
        $taxBreakdownLineItems = $refundData['tax_response']['breakdown']['line_items'];
        
        foreach ($refundData['line_items'] as $index => $lineItem) {
          $taxBreakdown = $taxBreakdownLineItems[$index] ?? null;
          
          $refundLineItem = [
            'quantity' => $lineItem['quantity'],
            'product_identifier' => $lineItem['product_identifier'],
            'description' => $lineItem['description'],
            'unit_price' => -abs($lineItem['unit_price']),
            'sales_tax' => $taxBreakdown ? -abs($taxBreakdown['tax_collectable']) : 0
          ];

          if (isset($lineItem['product_tax_code'])) {
            $refundLineItem['product_tax_code'] = $lineItem['product_tax_code'];
          }

          $refundLineItems[] = $refundLineItem;
        }
      }

      $refundRequest = [
        'transaction_id' => $refundTransactionId,
        'transaction_date' => (new \DateTime())->format('Y/m/d'),
        'transaction_reference_id' => $originalTransactionId,
        'to_country' => $countryIso,
        'to_zip' => $destinationAddress?->getZipcode(),
        'to_state' => $state,
        'to_city' => $destinationAddress?->getCity(),
        'to_street' => $destinationAddress?->getStreet(),
        'amount' => -abs($refundData['total_amount']),
        'shipping' => -abs($refundData['total_shipping']),
        'sales_tax' => -abs($refundData['total_tax']),
        'line_items' => $refundLineItems
      ];

      $customerCustomFields = $order->getOrderCustomer()->getCustomFields() ?? [];
      $taxjarCustomerId = $customerCustomFields['taxjar_customer_id'] ?? null;
      if ($taxjarCustomerId) {
        $refundRequest['customer_id'] = $taxjarCustomerId;
      }

      $endpointUrl = $this->_getApiEndPoint() . '/transactions/refunds';
      
      $response = $this->callTaxJar(self::ORDER_REFUND_REQUEST_TYPE, 'POST', $endpointUrl, $refundRequest, ['orderId' => $order->getId(), 'orderNumber' => $order->getOrderNumber()]);

      $logInfo = $this->getLogInfo($order, $refundRequest, self::ORDER_REFUND_REQUEST_TYPE);
      $logInfo['response'] = $response['body'];
      $this->logRequestResponse($logInfo);

    } catch (\Throwable $e) {
      $this->logTaxJarException(self::ORDER_REFUND_REQUEST_TYPE, $e, ['orderId' => $order->getId()]);
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

      if($order->getDeliveries()?->first()?->getStateMachineState()?->getTechnicalName() != 'shipped') {
        $selectedFlow = $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $this->salesChannelId);

        if ($selectedFlow === 'ship') {
          if ($order->getDeliveries()?->first()->getStateMachineState()->getTechnicalName() !== 'shipped') {
            return;
          }
        }
      }


      $this->salesChannelId = $order->getSalesChannelId();

      $orderId = $this->getExistTransactionId($orderId, $order) ?: $this->getTransactionId($order);

      $orderDetail = $this->toRefundPayload($this->getOrderDetail($order));
      $orderDetail['transaction_reference_id'] = $this->getTransactionId($order);
      $orderDetail['transaction_id'] = $orderId . '_refund';

      $logInfo = $this->getLogInfo($order, $orderDetail, self::ORDER_REFUND_REQUEST_TYPE);

      $endpointUrl = $this->_getApiEndPoint() . '/transactions/refunds';

      $response = $this->callTaxJar(self::ORDER_REFUND_REQUEST_TYPE, 'POST', $endpointUrl, $orderDetail, ['orderId' => $event->getOrderId(), 'orderNumber' => $order->getOrderNumber()]);

      $logInfo['response'] = $response['body'];
      $this->logRequestResponse($logInfo);

    } catch (\Throwable $e) {
      $this->logTaxJarException(self::ORDER_REFUND_REQUEST_TYPE, $e, ['orderId' => $event->getOrderId()]);
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
    $diagnosticContext = [
      'shopwareEventName' => $event->getName(),
      'orderId' => $orderId,
      'salesChannelId' => $event->getSalesChannelId(),
      'apiCallReached' => false,
    ];

    $this->logOrderTransactionDiagnostic('create_order_transaction_entered', $diagnosticContext);

    try {
      $order = $this->getOrder($orderId);
      if (!$order) {
        $this->logOrderTransactionDiagnostic('create_order_transaction_skipped', array_merge($diagnosticContext, [
          'earlyReturnReason' => 'order_not_found',
        ]));
        return;
      }

      $diagnosticContext = array_merge($diagnosticContext, [
        'orderNumber' => $order->getOrderNumber(),
        'salesChannelId' => $order->getSalesChannelId(),
        'selectedCommitFlow' => $this->systemConfigService->get('solu1TaxJar.setting.selectedCommitFlows', $order->getSalesChannelId()),
      ]);

      if (!$this->hasTaxJarProvider($order)) {
        $this->logOrderTransactionDiagnostic('create_order_transaction_skipped', array_merge($diagnosticContext, [
          'earlyReturnReason' => 'taxjar_provider_not_marked',
        ]));
        return;
      }

      $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders';
      $requestType = self::ORDER_CREATE_REQUEST_TYPE;
      $this->salesChannelId = $order->getSalesChannelId();
      $orderDetail = $this->getOrderDetail($order);

      $duplicateRequest = $this->isDuplicateRequest(serialize($orderDetail));
      if ($duplicateRequest) {
        $this->logOrderTransactionDiagnostic('create_order_transaction_skipped', array_merge($diagnosticContext, [
          'earlyReturnReason' => 'duplicate_request',
          'duplicateRequest' => true,
        ]));
        return;
      }

      $logInfo = $this->getLogInfo($order, $orderDetail, $requestType);

      $diagnosticContext['duplicateRequest'] = false;
      $diagnosticContext['apiCallReached'] = true;
      $this->logOrderTransactionDiagnostic('taxjar_create_order_api_call_reached', $diagnosticContext);

      $response = $this->callTaxJar($requestType, 'POST', $apiEndpointUrl, $orderDetail, $diagnosticContext);
      $succeeded = ($response['success'] ?? false) === true;

      $this->logOrderTransactionDiagnostic('taxjar_create_order_api_response', array_merge($diagnosticContext, [
        'duplicateRequest' => false,
        'apiCallReached' => true,
        'success' => $succeeded,
      ]));

      if ($succeeded) {
        $this->persistTransactionId($order, $orderDetail['transaction_id']);
      } elseif ($this->providerRejectedRequest($response)) {
        $logInfo['requestKey'] = 'rejected-' . $response['status'] . ':' . $logInfo['requestKey'];
      }

      $logInfo['response'] = $response['body'];
      $this->logRequestResponse($logInfo);

    } catch (\Throwable $e) {
      $this->logTaxJarException($requestType ?? self::ORDER_CREATE_REQUEST_TYPE, $e, $diagnosticContext);
      return;
    }
  }

  private function callTaxJar(string $operation, string $method, string $endpointUrl, array $body, array $context = []): array
  {
    $response = $this->clientApiService->sendRequest($method, $endpointUrl, $this->getHeaders(), $body);

    if (($response['success'] ?? false) !== true) {
      $this->logOrderTransactionDiagnostic('taxjar_operation_failed', $context + [
        'operation' => $operation,
        'success' => false,
        'httpStatus' => $response['status'] ?? null,
        'exceptionMessage' => $response['error'] ?? null,
        'responseBody' => substr((string) ($response['body'] ?? ''), 0, 2000),
      ], 'error');
    }

    return $response;
  }

  private function logTaxJarException(string $operation, \Throwable $e, array $context = []): void
  {
    $this->logOrderTransactionDiagnostic('taxjar_operation_exception', $context + [
      'operation' => $operation,
      'success' => false,
      'earlyReturnReason' => 'exception_caught',
      'exceptionClass' => \get_class($e),
      'exceptionMessage' => $e->getMessage(),
      'exceptionOrigin' => $e->getFile() . ':' . $e->getLine(),
    ], 'error');
  }

  private function providerRejectedRequest(array $response): bool
  {
    $status = $response['status'] ?? null;

    return \is_int($status) && $status >= 400 && $status < 500;
  }

  private function persistTransactionId(OrderEntity $order, string $transactionId): void
  {
    $this->orderRepository->update([[
      'id' => $order->getId(),
      'customFields' => array_merge($order->getCustomFields() ?? [], [
        'taxJarTransactionId' => $transactionId,
      ]),
    ]], $this->context);
  }

  private function logOrderTransactionDiagnostic(string $eventName, array $context = [], string $level = 'info'): void
  {
    $allowedKeys = [
      'shopwareEventName',
      'orderId',
      'orderNumber',
      'salesChannelId',
      'selectedCommitFlow',
      'earlyReturnReason',
      'duplicateRequest',
      'apiCallReached',
      'success',
      'exceptionClass',
      'exceptionMessage',
      'exceptionOrigin',
      'httpStatus',
      'responseBody',
      'operation',
    ];

    $safeContext = ['diagnosticEventName' => $eventName];
    foreach ($allowedKeys as $key) {
      if (array_key_exists($key, $context)) {
        $safeContext[$key] = $context[$key];
      }
    }

    $this->logger->log($level, 'TaxJar order transaction diagnostic', $safeContext);
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
    } catch (\Throwable $e) {
      $this->logTaxJarException('Country Lookup', $e);
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
        } catch (\Throwable $e) {
            $this->logTaxJarException('Country State Lookup', $e);
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

  private function toRefundPayload(array $payload): array
  {
    foreach (['amount', 'shipping', 'sales_tax'] as $key) {
      if (isset($payload[$key])) {
        $payload[$key] = $this->negate($payload[$key]);
      }
    }

    foreach ($payload['line_items'] ?? [] as $index => $lineItem) {
      foreach (['unit_price', 'sales_tax'] as $key) {
        if (isset($lineItem[$key])) {
          $payload['line_items'][$index][$key] = $this->negate($lineItem[$key]);
        }
      }
    }

    return $payload;
  }

  private function negate(mixed $value): float
  {
    $value = (float) $value;

    return $value === 0.0 ? 0.0 : -abs($value);
  }

  private function getExistTransactionId(string $orderId, ?OrderEntity $order = null): ?string
  {
    $customFields = $order?->getCustomFields() ?? [];
    if (!empty($customFields['taxJarTransactionId'])) {
      return (string) $customFields['taxJarTransactionId'];
    }

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

  private function getProductOrFail(?string $productId, string $lineItemId): ProductEntity
  {
    $product = $productId ? $this->productRepository->search(new Criteria([$productId]), $this->context)->get($productId) : null;

    return $product instanceof ProductEntity ? $product : throw new \RuntimeException(sprintf('TaxJar payload cannot be built: product "%s" of order line item "%s" is not readable.', (string) $productId, $lineItemId));
  }

  private function getOrder($orderId): ?OrderEntity
  {
    $criteria = new Criteria([$orderId]);
    $criteria->addAssociation('orderCustomer.customer');
    $criteria->getAssociation('lineItems');
    $criteria->getAssociation('salesChannel');
    $criteria->getAssociation('billingAddress');
    $criteria->getAssociation('addresses');
    $criteria->getAssociation('deliveries');
    $criteria->getAssociation('deliveries.shippingOrderAddress');
    $criteria->addAssociation('deliveries.shippingOrderAddress.country');
    $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
    $criteria->addAssociation('deliveries.stateMachineState');
    $criteria->addAssociation('billingAddress.country');
    $criteria->addAssociation('billingAddress.countryState');
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
    $getTaxJarCustomerConfigs = $this->_taxjarCustomers();
    if($getTaxJarCustomerConfigs){
      $taxjarCustomerId = $order->getOrderCustomer()->getCustomerNumber();
    }
    else{
      $taxjarCustomerId = $customerCustomFields['taxjar_customer_id'] ?? null;
      $exType = $customerCustomFields['taxjar_exemption_type'] ?? null;
    }

    $customerGroupId = $order->getOrderCustomer()?->getCustomer()?->getGroupId();

    $groupsToBeExempted = $this->systemConfigService->get('solu1TaxJar.setting.exemptCustomerGroup', $this->salesChannelId) ?? [];

    $isExempt =
        (!empty($taxjarCustomerId) && !empty($exType))
        || in_array($customerGroupId, $groupsToBeExempted, true);

    $lineItems = $this->getLineItems($order, $isExempt);

    /** @todo Maybe should use just orderNumber $transactionId */
    $transactionId = $this->getTransactionId($order);
    $shippingFromAddress = $this->getShippingOriginAddress();
    $payload = array_merge(
      $shippingFromAddress,
      [
        'transaction_id' => $transactionId,
        'transaction_date' => (new \DateTime())->format('Y/m/d'),
        'customer_id' => $taxjarCustomerId,
        'to_country' => $countryIso,
        'to_zip' => $destinationAddress?->getZipcode(),
        'to_state' => $state,
        'to_city' => $destinationAddress?->getCity(),
        'to_street' => $destinationAddress?->getStreet(),
        'amount' => $orderTotalAmount,
        'shipping' => $order->getShippingTotal(),
        'sales_tax' => $isExempt ? '0.0' : ($orderTaxAmount + $shippingTaxAmount),
        'line_items' => $lineItems
      ]
    );

      if (!empty($taxjarCustomerId) && !empty($exType)) {
          $payload['exemption_type'] = $exType;
      } elseif (in_array($customerGroupId, $groupsToBeExempted, true)){
          $payload['exemption_type'] = 'other';
      }

      return $payload;
  }

  private function getLineItems(OrderEntity $order, bool $isExempt): array
  {
    $lineItems = [];
    foreach ($order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
      $parentProduct = null;
      /** @var ProductEntity $product */
      $product = $this->getProductOrFail($lineItem->getProductId(), $lineItem->getId());

      $productTaxCode = null;
      if($product->getCustomFields() && isset($product->getCustomFields()['product_tax_code_value'])) {
        $productTaxCode = $product->getCustomFields()['product_tax_code_value'];
      }

      if ($product->getParentId()) {
        $parentProduct = $this->productRepository
          ->search(new Criteria([$product->getParentId()]), $this->context)
          ->get($product->getParentId());

        if($parentProduct?->getCustomFields() && isset($parentProduct->getCustomFields()['product_tax_code_value'])) {
          $productTaxCode = $parentProduct->getCustomFields()['product_tax_code_value'];
        }
      }
      if (!$productTaxCode) {
        $productTaxCode = $this->getDefaultProductTaxCode();
      }

        $salesTax = $lineItem->getPrice()?->getCalculatedTaxes()->getAmount() ?? '0.0';

        if ($isExempt) {
            $salesTax = '0.0';
        }

        $lineItem = [
        'quantity' => $lineItem->getQuantity(),
        'product_identifier' => $parentProduct ? $parentProduct->getProductNumber() : $product->getProductNumber(),
        'description' => $parentProduct ? $parentProduct->getTranslation('name') : $product->getTranslation('name'),
        'unit_price' => $lineItem->getUnitPrice(),
        'sales_tax' => $salesTax
      ];

      if(!empty($productTaxCode) && strtolower((string) $productTaxCode) !== 'none') {
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
  private function _taxjarCustomers(): bool
  {
    return (bool)$this->systemConfigService->get('solu1TaxJar.setting.taxjarCustomers', $this->salesChannelId);
  }

  protected function hasTaxJarProvider(OrderEntity $order): bool
  {
    return true;
//    $customFields = $order->getCustomFields() ?? [];
//    return !empty($customFields['taxJarProvider']);
  }
}
