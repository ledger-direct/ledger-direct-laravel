<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Database;

use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema as CoreSchema;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations against what the core says the tables are — the XRPL ones
 * from Xrpl\Schema, the Stellar ones from Stellar\Schema. Runs on SQLite,
 * and on MySQL or PostgreSQL when the suite is pointed at one — only on
 * MySQL are the unsigned flags visible.
 */
final class MigrationTest extends TestCase
{
    private Migration $migration;

    private Migration $stellarMigration;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(self::coreTables()) as $table) {
            Schema::dropIfExists($table);
        }

        $this->migration = require __DIR__ . '/../../database/migrations/2026_09_23_000000_create_ledger_direct_xrpl_tables.php';
        $this->migration->up();
        $this->stellarMigration = require __DIR__ . '/../../database/migrations/2026_10_27_000000_create_ledger_direct_stellar_tables.php';
        $this->stellarMigration->up();
    }

    /**
     * @return array<string, array{columns: list<array<string, mixed>>, primary: list<string>, unique: array<string, list<string>>, indexes: array<string, list<string>>}>
     */
    private static function coreTables(): array
    {
        return CoreSchema::tables() + StellarSchema::tables();
    }

    public function testTheColumnsAreTheCoreSchema(): void
    {
        $mysql = $this->isMysql();

        foreach (self::coreTables() as $table => $definition) {
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

                if ($column['name'] === 'memo_id' && !$mysql) {
                    continue;   // stored as numeric(20,0) or a string, see LedgerDirectTables
                }

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
        foreach (self::coreTables() as $table => $definition) {
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
        self::assertContains('app_ledger_direct_stellar_payment', $tables);
        self::assertContains('app_ledger_direct_stellar_memo', $tables);
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

    /** A stranger's memo id may be any uint64; the column must take the largest exactly. */
    public function testTheStellarMemoIdTakesTheFullUint64Range(): void
    {
        DB::table(StellarSchema::TABLE_PAYMENT)->insert([
            'network' => 'mainnet',
            'payment_id' => '4294967297',
            'hash' => str_repeat('B', 64),
            'op_index' => 1,
            'type' => 'payment',
            'account' => 'GSENDER',
            'destination' => 'GDEST',
            'memo_id' => '18446744073709551615',
            'asset_code' => 'XLM',
            'asset_issuer' => null,
            'amount' => '1.0000000',
            'created_at' => 4_000_000_000,
            'raw' => '{}',
        ]);

        self::assertSame('18446744073709551615', (string) DB::table(StellarSchema::TABLE_PAYMENT)->value('memo_id'));
    }

    public function testStellarDownKeepsTheMemoCounter(): void
    {
        DB::table(StellarSchema::TABLE_MEMO)->insert(['destination_account' => 'GDEST', 'sequence' => 321]);

        $this->stellarMigration->down();
        self::assertFalse(Schema::hasTable(StellarSchema::TABLE_PAYMENT));
        self::assertTrue(Schema::hasTable(StellarSchema::TABLE_MEMO));

        $this->stellarMigration->up();
        self::assertTrue(Schema::hasTable(StellarSchema::TABLE_PAYMENT));
        self::assertSame(321, (int) DB::table(StellarSchema::TABLE_MEMO)->value('sequence'));
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * What the database reports for a neutral core type. On SQLite every
     * integer is "integer"; elsewhere an unsigned int is widened to bigint
     * when the driver has no unsigned types, and the uint64 identifier
     * `memo_id` becomes numeric(20,0) or a string (see LedgerDirectTables).
     *
     * @param array{name: string, type: string, unsigned?: bool} $column
     * @return list<string>
     */
    private static function acceptedTypeNames(array $column, bool $mysql): array
    {
        if ($column['name'] === 'memo_id' && !$mysql) {
            return ['numeric', 'decimal', 'varchar', 'character varying'];
        }

        return match ($column['type']) {
            'int' => $mysql ? ['int'] : ['integer', 'int', 'int4', 'bigint', 'int8'],
            'bigint' => $mysql ? ['bigint'] : ['integer', 'bigint', 'int8'],
            'string' => ['varchar', 'character varying', 'nvarchar'],
            'text' => $mysql ? ['longtext'] : ['text', 'nvarchar'],
            default => [],
        };
    }
}
