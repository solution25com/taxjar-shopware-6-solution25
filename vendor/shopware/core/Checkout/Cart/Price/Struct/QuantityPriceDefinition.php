<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Price\Struct;

use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Util\FloatComparator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

/**
 * A QuantityPriceDefinition is the most common PriceDefinition type and is used for all prices which increase or decrease
 * based on an item quantity. These Definitions are used for LineItems created from Products. They do not depend on
 * other PriceDefinitions in a calculation process.
 */
#[Package('checkout')]
class QuantityPriceDefinition extends Struct implements PriceDefinitionInterface
{
    final public const TYPE = 'quantity';
    final public const SORTING_PRIORITY = 100;

    protected bool $isCalculated = true;

    protected ?ReferencePriceDefinition $referencePriceDefinition = null;

    protected ?float $listPrice = null;

    protected ?float $regulationPrice = null;

    public function __construct(
        protected float $price,
        protected TaxRuleCollection $taxRules,
        protected int $quantity = 1
    ) {
        $this->price = FloatComparator::cast($price);
    }

    public function getPrice(): float
    {
        return FloatComparator::cast($this->price);
    }

    public function getTaxRules(): TaxRuleCollection
    {
        return $this->taxRules;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function isCalculated(): bool
    {
        return $this->isCalculated;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $taxRules = array_map(
            fn (array $tax) => new TaxRule(
                (float) $tax['taxRate'],
                (float) $tax['percentage']
            ),
            $data['taxRules']
        );

        $self = new self(
            (float) $data['price'],
            new TaxRuleCollection($taxRules),
            \array_key_exists('quantity', $data) ? $data['quantity'] : 1
        );

        $self->setIsCalculated(\array_key_exists('isCalculated', $data) ? $data['isCalculated'] : false);
        $self->setListPrice(isset($data['listPrice']) ? (float) $data['listPrice'] : null);
        $self->setRegulationPrice(isset($data['regulationPrice']) ? (float) $data['regulationPrice'] : null);

        return $self;
    }

    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        $data['type'] = $this->getType();

        return $data;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getPriority(): int
    {
        return self::SORTING_PRIORITY;
    }

    /**
     * @return array<string, list<Constraint>>
     */
    public static function getConstraints(): array
    {
        return [
            'price' => [new NotBlank(), new Type('numeric')],
            'quantity' => [new Type('int')],
            'isCalculated' => [new Type('bool')],
        ];
    }

    public function getReferencePriceDefinition(): ?ReferencePriceDefinition
    {
        return $this->referencePriceDefinition;
    }

    public function getListPrice(): ?float
    {
        return $this->listPrice ? FloatComparator::cast($this->listPrice) : null;
    }

    public function setListPrice(?float $listPrice): void
    {
        $listPrice = $listPrice ? FloatComparator::cast($listPrice) : null;
        $this->listPrice = $listPrice;
    }

    public function getRegulationPrice(): ?float
    {
        return $this->regulationPrice ? FloatComparator::cast($this->regulationPrice) : null;
    }

    public function setRegulationPrice(?float $regulationPrice): void
    {
        $regulationPrice = $regulationPrice ? FloatComparator::cast($regulationPrice) : null;
        $this->regulationPrice = $regulationPrice;
    }

    public function getApiAlias(): string
    {
        return 'cart_price_quantity';
    }

    public function setIsCalculated(bool $isCalculated): void
    {
        $this->isCalculated = $isCalculated;
    }

    public function setReferencePriceDefinition(?ReferencePriceDefinition $referencePriceDefinition): void
    {
        $this->referencePriceDefinition = $referencePriceDefinition;
    }
}
