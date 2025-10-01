<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[Package('checkout')]
class DeleteUnusedGuestCustomerService
{
    final public const DELETE_CUSTOMERS_BATCH_SIZE = 100;

    /**
     * @internal
     *
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function countUnusedCustomers(Context $context): int
    {
        $maxLifeTime = $this->getUnusedGuestCustomerLifeTime();

        if (!$maxLifeTime) {
            return 0;
        }

        $criteria = $this->getUnusedCustomerCriteria($maxLifeTime);

        $criteria->addAggregation(new CountAggregation('customer-count', 'id'));

        $aggregation = $this->customerRepository->aggregate($criteria, $context)->get('customer-count');

        return $aggregation instanceof CountResult ? $aggregation->getCount() : 0;
    }

    /**
     * @return list<array{id: string}>
     */
    public function deleteUnusedCustomers(Context $context): array
    {
        $maxLifeTime = $this->getUnusedGuestCustomerLifeTime();

        if (!$maxLifeTime) {
            return [];
        }

        $criteria = $this->getUnusedCustomerCriteria($maxLifeTime)
            ->setLimit(self::DELETE_CUSTOMERS_BATCH_SIZE);

        /** @var list<string> $ids */
        $ids = $this->customerRepository->searchIds($criteria, $context)->getIds();
        $ids = \array_values(\array_map(static fn (string $id) => ['id' => $id], $ids));

        $this->customerRepository->delete($ids, $context);

        return $ids;
    }

    private function getUnusedCustomerCriteria(\DateTime $maxLifeTime): Criteria
    {
        $criteria = (new Criteria())
            ->addAssociation('orderCustomers')
            ->addFilter(new AndFilter([
                new EqualsFilter('guest', true),
                new EqualsFilter('orderCustomers.id', null),
                new RangeFilter(
                    'createdAt',
                    [
                        RangeFilter::LTE => $maxLifeTime->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                )]));

        return $criteria;
    }

    private function getUnusedGuestCustomerLifeTime(): ?\DateTime
    {
        $maxLifeTime = $this->systemConfigService->getInt(
            'core.loginRegistration.unusedGuestCustomerLifetime'
        );

        if ($maxLifeTime <= 0) {
            return null;
        }

        return new \DateTime(\sprintf('- %d seconds', $maxLifeTime));
    }
}
