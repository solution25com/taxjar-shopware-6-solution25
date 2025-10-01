<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Renderer;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

#[Package('after-sales')]
final class RendererResult extends Struct
{
    /**
     * @var array<string, RenderedDocument>
     */
    protected array $success = [];

    /**
     * @var array<string, \Throwable>
     */
    protected array $errors = [];

    public function addSuccess(string $orderId, RenderedDocument $renderedDocument): void
    {
        $this->success[$orderId] = $renderedDocument;
    }

    public function addError(string $orderId, \Throwable $exception): void
    {
        $this->errors[$orderId] = $exception;
    }

    /**
     * @return array<string, RenderedDocument>
     */
    public function getSuccess(): array
    {
        return $this->success;
    }

    /**
     * @return array<string, \Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getOrderSuccess(string $orderId): ?RenderedDocument
    {
        return $this->success[$orderId] ?? null;
    }

    public function getOrderError(string $orderId): ?\Throwable
    {
        return $this->errors[$orderId] ?? null;
    }
}
