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
 * MySQL is opt-in: set LEDGER_DIRECT_TEST_MYSQL_HOST (and optionally _PORT,
 * _DATABASE, _USERNAME, _PASSWORD). Locally `docker compose up -d mysql`
 * provides one on port 33306.
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
