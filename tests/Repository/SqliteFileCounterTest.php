<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Repository;

use Hardcastle\LedgerDirect\Laravel\Database\LedgerDirectTables;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\Databases;
use Hardcastle\LedgerDirect\Laravel\Tests\Support\DrawsSequencesConcurrently;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SQLite is the default database of a new Laravel app, and a file — unlike
 * the :memory: database of the contract test — is shared by every request.
 * A counter that reads before it writes makes SQLite upgrade a read lock to
 * a write lock, which fails at once with "database is locked" when another
 * request got there first; the busy timeout does not help with that. Two
 * customers checking out at the same moment must both get a tag.
 */
final class SqliteFileCounterTest extends TestCase
{
    use DrawsSequencesConcurrently;

    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ledger-direct-') . '.sqlite';
        touch($this->path);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm', $this->path . '-journal'] as $file) {
            @unlink($file);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function journalModes(): iterable
    {
        yield 'rollback journal' => ['delete'];
        yield 'WAL' => ['wal'];
    }

    #[DataProvider('journalModes')]
    public function testConcurrentDrawsAllSucceed(string $journalMode): void
    {
        LedgerDirectTables::create(Databases::sqliteFile($this->path, $journalMode)->getSchemaBuilder());

        $this->assertConcurrentDrawsAreGaplessAndUnique("sqlite:{$journalMode}:{$this->path}", workers: 6, callsPerWorker: 100);
    }
}
