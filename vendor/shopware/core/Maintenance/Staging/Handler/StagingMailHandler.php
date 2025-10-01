<?php declare(strict_types=1);

namespace Shopware\Core\Maintenance\Staging\Handler;

use Shopware\Core\Content\Mail\Service\MailSender;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Maintenance\Staging\Event\SetupStagingEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @internal
 */
#[Package('framework')]
readonly class StagingMailHandler
{
    public function __construct(
        private SystemConfigService $systemConfigService
    ) {
    }

    public function __invoke(SetupStagingEvent $event): void
    {
        if (!$event->disableMailDelivery) {
            return;
        }

        $this->systemConfigService->set(MailSender::DISABLE_MAIL_DELIVERY, true);

        $event->io->info('Disabled mail delivery.');
    }
}
