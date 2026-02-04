<?php

declare(strict_types=1);

namespace solu1TaxJar\Subscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderAddressMismatchSubscriber implements EventSubscriberInterface
{
    public const ORDER_CUSTOM_FIELD = 'taxjar_address_mismatch';

    private EntityRepository $orderRepository;
    private Connection $connection;

    public function __construct(EntityRepository $orderRepository, Connection $connection)
    {
        $this->orderRepository = $orderRepository;
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        if ($event->getIds() === []) {
            return;
        }

        $context = $event->getContext();
        if (!$context instanceof Context) {
            return;
        }

        $writeResults = $event->getWriteResults();
        foreach ($writeResults as $writeResult) {
            $orderId = $this->resolveOrderId($writeResult);
            if ($orderId === null) {
                continue;
            }

            if ($this->writeAlreadySetMismatchFlag($writeResult)) {
                continue;
            }

            if (!$this->orderHasMismatchMarker($orderId)) {
                continue;
            }

            try {
                $payload = [
                    'id' => $orderId,
                    'customFields' => [
                        self::ORDER_CUSTOM_FIELD => true,
                    ],
                ];
                $versionId = $writeResult->getPayload()['versionId'] ?? null;
                if ($versionId !== null) {
                    $payload['versionId'] = $versionId;
                }
                $this->orderRepository->upsert([$payload], $context);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }
    private function writeAlreadySetMismatchFlag(EntityWriteResult $writeResult): bool
    {
        $payload = $writeResult->getPayload();
        if (!\is_array($payload)) {
            return false;
        }
        $customFields = $payload['customFields'] ?? [];
        if (!\is_array($customFields)) {
            return false;
        }
        $value = $customFields[self::ORDER_CUSTOM_FIELD] ?? null;

        return $value === true || $value === 1 || $value === 'true' || $value === '1';
    }

    private function resolveOrderId(EntityWriteResult $writeResult): ?string
    {
        $getPrimaryKey = $writeResult->getPrimaryKey();
        if (\is_string($getPrimaryKey)) {
            return $getPrimaryKey;
        }
        if (\is_array($getPrimaryKey) && isset($getPrimaryKey['id'])) {
            return $getPrimaryKey['id'];
        }

        return null;
    }
    private function orderHasMismatchMarker(string $orderId): bool
    {
        $orderIdBytes = null;
        try {
            $orderIdBytes = Uuid::fromHexToBytes($orderId);
        } catch (\Throwable $e) {
            $orderIdBytes = $orderId;
        }

        $sql = <<<'SQL'
            SELECT 1
            FROM order_line_item
            WHERE order_id = :orderId
              AND (
                    JSON_EXTRACT(payload, '$.taxjar_address_mismatch') = true
                 OR JSON_EXTRACT(payload, '$.taxjar_address_mismatch') = 1
                 OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.taxjar_address_mismatch'))) = 'true'
                 OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.taxjar_address_mismatch'))) = '1'
              )
            LIMIT 1
        SQL;

        try {
            $found = $this->connection->fetchOne($sql, ['orderId' => $orderIdBytes], ['orderId' => ParameterType::BINARY]);
            return $found !== false && $found !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
