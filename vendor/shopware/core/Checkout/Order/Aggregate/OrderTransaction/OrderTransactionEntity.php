<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

#[Package('checkout')]
class OrderTransactionEntity extends Entity
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected string $orderId;

    protected string $orderVersionId;

    protected string $paymentMethodId;

    protected CalculatedPrice $amount;

    protected ?PaymentMethodEntity $paymentMethod = null;

    protected ?OrderEntity $order = null;

    protected ?StateMachineStateEntity $stateMachineState = null;

    protected string $stateId;

    protected ?OrderTransactionCaptureCollection $captures = null;

    protected ?OrderEntity $primaryOrder = null;

    /**
     * @var array<string, mixed>
     */
    protected array $validationData = [];

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }

    public function setPaymentMethodId(string $paymentMethodId): void
    {
        $this->paymentMethodId = $paymentMethodId;
    }

    public function getAmount(): CalculatedPrice
    {
        return $this->amount;
    }

    public function setAmount(CalculatedPrice $amount): void
    {
        $this->amount = $amount;
    }

    public function getPaymentMethod(): ?PaymentMethodEntity
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(PaymentMethodEntity $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getStateMachineState(): ?StateMachineStateEntity
    {
        return $this->stateMachineState;
    }

    public function setStateMachineState(StateMachineStateEntity $stateMachineState): void
    {
        $this->stateMachineState = $stateMachineState;
    }

    public function getStateId(): string
    {
        return $this->stateId;
    }

    public function setStateId(string $stateId): void
    {
        $this->stateId = $stateId;
    }

    public function getCaptures(): ?OrderTransactionCaptureCollection
    {
        return $this->captures;
    }

    public function setCaptures(OrderTransactionCaptureCollection $captures): void
    {
        $this->captures = $captures;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValidationData(): array
    {
        return $this->validationData;
    }

    /**
     * @param array<string, mixed> $validationData
     */
    public function setValidationData(array $validationData): void
    {
        $this->validationData = $validationData;
    }

    public function getPrimaryOrder(): ?OrderEntity
    {
        return $this->primaryOrder;
    }

    public function setPrimaryOrder(?OrderEntity $primaryOrder): void
    {
        $this->primaryOrder = $primaryOrder;
    }
}
