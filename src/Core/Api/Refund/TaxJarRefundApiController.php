<?php declare(strict_types=1);

namespace solu1TaxJar\Core\Api\Refund;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use solu1TaxJar\Core\TaxJar\Order\TransactionSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class TaxJarRefundApiController extends AbstractController
{
  public function __construct(private readonly TransactionSubscriber $transactionSubscriber)
  {
  }

  #[Route(
    path: '/api/_action/taxjar/order/{orderId}/refunds',
    name: 'api.action.taxjar.order.refunds',
    defaults: ['_acl' => ['order:read']],
    methods: ['GET']
  )]
  public function getRefunds(string $orderId, Context $context): JsonResponse
  {
    return $this->respond($orderId, fn (): array => $this->transactionSubscriber->getRefundReport($orderId, $context));
  }

  #[Route(
    path: '/api/_action/taxjar/order/{orderId}/refunds/response',
    name: 'api.action.taxjar.order.refunds.response',
    defaults: ['_acl' => ['order:read']],
    methods: ['GET']
  )]
  public function getRefundResponse(string $orderId, Request $request, Context $context): JsonResponse
  {
    $transactionId = (string) $request->query->get('transactionId', '');

    return $this->respond($orderId, fn (): array => $this->transactionSubscriber->getRefundResponse($orderId, $transactionId, $context));
  }

  #[Route(
    path: '/api/_action/taxjar/order/{orderId}/refunds/send',
    name: 'api.action.taxjar.order.refunds.send',
    defaults: ['_acl' => ['order:update']],
    methods: ['POST']
  )]
  public function sendRefunds(string $orderId, Context $context): JsonResponse
  {
    return $this->respond($orderId, fn (): array => [
      'results' => $this->transactionSubscriber->sendPendingRefunds($orderId, $context),
      'report' => $this->transactionSubscriber->getRefundReport($orderId, $context),
    ]);
  }

  private function respond(string $orderId, \Closure $action): JsonResponse
  {
    if (!Uuid::isValid($orderId)) {
      return new JsonResponse(['message' => 'Invalid order id.'], 400);
    }

    try {
      return new JsonResponse($action());
    } catch (\RuntimeException $e) {
      return new JsonResponse(['message' => $e->getMessage()], 400);
    }
  }
}
