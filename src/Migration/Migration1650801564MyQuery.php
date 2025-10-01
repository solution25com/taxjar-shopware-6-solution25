<?php

declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1650801564MyQuery extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1650801564;
    }

    public function update(Connection $connection): void
    {
        $query = /** @lang SQL */ <<<SQL
            CREATE TABLE IF NOT EXISTS `s25_tax_service_provider` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(512) DEFAULT NULL,
                `base_class` TEXT,
                `created_at` DATETIME(3) NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($query);
        $connection->executeStatement('TRUNCATE TABLE `s25_tax_service_provider`');
        $this->addTaxjarServiceProvider($connection);
    }

    /**
     * @throws Exception
     */
    private function addTaxjarServiceProvider(Connection $connection): void
    {
        $taxJarDataEntry = $this->getTaxJarData();
        $taxJarDataEntry['id'] = Uuid::randomBytes();
        $taxJarDataEntry['created_at'] = (new \DateTime())->format('Y-m-d H:i:s.v');

        $connection->insert('s25_tax_service_provider', $taxJarDataEntry);
    }

    /**
     * @return array{name: string, base_class: string}
     */
    private function getTaxJarData(): array
    {
        return [
            'name' => 'TaxJar',
            'base_class' => '\solu1TaxJar\Core\TaxJar\Calculator',
        ];
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `s25_tax_service_provider`');
    }
}