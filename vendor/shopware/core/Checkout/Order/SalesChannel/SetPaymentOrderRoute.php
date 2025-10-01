<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\SalesChannel;

use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Event\OrderPaymentMethodChangedCriteriaEvent;
use Shopware\Core\Checkout\Order\Event\OrderPaymentMethodChangedEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
#[Package('checkout')]
class SetPaymentOrderRoute extends AbstractSetPaymentOrderRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly OrderService $orderService,
        private readonly EntityRepository $orderRepository,
        private readonly OrderConverter $orderConverter,
        private readonly CartRuleLoader $cartRuleLoader,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly AbstractCheckoutGatewayRoute $checkoutGatewayRoute
    ) {
    }

    public function getDecorated(): AbstractSetPaymentOrderRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/order/payment',
        name: 'store-api.order.set-payment',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => true],
        methods: ['POST'],
    )]
    public function setPayment(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        $paymentMethodId = $request->request->getAlnum('paymentMethodId');
        if (!Uuid::isValid($paymentMethodId)) {
            throw OrderException::invalidUuid($paymentMethodId);
        }

        $orderId = $request->request->getAlnum('orderId');
        if (!Uuid::isValid($orderId)) {
            throw OrderException::invalidUuid($orderId);
        }

        $order = $this->loadOrder($orderId, $context);

        $context = $this->orderConverter->assembleSalesChannelContext(
            $order,
            $context->getContext(),
            [SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethodId]
        );

        $this->validateRequest($request, $order, $context);

        $this->validatePaymentState($order);

        $this->setPaymentMethod($paymentMethodId, $order, $context);

        return new SetPaymentOrderRouteResponse();
    }

    private function setPaymentMethod(string $paymentMethodId, OrderEntity $order, SalesChannelContext $salesChannelContext): void
    {
        $context = $salesChannelContext->getContext();

        if ($this->tryTransition($order, $paymentMethodId, $context)) {
            return;
        }

        $initialState = $this->initialStateIdLoader->get(OrderTransactionStates::STATE_MACHINE);

        $transactionAmount = new CalculatedPrice(
            $order->getPrice()->getTotalPrice(),
            $order->getPrice()->getTotalPrice(),
            $order->getPrice()->getCalculatedTaxes(),
            $order->getPrice()->getTaxRules()
        );

        $transactionId = Uuid::randomHex();
        $payload = [
            'id' => $order->getId(),
            'primaryOrderTransactionId' => $transactionId,
            'transactions' => [
                [
                    'id' => $transactionId,
                    'paymentMethodId' => $paymentMethodId,
                    'stateId' => $initialState,
                    'amount' => $transactionAmount,
                ],
            ],
            'ruleIds' => $this->getOrderRules($order, $salesChannelContext),
        ];

        $context->scope(
            Context::SYSTEM_SCOPE,
            function () use ($payload, $context): void {
                $this->orderRepository->update([$payload], $context);
            }
        );

        $changedOrder = $this->loadOrder($order->getId(), $salesChannelContext);
        $transactions = $changedOrder->getTransactions();
        if ($transactions === null || ($transaction = $transactions->get($transactionId)) === null) {
            throw OrderException::orderTransactionNotFound($transactionId);
        }

        $event = new OrderPaymentMethodChangedEvent(
            $changedOrder,
            $transaction,
            $context,
            $salesChannelContext->getSalesChannelId()
        );
        $this->eventDispatcher->dispatch($event);
    }

    private function validateRequest(Request $request, OrderEntity $order, SalesChannelContext $salesChannelContext): void
    {
        $paymentMethodId = $request->request->getAlnum('paymentMethodId');
        $cart = $this->orderConverter->convertToCart($order, $salesChannelContext->getContext());
        $response = $this->checkoutGatewayRoute->load($request, $cart, $salesChannelContext);

        if ($response->getPaymentMethods()->get($paymentMethodId) === null) {
            throw OrderException::paymentMethodNotAvailable($paymentMethodId);
        }
    }

    private function tryTransition(OrderEntity $order, string $paymentMethodId, Context $context): bool
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() < 1) {
            return false;
        }

        $lastTransaction = $order->getPrimaryOrderTransaction();

        if (!Feature::isActive('v6.8.0.0')) {
            $lastTransaction = $transactions->last();
        }

        if ($lastTransaction === null) {
            return false;
        }

        foreach ($transactions as $transaction) {
            if ($transaction->getPaymentMethodId() === $paymentMethodId && $lastTransaction->getId() === $transaction->getId()) {
                $initialState = $this->initialStateIdLoader->get(OrderTransactionStates::STATE_MACHINE);
                if ($transaction->getStateId() === $initialState) {
                    return true;
                }

                try {
                    $this->orderService->orderTransactionStateTransition(
                        $transaction->getId(),
                        StateMachineTransitionActions::ACTION_REOPEN,
                        new ParameterBag(),
                        $context
                    );

                    return true;
                } catch (IllegalTransitionException) {
                    // if we can't reopen the last transaction with a matching payment method
                    // we have to create a new transaction and cancel the previous one
                }
            }

            if ($transaction->getStateMachineState() !== null
                && \in_array($transaction->getStateMachineState()->getTechnicalName(), [OrderTransactionStates::STATE_CANCELLED, OrderTransactionStates::STATE_FAILED], true)
            ) {
                continue;
            }

            $context->scope(
                Context::SYSTEM_SCOPE,
                function () use ($transaction, $context): void {
                    $this->orderService->orderTransactionStateTransition(
                        $transaction->getId(),
                        StateMachineTransitionActions::ACTION_CANCEL,
                        new ParameterBag(),
                        $context
                    );
                }
            );
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function getOrderRules(OrderEntity $order, SalesChannelContext $salesChannelContext): array
    {
        $convertedCart = $this->orderConverter->convertToCart($order, $salesChannelContext->getContext());
        $ruleIds = $this->cartRuleLoader->loadByCart(
            $salesChannelContext,
            $convertedCart,
            new CartBehavior($salesChannelContext->getPermissions())
        )->getMatchingRules()->getIds();

        return array_values($ruleIds);
    }

    private function loadOrder(string $orderId, SalesChannelContext $context): OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('transactions')
            ->addAssociation('primaryOrderTransaction.stateMachineState');

        $criteria->getAssociation('transactions')
            ->addSorting(new FieldSorting('createdAt'));

        $customer = $context->getCustomer();
        \assert($customer !== null);

        $criteria
            ->addFilter(new EqualsFilter('order.orderCustomer.customerId', $customer->getId()))
            ->addAssociations([
                'lineItems',
                'deliveries.shippingOrderAddress',
                'deliveries.stateMachineState',
                'orderCustomer',
                'tags',
                'transactions.stateMachineState',
                'stateMachineState',
            ]);

        $this->eventDispatcher->dispatch(new OrderPaymentMethodChangedCriteriaEvent($orderId, $criteria, $context));

        $order = $this->orderRepository->search($criteria, $context->getContext())->first();
        if ($order === null) {
            throw OrderException::orderNotFound($orderId);
        }

        return $order;
    }

    /**
     * @throws OrderException
     */
    private function validatePaymentState(OrderEntity $order): void
    {
        if ($this->orderService->isPaymentChangeableByTransactionState($order)) {
            return;
        }

        throw OrderException::paymentMethodNotChangeable();
    }
}
