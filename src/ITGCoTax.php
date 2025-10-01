<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace ITGCoTax;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

class ITGCoTax extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $migrationCollection = $installContext->getMigrationCollection();
        foreach ($migrationCollection as $migration) {
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
