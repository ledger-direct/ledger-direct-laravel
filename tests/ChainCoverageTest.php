<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;

/**
 * The completeness check from the chain-selection handover: every chain the
 * core's catalog lists must be wired through the bridge — a config block, a
 * composition root, a migration for its tables. A chain the core gains
 * (Xahau) turns this red until the bridge has caught up.
 */
final class ChainCoverageTest extends TestCase
{
    public function testEveryCatalogChainHasAConfigBlockARootAndAMigration(): void
    {
        $config = require __DIR__ . '/../config/ledger-direct.php';
        $chains = $this->app->make(LedgerDirectChains::class);
        $migrations = array_map('basename', glob(__DIR__ . '/../database/migrations/*.php') ?: []);

        foreach (ChainCatalog::chains() as $chain) {
            $key = strtolower($chain->chain);

            self::assertArrayHasKey($key, $config['chains'], "{$chain->chain}: block in config/ledger-direct.php");
            self::assertSame($chain->chain, LaravelConfigProvider::coreChainName($key));

            foreach ($chain->assets as $asset) {
                self::assertArrayHasKey($asset->code, $config['chains'][$key]['assets'], "{$chain->chain}: switch for {$asset->code}");
            }

            self::assertIsObject($chains->forChain($chain->chain), "{$chain->chain}: composition root");
            self::assertNotEmpty(
                array_filter($migrations, static fn (string $file): bool => str_contains($file, "create_ledger_direct_{$key}_tables")),
                "{$chain->chain}: migration",
            );
        }
    }
}
