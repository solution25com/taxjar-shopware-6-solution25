<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Payment\Controller;

use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\ShopwareException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Package('checkout')]
class PaymentController extends AbstractController
{
    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly PaymentProcessor $paymentProcessor,
        private readonly OrderConverter $orderConverter,
        private readonly TokenFactoryInterfaceV2 $tokenFactory,
        private readonly EntityRepository $orderRepository
    ) {
    }

    /**
     * The route scope could not be defined as this route is called from external.
     * An API route scope would normally imply an authentication, which external callers could not provide.
     * Only a storefront route scope could also not be used, as it also needs to work on headless environments.
     *
     * @phpstan-ignore shopware.routeScope
     */
    #[Route(
        path: '/payment/finalize-transaction',
        name: 'payment.finalize.transaction',
        methods: [Request::METHOD_GET, Request::METHOD_POST]
    )]
    public function finalizeTransaction(Request $request): Response
    {
        $paymentToken = $request->get('_sw_payment_token');

        if ($paymentToken === null) {
            // @deprecated tag:v6.8.0 - remove this if block
            if (!Feature::isActive('v6.8.0.0')) {
                throw RoutingException::missingRequestParameter('_sw_payment_token'); // @phpstan-ignore-line shopware.domainException
            }
            throw PaymentException::missingRequestParameter('_sw_payment_token');
        }

        $token = $this->tokenFactory->parseToken($paymentToken);
        if ($token->isExpired()) {
            $token->setException(PaymentException::tokenExpired($paymentToken));
            if ($token->getToken() !== null) {
                $this->tokenFactory->invalidateToken($token->getToken());
            }

            return $this->handleResponse($token);
        }

        $salesChannelContext = $this->assembleSalesChannelContext($token);

        $result = $this->paymentProcessor->finalize(
            $token,
            $request,
            $salesChannelContext
        );

        return $this->handleResponse($result);
    }

    private function handleResponse(TokenStruct $token): Response
    {
        if ($token->getException() === null) {
            $finishUrl = $token->getFinishUrl();
            if ($finishUrl) {
                return new RedirectResponse($finishUrl);
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        if ($token->getErrorUrl() === null) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $url = $token->getErrorUrl();

        $exception = $token->getException();
        if ($exception instanceof ShopwareException) {
            return new RedirectResponse(
                $url . (parse_url($url, \PHP_URL_QUERY) ? '&' : '?') . 'error-code=' . $exception->getErrorCode()
            );
        }

        return new RedirectResponse($url);
    }

    private function assembleSalesChannelContext(TokenStruct $token): SalesChannelContext
    {
        $context = Context::createDefaultContext();

        $transactionId = $token->getTransactionId();
        if (!$transactionId) {
            throw PaymentException::invalidToken($token->getToken() ?? '');
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('transactions.id', $transactionId))
            ->addAssociations(['transactions', 'orderCustomer']);

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();
        if (!$order) {
            throw PaymentException::invalidToken($token->getToken() ?? '');
        }

        return $this->orderConverter->assembleSalesChannelContext($order, $context);
    }
}
