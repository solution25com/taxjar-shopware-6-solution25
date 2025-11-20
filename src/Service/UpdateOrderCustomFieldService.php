<?php declare(strict_types=1);

namespace solu1TaxJar\Service;

use Doctrine\DBAL\Connection;

class UpdateOrderCustomFieldService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function run(): void
    {
        $sql = <<<SQL
            UPDATE `order` o
            JOIN (
                SELECT DISTINCT order_id
                FROM order_line_item
                WHERE JSON_EXTRACT(payload, '$.taxJarRate') IS NOT NULL
            ) x ON x.order_id = o.id
            SET o.custom_fields = JSON_SET(
                COALESCE(o.custom_fields, JSON_OBJECT()),
                '$.taxJar',
                TRUE
            );
        SQL;

        $res = $this->connection->executeStatement($sql);

        $insert = <<<SQL
            INSERT INTO notification
                (id, status, message, admin_only, created_at)
            VALUES
                (
                    UNHEX(REPLACE(UUID(), '-', '')),
                    'success',
                    CONCAT('TaxJar order custom field updated successfully: ', :count),
                    0,
                    NOW()
                );
        SQL;

        $this->connection->executeStatement($insert, ['count' => $res]);
    }
}
