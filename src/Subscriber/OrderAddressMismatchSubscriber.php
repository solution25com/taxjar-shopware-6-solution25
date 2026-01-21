<?php

declare(strict_types=1);

namespace solu1TaxJar\Subscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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

        foreach ($event->getIds() as $orderId) {
            if (!$this->orderHasMismatchMarker($orderId)) {
                continue;
            }

            try {
                $this->orderRepository->upsert([
                    [
                        'id' => $orderId,
                        'customFields' => [
                            self::ORDER_CUSTOM_FIELD => true,
                        ],
                    ],
                ], $context);
            } catch (\Throwable $e) {
                continue;
            }
        }
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
