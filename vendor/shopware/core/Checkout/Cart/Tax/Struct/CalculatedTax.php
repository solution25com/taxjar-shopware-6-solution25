<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Tax\Struct;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Util\FloatComparator;

#[Package('checkout')]
class CalculatedTax extends Struct
{
    protected float $tax = 0;

    protected float $taxRate;

    protected float $price = 0;

    public function __construct(
        float $tax,
        float $taxRate,
        float $price,
        protected ?string $label = null,
    ) {
        $this->tax = FloatComparator::cast($tax);
        $this->taxRate = FloatComparator::cast($taxRate);
        $this->price = FloatComparator::cast($price);
    }

    public function getTax(): float
    {
        return $this->tax;
    }

    public function setTax(float $tax): void
    {
        $this->tax = FloatComparator::cast($tax);
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function increment(self $calculatedTax): void
    {
        $this->tax = FloatComparator::cast($this->tax + $calculatedTax->getTax());
        $this->price = FloatComparator::cast($this->price + $calculatedTax->getPrice());
        $this->label = implode(' + ', array_filter([$this->getLabel(), $calculatedTax->getLabel()])) ?: null;
    }

    public function setPrice(float $price): void
    {
        $this->price = FloatComparator::cast($price);
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getApiAlias(): string
    {
        return 'cart_tax_calculated';
    }
}
