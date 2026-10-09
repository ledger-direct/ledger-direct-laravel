<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PDO;

/**
 * Connections for the tests that run outside a Laravel application — the
 * core's contract test extends PHPUnit's TestCase, not Testbench's.
 *
 * MySQL and PostgreSQL are opt-in: set LEDGER_DIRECT_TEST_MYSQL_HOST or
 * LEDGER_DIRECT_TEST_PGSQL_HOST (and optionally _PORT, _DATABASE, _USERNAME,
 * _PASSWORD). Locally `docker compose up -d mysql postgres` provides them on
 * 33306 and 35432. When both are set, the Testbench suite takes MySQL.
 */
final class Databases
{
    public static function sqlite(string $prefix = ''): Connection
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => $prefix,
            'foreign_key_constraints' => true,
        ]);

        return $capsule->getConnection();
    }

    public static function mysql(string $prefix = ''): ?Connection
    {
        $config = self::mysqlConfig();

        if ($config === null) {
            return null;
        }

        $capsule = new Manager();
        $capsule->addConnection($config + ['prefix' => $prefix]);

        return $capsule->getConnection();
    }

    public static function pgsql(string $prefix = ''): ?Connection
    {
        $config = self::pgsqlConfig();

        if ($config === null) {
            return null;
        }

        $capsule = new Manager();
        $capsule->addConnection($config + ['prefix' => $prefix]);

        return $capsule->getConnection();
    }

    /** The configured server, MySQL before PostgreSQL, or null when there is none. */
    public static function server(string $prefix = ''): ?Connection
    {
        return self::mysql($prefix) ?? self::pgsql($prefix);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function serverConfig(): ?array
    {
        return self::mysqlConfig() ?? self::pgsqlConfig();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pgsqlConfig(): ?array
    {
        $host = getenv('LEDGER_DIRECT_TEST_PGSQL_HOST');

        if ($host === false || $host === '') {
            return null;
        }

        return [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => (int) (getenv('LEDGER_DIRECT_TEST_PGSQL_PORT') ?: 5432),
            'database' => getenv('LEDGER_DIRECT_TEST_PGSQL_DATABASE') ?: 'ledger_direct',
            'username' => getenv('LEDGER_DIRECT_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('LEDGER_DIRECT_TEST_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function mysqlConfig(): ?array
    {
        $host = getenv('LEDGER_DIRECT_TEST_MYSQL_HOST');

        if ($host === false || $host === '') {
            return null;
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => (int) (getenv('LEDGER_DIRECT_TEST_MYSQL_PORT') ?: 3306),
            'database' => getenv('LEDGER_DIRECT_TEST_MYSQL_DATABASE') ?: 'ledger_direct',
            'username' => getenv('LEDGER_DIRECT_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('LEDGER_DIRECT_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ];
    }
}
