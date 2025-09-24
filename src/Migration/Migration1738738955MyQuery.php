<?php declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1738738955MyQuery extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1738738955;
    }

    public function update(Connection $connection): void
    {
//        $connection->executeStatement("ALTER TABLE `s25_taxjar_log` ADD `order_id` TEXT NULL AFTER `order_number`;");
    }
}
