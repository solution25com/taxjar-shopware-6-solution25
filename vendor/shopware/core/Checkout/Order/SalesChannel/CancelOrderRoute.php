<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\SalesChannel;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
#[Package('checkout')]
class CancelOrderRoute extends AbstractCancelOrderRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly OrderService $orderService,
        private readonly EntityRepository $orderRepository
    ) {
    }

    public function getDecorated(): AbstractCancelOrderRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/order/state/cancel', name: 'store-api.order.state.cancel', methods: ['POST'], defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => true])]
    public function cancel(Request $request, SalesChannelContext $context): CancelOrderRouteResponse
    {
        $orderId = $request->get('orderId', null);

        if ($orderId === null) {
            throw OrderException::invalidRequestParameter('orderId');
        }

        $this->verify($orderId, $context);

        $newState = $this->orderService->orderStateTransition(
            $orderId,
            'cancel',
            new ParameterBag(),
            $context->getContext()
        );

        return new CancelOrderRouteResponse($newState);
    }

    private function verify(string $orderId, SalesChannelContext $context): void
    {
        if (!$context->getCustomer()) {
            throw OrderException::customerNotLoggedIn();
        }

        $criteria = (new Criteria([$orderId]))
            ->addFilter(new EqualsFilter('orderCustomer.customerId', $context->getCustomerId()));

        $total = $this->orderRepository->searchIds($criteria, $context->getContext())->getTotal();
        if ($total === 0) {
            throw OrderException::orderNotFound($orderId);
        }
    }
}
