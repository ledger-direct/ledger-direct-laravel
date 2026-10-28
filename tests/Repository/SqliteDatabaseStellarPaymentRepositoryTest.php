<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;

/** The Stellar repository on SQLite in memory, with a table prefix. */
final class SqliteDatabaseStellarPaymentRepositoryTest extends StellarRepositoryTestCase
{
    protected function connect(): ?Connection
    {
        return Databases::sqlite('app_');
    }
}
