<?php declare(strict_types=1);

namespace solu1TaxJar\Service\ScheduledTask;

use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class CleanLogTaskHandler extends ScheduledTaskHandler
{
    private const LOG_RETENTION_PERIOD = 10;

    /**
     * @var EntityRepository<TaxLogCollection>
     */
    private EntityRepository $logRepository;

    /**
     * @param EntityRepository<TaxLogCollection> $scheduledTaskRepository
     * @param EntityRepository<TaxLogCollection> $runRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $runRepository
    ) {
        /** @phpstan-ignore-next-line  */
        parent::__construct($scheduledTaskRepository);
        $this->logRepository = $runRepository;
    }

    /**
     * @return iterable<class-string>
     */
    public static function getHandledMessages(): iterable
    {
        return [CleanLogTask::class];
    }

    public function run(): void
    {
        $deletable = [];

        // default context is used to avoid issues with the context in the scheduled task handler
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var TaxLogCollection $runs */
        $runs = $this->logRepository->search($criteria, $context)->getEntities();

        $now = new \DateTime();
        foreach ($runs as $run) {
            $createdAt = $run->getCreatedAt();
            if ($createdAt !== null && $createdAt->diff($now)->days > self::LOG_RETENTION_PERIOD) {
                $deletable[] = ['id' => $run->getId()];
            }
        }

        if (\count($deletable) > 0) {
            $this->logRepository->delete($deletable, $context);
        }
    }
}
