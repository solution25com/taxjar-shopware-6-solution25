<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Framework\Log\Package;

#[Package('inventory')]
class PurchaseStepsError extends Error
{
    public function __construct(
        protected readonly string $id,
        protected readonly string $name,
        protected readonly int $quantity
    ) {
        $this->message = \sprintf(
            'Your input quantity does not match with the setup of the %s. The quantity was changed to %d',
            $name,
            $quantity
        );

        parent::__construct($this->message);
    }

    public function getParameters(): array
    {
        return ['name' => $this->name, 'quantity' => $this->quantity];
    }

    public function getId(): string
    {
        return $this->getMessageKey() . $this->id;
    }

    public function getMessageKey(): string
    {
        return 'purchase-steps-quantity';
    }

    public function getLevel(): int
    {
        return self::LEVEL_WARNING;
    }

    public function blockOrder(): bool
    {
        return true;
    }
}
