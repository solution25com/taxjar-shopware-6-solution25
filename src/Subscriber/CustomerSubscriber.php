<?php declare(strict_types=1);

namespace solu1TaxJar\Subscriber;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

class CustomerSubscriber implements EventSubscriberInterface
{
    public const LIVE_API_URL = 'https://api.taxjar.com/v2';
    public const SANDBOX_API_URL = 'https://api.sandbox.taxjar.com/v2';

    private EntityRepository $customerRepository;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private SystemConfigService $systemConfigService;
    private ?string $salesChannelId;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $customerRepository,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->systemConfigService = $systemConfigService;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->salesChannelId = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'onCustomerWritten',
        ];
    }

    public function onCustomerWritten(EntityWrittenEvent $event): void
    {
        if (!$this->_isActive()) {
            return;
        }

        $context = $event->getContext();
        if (!$context->getSource() instanceof AdminApiSource) {
            return;
        }

        $this->logger->info('TaxJar Subscriber: Processing CustomerWrittenEvent', [
            'customerIds' => $event->getIds(),
        ]);

        $customerIds = $event->getIds();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $customerIds));

        try {
            /** @var CustomerEntity[] $customers */
            $customers = $this->customerRepository->search($criteria, $context)->getEntities();

            if (empty($customers)) {
                $this->logger->warning('TaxJar Subscriber: No customers found for IDs', [
                    'customerIds' => $customerIds,
                ]);
                return;
            }

            foreach ($customers as $customer) {
                $this->salesChannelId = $customer->getSalesChannelId();
                $customFields = $customer->getCustomFields() ?? [];
                $exemptionType = $customFields['taxjar_exemption_type'] ?? null;
                $taxjarCustomerId = $customFields['taxjar_customer_id'] ?? null;

                if ($exemptionType === null) {
                    $this->logger->info('TaxJar Subscriber: Skipping customer {customerId} - no exemption type', [
                        'customerId' => $taxjarCustomerId ?? $customer->getCustomerNumber(),
                    ]);
                    continue;
                }

                $exemptRegions = [];
                $selectedStates = $customFields['exempt_regions'] ?? [];
                if (!empty($selectedStates)) {
                    foreach ((array) $selectedStates as $state) {
                        if (!empty($state)) {
                            $exemptRegions[] = [
                                'country' => 'US',
                                'state' => strtoupper($state),
                            ];
                        }
                    }
                }

                $customerId = $taxjarCustomerId ?: Uuid::randomHex(); // for testing
                $isNewCustomer = empty($taxjarCustomerId);

                $payload = [
                    'customer_id' => $customerId,
                    'exemption_type' => $exemptionType,
                    'exempt_regions' => $exemptRegions,
                    'name' => trim($customer->getFirstName() . ' ' . $customer->getLastName()),
                ];


                $baseUrl = $this->_getApiEndPoint() . '/customers/';
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->_taxJarApiToken(),
                    'X-CSRF-Token' => $this->_taxJarApiToken(),
                ];

                try {
                    $method = $isNewCustomer ? 'POST' : 'PUT';
                    $url = $isNewCustomer ? $baseUrl : $baseUrl . $customerId;
                    $this->logger->info('TaxJar Customer Subscriber', [$method, $url, $payload]);

                    $response = $this->httpClient->request($method, $url, [
                        'headers' => $headers,
                        'json' => $payload,
                    ]);

                    $this->logger->info('TaxJar Customer Response', $response->toArray());

                    if ($isNewCustomer) {
                        // Save the customer ID to the taxjar_customer_id custom field
                        $this->customerRepository->update([
                            [
                                'id' => $customer->getId(),
                                'customFields' => array_merge($customFields, ['taxjar_customer_id' => $customerId]),
                            ],
                        ], $context);

                        $this->logger->info('TaxJar Subscriber: Created customer {customerId} in TaxJar and saved to custom field', [
                            'customerId' => $customerId,
                        ]);
                    } else {
                        $this->logger->info('TaxJar Subscriber: Updated customer {customerId} in TaxJar', [
                            'customerId' => $customerId,
                        ]);
                    }
                } catch (Throwable $e) {
                        $this->logger->error('TaxJar Subscriber: Failed to sync customer {customerId} to TaxJar: {message}', [
                            'customerId' => $customerId,
                            'message' => $e->getMessage(),
                            'code' => $e->getCode(),
                        ]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('TaxJar Subscriber: Failed to search customers: {message}', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'customerIds' => $customerIds,
            ]);
        }
    }

    private function _taxJarApiToken(): ?string
    {
        if ($this->_isSandboxMode()) {
            return $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken', $this->salesChannelId);
        }
        return $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken', $this->salesChannelId);
    }

    private function _getApiEndPoint(): string
    {
        if ($this->_isSandboxMode()) {
            return self::SANDBOX_API_URL;
        }
        return self::LIVE_API_URL;
    }

    private function _isSandboxMode(): bool
    {
        return $this->systemConfigService->getBool('solu1TaxJar.setting.sandboxMode', $this->salesChannelId);
    }

    /**
     * @return int
     */
    private function _isActive(): bool
    {
        return $this->systemConfigService->getBool('solu1TaxJar.setting.active', $this->salesChannelId);
    }
}