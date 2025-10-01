<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Service;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\App\AppLocaleProvider;
use Shopware\Core\Framework\App\Event\AppChangedEvent;
use Shopware\Core\Framework\App\Event\AppDeletedEvent;
use Shopware\Core\Framework\App\Event\AppFlowActionEvent;
use Shopware\Core\Framework\App\Event\AppPermissionsUpdated;
use Shopware\Core\Framework\App\Exception\AppUrlChangeDetectedException;
use Shopware\Core\Framework\App\Hmac\Guzzle\AuthMiddleware;
use Shopware\Core\Framework\App\Hmac\RequestSigner;
use Shopware\Core\Framework\App\Payload\AppPayloadServiceHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;
use Shopware\Core\Framework\Webhook\Event\PreWebhooksDispatchEvent;
use Shopware\Core\Framework\Webhook\EventLog\WebhookEventLogDefinition;
use Shopware\Core\Framework\Webhook\Hookable;
use Shopware\Core\Framework\Webhook\Hookable\HookableEntityWrittenEvent;
use Shopware\Core\Framework\Webhook\Hookable\HookableEventFactory;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Webhook;
use Shopware\Core\Profiling\Profiler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
#[Package('framework')]
class WebhookManager implements ResetInterface
{
    /**
     * @var array<string, list<Webhook>>
     */
    private ?array $webhooks = null;

    /**
     * @var array<string, mixed>
     */
    private array $privileges = [];

    public function __construct(
        private readonly WebhookLoader $webhookLoader,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Connection $connection,
        private readonly HookableEventFactory $eventFactory,
        private readonly AppLocaleProvider $appLocaleProvider,
        private readonly AppPayloadServiceHelper $appPayloadServiceHelper,
        private readonly Client $guzzle,
        private readonly MessageBusInterface $bus,
        private readonly string $shopUrl,
        private readonly string $shopwareVersion,
        private readonly bool $isAdminWorkerEnabled,
    ) {
    }

    public function dispatch(object $event): void
    {
        $context = Context::createDefaultContext();

        foreach ($this->eventFactory->createHookablesFor($event) as $hookable) {
            $useEventContext = $event instanceof FlowEventAware || $event instanceof AppChangedEvent || $event instanceof EntityWrittenContainerEvent;

            $this->callWebhooks($hookable, $useEventContext ? $event->getContext() : $context);
        }
    }

    public function reset(): void
    {
        $this->webhooks = null;
        $this->privileges = [];
    }

    public function clearInternalWebhookCache(): void
    {
        $this->webhooks = null;
    }

    public function clearInternalPrivilegesCache(): void
    {
        $this->privileges = [];
    }

    private function callWebhooks(Hookable $event, Context $context): void
    {
        $webhooksForEvent = $this->filterWebhooksByLiveVersion($this->getWebhooks($event->getName()), $event);

        if (\count($webhooksForEvent) === 0) {
            return;
        }

        $this->eventDispatcher->dispatch($e = new PreWebhooksDispatchEvent($webhooksForEvent));
        $webhooksForEvent = $e->webhooks;

        $languageId = $context->getLanguageId();
        $userLocale = $this->appLocaleProvider->getLocaleFromContext($context);

        $affectedRoleIds = array_values(array_filter(array_map(fn (Webhook $webhook) => $webhook->appAclRoleId, $webhooksForEvent)));
        $this->loadPrivileges($event->getName(), $affectedRoleIds);

        // If the admin worker is enabled we send all events synchronously, as we can't guarantee timely delivery otherwise.
        // Additionally, all app lifecycle events are sent synchronously as those can lead to nasty race conditions otherwise.
        if ($this->isAdminWorkerEnabled || $event instanceof AppDeletedEvent || $event instanceof AppChangedEvent || $event instanceof AppPermissionsUpdated) {
            Profiler::trace(
                'webhook::dispatch-sync',
                fn () => $this->callWebhooksSynchronous($webhooksForEvent, $event, $languageId, $userLocale)
            );

            return;
        }

        Profiler::trace(
            'webhook::dispatch-async',
            fn () => $this->dispatchWebhooksToQueue($webhooksForEvent, $event, $languageId, $userLocale)
        );
    }

    /**
     * @param array<Webhook> $webhooksForEvent
     */
    private function dispatchWebhooksToQueue(
        array $webhooksForEvent,
        Hookable $event,
        string $languageId,
        string $userLocale
    ): void {
        foreach ($webhooksForEvent as $webhook) {
            if (!$this->isEventDispatchingAllowed($webhook, $event)) {
                continue;
            }

            try {
                $webhookData = $this->getPayloadForWebhook($webhook, $event);
            } catch (AppUrlChangeDetectedException) {
                // don't dispatch webhooks for apps if url changed
                continue;
            }

            $webhookEventMessage = new WebhookEventMessage(
                $webhookData['source']['eventId'],
                $webhookData,
                $webhook->appId,
                $webhook->id,
                $this->shopwareVersion,
                $webhook->url,
                $webhook->appSecret,
                $languageId,
                $userLocale
            );

            $this->logWebhookWithEvent($webhook, $webhookEventMessage);

            $this->bus->dispatch($webhookEventMessage);
        }
    }

    private function logWebhookWithEvent(Webhook $webhook, WebhookEventMessage $webhookEventMessage): void
    {
        $this->connection->insert(
            'webhook_event_log',
            [
                'id' => Uuid::fromHexToBytes($webhookEventMessage->getWebhookEventId()),
                'app_name' => $webhook->appName,
                'delivery_status' => WebhookEventLogDefinition::STATUS_QUEUED,
                'webhook_name' => $webhook->webhookName,
                'event_name' => $webhook->eventName,
                'app_version' => $webhook->appVersion,
                'url' => $webhook->url,
                'only_live_version' => (int) $webhook->onlyLiveVersion,
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'serialized_webhook_message' => serialize($webhookEventMessage),
            ]
        );
    }

