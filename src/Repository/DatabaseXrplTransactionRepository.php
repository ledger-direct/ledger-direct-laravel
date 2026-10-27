<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Repository;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use JsonException;
use UnexpectedValueException;

/**
 * The core's storage port on Laravel's query builder.
 *
 * The query builder rather than Eloquent models on purpose: no model events
 * fire, no casts reinterpret a column, and the table names stay the core's
 * base names from Xrpl\Schema — the builder prepends the connection's table
 * prefix itself. The core never learns a physical name.
 *
 * The rules this class keeps are the port's docblock, and the core's
 * XrplTransactionRepositoryContractTestCase checks them against SQLite,
 * MySQL and PostgreSQL.
 */
final class DatabaseXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    private readonly SequenceCounter $counter;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->counter = new SequenceCounter($connection, Schema::TABLE_DESTINATION_TAG);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        return $this->counter->next($destinationAccount);
    }

    /**
     * @param string[] $hashes
     * @return string[]
     */
    public function findExistingHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $existing = [];

        // Chunked so a long page cannot exceed a driver's bound-parameter limit.
        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            foreach ($this->transactions()->whereIn('hash', $chunk)->pluck('hash') as $hash) {
                $existing[] = (string) $hash;
            }
        }

        return $existing;
    }

    /**
     * Duplicates by hash are skipped, so a sync that overlaps a previous
     * one, or runs twice at once, stores nothing twice — and *only*
     * duplicates are skipped. This used to be INSERT IGNORE, which on MySQL
     * also downgrades every other data error to a warning: a CTID too long
     * for its column was stored truncated, silently, for weeks. An upsert
     * on the hash that "updates" the hash to itself is a no-op on a
     * duplicate and a hard error on anything else, on every driver.
     *
     * @param XrplTransaction[] $transactions
     */
    public function saveTransactions(array $transactions): void
    {
        if ($transactions === []) {
            return;
        }

        $byHash = [];
        foreach ($transactions as $transaction) {
            $byHash[$transaction->hash] ??= $transaction;   // one row per hash within a batch, too
        }

        $rows = array_map(static fn (XrplTransaction $transaction): array => [
            'network' => $transaction->network,
            'ledger_index' => (int) $transaction->ledgerIndex,
            'hash' => $transaction->hash,
            'tx_ctid' => $transaction->ctid,
            'account' => $transaction->account,
            'destination' => $transaction->destination,
            'destination_tag' => $transaction->destinationTag,
            'date' => $transaction->date,
            'meta' => self::encode($transaction->meta),
            'tx' => self::encode($transaction->tx),
        ], array_values($byHash));

        // A multi-row INSERT assigns the auto-increment keys in row order, so
        // the primary key follows the order the core handed the transactions
        // over — the tie-break findTransactions() relies on.
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->transactions()->upsert($chunk, ['hash'], ['hash']);
        }
    }

    public function findTransactions(string $destination, int $destinationTag): array
    {
        return $this->transactions()
            ->where('destination', $destination)
            ->where('destination_tag', $destinationTag)
            ->orderByDesc('ledger_index')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (object $row): XrplTransaction => self::hydrate($row))
            ->all();
    }

    public function getLastSyncedLedgerIndex(string $destinationAccount, string $network): ?string
    {
        $max = $this->transactions()
            ->where('destination', $destinationAccount)
            ->where('network', $network)
            ->max('ledger_index');

        return $max === null ? null : (string) $max;
    }

    public function truncate(): void
    {
        $this->transactions()->truncate();
    }

    private function transactions(): Builder
    {
        return $this->connection->table(Schema::TABLE_TX);
    }

    private static function hydrate(object $row): XrplTransaction
    {
        return new XrplTransaction(
            network: (string) $row->network,
            ledgerIndex: (string) $row->ledger_index,
            hash: (string) $row->hash,
            ctid: (string) $row->tx_ctid,
            account: (string) $row->account,
            destination: (string) $row->destination,
            destinationTag: $row->destination_tag === null ? null : (int) $row->destination_tag,
            date: (int) $row->date,
            meta: self::decode((string) $row->meta, (string) $row->hash),
            tx: self::decode((string) $row->tx, (string) $row->hash),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json, string $hash): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("Stored transaction {$hash} holds malformed JSON.", previous: $exception);
        }

        return is_array($value) ? $value : [];
    }
}
