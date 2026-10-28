<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Database;

use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema as XrplSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Builds the core's tables from its schema classes — Xrpl\Schema::tables(),
 * Stellar\Schema::tables() — read, not copied, so a column the core adds
 * reaches every Laravel installation through its next migration instead of
 * through a hand-edited one.
 *
 * The names are the core's base names; the schema builder puts the
 * connection's table prefix in front, as for every other table.
 *
 * Only MySQL and MariaDB have unsigned integers. Elsewhere an unsigned
 * column is widened to the next signed type, because the values need the
 * unsigned range: a destination tag goes up to 4294967295, and a signed
 * 32-bit `int` would reject everything above 2147483647 — including the tag
 * counter one step after a random start near its ceiling.
 *
 * Widening stops at 64 bits, and one column needs all of them: Stellar's
 * `memo_id` holds whatever identifier a payment to the account carried, and
 * a stranger's memo or muxed id may be any uint64 up to 18446744073709551615.
 * A signed `bigint` would reject it and fail the whole sync batch with it;
 * so on PostgreSQL it is `numeric(20, 0)` and elsewhere a string, which keeps
 * it exact. It is only ever compared, never ordered or summed.
 *
 * PostgreSQL works since core 0.9: the CTID column is `tx_ctid`, because
 * every PostgreSQL table has a system column called `ctid` that no user
 * column may be named after. The core's schema test keeps every column
 * name clear of those.
 */
final class LedgerDirectTables
{
    private const UNSIGNED_DRIVERS = ['mysql', 'mariadb'];

    /** Unsigned 64-bit identifiers: compared as exact decimals, never ordered. */
    private const UINT64_IDENTIFIERS = ['memo_id'];

    /**
     * Creates the XRPL tables, each only if it does not exist yet: the
     * counter table outlives a rollback on purpose (see dropReSyncable()),
     * so a later `migrate` finds it already there and must leave it alone.
     */
    public static function create(Builder $schema): void
    {
        self::createFrom($schema, XrplSchema::tables());
    }

    /**
     * Drops what can be rebuilt from the ledger: the XRPL transaction table.
     * The destination-tag counter stays. It is the only record of which tags
     * were already issued, and a fresh counter hands out tags that open
     * orders are still waiting on (INVARIANTS.md, "Tables").
     */
    public static function dropReSyncable(Builder $schema): void
    {
        $schema->dropIfExists(XrplSchema::TABLE_TX);
    }

    /** The Stellar tables, on the same terms as create(). */
    public static function createStellar(Builder $schema): void
    {
        self::createFrom($schema, StellarSchema::tables());
    }

    /**
     * Drops the Stellar payment table, which the next sync rebuilds from
     * Horizon. The memo-id counter stays, for the reason the tag counter
     * does (INVARIANTS.md, "Stellar", Tables).
     */
    public static function dropStellarReSyncable(Builder $schema): void
    {
        $schema->dropIfExists(StellarSchema::TABLE_PAYMENT);
    }

    /**
     * @param array<string, array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * }> $tables
     */
    private static function createFrom(Builder $schema, array $tables): void
    {
        $driver = $schema->getConnection()->getDriverName();

        foreach ($tables as $table => $definition) {
            if ($schema->hasTable($table)) {
                continue;
            }

            $schema->create($table, static function (Blueprint $blueprint) use ($definition, $driver): void {
                self::build($blueprint, $definition, $driver);
            });
        }
    }

    /**
     * @param array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * } $definition
     */
    private static function build(Blueprint $table, array $definition, string $driver): void
    {
        $unsigned = in_array($driver, self::UNSIGNED_DRIVERS, true);
        $autoincrement = null;

        foreach ($definition['columns'] as $column) {
            $name = $column['name'];
            $isUnsigned = $column['unsigned'] ?? false;

            if ($column['autoincrement'] ?? false) {
                $autoincrement = $name;

                if ($unsigned || !$isUnsigned) {
                    $table->increments($name);
                } else {
                    $table->bigIncrements($name);
                }

                continue;
            }

            $definitionColumn = match (true) {
                $column['type'] === 'bigint' && $isUnsigned && !$unsigned && in_array($name, self::UINT64_IDENTIFIERS, true)
                    => $driver === 'pgsql' ? $table->decimal($name, 20, 0) : $table->string($name, 20),
                $column['type'] === 'string' => $table->string($name, $column['length'] ?? 255),
                $column['type'] === 'text' => $table->longText($name),
                $column['type'] === 'int' => match (true) {
                    !$isUnsigned => $table->integer($name),
                    $unsigned => $table->unsignedInteger($name),
                    default => $table->bigInteger($name),
                },
                default => $isUnsigned && $unsigned
                    ? $table->unsignedBigInteger($name)
                    : $table->bigInteger($name),
            };

            $definitionColumn->nullable($column['nullable']);
        }

        // increments() already declares its column the primary key.
        if ($definition['primary'] !== [] && $definition['primary'] !== [$autoincrement]) {
            $table->primary($definition['primary']);
        }

        foreach ($definition['unique'] as $indexName => $columns) {
            $table->unique($columns, $indexName);
        }

        foreach ($definition['indexes'] as $indexName => $columns) {
            $table->index($columns, $indexName);
        }
    }
}
