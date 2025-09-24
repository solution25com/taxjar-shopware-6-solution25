<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1651411372MyQuery extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1651411372;
    }

    public function update(Connection $connection): void
    {
       $query = /** @lang SQL */
            <<<SQL
          CREATE TABLE IF NOT EXISTS `itg_tax_provider` (
          `id` binary(16) NOT NULL,
          `tax_id` binary(16) NOT NULL,
          `provider_id` binary(16) NOT NULL,
           `created_at` datetime DEFAULT NULL,
          `updated_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       ;
SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `itg_tax_provider`');
    }
}
