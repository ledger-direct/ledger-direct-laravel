<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Repository;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The per-account counter behind destination tags (XRPL) and memo ids
 * (Stellar): one row per receiving account, `destination_account` and
 * `sequence`, in the table the core's schema names for the chain.
 *
 * The rules are the ports' (XrplTransactionRepositoryInterface::
 * nextDestinationTagSequence(), StellarPaymentRepositoryInterface::
 * nextMemoIdSequence()): the first value is a fresh random_int(0, 2^31 − 1),
 * every later one the previous plus one, never 0 as a fixed start, and
 * atomic under concurrent calls for the same account.
 */
final class SequenceCounter
{
    /** The counter's random start never exceeds this (see the port contract). */
    private const MAX_RANDOM_START = 2147483647;

    /** How often the portable counter retries after losing a first-insert race. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    public function next(string $destinationAccount): int
    {
        return match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->nextOnMysql($destinationAccount),
            default => $this->nextPortable($destinationAccount),
        };
    }

    /**
     * The pattern from the port's docblock: one statement that creates the
     * row at a random start or increments it, with the result parked in
     * LAST_INSERT_ID() — which is per connection, so it is read back on the
     * very same one. The read goes to the write PDO explicitly; with a
     * read/write split a replica would answer with its own, unrelated value.
     */
    private function nextOnMysql(string $destinationAccount): int
    {
        $table = $this->connection->getQueryGrammar()->wrapTable($this->table);

        $this->connection->statement(
            "INSERT INTO {$table} (destination_account, sequence) VALUES (?, LAST_INSERT_ID(?))"
                . ' ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)',
            [$destinationAccount, random_int(0, self::MAX_RANDOM_START)],
        );

        $row = $this->connection->selectOne('SELECT LAST_INSERT_ID() AS sequence', [], false);

        return (int) $row->sequence;
    }

    /**
     * Every other driver: increment first, read second, in one transaction.
     *
     * The UPDATE is the transaction's first statement, so it takes the write
     * lock before anything is read — the row lock on PostgreSQL, the
     * database's write lock on SQLite. A read first would make SQLite upgrade
     * a read lock to a write lock, and that upgrade fails at once with
     * "database is locked" when another request wrote in between, busy
     * timeout or not: two customers checking out at the same moment, one of
     * them without an identifier.
     *
     * No row updated means a new account: insert the random start. Two first
     * calls can both get there; the loser of the insert race gets a unique
     * violation and goes round again, now incrementing the winner's row.
     */
    private function nextPortable(string $destinationAccount): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->connection->transaction(function () use ($destinationAccount): int {
                    $updated = $this->rows()
                        ->where('destination_account', $destinationAccount)
                        ->increment('sequence');

                    if ($updated === 0) {
                        $start = random_int(0, self::MAX_RANDOM_START);
                        $this->rows()->insert(['destination_account' => $destinationAccount, 'sequence' => $start]);

                        return $start;
                    }

                    return (int) $this->rows()
                        ->where('destination_account', $destinationAccount)
                        ->value('sequence');
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function rows(): Builder
    {
        return $this->connection->table($this->table);
    }
}
