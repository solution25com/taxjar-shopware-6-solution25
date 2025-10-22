<?php declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1697049600AddTaxJarCustomFields extends MigrationStep
{
    /**
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1697049600; // 2023-10-12
    }

    /**
     * @param Connection $connection
     * @return void
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        if (!$this->isCustomFieldPresent($connection)) {
            $customFieldSetId = $this->createCustomFieldSet($connection);
            $this->createCustomFieldTaxJarCustomerId($connection, $customFieldSetId);
            $this->createCustomFieldExemptionType($connection, $customFieldSetId);
            $this->createCustomFieldExemptRegions($connection, $customFieldSetId);
            $this->createFieldRelation($connection, $customFieldSetId);
        }
    }

    /**
     * @param Connection $connection
     * @return void
     */
    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM custom_field WHERE name IN ("taxjar_customer_id", "taxjar_exemption_type", "exempt_regions")');
        $connection->executeStatement('DELETE FROM custom_field_set WHERE name = "taxjar_customer_exemptions"');
    }

    /**
     * @param Connection $connection
     * @return bool
     */
    private function isCustomFieldPresent(Connection $connection): bool
    {
        $customFieldInfo = $connection->fetchAllKeyValue(
            'SELECT * FROM custom_field WHERE name IN ("taxjar_customer_id", "taxjar_exemption_type", "exempt_regions")',
            [],
            []
        );
        if (!empty($customFieldInfo)) {
            return true;
        }
        return false;
    }

    /**
     * @param Connection $connection
     * @return string
     * @throws Exception
     */
    private function createCustomFieldSet(Connection $connection): string
    {
        $customFieldSetId = Uuid::randomBytes();
        $customFieldSet = [
            'id' => $customFieldSetId,
            'name' => 'taxjar_customer_exemptions',
            'config' => json_encode([
                'label' => [
                    'en-GB' => 'TaxJar Customer Exemptions',
                    'en-US' => 'TaxJar Customer Exemptions',
                ],
                'translated' => true,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $connection->insert('custom_field_set', $customFieldSet);
        return Uuid::fromBytesToHex($customFieldSetId);
    }

    /**
     * @param Connection $connection
     * @param string $customFieldSetId
     * @return string
     * @throws Exception
     */
    private function createCustomFieldTaxJarCustomerId(Connection $connection, string $customFieldSetId): string
    {
        $customFieldId = Uuid::randomBytes();
        $customField = [
            'id' => $customFieldId,
            'set_id' => Uuid::fromHexToBytes($customFieldSetId),
            'name' => 'taxjar_customer_id',
            'type' => 'text',
            'config' => json_encode([
                'type' => 'text',
                'label' => [
                    'en-GB' => 'TaxJar Customer ID',
                    'en-US' => 'TaxJar Customer ID',
                ],
                'placeholder' => [
                    'en-GB' => 'Enter TaxJar Customer ID',
                    'en-US' => 'Enter TaxJar Customer ID',
                ],
                'helpText' => [
                    'en-GB' => 'Leave blank if new exemption',
                    'en-US' => 'Leave blank if new exemption',
                ],
                'componentName' => 'sw-field',
                'customFieldType' => 'text',
                'customFieldPosition' => 1,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $connection->insert('custom_field', $customField);
        return Uuid::fromBytesToHex($customFieldId);
    }

    /**
     * @param Connection $connection
     * @param string $customFieldSetId
     * @return string
     * @throws Exception
     */
    private function createCustomFieldExemptionType(Connection $connection, string $customFieldSetId): string
    {
        $customFieldId = Uuid::randomBytes();
        $customField = [
            'id' => $customFieldId,
            'set_id' => Uuid::fromHexToBytes($customFieldSetId),
            'name' => 'taxjar_exemption_type',
            'type' => 'select',
            'config' => json_encode([
                'type' => 'select',
                'label' => [
                    'en-GB' => 'TaxJar Exemption Type',
                    'en-US' => 'TaxJar Exemption Type',
                ],
                'options' => [
                    ['value' => 'wholesale', 'label' => ['en-GB' => 'Wholesale', 'en-US' => 'Wholesale']],
                    ['value' => 'government', 'label' => ['en-GB' => 'Government', 'en-US' => 'Government']],
                    ['value' => 'other', 'label' => ['en-GB' => 'Other', 'en-US' => 'Other']],
                    ['value' => 'non_exempt', 'label' => ['en-GB' => 'Non-Exempt', 'en-US' => 'Non-Exempt']],
                ],
                'componentName' => 'sw-single-select',
                'customFieldType' => 'select',
                'customFieldPosition' => 2,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $connection->insert('custom_field', $customField);
        return Uuid::fromBytesToHex($customFieldId);
    }

    /**
     * @param Connection $connection
     * @param string $customFieldSetId
     * @return string
     * @throws Exception
     */
    private function createCustomFieldExemptRegions(Connection $connection, string $customFieldSetId): string
    {
        $customFieldId = Uuid::randomBytes();
        $customField = [
            'id' => $customFieldId,
            'set_id' => Uuid::fromHexToBytes($customFieldSetId),
            'name' => 'exempt_regions',
            'type' => 'select',
            'config' => json_encode([
                'type' => 'select',
                'label' => [
                    'en-GB' => 'Exempt Regions (US States)',
                    'en-US' => 'Exempt Regions (US States)',
                ],
                'multiple' => true,
                'componentName' => 'sw-multi-select',
                'options' => $this->getUsStateOptions(),
                'customFieldType' => 'select',
                'customFieldPosition' => 3,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $connection->insert('custom_field', $customField);
        return Uuid::fromBytesToHex($customFieldId);
    }

    /**
     * @param Connection $connection
     * @param string $customFieldSetId
     * @return string
     * @throws Exception
     */
    private function createFieldRelation(Connection $connection, string $customFieldSetId): string
    {
        $customFieldRelationId = Uuid::randomBytes();
        $customFieldSet = [
            'id' => $customFieldRelationId,
            'set_id' => Uuid::fromHexToBytes($customFieldSetId),
            'entity_name' => 'customer',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $connection->insert('custom_field_set_relation', $customFieldSet);
        return Uuid::fromBytesToHex($customFieldRelationId);
    }

    /**
     * @return array<int, array{value: string, label: array{en-GB: string, en-US: string}}>
     */
    private function getUsStateOptions(): array
    {
        $states = [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
            'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
            'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
            'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
            'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
        ];

        /** @var array<int, array{value: string, label: array{en-GB: string, en-US: string}}> $options */
        $options = [];
        foreach ($states as $state) {
            $options[] = [
                'value' => $state,
                'label' => ['en-GB' => $state, 'en-US' => $state],
            ];
        }

        return $options;
    }
}
