<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\TaxJar\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after every request the plugin sends to the TaxJar API for a cart, an order, a return or a customer,
 * whether TaxJar answered successfully, with an error, or not at all. Calculations served from the plugin's cache
 * do not reach TaxJar and do not dispatch it. Request headers are never included, so the API token is never exposed.
 */
final class TaxJarApiCallEvent extends Event implements ShopwareEvent
{
    public const OPERATION_TAX_CALCULATION = 'tax_calculation';

    public const OPERATION_TAX_CALCULATION_ADDRESS_FALLBACK = 'tax_calculation_address_fallback';

    public const OPERATION_ORDER_CREATE = 'order_create';

    public const OPERATION_ORDER_CANCEL = 'order_cancel';

    public const OPERATION_ORDER_DELETE = 'order_delete';

    public const OPERATION_REFUND = 'refund';

    public const OPERATION_PARTIAL_REFUND_CALCULATION = 'partial_refund_calculation';

    public const OPERATION_PARTIAL_REFUND = 'partial_refund';

    public const OPERATION_PARTIAL_REFUND_LOOKUP = 'partial_refund_lookup';

    public const OPERATION_CUSTOMER_CREATE = 'customer_create';

    public const OPERATION_CUSTOMER_UPDATE = 'customer_update';

    private readonly \DateTimeImmutable $requestedAt;

    private readonly float $durationMs;

    /**
     * @param float $startedAt microtime(true) taken right before the request was sent
     */
    public function __construct(
        private readonly string $operation,
        private readonly string $method,
        private readonly string $endpoint,
        private readonly array $request,
        private readonly ?int $statusCode,
        private readonly ?string $responseBody,
        private readonly ?string $error,
        private readonly bool $sandbox,
        float $startedAt,
        private readonly Context $context,
        private readonly ?string $salesChannelId = null,
        private readonly ?string $orderId = null,
        private readonly ?string $returnId = null,
        private readonly ?string $customerId = null,
    ) {
        $this->durationMs = round((microtime(true) - $startedAt) * 1000, 1);
        $this->requestedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $startedAt)) ?: new \DateTimeImmutable();
    }

    /**
     * One of the OPERATION_* constants.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * The payload sent to TaxJar as the JSON request body.
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * HTTP status returned by TaxJar, or null when no response was received (timeout, connection error).
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The unmodified response body returned by TaxJar, or null when no response was received.
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * The response body decoded as JSON, or null when there is nobody or it is not a JSON object.
     */
    public function getResponseData(): ?array
    {
        if ($this->responseBody === null || $this->responseBody === '') {
            return null;
        }

        $data = json_decode($this->responseBody, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Transport or HTTP error message, or null when TaxJar answered with a 2xx status.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    /**
     * Shopware order id. Null for cart calculations and customer requests.
     */
    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    /**
     * Shopware Commercial return id for partial refund requests, otherwise null.
     */
    public function getReturnId(): ?string
    {
        return $this->returnId;
    }

    /**
     * Shopware customer id for cart calculations and customer requests, otherwise null.
     */
    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }
}
