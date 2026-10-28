<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * The Stellar repository on MySQL. Skipped unless
 * LEDGER_DIRECT_TEST_MYSQL_HOST is set.
 */
final class MysqlDatabaseStellarPaymentRepositoryTest extends StellarRepositoryTestCase
{
    protected function connect(): ?Connection
    {
        $connection = Databases::mysql('app_');

        if ($connection === null) {
            self::markTestSkipped('LEDGER_DIRECT_TEST_MYSQL_HOST is not set.');
        }

        return $connection;
    }

    /** Only duplicates are ignored; a value that does not fit must fail, not be truncated. */
    public function testAValueThatDoesNotFitItsColumnFailsInsteadOfBeingTruncated(): void
    {
        $repository = $this->repository();

        $this->expectException(QueryException::class);
        $repository->savePayments([$this->payment(str_repeat('H', 65), '4294967297')]);
    }
}
