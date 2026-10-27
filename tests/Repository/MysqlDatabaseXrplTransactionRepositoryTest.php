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
use Hardcastle\LedgerDirect\Laravel\Tests\Support\DrawsSequencesConcurrently;
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
    use DrawsSequencesConcurrently;

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

        $this->assertConcurrentDrawsAreGaplessAndUnique(self::PREFIX);
    }

    /**
     * saveTransactions() used to be INSERT IGNORE, under which MySQL stores
     * a 23-character CTID truncated to 16 and says nothing. Only duplicates
     * may be ignored; a value that does not fit must fail.
     */
    public function testAValueThatDoesNotFitItsColumnFailsInsteadOfBeingTruncated(): void
    {
        $repository = $this->repository();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $repository->saveTransactions([self::overlongCtidTransaction()]);
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
