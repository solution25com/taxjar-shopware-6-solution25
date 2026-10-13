<?php

declare(strict_types=1);

namespace solu1TaxJar\Subscriber;

use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use solu1TaxJar\Core\TaxJar\TaxJarCalculation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderTaxJarCalculationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $isRecalculation = !$event->getConversionContext()->shouldIncludeTransactions();

        if (TaxJarCalculation::isStoredInOrder($this->systemConfigService, $event->getSalesChannelContext()->getSalesChannelId())) {
            $extension = $event->getCart()->getExtension(TaxJarCalculation::EXTENSION_NAME);

            if (!$extension instanceof ArrayStruct) {
                if (!$isRecalculation) {
                    return;
                }

                $extension = TaxJarCalculation::notApplicable();
            }

            $calculation = $extension->all();
        } elseif ($isRecalculation) {
            $calculation = null;
        } else {
            return;
        }

        $convertedCart = $event->getConvertedCart();
        $customFields = \is_array($convertedCart['customFields'] ?? null) ? $convertedCart['customFields'] : [];
        $customFields[TaxJarCalculation::ORDER_CUSTOM_FIELD] = $calculation;
        $convertedCart['customFields'] = $customFields;

        $event->setConvertedCart($convertedCart);
    }
}
