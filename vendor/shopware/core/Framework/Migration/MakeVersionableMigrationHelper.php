<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Shopware\Core\Framework\Log\Package;

/**
 * @phpstan-type RelationData array{TABLE_NAME: string, COLUMN_NAME: string, CONSTRAINT_NAME: string, REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string}
 * @phpstan-type ForeignKeyData array{TABLE_NAME: string, COLUMN_NAME: list<string>, REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: list<string>}
 */
#[Package('framework')]
class MakeVersionableMigrationHelper
{
    private const DROP_FOREIGN_KEY = 'ALTER TABLE `%s` DROP FOREIGN KEY `%s`';
    private const DROP_KEY = 'ALTER TABLE `%s` DROP KEY `%s`';
    private const ADD_FOREIGN_KEY = 'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s, `%s`) REFERENCES `%s` (%s, `%s`) ON DELETE %s ON UPDATE CASCADE';
    private const ADD_NEW_COLUMN_WITH_DEFAULT = 'ALTER TABLE `%s` ADD `%s` binary(16) NOT NULL DEFAULT 0x%s AFTER `%s`';
    private const ADD_NEW_COLUMN_NULLABLE = 'ALTER TABLE `%s` ADD `%s` binary(16) NULL AFTER `%s`';
    private const MODIFY_PRIMARY_KEY_IN_MAIN = 'ALTER TABLE `%s` DROP PRIMARY KEY, ADD `%s` binary(16) NOT NULL DEFAULT 0x%s AFTER `%s`, ADD PRIMARY KEY (`%s`, `%s`)';
    private const MODIFY_PRIMARY_KEY_IN_RELATION = 'ALTER TABLE `%s` DROP PRIMARY KEY, ADD PRIMARY KEY (%s, `%s`)';
    private const ADD_KEY = 'ALTER TABLE `%s` ADD INDEX `fk.%s.%s` (%s)';
    private const FIND_RELATIONSHIPS_QUERY = <<<EOD
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
	REFERENCED_TABLE_SCHEMA = '%s'
    AND REFERENCED_TABLE_NAME = '%s';
EOD;

    /**
     * @var AbstractSchemaManager<MySQLPlatform>
     */
    private readonly AbstractSchemaManager $schemaManager;

    public function __construct(
        private readonly Connection $connection
    ) {
        $this->schemaManager = $connection->createSchemaManager();
    }

    /**
     * @return array<string, ForeignKeyData>
     */
    public function getRelationData(string $tableName, string $keyColumn): array
    {
        $data = $this->fetchRelationData($tableName);

        return $this->hydrateForeignKeyData($data, $keyColumn);
    }

    /**
     * @param array<string, ForeignKeyData> $keyStructures
     *
     * @return array<string>
     */
    public function createSql(array $keyStructures, string $tableName, string $newColumnName, string $defaultValue): array
    {
        return array_filter(array_merge(
            $this->createDropKeysPlaybookEntries($keyStructures),
            [$this->createModifyPrimaryKeyQuery($tableName, $newColumnName, $defaultValue)],
            $this->createAddKeysPlaybookEntries($keyStructures, $newColumnName, $tableName),
            $this->createAddColumnsAndKeysPlaybookEntries($newColumnName, $keyStructures, $defaultValue)
        ));
    }

    /**
     * @param array<string, ForeignKeyData> $keyStructures
     *
     * @return list<string>
     */
    private function createDropKeysPlaybookEntries(array $keyStructures): array
    {
        $playbook = [];
        foreach ($keyStructures as $constraintName => $keyStructure) {
            \assert(\is_string($keyStructure['TABLE_NAME']));

            $indexes = $this->schemaManager->listTableIndexes($keyStructure['TABLE_NAME']);

            $playbook[] = \sprintf(self::DROP_FOREIGN_KEY, $keyStructure['TABLE_NAME'], $constraintName);

            if (\array_key_exists(strtolower($constraintName), $indexes)) {
                $playbook[] = \sprintf(self::DROP_KEY, $keyStructure['TABLE_NAME'], $constraintName);
            }
        }

        return $playbook;
    }

