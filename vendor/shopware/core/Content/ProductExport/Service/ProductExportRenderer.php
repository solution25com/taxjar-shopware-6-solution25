<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\Service;

use Monolog\Level;
use Shopware\Core\Content\ProductExport\Event\ProductExportLoggingEvent;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderFooterContextEvent;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderHeaderContextEvent;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\ProductExportException;
use Shopware\Core\Framework\Adapter\AdapterException;
use Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('inventory')]
class ProductExportRenderer implements ProductExportRendererInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly StringTemplateRenderer $templateRenderer,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function renderHeader(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext
    ): string {
        if ($productExport->getHeaderTemplate() === null) {
            return '';
        }

        $headerContext = $this->eventDispatcher->dispatch(
            new ProductExportRenderHeaderContextEvent(
                [
                    'productExport' => $productExport,
                    'context' => $salesChannelContext,
                ]
            )
        );

        try {
            return $this->templateRenderer->render(
                $productExport->getHeaderTemplate(),
                $headerContext->getContext(),
                $salesChannelContext->getContext()
            ) . \PHP_EOL;
        } catch (AdapterException $exception) {
            if ($exception->getErrorCode() === AdapterException::STRING_TEMPLATE_RENDERING_FAILED) {
                $renderHeaderException = ProductExportException::renderHeaderException($exception->getMessage());
                $this->logException($salesChannelContext->getContext(), $renderHeaderException);

                throw $renderHeaderException;
            }

            throw $exception;
        }
    }

    public function renderFooter(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext
    ): string {
        if ($productExport->getFooterTemplate() === null) {
            return '';
        }

        $footerContext = $this->eventDispatcher->dispatch(
            new ProductExportRenderFooterContextEvent(
                [
                    'productExport' => $productExport,
                    'context' => $salesChannelContext,
                ]
            )
        );

        try {
            return $this->templateRenderer->render(
                $productExport->getFooterTemplate(),
                $footerContext->getContext(),
                $salesChannelContext->getContext()
            ) . \PHP_EOL;
        } catch (AdapterException $exception) {
            $renderFooterException = ProductExportException::renderFooterException($exception->getMessage());
            $this->logException($salesChannelContext->getContext(), $renderFooterException);

            throw $renderFooterException;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderBody(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
        array $data
    ): string {
        $bodyTemplate = $productExport->getBodyTemplate();
        if (!\is_string($bodyTemplate)) {
            throw ProductExportException::templateBodyNotSet();
        }

        try {
            return $this->templateRenderer->render(
                $bodyTemplate,
                $data,
                $salesChannelContext->getContext()
            ) . \PHP_EOL;
        } catch (AdapterException $exception) {
            $renderProductException = ProductExportException::renderProductException($exception->getMessage());
            $this->logException($salesChannelContext->getContext(), $renderProductException);

            throw $renderProductException;
        }
    }

    private function logException(
        Context $context,
        \Exception $exception
    ): void {
        $loggingEvent = new ProductExportLoggingEvent(
            $context,
            $exception->getMessage(),
            Level::Warning,
            $exception
        );

        $this->eventDispatcher->dispatch($loggingEvent);
    }
}
