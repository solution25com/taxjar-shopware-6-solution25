<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Renderer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Document\DocumentException;
use Shopware\Core\Checkout\Document\Event\CreditNoteOrdersEvent;
use Shopware\Core\Checkout\Document\Event\DocumentOrderCriteriaEvent;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Service\ReferenceInvoiceLoader;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('after-sales')]
final class CreditNoteRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'credit_note';

    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DocumentConfigLoader $documentConfigLoader,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly ReferenceInvoiceLoader $referenceInvoiceLoader,
        private readonly Connection $connection,
        private readonly DocumentFileRendererRegistry $fileRendererRegistry,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function supports(): string
    {
        return self::TYPE;
    }

    public function render(array $operations, Context $context, DocumentRendererConfig $rendererConfig): RendererResult
    {
        $result = new RendererResult();

        $template = '@Framework/documents/credit_note.html.twig';

        $ids = \array_map(fn (DocumentGenerateOperation $operation) => $operation->getOrderId(), $operations);

        if (empty($ids)) {
            return $result;
        }

        $referenceInvoiceNumbers = [];

        $orders = new OrderCollection();

        foreach ($operations as $operation) {
            try {
                $orderId = $operation->getOrderId();
                $invoice = $this->referenceInvoiceLoader->load($orderId, $operation->getReferencedDocumentId(), $rendererConfig->deepLinkCode);

                if (empty($invoice)) {
                    throw DocumentException::generationError('Can not generate credit note document because no invoice document exists. OrderId: ' . $orderId);
                }

                $documentRefer = json_decode($invoice['config'], true, 512, \JSON_THROW_ON_ERROR);
                $referenceInvoiceNumbers[$orderId] = $invoice['documentNumber'] ?? $documentRefer['documentNumber'];

                $order = $this->getOrder($operation, $context, $rendererConfig);

                $orders->add($order);
                $operation->setReferencedDocumentId($invoice['id']);
            } catch (\Throwable $exception) {
                $result->addError($operation->getOrderId(), $exception);
            }
        }

        $this->eventDispatcher->dispatch(new CreditNoteOrdersEvent($orders, $context, $operations));

        foreach ($orders as $order) {
            $orderId = $order->getId();

            try {
                $operation = $operations[$orderId] ?? null;

                if ($operation === null) {
                    continue;
                }

                $forceDocumentCreation = $operation->getConfig()['forceDocumentCreation'] ?? true;
                if (!$forceDocumentCreation && $order->getDocuments()?->first()) {
                    continue;
                }

                $lineItems = $order->getLineItems();
                $creditItems = new OrderLineItemCollection();

                if ($lineItems) {
                    $creditItems = $lineItems->filterByType(LineItem::CREDIT_LINE_ITEM_TYPE);
                }

                if ($creditItems->count() === 0) {
                    throw DocumentException::generationError(
                        'Can not generate credit note document because no credit line items exists. OrderId: ' . $operation->getOrderId()
                    );
                }

                $config = clone $this->documentConfigLoader->load(self::TYPE, $order->getSalesChannelId(), $context);

                $config->merge($operation->getConfig());

                $number = $config->getDocumentNumber() ?: $this->getNumber($context, $order, $operation);

                $referenceDocumentNumber = $referenceInvoiceNumbers[$operation->getOrderId()];

                $config->merge([
                    'documentDate' => $operation->getConfig()['documentDate'] ?? (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'documentNumber' => $number,
                    'custom' => [
                        'creditNoteNumber' => $number,
                        'invoiceNumber' => $referenceDocumentNumber,
                    ],
                    'intraCommunityDelivery' => $this->isAllowIntraCommunityDelivery(
                        $config->jsonSerialize(),
                        $order,
                    ) && $this->isValidVat($order, $this->validator),
                ]);

                // create version of order to ensure the document stays the same even if the order changes
                $operation->setOrderVersionId($this->orderRepository->createVersion($order->getId(), $context, 'document'));

                if ($operation->isStatic()) {
                    $doc = new RenderedDocument($number, $config->buildName(), $operation->getFileType(), $config->jsonSerialize());
                    $result->addSuccess($orderId, $doc);

                    continue;
                }

                $price = $this->calculatePrice($creditItems, $order);

                $language = $order->getLanguage();
                if ($language === null) {
                    throw DocumentException::generationError('Can not generate credit note document because no language exists. OrderId: ' . $operation->getOrderId());
                }

                $doc = new RenderedDocument(
                    $number,
                    $config->buildName(),
                    $operation->getFileType(),
                    $config->jsonSerialize(),
                );

                $doc->setParameters([
                    'creditItems' => $creditItems,
                    'price' => $price->getTotalPrice() * -1,
                    'amountTax' => $price->getCalculatedTaxes()->getAmount(),
                ]);
                $doc->setTemplate($template);
                $doc->setOrder($order);
                $doc->setContext($context);

                $doc->setContent($this->fileRendererRegistry->render($doc));

                $result->addSuccess($orderId, $doc);
            } catch (\Throwable $exception) {
                $result->addError($orderId, $exception);
            }
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new DecorationPatternException(self::class);
    }

    private function getOrder(DocumentGenerateOperation $operation, Context $context, DocumentRendererConfig $rendererConfig): OrderEntity
    {
        ['language_id' => $languageId] = $this->getOrdersLanguageId([$operation->getOrderId()], Defaults::LIVE_VERSION, $this->connection)[0];
        $languageIdChain = array_values(
            array_unique(
                array_filter([$languageId, ...$context->getLanguageIdChain()])
            )
        );

        $order = $this->loadOrder($operation, $context, $languageIdChain, $rendererConfig);

        if ($order === null) {
            throw DocumentException::orderNotFound($operation->getOrderId());
        }

        return $order;
    }

    /**
     * @param list<string> $languageIdChain
     */
    private function loadOrder(
        DocumentGenerateOperation $operation,
        Context $context,
        array $languageIdChain,
        DocumentRendererConfig $rendererConfig,
    ): ?OrderEntity {
        $localizedContext = $context->assign([
            'languageIdChain' => $languageIdChain,
        ]);

        $criteria = OrderDocumentCriteriaFactory::create([$operation->getOrderId()], $rendererConfig->deepLinkCode, self::TYPE);
        $criteria->getAssociation('lineItems')->addFilter(
            new EqualsFilter('type', LineItem::CREDIT_LINE_ITEM_TYPE)
        );

        $this->eventDispatcher->dispatch(new DocumentOrderCriteriaEvent(
            $criteria,
            $context,
            [$operation->getOrderId() => $operation],
            $rendererConfig,
            self::TYPE
        ));

        return $this->orderRepository->search($criteria, $localizedContext)->getEntities()->first();
    }

    private function getNumber(Context $context, OrderEntity $order, DocumentGenerateOperation $operation): string
    {
        return $this->numberRangeValueGenerator->getValue(
            'document_' . self::TYPE,
            $context,
            $order->getSalesChannelId(),
            $operation->isPreview()
        );
    }

    private function calculatePrice(OrderLineItemCollection $creditItems, OrderEntity $order): CartPrice
    {
        foreach ($creditItems as $creditItem) {
            $creditItem->setUnitPrice($creditItem->getUnitPrice() !== 0.0 ? -$creditItem->getUnitPrice() : 0.0);
            $creditItem->setTotalPrice($creditItem->getTotalPrice() !== 0.0 ? -$creditItem->getTotalPrice() : 0.0);
        }

        $creditItemsCalculatedPrice = $creditItems->getPrices()->sum();
        $totalPrice = $creditItemsCalculatedPrice->getTotalPrice();
        $taxAmount = $creditItemsCalculatedPrice->getCalculatedTaxes()->getAmount();
        $taxes = $creditItemsCalculatedPrice->getCalculatedTaxes();

        foreach ($taxes as $tax) {
            $tax->setTax($tax->getTax() !== 0.0 ? -$tax->getTax() : 0.0);
        }

        if ($order->getPrice()->hasNetPrices()) {
            $price = new CartPrice(
                -$totalPrice,
                -($totalPrice + $taxAmount),
                -$order->getPositionPrice(),
                $taxes,
                $creditItemsCalculatedPrice->getTaxRules(),
                $order->getTaxStatus() ?? $order->getPrice()->getTaxStatus(),
            );
        } else {
            $price = new CartPrice(
                -($totalPrice - $taxAmount),
                -$totalPrice,
                -$order->getPositionPrice(),
                $taxes,
                $creditItemsCalculatedPrice->getTaxRules(),
                $order->getTaxStatus() ?? $order->getPrice()->getTaxStatus(),
            );
        }

        $order->setLineItems($creditItems);
        $order->setPrice($price);
        $order->setAmountNet($price->getNetPrice());

        return $price;
    }
}