    /**
     * @param array<Webhook> $webhooksForEvent
     */
    private function callWebhooksSynchronous(
        array $webhooksForEvent,
        Hookable $event,
        string $languageId,
        string $userLocale
    ): void {
        $requests = [];
        foreach ($webhooksForEvent as $webhook) {
            if (!$this->isEventDispatchingAllowed($webhook, $event)) {
                continue;
            }

            try {
                $webhookData = $this->getPayloadForWebhook($webhook, $event);
            } catch (AppUrlChangeDetectedException) {
                // don't dispatch webhooks for apps if url changed
                continue;
            }

            $timestamp = time();
            $webhookData['timestamp'] = $timestamp;

            $jsonPayload = json_encode($webhookData, \JSON_THROW_ON_ERROR);

            $headers = [
                'Content-Type' => 'application/json',
                'sw-version' => $this->shopwareVersion,
                AuthMiddleware::SHOPWARE_CONTEXT_LANGUAGE => $languageId,
                AuthMiddleware::SHOPWARE_USER_LANGUAGE => $userLocale,
            ];

            if ($event instanceof AppFlowActionEvent) {
                $headers = array_merge($headers, $event->getWebhookHeaders());
            }

            $request = new Request(
                'POST',
                $webhook->url,
                $headers,
                $jsonPayload
            );

            if ($webhook->appId !== null && $webhook->appSecret !== null) {
                $request = $request->withHeader(
                    RequestSigner::SHOPWARE_SHOP_SIGNATURE,
                    (new RequestSigner())->signPayload($jsonPayload, $webhook->appSecret)
                );
            }

            $requests[] = $request;
        }

        if (\count($requests) > 0) {
            $pool = new Pool($this->guzzle, $requests);
            $pool->promise()->wait();
        }
    }

    /**
     * @return array{
     *     data: array{payload: array<string, mixed>, event: string},
     *     source: array{url: string, eventId: string, action?: string}
     * }|array<string, mixed>
     */
    private function getPayloadForWebhook(Webhook $webhook, Hookable $event): array
    {
        $source = [
            'url' => $this->shopUrl,
            'eventId' => Uuid::randomHex(),
        ];

        if ($webhook->appId !== null && $webhook->appVersion !== null) {
            $source = \array_merge(
                $source,
                $this->appPayloadServiceHelper->buildSource($webhook->appVersion, $webhook->appName ?? '')->jsonSerialize()
            );
        }

        if ($event instanceof AppFlowActionEvent) {
            $source['action'] = $event->getName();
            $payload = $event->getWebhookPayload();
            $payload['source'] = $source;

            return $payload;
        }

        $data = [
            'payload' => $this->filterPayloadByLiveVersion($event->getWebhookPayload(), $webhook, $event),
            'event' => $event->getName(),
        ];

        return [
            'data' => $data,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function filterPayloadByLiveVersion(array $payload, Webhook $webhook, Hookable $event): array
    {
        if (!$event instanceof HookableEntityWrittenEvent || $webhook->onlyLiveVersion === false) {
            return $payload;
        }

        return array_filter($payload, function ($writeResult) {
            return isset($writeResult['versionId']) && $writeResult['versionId'] === Defaults::LIVE_VERSION;
        });
    }

    private function isEventDispatchingAllowed(Webhook $webhook, Hookable $event): bool
    {
        if ($webhook->appId === null) {
            return true;
        }

        // Only app lifecycle hooks can be received if app is deactivated
        if ($webhook->appActive === false && !($event instanceof AppChangedEvent || $event instanceof AppDeletedEvent || $event instanceof AppPermissionsUpdated)) {
            return false;
        }

        $privileges = $this->privileges[$event->getName()][$webhook->appAclRoleId] ?? new AclPrivilegeCollection([]);

        return $event->isAllowed($webhook->appId, $privileges);
    }

    /**
     * @param list<string> $affectedRoleIds
     */
    private function loadPrivileges(string $eventName, array $affectedRoleIds): void
    {
        if (\array_key_exists($eventName, $this->privileges)) {
            return;
        }

        $this->privileges[$eventName] = $this->webhookLoader->getPrivilegesForRoles($affectedRoleIds);
    }

    /**
     * @return list<Webhook>
     */
    private function getWebhooks(string $eventName): array
    {
        $this->loadWebhooks();

        return $this->webhooks[$eventName] ?? [];
    }

    private function loadWebhooks(): void
    {
        if ($this->webhooks !== null) {
            return;
        }

        $webhooks = $this->webhookLoader->getWebhooks();
        foreach ($webhooks as $webhook) {
            $this->webhooks[$webhook->eventName][] = $webhook;
        }
    }

    /**
     * @param list<Webhook> $webhooks
     *
     * @return list<Webhook>
     */
    private function filterWebhooksByLiveVersion(array $webhooks, Hookable $event): array
    {
        if (!$event instanceof HookableEntityWrittenEvent) {
            return $webhooks;
        }

        return array_values(array_filter($webhooks, static function (Webhook $webhook) use ($event): bool {
            if (!$webhook->onlyLiveVersion) {
                return true;
            }

            $isVersioned = false;

            foreach ($event->getWebhookPayload() as $writeResult) {
                if (isset($writeResult['versionId']) && $writeResult['versionId'] === Defaults::LIVE_VERSION) {
                    return true;
                }

                if (isset($writeResult['versionId'])) {
                    $isVersioned = true;
                }
            }

            // If the event is not versioned we should send the webhook,
            // only if it is versioned all results are not in the live version we skip it
            return !$isVersioned;
        }));
    }
}
