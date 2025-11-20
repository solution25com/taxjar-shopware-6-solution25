<?php
declare(strict_types=1);

namespace solu1TaxJar;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Kernel;
use solu1TaxJar\Service\UpdateOrderCustomFieldService;

class solu1TaxJar extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $migrationCollection = $installContext->getMigrationCollection();
        $migrationSteps = $migrationCollection->getMigrationSteps();

        foreach ($migrationSteps as $migration) {
            $migration->update(Kernel::getConnection());
        }

        parent::install($installContext);
    }


    public function uninstall(UninstallContext $uninstallContext): void
    {
        if (!$uninstallContext->keepUserData()) {
            $migrationCollection = $uninstallContext->getMigrationCollection();
            $migrationSteps = $migrationCollection->getMigrationSteps();
            foreach ($migrationSteps as $migration) {
                $migration->updateDestructive(Kernel::getConnection());
            }
        }

        parent::uninstall($uninstallContext);
    }
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $service = new UpdateOrderCustomFieldService(
            $this->container->get(Connection::class));
        $service->run();
    }
}
