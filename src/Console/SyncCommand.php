<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Console;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Illuminate\Console\Command;

/**
 * The safety net: syncs the configured receiving account of every enabled
 * chain — or of the one named with --chain — with its ledger, unthrottled,
 * on a schedule. A payment whose customer closed the payment page still
 * lands in the transaction table this way.
 *
 * It matches nothing — the bridge knows no orders. A shop package hooks in
 * after it, or ships a command of its own that matches its open orders.
 *
 * One chain failing (a node down, a rate limit) does not keep the others
 * from syncing; the command then exits non-zero.
 */
final class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'ledger-direct:sync {--chain= : Only this chain (xrpl, stellar); default: every enabled chain}';

    /** @var string */
    protected $description = 'Sync incoming payments of the configured receiving accounts from their ledgers';

    public function handle(LedgerDirectChains $chains): int
    {
        $option = $this->option('chain');

        if (is_string($option) && $option !== '') {
            $chain = LaravelConfigProvider::coreChainName($option);

            if ($chain === null) {
                $this->components->error(sprintf(
                    'Unsupported chain "%s". Supported: %s.',
                    $option,
                    implode(', ', array_map(static fn ($c): string => strtolower($c->chain), ChainCatalog::chains())),
                ));

                return self::INVALID;
            }

            if (!in_array($chain, $chains->enabledChains(), true)) {
                $this->components->error(sprintf('%s is not enabled (ledger-direct.chains.%s.enabled).', ChainCatalog::chain($chain)->label, strtolower($chain)));

                return self::FAILURE;
            }

            $selected = [$chain];
        } else {
            $selected = $chains->enabledChains();

            if ($selected === []) {
                $this->components->warn('No chain is enabled; nothing to sync.');

                return self::SUCCESS;
            }
        }

        $failed = false;

        foreach ($selected as $chain) {
            $root = $chains->forChain($chain);

            try {
                $config = $root->configProvider();
                $account = $config->getDestinationAccount($chain);
                $network = $config->getNetwork($chain);

                $root->syncService()->sync($account, $network);
            } catch (LedgerDirectException $exception) {
                $this->components->error(sprintf('%s: %s', ChainCatalog::chain($chain)->label, $exception->getMessage()));
                $failed = true;

                continue;
            }

            $this->components->info(sprintf('Synced %s on %s %s.', $account, $chain, $network));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
