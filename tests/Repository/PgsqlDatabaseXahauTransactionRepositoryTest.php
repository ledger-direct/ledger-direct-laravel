<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;

/**
 * The repository on Xahau's tables, on PostgreSQL. Skipped unless
 * LEDGER_DIRECT_TEST_PGSQL_HOST is set.
 */
final class PgsqlDatabaseXahauTransactionRepositoryTest extends XahauRepositoryTestCase
{
    protected function connect(): ?Connection
    {
        $connection = Databases::pgsql('app_');

        if ($connection === null) {
            self::markTestSkipped('LEDGER_DIRECT_TEST_PGSQL_HOST is not set.');
        }

        return $connection;
    }
}
