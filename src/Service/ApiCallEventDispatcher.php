<?php

declare(strict_types=1);

namespace solu1TaxJar\Service;

use Psr\Log\LoggerInterface;
use solu1TaxJar\Core\TaxJar\Event\TaxJarApiCallEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches TaxJarApiCallEvent without letting a listener affect the TaxJar flow that triggered it.
 * A listener exception would otherwise make the cart fall back to native tax, or abort an order commit
 * after TaxJar accepted it and before the transaction id is stored.
 */
class ApiCallEventDispatcher
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(TaxJarApiCallEvent $event): void
    {
        try {
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('TaxJar API call event listener failed', [
                'operation' => $event->getOperation(),
                'orderId' => $event->getOrderId(),
                'returnId' => $event->getReturnId(),
                'exceptionClass' => \get_class($e),
                'exceptionMessage' => $e->getMessage(),
            ]);
        }
    }
}
