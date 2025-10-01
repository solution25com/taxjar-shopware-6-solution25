<?php declare(strict_types=1);

namespace solu1TaxJar\Service\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use solu1TaxJar\Core\Content\TaxLog\TaxLogEntity;

class CleanLogTaskHandler extends ScheduledTaskHandler
{
    private const LOG_RETENTION_PERIOD = 10;

    /**
     * @var TaxLogCollection
     */
    private TaxLogCollection  $logRepository;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param TaxLogCollection $logRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        TaxLogCollection $logRepository,
        LoggerInterface $exceptionLogger
    )
    {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->logRepository = $logRepository;
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
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var TaxLogCollection $taxLogs */
        $taxLogs = $this->logRepository->search($criteria, $context)->getEntities();

        $now = new \DateTime();
        foreach ($taxLogs as $taxLog) {
            /** @var TaxLogEntity $taxLog */
            $createdAt = $taxLog->getCreatedAt();
            if ($createdAt !== null && $createdAt->diff($now)->days > self::LOG_RETENTION_PERIOD) {
                $deletable[] = ['id' => $taxLog->getId()];
            }
        }
        if (\count($deletable) > 0) {
            $this->logRepository->delete($deletable, $context);
        }
    }
}