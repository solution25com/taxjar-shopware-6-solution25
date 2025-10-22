<?php

namespace solu1TaxJar\Core\Tax;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface TaxCalculatorInterface
{
    public function supports(string $baseClass): bool;

    /**
     * @param array<int, mixed> $lineItems
     * @return array<int, mixed>
     */
    public function calculate(array $lineItems, SalesChannelContext $context, Cart $original): array;
}