<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Renderer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Document\DocumentException;
use Shopware\Core\Checkout\Document\Event\DocumentOrderCriteriaEvent;
use Shopware\Core\Checkout\Document\Event\StornoOrdersEvent;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Service\ReferenceInvoiceLoader;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('after-sales')]
final class StornoRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'storno';

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

        $template = '@Framework/documents/storno.html.twig';

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
                    throw DocumentException::generationError('Can not generate storno document because no invoice document exists. OrderId: ' . $operation->getOrderId());
                }

                $documentRefer = json_decode($invoice['config'], true, 512, \JSON_THROW_ON_ERROR);
                $referenceInvoiceNumbers[$orderId] = $invoice['documentNumber'] ?? $documentRefer['documentNumber'];

                $order = $this->getOrder($operation, $invoice['orderVersionId'], $context, $rendererConfig);

                $orders->add($order);
                $operation->setReferencedDocumentId($invoice['id']);
                if ($order->getVersionId()) {
                    $operation->setOrderVersionId($order->getVersionId());
                }
            } catch (\Throwable $exception) {
                $result->addError($operation->getOrderId(), $exception);
            }
        }

        // TODO: future implementation (only fetch required data and associations)

        $this->eventDispatcher->dispatch(new StornoOrdersEvent($orders, $context, $operations));

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

                $order = $this->handlePrices($order);

                $config = clone $this->documentConfigLoader->load(self::TYPE, $order->getSalesChannelId(), $context);

                $config->merge($operation->getConfig());

                $number = $config->getDocumentNumber() ?: $this->getNumber($context, $order, $operation);

                $referenceDocumentNumber = $referenceInvoiceNumbers[$operation->getOrderId()];

                $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

                $config->merge([
                    'documentDate' => $operation->getConfig()['documentDate'] ?? $now,
                    'documentNumber' => $number,
                    'custom' => [
                        'stornoNumber' => $number,
                        'invoiceNumber' => $referenceDocumentNumber,
                    ],
                    'intraCommunityDelivery' => $this->isAllowIntraCommunityDelivery(
                        $config->jsonSerialize(),
                        $order,
                    ) && $this->isValidVat($order, $this->validator),
                ]);

                if ($operation->isStatic()) {
                    $doc = new RenderedDocument($number, $config->buildName(), $operation->getFileType(), $config->jsonSerialize());
                    $result->addSuccess($orderId, $doc);

                    continue;
                }

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

    private function getOrder(DocumentGenerateOperation $operation, string $versionId, Context $context, DocumentRendererConfig $rendererConfig): OrderEntity
    {
        $orderId = $operation->getOrderId();

        ['language_id' => $languageId] = $this->getOrdersLanguageId([$orderId], $versionId, $this->connection)[0];

        // Get the order with the version from the reference invoice
        $versionContext = $context->createWithVersionId($versionId)->assign([
            'languageIdChain' => array_values(array_unique(array_filter([$languageId, ...$context->getLanguageIdChain()]))),
        ]);

        $criteria = OrderDocumentCriteriaFactory::create([$orderId], $rendererConfig->deepLinkCode, self::TYPE);

        $this->eventDispatcher->dispatch(new DocumentOrderCriteriaEvent(
            $criteria,
            $context,
            [$operation->getOrderId() => $operation],
            $rendererConfig,
            self::TYPE
        ));

        $order = $this->orderRepository->search($criteria, $versionContext)->getEntities()->first();
        if ($order === null) {
            throw DocumentException::orderNotFound($orderId);
        }

        return $order;
    }

    private function handlePrices(OrderEntity $order): OrderEntity
    {
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $lineItem->setUnitPrice($lineItem->getUnitPrice() / -1);
            $lineItem->setTotalPrice($lineItem->getTotalPrice() / -1);
        }

        foreach ($order->getPrice()->getCalculatedTaxes()->sortByTax()->getElements() as $tax) {
            $tax->setTax($tax->getTax() / -1);
        }

        $order->setShippingTotal($order->getShippingTotal() / -1);
        $order->setAmountNet($order->getAmountNet() / -1);
        $order->setAmountTotal($order->getAmountTotal() / -1);

        $currentOrderCartPrice = $order->getPrice();
        $CartPrice = new CartPrice(
            $currentOrderCartPrice->getNetPrice(),
            $currentOrderCartPrice->getTotalPrice() / -1,
            $currentOrderCartPrice->getPositionPrice(),
            $currentOrderCartPrice->getCalculatedTaxes(),
            $currentOrderCartPrice->getTaxRules(),
            $currentOrderCartPrice->getTaxStatus(),
            $currentOrderCartPrice->getRawTotal(),
        );
        $order->setPrice($CartPrice);

        return $order;
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
}
