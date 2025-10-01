<?php declare(strict_types=1);

namespace Shopware\Core\Content\Flow\Controller;

use Shopware\Core\Content\Flow\FlowException;
use Shopware\Core\Framework\App\Aggregate\FlowEvent\AppFlowEventCollection;
use Shopware\Core\Framework\App\Event\CustomAppEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('after-sales')]
class TriggerFlowController extends AbstractController
{
    /**
     * @internal
     *
     * @param EntityRepository<AppFlowEventCollection> $appFlowEventRepository
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $appFlowEventRepository,
    ) {
    }

    #[Route(path: '/api/_action/trigger-event/{eventName}', name: 'api.action.trigger_event', methods: ['POST'])]
    public function trigger(string $eventName, Request $request, Context $context): JsonResponse
    {
        $data = $request->request->all();

        $this->checkAppEventIsExist($eventName, $context);

        $this->eventDispatcher->dispatch(new CustomAppEvent($eventName, $data, $context), $eventName);

        return new JsonResponse([
            'message' => \sprintf('The trigger `%s`successfully dispatched!', $eventName),
        ], Response::HTTP_OK);
    }

    private function checkAppEventIsExist(string $eventName, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('name', $eventName));
        $criteria->addFilter(new EqualsFilter('app.active', 1));

        $this->appFlowEventRepository->search($criteria, $context)->first() ?? throw FlowException::customTriggerByNameNotFound($eventName);
    }
}
