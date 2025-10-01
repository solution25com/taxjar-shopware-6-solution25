<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Context\Cleanup;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

#[Package('discovery')]
class CleanupSalesChannelContextTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'sales_channel_context.cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
