<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelAnalytics\SalesChannelAnalyticsCollection;
use Shopware\Storefront\Event\StorefrontRenderEvent;

/**
 * @internal
 */
#[Package('discovery')]
class SalesChannelAnalyticsLoader
{
    /**
     * @param EntityRepository<SalesChannelAnalyticsCollection> $salesChannelAnalyticsRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelAnalyticsRepository,
    ) {
    }

    public function loadAnalytics(StorefrontRenderEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannel = $salesChannelContext->getSalesChannel();
        $analyticsId = $salesChannel->getAnalyticsId();

        if (empty($analyticsId)) {
            return;
        }

        $criteria = new Criteria([$analyticsId]);
        $criteria->setTitle('sales-channel::load-analytics');

        $analytics = $this->salesChannelAnalyticsRepository->search($criteria, $salesChannelContext->getContext())->getEntities()->first();

        $event->setParameter('storefrontAnalytics', $analytics);
    }
}
