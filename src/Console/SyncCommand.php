<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Console;

use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Illuminate\Console\Command;

/**
 * The safety net: syncs the configured receiving account with the ledger,
 * unthrottled, on a schedule. A payment whose customer closed the payment
 * page still lands in the transaction table this way.
 *
 * It matches nothing — the bridge knows no orders. A shop package hooks in
 * after it, or ships a command of its own that matches its open orders.
 */
final class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'ledger-direct:sync {--chain=xrpl : The chain to sync}';

    /** @var string */
    protected $description = 'Sync incoming transactions of the configured receiving account from the ledger';

    public function handle(LedgerDirect $ledgerDirect): int
    {
        $option = $this->option('chain');
        $chain = LaravelConfigProvider::coreChainName(is_string($option) ? $option : '');

        if ($chain !== 'XRPL') {
            $this->components->error(sprintf('Unsupported chain "%s". Supported: xrpl.', is_string($option) ? $option : ''));

            return self::INVALID;
        }

        try {
            $config = $ledgerDirect->configProvider();
            $account = $config->getDestinationAccount($chain);
            $network = $config->getNetwork($chain);

            $ledgerDirect->syncService()->syncTransactions($account, $network);
        } catch (LedgerDirectException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Synced %s on %s %s.', $account, $chain, $network));

        return self::SUCCESS;
    }
}
