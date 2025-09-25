<?php declare(strict_types=1);

namespace ITGCoTax\Service\ScheduledTask;

use ITGCoTax\Core\Content\TaxLog\TaxLogCollection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class CleanLogTaskHandler extends ScheduledTaskHandler
{
    private const LOG_RETENTION_PERIOD = 10;

    /**
     * @var EntityRepository
     */
    private $logRepository;

    /**
     * @param EntityRepository $scheduledTaskRepository
     * @param EntityRepository $runRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $runRepository,
        LoggerInterface $exceptionLogger
    )
    {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->logRepository = $runRepository;
    }

    /**
     * @return iterable
     */
    public static function getHandledMessages(): iterable
    {
        return [CleanLogTask::class];
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $deletable = [];
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var TaxLogCollection $runs */
        $runs = $this->logRepository->search($criteria, $context)->getEntities();

        $now = new \DateTime();
        foreach ($runs as $run) {
            /** @var \ITGCoTax\Core\Content\TaxLog\TaxLogEntity $run */
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
