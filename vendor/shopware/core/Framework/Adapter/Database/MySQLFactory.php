<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class MySQLFactory
{
    /**
     * @param array<Middleware> $middlewares
     */
    public static function create(array $middlewares = []): Connection
    {
        $config = (new Configuration())
            ->setMiddlewares($middlewares);

        $url = (string) EnvironmentHelper::getVariable('DATABASE_URL', getenv('DATABASE_URL'));
        if ($url === '') {
            $url = 'mysql://root:shopware@127.0.0.1:3306/shopware';
        }

        $replicaUrl = (string) EnvironmentHelper::getVariable('DATABASE_REPLICA_0_URL');

        $dsnParser = new DsnParser(['mysql' => 'pdo_mysql']);
        $dsnParameters = $dsnParser->parse($url);

        $parameters = array_merge([
            'charset' => 'utf8mb4',
            'driver' => 'pdo_mysql',
            'driverOptions' => [
                \PDO::ATTR_STRINGIFY_FETCHES => true,
                \PDO::ATTR_TIMEOUT => 5, // 5s connection timeout
            ],
        ], $dsnParameters); // adding parameters that are not in the DSN

        $initCommands = [
            'SET @@session.time_zone = \'+00:00\'',
            'SET @@group_concat_max_len = CAST(IF(@@group_concat_max_len > 320000, @@group_concat_max_len, 320000) AS UNSIGNED)',
            'SET sql_mode=(SELECT REPLACE(@@sql_mode,\'ONLY_FULL_GROUP_BY\',\'\'))',
        ];

        $parameters['driverOptions'][\PDO::MYSQL_ATTR_INIT_COMMAND] = \implode(';', $initCommands);

        if ($sslCa = EnvironmentHelper::getVariable('DATABASE_SSL_CA')) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }

        if ($sslCert = EnvironmentHelper::getVariable('DATABASE_SSL_CERT')) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_CERT] = $sslCert;
        }

        if ($sslCertKey = EnvironmentHelper::getVariable('DATABASE_SSL_KEY')) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_KEY] = $sslCertKey;
        }

        if (EnvironmentHelper::getVariable('DATABASE_SSL_DONT_VERIFY_SERVER_CERT')) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        if (EnvironmentHelper::getVariable('DATABASE_PERSISTENT_CONNECTION')) {
            $parameters['driverOptions'][\PDO::ATTR_PERSISTENT] = true;
        }

        if (EnvironmentHelper::getVariable('DATABASE_PROTOCOL_COMPRESSION')) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_COMPRESS] = true;
        }

        if ($replicaUrl) {
            $parameters['wrapperClass'] = PrimaryReadReplicaConnection::class;

            // Primary connection should use parameters from the main url
            $parameters['primary'] = array_merge([
                'charset' => $parameters['charset'],
                'driverOptions' => $parameters['driverOptions'],
            ], $dsnParameters);

            $parameters['replica'] = [];

            for ($i = 0; $replicaUrl = (string) EnvironmentHelper::getVariable('DATABASE_REPLICA_' . $i . '_URL'); ++$i) {
                $replicaParams = $dsnParser->parse($replicaUrl);

                $replicaParams = array_merge([
                    'charset' => $parameters['charset'],
                    'driverOptions' => $parameters['driverOptions'],
                ], $replicaParams);

                $parameters['replica'][$i] = $replicaParams;
            }
        }

        return DriverManager::getConnection($parameters, $config);
    }
}
