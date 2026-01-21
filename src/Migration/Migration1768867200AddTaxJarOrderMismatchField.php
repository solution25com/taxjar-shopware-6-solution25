<?php

declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1768867200AddTaxJarOrderMismatchField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1768867200;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $setName = 'taxjar_order_flags';
        $fieldName = 'taxjar_address_mismatch';

        $existing = $connection->fetchOne('SELECT id FROM custom_field WHERE name = :name', ['name' => $fieldName]);
        if ($existing) {
            return;
        }

        $setId = $connection->fetchOne('SELECT id FROM custom_field_set WHERE name = :name', ['name' => $setName]);
        if (!$setId) {
            $setIdBytes = Uuid::randomBytes();
            $connection->insert('custom_field_set', [
                'id' => $setIdBytes,
                'name' => $setName,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'TaxJar Order Flags',
                        'en-US' => 'TaxJar Order Flags',
                    ],
                    'translated' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $setId = $setIdBytes;

            $connection->insert('custom_field_set_relation', [
                'id' => Uuid::randomBytes(),
                'set_id' => $setIdBytes,
                'entity_name' => 'order',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $setIdBytes = is_string($setId) && strlen($setId) === 32 ? Uuid::fromHexToBytes($setId) : $setId;

        $connection->insert('custom_field', [
            'id' => Uuid::randomBytes(),
            'set_id' => $setIdBytes,
            'name' => $fieldName,
            'type' => 'bool',
            'config' => json_encode([
                'type' => 'checkbox',
                'label' => [
                    'en-GB' => 'TaxJar address mismatch (Zip/State)',
                    'en-US' => 'TaxJar address mismatch (Zip/State)',
                ],
                'helpText' => [
                    'en-GB' => 'Flagged when TaxJar reports a zip/state mismatch and tax could not be reliably calculated.',
                    'en-US' => 'Flagged when TaxJar reports a zip/state mismatch and tax could not be reliably calculated.',
                ],
                'componentName' => 'sw-field',
                'customFieldType' => 'checkbox',
                'customFieldPosition' => 1,
            ], JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM custom_field WHERE name = "taxjar_address_mismatch"');
    }
}

