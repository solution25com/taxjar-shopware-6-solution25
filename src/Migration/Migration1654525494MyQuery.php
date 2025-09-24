<?php declare(strict_types=1);

namespace solu1TaxJar\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1654525494MyQuery extends MigrationStep
{
    /**
     * @return int
     */
    public function getCreationTimestamp(): int
    {
        return 1654525494;
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
            $this->createCustomField($connection, $customFieldSetId);
            $this->createFieldRelation($connection, $customFieldSetId);
        }
    }

    /**
     * @param Connection $connection
     * @return void
     */
    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM custom_field WHERE name = "product_tax_code_value"');
        $connection->executeStatement('DELETE FROM custom_field_set WHERE name = "product_tax_code"');

    }

    /**
     * @param Connection $connection
     * @return bool
     */
    private function isCustomFieldPresent(Connection $connection)
    {
        $customFieldInfo = $connection->fetchAllKeyValue(
            'SELECT * FROM custom_field WHERE name = "product_tax_code_value"',
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
    private function createCustomFieldSet(Connection $connection)
    {
        $customFieldSetId = Uuid::randomBytes();
        $customFieldSet = [
            'name' => 'product_tax_code',
            'active' => 1,
            'position' => 20,
            'config' => json_encode([
                'label' => [
                    'de-DE' => 'Tax Calculation Setting',
                    'en-GB' => 'Tax Calculation Setting',
                ],
                'translated' => true,
            ]),
            'id' => $customFieldSetId,
            'created_at' => date('Y-m-d h:i:s')
        ];
        $connection->insert('custom_field_set', $customFieldSet);
        return $customFieldSetId;
    }


    /**
     * @param Connection $connection
     * @return string
     * @throws Exception
     */
    private function createCustomField(Connection $connection, $customerFieldSetId)
    {
        $customFieldId = Uuid::randomBytes();
        $customFieldSet = [
            'name' => 'product_tax_code_value',
            'set_id' => $customerFieldSetId,
            'type' => 'text',
            'active' => 1,
            'config' => json_encode([
                'type' => 'text',
                'label' => [
                    'en-GB' => 'Product Tax Code',
                ],
                'helpText' => [
                    'en-GB' => NULL,
                ],
                'placeholder' => [
                    'en-GB' => 'Product Tax Code',
                ],
                'componentName' => 'sw-field',
                'customFieldType' => 'text',
                'customFieldPosition' => 1,
            ]),
            'id' => $customFieldId,
            'created_at' => date('Y-m-d h:i:s')
        ];
        $connection->insert('custom_field', $customFieldSet);
        return $customFieldId;
    }

    /**
     * @param Connection $connection
     * @param $customerFieldSetId
     * @return string
     * @throws Exception
     */
    private function createFieldRelation(Connection $connection, $customerFieldSetId)
    {
        $customFieldRelationId = Uuid::randomBytes();
        $customFieldSet = [
            'set_id' => $customerFieldSetId,
            'entity_name' => 'product',
            'id' => $customFieldRelationId,
            'created_at' => date('Y-m-d h:i:s')
        ];
        $connection->insert('custom_field_set_relation', $customFieldSet);
        return $customFieldRelationId;
    }
}
