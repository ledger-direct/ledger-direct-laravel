<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\Schema;
use Hardcastle\LedgerDirect\Core\Testing\StellarPaymentRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseStellarPaymentRepository;
use Illuminate\Database\Connection;

/**
 * The core's Stellar repository contract plus what only a real column type
 * can get wrong, run once per database by the subclasses. Each repository()
 * starts from freshly created tables, counter included.
 */
abstract class StellarRepositoryTestCase extends StellarPaymentRepositoryContractTestCase
{
    /** Largest uint64 — a stranger's memo or muxed id may be any of them. */
    protected const MAX_UINT64 = '18446744073709551615';

    private ?Connection $connection = null;

    /** A connection to the database under test, or null to skip. */
    abstract protected function connect(): ?Connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->connect();
    }

    protected function tearDown(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;

        parent::tearDown();
    }

    protected function repository(): StellarPaymentRepositoryInterface
    {
        $schema = $this->connection->getSchemaBuilder();

        foreach (array_keys(Schema::tables()) as $table) {
            $schema->dropIfExists($table);
        }

        LedgerDirectTables::createStellar($schema);

        return new DatabaseStellarPaymentRepository($this->connection);
    }

    /**
     * A foreign payment with a memo id beyond PHP's and SQL's signed 64-bit
     * range must be stored exactly — a column that rejected it would fail
     * the sync batch, and with it the merchant's own payments.
     */
    public function testAForeignMemoIdUpToUint64IsStoredExactly(): void
    {
        $repository = $this->repository();
        $repository->savePayments([
            $this->payment('HASH_FOREIGN', '4294967297', memoId: self::MAX_UINT64),
            $this->payment('HASH_OURS', '4294967298', memoId: '123456'),
        ]);

        $found = $repository->findPayments('GDEST', self::MAX_UINT64);

        self::assertCount(1, $found);
        self::assertSame(self::MAX_UINT64, $found[0]->memoId);
        self::assertSame(['HASH_OURS'], array_map(static fn ($p): string => $p->hash, $repository->findPayments('GDEST', '123456')));
    }

    public function testAPaymentWithoutAMemoIsStoredButNeverFound(): void
    {
        $repository = $this->repository();
        $repository->savePayments([$this->payment('HASH_NO_MEMO', '4294967297', memoId: null)]);

        self::assertSame(['HASH_NO_MEMO:1'], $repository->findExistingKeys(['HASH_NO_MEMO:1']));
        self::assertSame([], $repository->findPayments('GDEST', '0'));
    }

    public function testSavingTheSameOperationTwiceStoresItOnce(): void
    {
        $repository = $this->repository();
        $payment = $this->payment('HASH_A', '4294967297');

        $repository->savePayments([$payment, $payment]);
        $repository->savePayments([$payment]);

        self::assertCount(1, $repository->findPayments('GDEST', '123456'));
    }

    public function testTruncateKeepsTheMemoCounter(): void
    {
        $repository = $this->repository();
        $first = $repository->nextMemoIdSequence('GDEST');

        $repository->truncate();

        self::assertSame($first + 1, $repository->nextMemoIdSequence('GDEST'));
    }

    protected function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('No connection.');
    }
}
