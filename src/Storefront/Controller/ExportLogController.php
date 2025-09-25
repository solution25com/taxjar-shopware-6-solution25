<?php declare(strict_types=1);

namespace ITGCoTax\Storefront\Controller;


use ITGCoTax\Core\Content\TaxLog\TaxLogEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system.plugin_maintain']])]
class ExportLogController
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $taxJarLogRepository;

    /**
     * @var EntityRepository $taxJarLogRepository
     */
    public function __construct(
        EntityRepository $taxJarLogRepository
    )
    {
        $this->taxJarLogRepository = $taxJarLogRepository;
    }

    #[Route(path: '/api/_action/tax-jar/export-log', name: 'frontend.taxjar.export-log', methods: ['GET'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function exportLog(Request $request, Context $context): JsonResponse
    {
        $response = [];
        $iterator = new RepositoryIterator(
            $this->taxJarLogRepository,
            $context
        );
        $records = $iterator->fetch();
        if (!is_null($records)) {
            /** @var TaxLogEntity $taxJarLog */
            foreach ($records->getEntities() as $taxJarLog) {
                $response[] = [
                    'customerName' => $taxJarLog->getCustomerName(),
                    'customerEmail' => $taxJarLog->getCustomerEmail(),
                    'orderNumber' => $taxJarLog->getOrderNumber(),
                    'orderId' => $taxJarLog->getOrderId(),
                    'remoteIp' => $taxJarLog->getRemoteIp(),
                    'request' => str_replace('"', "'", $taxJarLog->getRequest()),
                    'response' => str_replace('"', "'",$taxJarLog->getResponse()),
                    'createdAt' => $taxJarLog->getCreatedAt()->format(DATE_RFC3339_EXTENDED)
                ];
            }
        }
        return new JsonResponse($response);
    }
}