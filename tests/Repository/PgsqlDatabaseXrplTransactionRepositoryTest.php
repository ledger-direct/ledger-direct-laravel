<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\XrplTransactionRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * The core's repository contract against PostgreSQL — the database that
 * could not even create the transaction table before core 0.9 renamed the
 * `ctid` column — plus the portable counter's atomicity across processes
 * (a locked transaction, not LAST_INSERT_ID()).
 *
 * Skipped unless LEDGER_DIRECT_TEST_PGSQL_HOST is set.
 */
final class PgsqlDatabaseXrplTransactionRepositoryTest extends XrplTransactionRepositoryContractTestCase
{
    private const PREFIX = 'app_';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Databases::mysqlConfig() !== null) {
            // draw-sequences.php takes the configured server, MySQL first; the
            // multi-process test below would then measure the wrong database.
            self::markTestSkipped('LEDGER_DIRECT_TEST_MYSQL_HOST is set as well; run the PostgreSQL tests on their own.');
        }

        $this->connection = Databases::pgsql(self::PREFIX);

        if ($this->connection === null) {
            self::markTestSkipped('LEDGER_DIRECT_TEST_PGSQL_HOST is not set.');
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;

        parent::tearDown();
    }

    protected function repository(): XrplTransactionRepositoryInterface
    {
        $schema = $this->connection->getSchemaBuilder();

        foreach (array_keys(Schema::tables()) as $table) {
            $schema->dropIfExists($table);
        }

        LedgerDirectTables::create($schema);

        return new DatabaseXrplTransactionRepository($this->connection);
    }

    public function testTheCounterIsAtomicAcrossProcesses(): void
    {
        $this->repository();

        $workers = 4;
        $callsPerWorker = 50;
        $processes = [];

        for ($i = 0; $i < $workers; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, __DIR__ . '/../Fixtures/draw-sequences.php', self::PREFIX, 'rConcurrent', (string) $callsPerWorker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }

        $sequences = [];
        foreach ($processes as $i => $process) {
            $output = stream_get_contents($pipes[$i][1]);
            $errors = stream_get_contents($pipes[$i][2]);
            self::assertSame(0, proc_close($process), "worker {$i} failed: {$errors}");

            foreach (array_filter(explode("\n", (string) $output)) as $line) {
                $sequences[] = (int) $line;
            }
        }

        self::assertCount($workers * $callsPerWorker, $sequences);
        self::assertCount(count($sequences), array_unique($sequences), 'no sequence is handed out twice');

        sort($sequences);
        self::assertSame(range($sequences[0], $sequences[0] + count($sequences) - 1), $sequences, 'and none is skipped');
    }

    /** PostgreSQL enforces the column width itself; the upsert must not hide that. */
    public function testAValueThatDoesNotFitItsColumnFailsInsteadOfBeingTruncated(): void
    {
        $repository = $this->repository();

        $this->expectException(QueryException::class);
        $repository->saveTransactions([self::overlongCtidTransaction()]);
    }

    /** The unsigned range on a driver without unsigned types: widened to bigint. */
    public function testTheFullDestinationTagAndCounterRangeFits(): void
    {
        $repository = $this->repository();
        $repository->saveTransactions([$this->transaction('HASH_MAX', '99000000000', destinationTag: 4_294_967_295)]);

        self::assertCount(1, $repository->findTransactions('rDest', 4_294_967_295));
    }

    private static function overlongCtidTransaction(): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '1',
            hash: 'HASH_LONG',
            ctid: 'C0000000000000000000000',   // 23 characters, the column holds 16
            account: 'rSender',
            destination: 'rDest',
            destinationTag: 1,
            date: 800000000,
            meta: [],
            tx: [],
        );
    }
}
