<?php

declare(strict_types=1);

namespace solu1TaxJar\Subscriber;

use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use solu1TaxJar\Core\TaxJar\TaxJarCalculation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderTaxJarCalculationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $cart = $event->getCart();
        $extension = $cart->getExtension(TaxJarCalculation::EXTENSION_NAME);

        if (!$extension instanceof ArrayStruct) {
            if ($event->getConversionContext()->shouldIncludeTransactions()) {
                return;
            }

            $extension = TaxJarCalculation::notApplicable();
        }

        $convertedCart = $event->getConvertedCart();
        $customFields = \is_array($convertedCart['customFields'] ?? null) ? $convertedCart['customFields'] : [];
        $customFields[TaxJarCalculation::ORDER_CUSTOM_FIELD] = $extension->all();
        $convertedCart['customFields'] = $customFields;

        $event->setConvertedCart($convertedCart);
    }
}
