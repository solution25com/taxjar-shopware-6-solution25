<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Order\Api;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\RecalculationService;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Rule\LineItemOfTypeRule;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Order\OrderAddressService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('checkout')]
class OrderRecalculationController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected RecalculationService $recalculationService,
        protected OrderAddressService $orderAddressService
    ) {
    }

    #[Route(path: '/api/_action/order/{orderId}/recalculate', name: 'api.action.order.recalculate', methods: ['POST'])]
    public function recalculateOrder(string $orderId, Context $context): Response
    {
        $errors = $this->recalculationService->recalculate($orderId, $context);

        if ($errors->count() > 0) {
            return new JsonResponse(['errors' => $errors]);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/order/{orderId}/product/{productId}', name: 'api.action.order.add-product', methods: ['POST'])]
    public function addProductToOrder(string $orderId, string $productId, Request $request, Context $context): Response
    {
        $quantity = $request->request->getInt('quantity', 1);
        $this->recalculationService->addProductToOrder($orderId, $productId, $quantity, $context);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/order/{orderId}/creditItem', name: 'api.action.order.add-credit-item', methods: ['POST'])]
    public function addCreditItemToOrder(string $orderId, Request $request, Context $context): Response
    {
        $identifier = (string) $request->request->get('identifier');
        $type = LineItem::CREDIT_LINE_ITEM_TYPE;
        $quantity = $request->request->getInt('quantity', 1);

        $lineItem = new LineItem($identifier, $type, null, $quantity);
        $this->updateLineItemByRequest($request, $lineItem, true);

        $this->recalculationService->addCustomLineItem($orderId, $lineItem, $context);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/order/{orderId}/lineItem', name: 'api.action.order.add-line-item', methods: ['POST'])]
    public function addCustomLineItemToOrder(string $orderId, Request $request, Context $context): Response
    {
        $identifier = (string) $request->request->get('identifier');
        $type = $request->request->get('type', LineItem::CUSTOM_LINE_ITEM_TYPE);
        $quantity = $request->request->getInt('quantity', 1);

        $lineItem = (new LineItem($identifier, (string) $type, null, $quantity))
            ->setStackable(true)
            ->setRemovable(true);
        $this->updateLineItemByRequest($request, $lineItem);

        $this->recalculationService->addCustomLineItem($orderId, $lineItem, $context);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/order/{orderId}/promotion-item', name: 'api.action.order.add-promotion-item', methods: ['POST'])]
    public function addPromotionItemToOrder(string $orderId, Request $request, Context $context): Response
    {
        $code = (string) $request->request->get('code');

        $cart = $this->recalculationService->addPromotionLineItem($orderId, $code, $context);

        return new CartResponse($cart);
    }

    /**
     * @deprecated tag:v6.8.0 - Will be removed. Use {@see applyAutomaticPromotions} instead.
     */
    #[Route(path: '/api/_action/order/{orderId}/toggleAutomaticPromotions', name: 'api.action.order.toggle-automatic-promotions', methods: ['POST'])]
    public function toggleAutomaticPromotions(string $orderId, Request $request, Context $context): Response
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            'Route "api.action.order.toggle-automatic-promotions" is deprecated and will be removed in v6.8.0.0. Use "api.action.order.apply-automatic-promotions" instead.',
        );

        $skipAutomaticPromotions = (bool) $request->request->get('skipAutomaticPromotions', true);

        $cart = $this->recalculationService->toggleAutomaticPromotion($orderId, $context, $skipAutomaticPromotions);

        return new CartResponse($cart);
    }

    #[Route(path: '/api/_action/order/{orderId}/applyAutomaticPromotions', name: 'api.action.order.apply-automatic-promotions', methods: ['POST'])]
    public function applyAutomaticPromotions(string $orderId, Request $request, Context $context): Response
    {
        $errors = $this->recalculationService->applyAutomaticPromotions($orderId, $context);

        return new JsonResponse(['errors' => $errors]);
    }

    #[Route(path: '/api/_action/order-address/{orderAddressId}/customer-address/{customerAddressId}', name: 'api.action.order.replace-order-address', methods: ['POST'])]
    public function replaceOrderAddressWithCustomerAddress(string $orderAddressId, string $customerAddressId, Context $context): JsonResponse
    {
        $this->recalculationService->replaceOrderAddressWithCustomerAddress($orderAddressId, $customerAddressId, $context);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/_action/order/{orderId}/order-address', name: 'api.action.order.update', methods: ['POST'])]
    public function updateOrderAddresses(string $orderId, Request $request, Context $context): JsonResponse
    {
        $mapping = $request->request->all('mapping');
        \assert(array_is_list($mapping));

        $this->orderAddressService->updateOrderAddresses($orderId, $mapping, $context);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function updateLineItemByRequest(Request $request, LineItem $lineItem, bool $absolute = false): void
    {
        $label = $request->request->get('label');
        $description = $request->request->get('description');
        $removable = (bool) $request->request->get('removeable', true);
        $stackable = (bool) $request->request->get('stackable', true);
        $payload = $request->request->all('payload');
        $priceDefinition = $request->request->all('priceDefinition');

        if ($label !== null && !\is_string($label)) {
            // @deprecated tag:v6.8.0 - remove this if block
            if (!Feature::isActive('v6.8.0.0')) {
                throw RoutingException::invalidRequestParameter('label'); // @phpstan-ignore shopware.domainException
            }
            throw CartException::invalidRequestParameter('label');
        }

        if ($description !== null && !\is_string($description)) {
            // @deprecated tag:v6.8.0 - remove this if block
            if (!Feature::isActive('v6.8.0.0')) {
                throw RoutingException::invalidRequestParameter('description'); // @phpstan-ignore shopware.domainException
            }
            throw CartException::invalidRequestParameter('description');
        }

        $lineItem->setLabel($label);
        $lineItem->setDescription($description);
        $lineItem->setRemovable($removable);
        $lineItem->setStackable($stackable);
        $lineItem->setPayload($payload);

        if (!$absolute) {
            $lineItem->setPriceDefinition(QuantityPriceDefinition::fromArray($priceDefinition));
        } else {
            $lineItem->setPriceDefinition(
                new AbsolutePriceDefinition(
                    (float) $priceDefinition['price'],
                    new LineItemOfTypeRule(Rule::OPERATOR_NEQ, LineItem::CREDIT_LINE_ITEM_TYPE)
                )
            );
        }
    }
}
