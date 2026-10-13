<?php

declare(strict_types=1);

namespace solu1TaxJar\Migration;

use DateTime;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Shopware writes setting defaults only for config.xml, and the settings page shows an unsaved switch as off.
 * Saving the default makes the "Store TaxJar calculation data on the order" switch show the value the plugin uses.
 */
class Migration1791540000StoreCalculationInOrderDefault extends MigrationStep
{
    private const CONFIG_KEY = 'solu1TaxJar.setting.storeCalculationInOrder';

    public function getCreationTimestamp(): int
    {
        return 1791540000;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL',
            ['key' => self::CONFIG_KEY]
        );

        if ($exists !== false) {
            return;
        }

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => self::CONFIG_KEY,
            'configuration_value' => json_encode(['_value' => true], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => null,
            'created_at' => (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
