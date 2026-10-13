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

  /**
   * Reports one completed return to TaxJar. Calling it again for a reported return sends nothing.
   * Responds 502 when TaxJar did not accept the refund, so the caller can retry.
   */
  #[Route(
    path: '/api/_action/taxjar/return/{returnId}/refund',
    name: 'api.action.taxjar.return.refund',
    defaults: ['_acl' => ['order:update']],
    methods: ['POST']
  )]
  public function sendReturnRefund(string $returnId, Context $context): JsonResponse
  {
    if (!Uuid::isValid($returnId)) {
      return new JsonResponse(['message' => 'Invalid return id.'], 400);
    }

    try {
      $result = $this->transactionSubscriber->reportReturn($returnId, $context);
    } catch (\RuntimeException $e) {
      return new JsonResponse(['message' => $e->getMessage()], 400);
    }

    return new JsonResponse($result, $result['status'] === 'failed' ? 502 : 200);
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
