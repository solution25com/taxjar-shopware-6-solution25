<?php
declare(strict_types=1);

namespace solu1TaxJar;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

class solu1TaxJar extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $migrationCollection = $installContext->getMigrationCollection();
        foreach ($migrationCollection->getMigrationSteps() as $migration) {
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
}
