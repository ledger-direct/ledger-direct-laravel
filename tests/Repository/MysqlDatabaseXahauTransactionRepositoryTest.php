<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;

/**
 * The repository on Xahau's tables, on MySQL. Skipped unless
 * LEDGER_DIRECT_TEST_MYSQL_HOST is set.
 */
final class MysqlDatabaseXahauTransactionRepositoryTest extends XahauRepositoryTestCase
{
    protected function connect(): ?Connection
    {
        $connection = Databases::mysql('app_');

        if ($connection === null) {
            self::markTestSkipped('LEDGER_DIRECT_TEST_MYSQL_HOST is not set.');
        }

        return $connection;
    }
}
