<?php declare(strict_types=1);

namespace solu1TaxJar\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CleanLogTask extends ScheduledTask
{
    private const TIME_INTERVAL_DAILY = 86400;

    public static function getTaskName(): string
    {
        return 's25cotax.clear_log_task';
    }

    /**
     * @return int
     */
    public static function getDefaultInterval(): int
    {
        return self::TIME_INTERVAL_DAILY;
    }
}
