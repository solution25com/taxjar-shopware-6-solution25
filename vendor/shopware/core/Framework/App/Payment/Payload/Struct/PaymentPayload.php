<?php declare(strict_types=1);

namespace Shopware\Core\Framework\App\Payment\Payload\Struct;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Recurring\RecurringDataStruct;
use Shopware\Core\Framework\App\Payload\Source;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\CloneTrait;
use Shopware\Core\Framework\Struct\JsonSerializableTrait;
use Shopware\Core\Framework\Struct\Struct;

#[Package('checkout')]
class PaymentPayload implements PaymentPayloadInterface
{
    use CloneTrait;
    use JsonSerializableTrait;
    use RemoveAppTrait;

    protected Source $source;

    protected OrderTransactionEntity $orderTransaction;

    /**
     * @param mixed[] $requestData
     */
    public function __construct(
        OrderTransactionEntity $orderTransaction,
        protected OrderEntity $order,
        protected array $requestData = [],
        protected ?string $returnUrl = null,
        protected ?Struct $validateStruct = null,
        protected ?RecurringDataStruct $recurring = null,
    ) {
        $this->orderTransaction = $this->removeApp($orderTransaction);
    }

    public function getOrderTransaction(): OrderTransactionEntity
    {
        return $this->orderTransaction;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    /**
     * @return mixed[]
     */
    public function getRequestData(): array
    {
        return $this->requestData;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function getValidateStruct(): ?Struct
    {
        return $this->validateStruct;
    }

    public function getRecurring(): ?RecurringDataStruct
    {
        return $this->recurring;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function setSource(Source $source): void
    {
        $this->source = $source;
    }
}
