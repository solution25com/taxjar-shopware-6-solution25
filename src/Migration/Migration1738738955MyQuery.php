<?php declare(strict_types=1);

namespace ITGCoTax\Migration;

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
//        $connection->executeStatement("ALTER TABLE `itg_taxjar_log` ADD `order_id` TEXT NULL AFTER `order_number`;");
    }
}
