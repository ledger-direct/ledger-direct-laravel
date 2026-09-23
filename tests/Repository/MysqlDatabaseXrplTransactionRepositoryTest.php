<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\XrplTransactionRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Illuminate\Database\Connection;

/**
 * The core's repository contract against MySQL, plus the one property only
 * a real server can show: the destination-tag counter stays gapless and
 * duplicate-free while several processes draw from it at once.
 *
 * Skipped unless LEDGER_DIRECT_TEST_MYSQL_HOST is set.
 */
final class MysqlDatabaseXrplTransactionRepositoryTest extends XrplTransactionRepositoryContractTestCase
{
    private const PREFIX = 'app_';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Databases::mysql(self::PREFIX);

        if ($this->connection === null) {
            self::markTestSkipped('LEDGER_DIRECT_TEST_MYSQL_HOST is not set.');
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

    public function testTheCounterIsReadFromTheWritePdoOfASplitConnection(): void
    {
        $config = Databases::mysqlConfig();
        $capsule = new \Illuminate\Database\Capsule\Manager();
        // A "replica" that is a different session: LAST_INSERT_ID() read there would be 0.
        $capsule->addConnection(['read' => ['host' => [$config['host']]], 'write' => ['host' => [$config['host']]], 'prefix' => self::PREFIX] + $config);
        $split = $capsule->getConnection();

        $this->repository();
        $repository = new DatabaseXrplTransactionRepository($split);

        $first = $repository->nextDestinationTagSequence('rSplit');
        self::assertSame($first + 1, $repository->nextDestinationTagSequence('rSplit'));
        self::assertNotSame(0, $first);
    }
}
