<?php declare(strict_types=1);

namespace solu1TaxJar\Core\Returning;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ShippingTaxRecovery
{
    private const TAX_TOLERANCE_PER_LINE = 0.01;

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function recover(OrderEntity $order): ?TaxRule
    {
        $lineItems = $order->getLineItems()?->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        if (!$lineItems || $lineItems->count() === 0) {
            return null;
        }

        $discounts = $this->discountsByProduct($order);

        if ($discounts === null) {
            return null;
        }

        $lineRates = [];
        $providerRates = [];
        $excess = 0.0;

        foreach ($lineItems as $lineItem) {
            $price = $lineItem->getPrice();

            if (!$price || $price->getTaxRules()->count() !== 1) {
                return null;
            }

            $lineRate = $price->getTaxRules()->first()->getTaxRate();
            $lineRates[(string) $lineRate] = $lineRate;

            $providerRate = ($lineItem->getPayload() ?? [])['taxJarRate'] ?? null;

            if (is_numeric($providerRate)) {
                $providerRates[(string) $providerRate] = (float) $providerRate * 100;
            }

            $taxable = $lineItem->getUnitPrice() * $lineItem->getQuantity()
                - ($discounts[$lineItem->getReferencedId()] ?? 0.0);

            $excess += $price->getCalculatedTaxes()->getAmount()
                - round(max($taxable, 0.0) * $lineRate / 100, 2);
        }

        $tolerance = self::TAX_TOLERANCE_PER_LINE * $lineItems->count();

        if (FloatComparator::lessThan($excess, -$tolerance)) {
            return null;
        }

        if (!FloatComparator::greaterThan($excess, $tolerance)) {
            return new TaxRule(0.0);
        }

        $shippingRate = match (true) {
            \count($providerRates) === 1 => reset($providerRates),
            \count($lineRates) === 1 => reset($lineRates),
            default => null,
        };

        return $shippingRate === null ? null : new TaxRule($shippingRate);
    }

    /**
     * @return array<string, float>|null
     */
    private function discountsByProduct(OrderEntity $order): ?array
    {
        if ($this->systemConfigService->get('solu1TaxJar.setting.giftcardsExemptTax', $order->getSalesChannelId())) {
            return null;
        }

        $discounts = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($lineItem->getType() !== LineItem::PROMOTION_LINE_ITEM_TYPE) {
                continue;
            }

            foreach (($lineItem->getPayload() ?? [])['composition'] ?? [] as $composition) {
                $referencedId = $composition['id'] ?? null;

                if ($referencedId !== null) {
                    $discounts[$referencedId] = ($discounts[$referencedId] ?? 0.0) + abs((float) ($composition['discount'] ?? 0));
                }
            }
        }

        return $discounts;
    }
}
