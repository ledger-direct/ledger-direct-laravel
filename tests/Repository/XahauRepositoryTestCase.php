<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\XrplTransactionRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The core's repository contract on Xahau's tables, plus the rule that
 * makes them tables of their own: nothing is shared with the XRP Ledger, not
 * even for an account that exists on both (INVARIANTS.md, "Xahau", Tables).
 * Run once per database by the subclasses.
 */
abstract class XahauRepositoryTestCase extends XrplTransactionRepositoryContractTestCase
{
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

    protected function repository(): XrplTransactionRepositoryInterface
    {
        $schema = $this->connection->getSchemaBuilder();

        foreach (array_keys(Schema::tables('XAHAU') + Schema::tables()) as $table) {
            $schema->dropIfExists($table);
        }

        LedgerDirectTables::createXahau($schema);
        LedgerDirectTables::create($schema);

        return new DatabaseXrplTransactionRepository($this->connection, ChainCatalog::XAHAU);
    }

    public function testAChainWithoutXrplProtocolTablesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No XRPL-protocol tables for the chain 'STELLAR'");

        new DatabaseXrplTransactionRepository($this->connection, ChainCatalog::STELLAR);
    }

    public function testNothingIsSharedWithTheXrpLedger(): void
    {
        $xahau = $this->repository();
        $xrpl = new DatabaseXrplTransactionRepository($this->connection);

        $xahau->saveTransactions([$this->transaction('HASH_XAHAU', '500')]);

        self::assertSame([], $xrpl->findTransactions('rDest', 1), 'a Xahau payment never reaches an XRPL order');
        self::assertNull($xrpl->getLastSyncedLedgerIndex('rDest', 'testnet'));
        self::assertSame([], $xrpl->findExistingHashes(['HASH_XAHAU']));

        $xahauFirst = $xahau->nextDestinationTagSequence('rDest');
        $xrplFirst = $xrpl->nextDestinationTagSequence('rDest');

        self::assertSame($xahauFirst + 1, $xahau->nextDestinationTagSequence('rDest'));
        self::assertSame($xrplFirst + 1, $xrpl->nextDestinationTagSequence('rDest'), 'one counter per chain');
    }
}
