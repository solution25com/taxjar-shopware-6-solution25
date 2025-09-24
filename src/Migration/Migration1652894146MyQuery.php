<?php declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1652894146MyQuery extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1652894146;
    }

    public function update(Connection $connection): void
    {
        $query = /** @lang SQL */
            <<<SQL
          CREATE TABLE IF NOT EXISTS `s25_taxjar_log` (
          `id` binary(16) NOT NULL,
          `request_key` text,
          `request` text,
          `response` text,
          `customer_name` VARCHAR(512) NULL,
          `remote_ip` VARCHAR(20) NULL,
          `customer_email` VARCHAR(512) NULL,
          `type` VARCHAR(512) NULL,
          `order_number` VARCHAR(50) NULL,
          `order_id` TEXT NULL,
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
        $connection->executeStatement('DROP TABLE IF EXISTS `s25_taxjar_log`');
    }
}