    /**
     * @param array<string, ForeignKeyData> $keyStructures
     *
     * @return list<string|null>
     */
    private function createAddColumnsAndKeysPlaybookEntries(string $newColumnName, array $keyStructures, string $default): array
    {
        $playbook = [];
        $duplicateColumnNamePrevention = [];

        foreach ($keyStructures as $constraintName => $keyStructure) {
            $foreignKeyColumnName = $keyStructure['REFERENCED_TABLE_NAME'] . '_' . $newColumnName;

            if (isset($duplicateColumnNamePrevention[$keyStructure['TABLE_NAME']])) {
                $foreignKeyColumnName .= '_' . $duplicateColumnNamePrevention[$keyStructure['TABLE_NAME']];
            }

            $fk = $this->findForeignKeyDefinition($keyStructure);

            $playbook[] = $this->determineAddColumnSql($fk, $keyStructure, $foreignKeyColumnName, $default);
            $playbook[] = $this->determineModifyPrimaryKeySql($keyStructure, $foreignKeyColumnName);
            $playbook[] = $this->getAddForeignKeySql($keyStructure, $constraintName, $foreignKeyColumnName, $newColumnName, $fk);

            if (isset($duplicateColumnNamePrevention[$keyStructure['TABLE_NAME']])) {
                ++$duplicateColumnNamePrevention[$keyStructure['TABLE_NAME']];
            } else {
                $duplicateColumnNamePrevention[$keyStructure['TABLE_NAME']] = 1;
            }
        }

        return $playbook;
    }

    /**
     * @param array<string, ForeignKeyData> $keyStructures
     *
     * @return list<string>
     */
    private function createAddKeysPlaybookEntries(array $keyStructures, string $newColumnName, string $tableName): array
    {
        $playbook = [];
        foreach ($keyStructures as $keyStructure) {
            if ((is_countable($keyStructure['REFERENCED_COLUMN_NAME']) ? \count($keyStructure['REFERENCED_COLUMN_NAME']) : 0) < 2) {
                continue;
            }

            $keyColumns = $keyStructure['REFERENCED_COLUMN_NAME'];
            $keyColumns[] = $newColumnName;
            $uniqueName = implode('_', $keyColumns);

            $playbook[$uniqueName] = \sprintf(self::ADD_KEY, $tableName, $tableName, $uniqueName, $this->implodeColumns($keyColumns));
        }

        return array_values($playbook);
    }

    /**
     * @param array<string> $columns
     */
    private function implodeColumns(array $columns): string
    {
        return implode(',', array_map(fn (string $column): string => '`' . $column . '`', $columns));
    }

    /**
     * @param list<string> $foreignFieldNames
     */
    private function isEqualForeignKey(ForeignKeyConstraint $constraint, string $foreignTable, array $foreignFieldNames): bool
    {
        if ($constraint->getReferencedTableName()->toString() !== $foreignTable) {
            return false;
        }

        $referencedColumns = array_map(fn (UnqualifiedName $column): string => $column->toString(), $constraint->getReferencedColumnNames());

        return \count(array_diff($referencedColumns, $foreignFieldNames)) === 0;
    }

    /**
     * @param list<RelationData> $data
     *
     * @return array<string, ForeignKeyData>
     */
    private function hydrateForeignKeyData(array $data, string $keyColumnName): array
    {
        $hydratedData = $this->mapHydrateForeignKeyData($data);

        return $this->filterHydrateForeignKeyData($hydratedData, $keyColumnName);
    }

    /**
     * @param list<RelationData> $data
     *
     * @return array<string, ForeignKeyData>
     */
    private function mapHydrateForeignKeyData(array $data): array
    {
        $hydratedData = [];

        foreach ($data as $entry) {
            $constraintName = $entry['CONSTRAINT_NAME'];

            if (!isset($hydratedData[$constraintName])) {
                $hydratedData[$constraintName] = [
                    'TABLE_NAME' => $entry['TABLE_NAME'],
                    'COLUMN_NAME' => [$entry['COLUMN_NAME']],
                    'REFERENCED_TABLE_NAME' => $entry['REFERENCED_TABLE_NAME'],
                    'REFERENCED_COLUMN_NAME' => [$entry['REFERENCED_COLUMN_NAME']],
                ];

                continue;
            }

            $hydratedData[$constraintName]['COLUMN_NAME'][] = $entry['COLUMN_NAME'];
            $hydratedData[$constraintName]['REFERENCED_COLUMN_NAME'][] = $entry['REFERENCED_COLUMN_NAME'];
        }

        return $hydratedData;
    }

    /**
     * @param array<string, ForeignKeyData> $hydratedData
     *
     * @return array<string, ForeignKeyData>
     */
    private function filterHydrateForeignKeyData(array $hydratedData, string $keyColumnName): array
    {
        return array_filter($hydratedData, fn (array $entry): bool => \in_array($keyColumnName, $entry['REFERENCED_COLUMN_NAME'], true));
    }

