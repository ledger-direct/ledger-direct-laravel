<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Database;

use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

/**
 * Builds the core's tables from Xrpl\Schema::tables() — read, not copied, so
 * a column the core adds reaches every Laravel installation through its next
 * migration instead of through a hand-edited one.
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
 * PostgreSQL is refused outright: every table there has a system column
 * called `ctid`, and the core's transaction table has a column of that name.
 */
final class LedgerDirectTables
{
    private const UNSIGNED_DRIVERS = ['mysql', 'mariadb'];

    /**
     * Creates both tables, each only if it does not exist yet: the counter
     * table outlives a rollback on purpose (see dropReSyncable()), so a
     * later `migrate` finds it already there and must leave it alone.
     */
    public static function create(Builder $schema): void
    {
        $driver = $schema->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            throw new RuntimeException(
                'LedgerDirect: PostgreSQL is not supported yet. The core\'s table ' . Schema::TABLE_TX
                    . ' has a column named "ctid", which PostgreSQL reserves as a system column.'
                    . ' Point ledger-direct.connection at a MySQL, MariaDB or SQLite connection.',
            );
        }

        $unsigned = in_array($driver, self::UNSIGNED_DRIVERS, true);

        foreach (Schema::tables() as $table => $definition) {
            if ($schema->hasTable($table)) {
                continue;
            }

            $schema->create($table, static function (Blueprint $blueprint) use ($definition, $unsigned): void {
                self::build($blueprint, $definition, $unsigned);
            });
        }
    }

    /**
     * Drops what can be rebuilt from the ledger: the transaction table. The
     * destination-tag counter stays. It is the only record of which tags
     * were already issued, and a fresh counter hands out tags that open
     * orders are still waiting on (INVARIANTS.md, "Tables").
     */
    public static function dropReSyncable(Builder $schema): void
    {
        $schema->dropIfExists(Schema::TABLE_TX);
    }

    /**
     * @param array{
     *     columns: list<array{name: string, type: 'int'|'bigint'|'string'|'text', length?: int, unsigned?: bool, nullable: bool, autoincrement?: bool}>,
     *     primary: list<string>,
     *     unique: array<string, list<string>>,
     *     indexes: array<string, list<string>>,
     * } $definition
     */
    private static function build(Blueprint $table, array $definition, bool $unsigned): void
    {
        $autoincrement = null;

        foreach ($definition['columns'] as $column) {
            $isUnsigned = $column['unsigned'] ?? false;

            if ($column['autoincrement'] ?? false) {
                $autoincrement = $column['name'];

                if ($unsigned || !$isUnsigned) {
                    $table->increments($column['name']);
                } else {
                    $table->bigIncrements($column['name']);
                }

                continue;
            }

            $definitionColumn = match ($column['type']) {
                'string' => $table->string($column['name'], $column['length'] ?? 255),
                'text' => $table->longText($column['name']),
                'int' => match (true) {
                    !$isUnsigned => $table->integer($column['name']),
                    $unsigned => $table->unsignedInteger($column['name']),
                    default => $table->bigInteger($column['name']),
                },
                'bigint' => $isUnsigned && $unsigned
                    ? $table->unsignedBigInteger($column['name'])
                    : $table->bigInteger($column['name']),
            };

            $definitionColumn->nullable($column['nullable']);
        }

        // increments() already declares its column the primary key.
        if ($definition['primary'] !== [] && $definition['primary'] !== [$autoincrement]) {
            $table->primary($definition['primary']);
        }

        foreach ($definition['unique'] as $name => $columns) {
            $table->unique($columns, $name);
        }

        foreach ($definition['indexes'] as $name => $columns) {
            $table->index($columns, $name);
        }
    }
}
