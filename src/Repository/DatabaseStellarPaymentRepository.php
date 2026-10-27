<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Repository;

use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\Schema;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use JsonException;
use UnexpectedValueException;

/**
 * The core's Stellar storage port on Laravel's query builder — the sibling of
 * DatabaseXrplTransactionRepository, on the same terms: no Eloquent, the
 * core's base table names, the connection's prefix applied by the builder.
 *
 * One row is a payment *operation*, identified by (hash, op_index); the
 * operation id (`payment_id`) orders rows and is the sync cursor but repeats
 * after a testnet reset, so it is not the key (INVARIANTS.md, "Stellar").
 *
 * Identifiers are strings end to end, as the core requires: `memo_id` and
 * `payment_id` are bound and read back as decimal strings, never cast to
 * int — a foreign memo id may exceed PHP's signed 64-bit range.
 *
 * The core's StellarPaymentRepositoryContractTestCase checks this class
 * against SQLite, MySQL and PostgreSQL.
 */
final class DatabaseStellarPaymentRepository implements StellarPaymentRepositoryInterface
{
    private readonly SequenceCounter $counter;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->counter = new SequenceCounter($connection, Schema::TABLE_MEMO);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function nextMemoIdSequence(string $destinationAccount): int
    {
        return $this->counter->next($destinationAccount);
    }

    /**
     * Looked up by hash — the leading column of the unique (hash, op_index)
     * index — and matched to the full key here, which keeps the query a
     * plain IN list on every driver.
     *
     * @param list<string> $keys "{hash}:{op_index}"
     * @return list<string>
     */
    public function findExistingKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $wanted = array_fill_keys($keys, true);
        $hashes = array_values(array_unique(array_map(
            static fn (string $key): string => substr($key, 0, (int) strrpos($key, ':')),
            $keys,
        )));

        $existing = [];

        // Chunked so a long page cannot exceed a driver's bound-parameter limit.
        foreach (array_chunk($hashes, 500) as $chunk) {
            foreach ($this->payments()->whereIn('hash', $chunk)->get(['hash', 'op_index']) as $row) {
                $key = $row->hash . ':' . $row->op_index;

                if (isset($wanted[$key])) {
                    $existing[] = $key;
                }
            }
        }

        return $existing;
    }

    /**
     * Duplicates by (hash, op_index) are skipped and only those: an upsert
     * that "updates" the hash to itself is a no-op on a duplicate and a hard
     * error on every other data error, on every driver — the lesson of the
     * XRPL repository's former INSERT IGNORE.
     *
     * @param list<StellarPayment> $payments
     */
    public function savePayments(array $payments): void
    {
        if ($payments === []) {
            return;
        }

        $byKey = [];
        foreach ($payments as $payment) {
            $byKey[$payment->key()] ??= $payment;   // one row per key within a batch, too
        }

        $rows = array_map(static fn (StellarPayment $payment): array => [
            'network' => $payment->network,
            'payment_id' => $payment->paymentId,
            'hash' => $payment->hash,
            'op_index' => $payment->opIndex,
            'type' => $payment->type,
            'account' => $payment->account,
            'destination' => $payment->destination,
            'memo_id' => $payment->memoId,
            'asset_code' => $payment->assetCode,
            'asset_issuer' => $payment->assetIssuer,
            'amount' => $payment->amount,
            'created_at' => $payment->createdAt,
            'raw' => self::encode($payment->raw),
        ], array_values($byKey));

        // A multi-row INSERT assigns the auto-increment keys in row order —
        // the tie-break findPayments() relies on.
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->payments()->upsert($chunk, ['hash', 'op_index'], ['hash']);
        }
    }

    public function findPayments(string $destination, string $memoId): array
    {
        return $this->payments()
            ->where('destination', $destination)
            ->where('memo_id', $memoId)
            ->orderByDesc('payment_id')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (object $row): StellarPayment => self::hydrate($row))
            ->values()
            ->all();
    }

    public function getLastPaymentId(string $destinationAccount, string $network): ?string
    {
        $max = $this->payments()
            ->where('destination', $destinationAccount)
            ->where('network', $network)
            ->max('payment_id');

        return $max === null ? null : (string) $max;
    }

    /** Empties the payment table. The memo-id counter is never touched. */
    public function truncate(): void
    {
        $this->payments()->truncate();
    }

    private function payments(): Builder
    {
        return $this->connection->table(Schema::TABLE_PAYMENT);
    }

    private static function hydrate(object $row): StellarPayment
    {
        return new StellarPayment(
            network: (string) $row->network,
            paymentId: (string) $row->payment_id,
            hash: (string) $row->hash,
            opIndex: (int) $row->op_index,
            type: (string) $row->type,
            account: (string) $row->account,
            destination: (string) $row->destination,
            memoId: $row->memo_id === null ? null : (string) $row->memo_id,
            assetCode: (string) $row->asset_code,
            assetIssuer: $row->asset_issuer === null ? null : (string) $row->asset_issuer,
            amount: (string) $row->amount,
            createdAt: (int) $row->created_at,
            raw: self::decode((string) $row->raw, (string) $row->hash),
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
            throw new UnexpectedValueException("Stored Stellar payment {$hash} holds malformed JSON.", previous: $exception);
        }

        return is_array($value) ? $value : [];
    }
}
