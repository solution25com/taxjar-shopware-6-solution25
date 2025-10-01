<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\SalesChannel;

use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentException;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Service\PdfRenderer;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
#[Package('after-sales')]
final class DocumentRoute extends AbstractDocumentRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<DocumentCollection> $documentRepository
     */
    public function __construct(
        private readonly DocumentGenerator $documentGenerator,
        private readonly EntityRepository $documentRepository,
    ) {
    }

    public function getDecorated(): AbstractDocumentRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/document/download/{documentId}/{deepLinkCode}', name: 'store-api.document.download', methods: ['GET', 'POST'], defaults: ['_entity' => 'document'])]
    public function download(
        string $documentId,
        Request $request,
        SalesChannelContext $context,
        string $deepLinkCode = '',
        string $fileType = PdfRenderer::FILE_EXTENSION
    ): Response {
        $this->checkAuth($documentId, $request, $context);

        $isGuest = $context->getCustomer() === null || $context->getCustomer()->getGuest();
        if ($isGuest && $deepLinkCode === '') {
            throw DocumentException::customerNotLoggedIn();
        }

        $download = $request->query->getBoolean('download');

        $document = $this->documentGenerator->readDocument($documentId, $context->getContext(), $deepLinkCode, $fileType);

        if ($document === null) {
            return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
        }

        return $this->createResponse(
            $document->getName(),
            $document->getContent(),
            $download,
            $document->getContentType()
        );
    }

    private function createResponse(string $filename, string $content, bool $forceDownload, string $contentType): Response
    {
        $response = new Response($content);

        $disposition = HeaderUtils::makeDisposition(
            $forceDownload ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            // only printable ascii
            preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', $filename) ?? ''
        );

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    private function checkAuth(string $documentId, Request $request, SalesChannelContext $context): void
    {
        $criteria = (new Criteria([$documentId]))
            ->addAssociations(['order.orderCustomer.customer', 'order.billingAddress']);

        $document = $this->documentRepository->search($criteria, $context->getContext())->getEntities()->first();
        if (!$document) {
            throw DocumentException::documentNotFound($documentId);
        }

        $order = $document->getOrder();
        if (!$order) {
            throw DocumentException::orderNotFound($document->getOrderId());
        }

        $orderCustomer = $order->getOrderCustomer();
        if (!$orderCustomer) {
            throw DocumentException::customerNotLoggedIn();
        }

        if ($orderCustomer->getCustomerId() === $context->getCustomer()?->getId()) {
            return;
        }

        $this->checkGuestAuth($order, $orderCustomer, $request);
    }

    private function checkGuestAuth(
        OrderEntity $order,
        OrderCustomerEntity $orderCustomer,
        Request $request
    ): void {
        $isOrderByGuest = $orderCustomer->getCustomer() !== null && $orderCustomer->getCustomer()->getGuest();

        if (!$isOrderByGuest) {
            throw DocumentException::customerNotLoggedIn();
        }

        // Verify email and zip code with this order
        if ($request->get('email', false) && $request->get('zipcode', false)) {
            $billingAddress = $order->getBillingAddress();
            if ($billingAddress === null
                || $request->get('email') !== $orderCustomer->getEmail()
                || $request->get('zipcode') !== $billingAddress->getZipcode()) {
                throw DocumentException::wrongGuestCredentials();
            }
        } else {
            throw DocumentException::guestNotAuthenticated();
        }
    }
}
