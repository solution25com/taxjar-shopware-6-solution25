<?php declare(strict_types=1);

namespace Shopware\Core\Service\ScheduledTask;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * @internal
 */
#[Package('framework')]
class InstallServicesTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'services.install';
    }

    public static function getDefaultInterval(): int
    {
        return parent::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