    /**
     * @return list<RelationData>
     */
    private function fetchRelationData(string $tableName): array
    {
        $databaseName = $this->connection->fetchOne('SELECT DATABASE()');
        \assert(\is_string($databaseName));
        $query = \sprintf(self::FIND_RELATIONSHIPS_QUERY, $databaseName, $tableName);

        /* @phpstan-ignore return.type (PHPStan cannot properly determine the array type from the DB) */
        return $this->connection->fetchAllAssociative($query);
    }

    private function createModifyPrimaryKeyQuery(string $tableName, string $newColumnName, string $defaultValue): string
    {
        $pk = $this->schemaManager->listTableIndexes($tableName)['primary'];

        if (\count($pk->getIndexedColumns()) !== 1) {
            throw MigrationException::multiColumnPrimaryKey();
        }
        $pkName = current($pk->getIndexedColumns())->getColumnName()->toString();

        return \sprintf(self::MODIFY_PRIMARY_KEY_IN_MAIN, $tableName, $newColumnName, $defaultValue, $pkName, $pkName, $newColumnName);
    }

    /**
     * @param ForeignKeyData $keyStructure
     */
    private function findForeignKeyDefinition(array $keyStructure): ForeignKeyConstraint
    {
        $fks = $this->schemaManager->listTableForeignKeys($keyStructure['TABLE_NAME']);
        $fk = null;

        foreach ($fks as $fk) {
            if ($this->isEqualForeignKey($fk, $keyStructure['REFERENCED_TABLE_NAME'], $keyStructure['REFERENCED_COLUMN_NAME'])) {
                break;
            }
        }

        if ($fk === null) {
            throw MigrationException::logicError('Unable to find a foreign key that was previously selected');
        }

        return $fk;
    }

    /**
     * @param ForeignKeyData $keyStructure
     */
    private function determineAddColumnSql(ForeignKeyConstraint $fk, array $keyStructure, string $foreignKeyColumnName, string $default): string
    {
        \assert(\is_string($keyStructure['TABLE_NAME']));
        $columnName = end($keyStructure['COLUMN_NAME']);
        \assert(\is_string($columnName));

        $isNullable = $fk->getOnDeleteAction()->value === 'SET NULL';
        if ($isNullable) {
            $addColumnSql = \sprintf(
                self::ADD_NEW_COLUMN_NULLABLE,
                $keyStructure['TABLE_NAME'],
                $foreignKeyColumnName,
                $columnName
            );
        } else {
            $addColumnSql = \sprintf(
                self::ADD_NEW_COLUMN_WITH_DEFAULT,
                $keyStructure['TABLE_NAME'],
                $foreignKeyColumnName,
                $default,
                $columnName
            );
        }

        return $addColumnSql;
    }

    /**
     * @param ForeignKeyData $keyStructure
     */
    private function getAddForeignKeySql(
        array $keyStructure,
        string $constraintName,
        string $foreignKeyColumnName,
        string $newColumnName,
        ForeignKeyConstraint $fk
    ): string {
        \assert(\is_string($keyStructure['TABLE_NAME']));
        \assert(\is_string($keyStructure['REFERENCED_TABLE_NAME']));

        return \sprintf(
            self::ADD_FOREIGN_KEY,
            $keyStructure['TABLE_NAME'],
            $constraintName,
            $this->implodeColumns($keyStructure['COLUMN_NAME']),
            $foreignKeyColumnName,
            $keyStructure['REFERENCED_TABLE_NAME'],
            $this->implodeColumns($keyStructure['REFERENCED_COLUMN_NAME']),
            $newColumnName,
            $fk->getOnDeleteAction()->value ?? 'RESTRICT'
        );
    }

    /**
     * @param ForeignKeyData $keyStructure
     */
    private function determineModifyPrimaryKeySql(array $keyStructure, string $foreignKeyColumnName): ?string
    {
        \assert(\is_string($keyStructure['TABLE_NAME']));
        $indexes = $this->schemaManager->listTableIndexes($keyStructure['TABLE_NAME']);

        $indexedColumns = $indexes['primary']->getIndexedColumns() ?? [];
        $indexedColumns = array_map(fn (IndexedColumn $column): string => $column->getColumnName()->toString(), $indexedColumns);

        if (\count(array_intersect($indexedColumns, $keyStructure['COLUMN_NAME']))) {
            return \sprintf(
                self::MODIFY_PRIMARY_KEY_IN_RELATION,
                $keyStructure['TABLE_NAME'],
                $this->implodeColumns($indexedColumns),
                $foreignKeyColumnName
            );
        }

        return null;
    }
}
