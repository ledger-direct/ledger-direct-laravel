<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Database;

use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LedgerDirectTablesTest extends TestCase
{
    /**
     * Every PostgreSQL table has a system column named ctid; the core's
     * transaction table has one too. Say so before the database does.
     */
    public function testPostgresIsRefusedWithAReason(): void
    {
        $connection = new PostgresConnection(
            static fn () => throw new \LogicException('must not connect'),
            'ledger_direct',
            '',
            ['driver' => 'pgsql', 'name' => 'pgsql'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserves as a system column');

        LedgerDirectTables::create($connection->getSchemaBuilder());
    }
}
