<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxLog;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class TaxLogEntity extends Entity
{
    use EntityIdTrait;
    use EntityCustomFieldsTrait;

    /**
     * @var string
     */
    protected $request = '';

    /**
     * @var string
     */
    protected $customerName = '';

    /**
     * @var string
     */
    protected $customerEmail = '';

    /**
     * @var string
     */
    protected $remoteIp = '';

    /**
     * @var string
     */
    protected $response = '';

    /**
     * @var string
     */
    protected $transactionId = '';

    /**
     * @var string
     */
    protected $orderNumber = '';

    /**
     * @var string
     */
    protected $orderId = '';

    /**
     * @var TaxLogEntity
     */
    protected mixed $taxLogRepository;

    public function getRequest(): string
    {
        return (string)$this->request;
    }

    public function setRequest(string $request): void
    {
        $this->request = $request;
    }

    public function getCustomerName(): string
    {
        return (string)$this->customerName;
    }

    public function setCustomerName(string $customerName): void
    {
        $this->customerName = $customerName;
    }

    public function getCustomerEmail(): string
    {
        return (string)$this->customerEmail;
    }

    public function setCustomerEmail(string $customerEmail): void
    {
        $this->customerEmail = $customerEmail;
    }

    public function getRemoteIp(): string
    {
        return (string)$this->remoteIp;
    }

    public function setRemoteIp(string $remoteIp): void
    {
        $this->remoteIp = $remoteIp;
    }

    public function getResponse(): string
    {
        return (string) $this->response;
    }

    public function setResponse(string $response): void
    {
        $this->response = $response;
    }

    public function getTransactionId(): string
    {
        return (string) $this->transactionId;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    public function getOrderNumber(): string
    {
        return (string) $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getOrderId(): string
    {
        return (string) $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

}