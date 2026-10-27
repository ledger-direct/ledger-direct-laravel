<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\XrplTransactionRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;

/**
 * The core's repository contract against SQLite in memory — with a table
 * prefix, so the tests also prove that the builder, not this package,
 * applies it. Every repository() is a fresh database.
 *
 * SQLite cannot prove the counter's atomicity (lockForUpdate() takes no
 * lock there); the MySQL variant does.
 */
final class SqliteDatabaseXrplTransactionRepositoryTest extends XrplTransactionRepositoryContractTestCase
{
    protected function repository(): XrplTransactionRepositoryInterface
    {
        $connection = Databases::sqlite('app_');
        LedgerDirectTables::create($connection->getSchemaBuilder());

        return new DatabaseXrplTransactionRepository($connection);
    }
}
