<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Database;

use Hardcastle\LedgerDirect\Core\Xrpl\Schema as CoreSchema;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration against what the core says the tables are. Runs on SQLite,
 * and on MySQL or PostgreSQL when the suite is pointed at one — only on
 * MySQL are the unsigned flags visible.
 */
final class MigrationTest extends TestCase
{
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(CoreSchema::tables()) as $table) {
            Schema::dropIfExists($table);
        }

        $this->migration = require __DIR__ . '/../../database/migrations/2026_09_23_000000_create_ledger_direct_xrpl_tables.php';
        $this->migration->up();
    }

    public function testTheColumnsAreTheCoreSchema(): void
    {
        $mysql = $this->isMysql();

        foreach (CoreSchema::tables() as $table => $definition) {
            $actual = collect(Schema::getColumns($table))->keyBy('name');

            self::assertSame(
                array_column($definition['columns'], 'name'),
                $actual->keys()->all(),
                "{$table}: columns and their order",
            );

            foreach ($definition['columns'] as $column) {
                $found = $actual[$column['name']];
                $where = "{$table}.{$column['name']}";

                self::assertSame($column['nullable'], $found['nullable'], "{$where}: nullability");
                self::assertContains(strtolower($found['type_name']), self::acceptedTypeNames($column, $mysql), "{$where}: type");

                // SQLite reports a bare "varchar" and enforces no length anyway.
                if ($column['type'] === 'string' && str_contains($found['type'], '(')) {
                    self::assertStringContainsString('(' . $column['length'] . ')', $found['type'], "{$where}: length");
                }

                if ($mysql) {
                    self::assertSame($column['unsigned'] ?? false, str_contains($found['type'], 'unsigned'), "{$where}: unsigned");
                }

                if ($column['autoincrement'] ?? false) {
                    self::assertTrue($found['auto_increment'], "{$where}: auto increment");
                }
            }
        }
    }

    public function testTheIndexesAreTheCoreSchema(): void
    {
        foreach (CoreSchema::tables() as $table => $definition) {
            $indexes = collect(Schema::getIndexes($table));

            $primary = $indexes->firstWhere('primary', true);
            self::assertNotNull($primary, "{$table}: primary key");
            self::assertSame($definition['primary'], $primary['columns'], "{$table}: primary key columns");

            foreach ($definition['unique'] as $name => $columns) {
                $index = $indexes->firstWhere('name', $name);
                self::assertNotNull($index, "{$table}: unique index {$name}");
                self::assertTrue($index['unique'], "{$table}: {$name} is unique");
                self::assertSame($columns, $index['columns'], "{$table}: {$name} columns");
            }

            foreach ($definition['indexes'] as $name => $columns) {
                $index = $indexes->firstWhere('name', $name);
                self::assertNotNull($index, "{$table}: index {$name}");
                self::assertSame($columns, $index['columns'], "{$table}: {$name} columns");
            }
        }
    }

    public function testTheTableNamesCarryTheConnectionPrefix(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')->all();

        self::assertContains('app_ledger_direct_xrpl_tx', $tables);
        self::assertContains('app_ledger_direct_xrpl_destination_tag', $tables);
    }

    public function testTheUnsignedRangeFitsOnEveryDriver(): void
    {
        DB::table(CoreSchema::TABLE_TX)->insert([
            'network' => 'mainnet',
            'ledger_index' => 99_000_000_000,
            'hash' => str_repeat('A', 64),
            'tx_ctid' => 'C000000000000000',
            'account' => 'rSender',
            'destination' => 'rDest',
            'destination_tag' => 4_294_967_295,
            'date' => 4_000_000_000,
            'meta' => '{}',
            'tx' => '{}',
        ]);
        DB::table(CoreSchema::TABLE_DESTINATION_TAG)->insert(['destination_account' => 'rDest', 'sequence' => 4_294_967_295]);

        self::assertSame(4_294_967_295, (int) DB::table(CoreSchema::TABLE_TX)->value('destination_tag'));
        self::assertSame(4_294_967_295, (int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->value('sequence'));
    }

    public function testDownKeepsTheCounterTable(): void
    {
        DB::table(CoreSchema::TABLE_DESTINATION_TAG)->insert(['destination_account' => 'rDest', 'sequence' => 123]);

        $this->migration->down();

        self::assertFalse(Schema::hasTable(CoreSchema::TABLE_TX));
        self::assertTrue(Schema::hasTable(CoreSchema::TABLE_DESTINATION_TAG));
        self::assertSame(123, (int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->value('sequence'));
    }

    public function testUpAfterDownRebuildsTheTransactionTableAndLeavesTheCounterAlone(): void
    {
        DB::table(CoreSchema::TABLE_DESTINATION_TAG)->insert(['destination_account' => 'rDest', 'sequence' => 123]);

        $this->migration->down();
        $this->migration->up();

        self::assertTrue(Schema::hasTable(CoreSchema::TABLE_TX));
        self::assertSame(123, (int) DB::table(CoreSchema::TABLE_DESTINATION_TAG)->value('sequence'));
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * What the database reports for a neutral core type. On SQLite every
     * integer is "integer"; elsewhere an unsigned int is widened to bigint
     * when the driver has no unsigned types (see LedgerDirectTables).
     *
     * @param array{type: string, unsigned?: bool} $column
     * @return list<string>
     */
    private static function acceptedTypeNames(array $column, bool $mysql): array
    {
        return match ($column['type']) {
            'int' => $mysql ? ['int'] : ['integer', 'int', 'int4', 'bigint', 'int8'],
            'bigint' => $mysql ? ['bigint'] : ['integer', 'bigint', 'int8'],
            'string' => ['varchar', 'character varying', 'nvarchar'],
            'text' => $mysql ? ['longtext'] : ['text', 'nvarchar'],
            default => [],
        };
    }
}
