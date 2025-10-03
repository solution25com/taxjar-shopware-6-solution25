<?php declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Order;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GRequest;

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
      'state_enter.order_delivery.state.shipped' => 'onOrderShipped',
      StateMachineTransitionEvent::class => 'onStateMachineTransition',
      OrderEvents::ORDER_DELETED_EVENT => 'onOrderDeleted',
      'state_enter.order_transaction.state.refunded' => 'onOrderStateChange',
      'state_enter.order_transaction.state.cancelled' => 'onOrderStateCancel'
    ];
  }

  /**
   * @param OrderStateMachineStateChangeEvent $event
   * @return void
   */
  public function onOrderShipped(OrderStateMachineStateChangeEvent $event): void
  {
    $this->context = $event->getContext();
    if (!$this->dispatched) {
      $this->createOrderTransaction($event->getOrderId(), $event);
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

      $apiEndpointUrl = $this->_getApiEndPoint() . '/transactions/orders';
      $requestType = self::ORDER_CREATE_REQUEST_TYPE;
      $this->salesChannelId = $order->getSalesChannelId();
      $orderDetail = $this->getOrderDetail($order);

      if ($this->isDuplicateRequest(serialize($orderDetail))) {
        return;
      }

      $logInfo = $this->getLogInfo($order, $orderDetail, $requestType);

      $response = $this->clientApiService->sendOrderTransaction(
        $apiEndpointUrl,
        $orderDetail,
        $this->getHeaders()
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

  private function getOrder($orderId): ?Entity
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

    $shippingAddress = $order->getBillingAddress();
    $billingAddress = $order->getBillingAddress();

    $country = $billingAddress?->getCountry()?->getIso();
    $shortCode = $billingAddress?->getCountryState()?->getShortCode();
    $state = explode('-', $shortCode)[1];

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
        'to_country' => $country,
        'to_zip' => $shippingAddress ? $shippingAddress->getZipcode() : $billingAddress?->getZipcode(),
        'to_state' => $state,
        'to_city' => $shippingAddress->getCity(),
        'to_street' => $shippingAddress->getStreet(),
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
        $productIds = [];
        $lineItemsRaw = $order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE) ?? [];

        foreach ($lineItemsRaw as $item) {
            if ($item->getProductId()) {
                $productIds[] = $item->getProductId();
            }
        }

        $criteria = new Criteria(array_unique($productIds));
        $criteria->addAssociation('parent');
        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $this->context)->getEntities();

        foreach ($lineItemsRaw as $item) {
            $productId = $item->getProductId();
            if (!$productId || !$products->has($productId)) {
                continue;
            }

            /** @var ProductEntity $product */
            $product = $products->get($productId);
            $parentProduct = $product->getParent();

            $productTaxCode = $product->getCustomFields()['product_tax_code_value'] ?? null;

            if (!$productTaxCode && $parentProduct) {
                $productTaxCode = $parentProduct->getCustomFields()['product_tax_code_value'] ?? null;
            }

            if (!$productTaxCode) {
                $productTaxCode = $this->getDefaultProductTaxCode();
            }

            $lineItemData = [
                'quantity' => $item->getQuantity(),
                'product_identifier' => $parentProduct ? $parentProduct->getProductNumber() : $product->getProductNumber(),
                'description' => $parentProduct ? $parentProduct->getTranslation('name') : $product->getTranslation('name'),
                'unit_price' => $item->getUnitPrice(),
                'sales_tax' => $item->getPrice()?->getCalculatedTaxes()->getAmount()
            ];

            if ($productTaxCode && strtolower($productTaxCode) !== 'none') {
                $lineItemData['product_tax_code'] = $productTaxCode;
            }

            $lineItems[] = $lineItemData;
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
}