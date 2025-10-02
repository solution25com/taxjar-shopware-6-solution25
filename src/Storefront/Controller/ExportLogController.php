<?php

declare(strict_types=1);

namespace solu1TaxJar\Storefront\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system.plugin_maintain']])]
class ExportLogController
{
    /**
     * @var EntityRepository<TaxLogCollection>
     */
    private  $taxJarLogRepository;

    /**
     * @param EntityRepository<TaxLogCollection> $taxJarLogRepository
     */
    public function __construct(EntityRepository $taxJarLogRepository)
    {
        $this->taxJarLogRepository = $taxJarLogRepository;
    }

    #[Route(
        path: '/api/_action/tax-jar/export-log',
        name: 'frontend.taxjar.export-log',
        methods: ['GET'],
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false]
    )]
    public function exportLog(Context $context): JsonResponse
    {$response = [];

        $criteria = new Criteria();
        $taxLogs = $this->taxJarLogRepository->search($criteria, $context);

        /** @var TaxLogEntity $taxJarLog */
        foreach ($taxLogs->getEntities() as $taxJarLog) {
            $response[] = [
                'customerName' => $taxJarLog->getCustomerName(),
                'customerEmail' => $taxJarLog->getCustomerEmail(),
                'orderNumber' => $taxJarLog->getOrderNumber(),
                'orderId' => $taxJarLog->getOrderId(),
                'remoteIp' => $taxJarLog->getRemoteIp(),
                'request' => str_replace('"', "'", $taxJarLog->getRequest()),
                'response' => str_replace('"', "'", $taxJarLog->getResponse()),
                'createdAt' => $taxJarLog->getCreatedAt()
            ];
        }

        return new JsonResponse($response);
    }
}