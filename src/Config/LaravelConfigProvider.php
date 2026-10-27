<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Config;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads config/ledger-direct.php for the core.
 *
 * The core names chains in upper case ('XRPL'); the config file keys them in
 * lower case ('xrpl'), one block per chain, so a further chain is a further
 * block and nothing else moves.
 *
 * Every value is validated when it is read, not at boot: an application
 * that has not configured LedgerDirect yet must still boot, run migrations
 * and serve every other page. The first code path that actually needs the
 * value fails, with a message that says what to set.
 */
final class LaravelConfigProvider implements ConfigProviderInterface
{
    public const QUOTE_EXPIRY_MIN = 60;
    public const QUOTE_EXPIRY_MAX = 3600;

    private const NETWORKS = ['mainnet', 'testnet'];

    /** The core's chain name => the key of its block under `chains`. */
    private const CHAIN_KEYS = [
        'XRPL' => 'xrpl',
    ];

    public function __construct(
        private readonly Repository $config,
    ) {
    }

    public function getNetwork(string $chain): string
    {
        $key = $this->chainKey($chain) . '.network';
        $network = $this->config->get('ledger-direct.' . $key);

        if (!is_string($network) || !in_array($network, self::NETWORKS, true)) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: ledger-direct.%s must be "mainnet" or "testnet", got %s. Set %s in your .env.',
                $key,
                self::describe($network),
                self::envName($chain, 'NETWORK'),
            ));
        }

        return $network;
    }

    public function getDestinationAccount(string $chain): string
    {
        $key = $this->chainKey($chain) . '.destination_account';
        $account = $this->config->get('ledger-direct.' . $key);

        if ($account === null || $account === '') {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: no receiving account configured. Set %s in your .env to your %s address (ledger-direct.%s).',
                self::envName($chain, 'ACCOUNT'),
                $chain,
                $key,
            ));
        }

        // The core's format check (ChainCatalog): catches a pasted X-address,
        // a Stellar G-key, a truncated value or a trailing newline. Not a
        // checksum — that is a core ticket, and arrives here with the core.
        if (!is_string($account) || !ChainCatalog::chain($chain)->isValidAccount($account)) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: ledger-direct.%s is not a classic XRPL address (r…), got %s. Check %s in your .env.',
                $key,
                self::describe($account),
                self::envName($chain, 'ACCOUNT'),
            ));
        }

        return $account;
    }

    /**
     * Only an explicit `true` enables an asset. An asset the config does not
     * list is off — stablecoins are opt-in, and so is anything added later.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $assets = $this->config->get('ledger-direct.' . $this->chainKey($chain) . '.assets', []);

        return is_array($assets) && ($assets[$baseAsset] ?? false) === true;
    }

    public function getQuoteExpirySeconds(): int
    {
        $seconds = $this->config->get('ledger-direct.quote_expiry_seconds');

        if (is_string($seconds) && ctype_digit($seconds)) {
            $seconds = (int) $seconds;
        }

        if (!is_int($seconds) || $seconds < self::QUOTE_EXPIRY_MIN || $seconds > self::QUOTE_EXPIRY_MAX) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: ledger-direct.quote_expiry_seconds must be a whole number of seconds between %d and %d, got %s.',
                self::QUOTE_EXPIRY_MIN,
                self::QUOTE_EXPIRY_MAX,
                self::describe($seconds),
            ));
        }

        return $seconds;
    }

    /**
     * The chains this installation has a config block for, in the core's
     * naming — e.g. ['XRPL'].
     *
     * @return list<string>
     */
    public function configuredChains(): array
    {
        $blocks = $this->config->get('ledger-direct.chains', []);
        $chains = [];

        foreach (self::CHAIN_KEYS as $chain => $key) {
            if (is_array($blocks) && isset($blocks[$key])) {
                $chains[] = $chain;
            }
        }

        return $chains;
    }

    /**
     * Maps a CLI-style chain name ('xrpl') to the core's ('XRPL').
     */
    public static function coreChainName(string $configKey): ?string
    {
        $chain = array_search(strtolower($configKey), self::CHAIN_KEYS, true);

        return $chain === false ? null : $chain;
    }

    private function chainKey(string $chain): string
    {
        $key = self::CHAIN_KEYS[$chain] ?? throw new InvalidConfigurationException(sprintf(
            'LedgerDirect: this version of the Laravel bridge does not support the chain "%s".',
            $chain,
        ));

        return 'chains.' . $key;
    }

    private static function envName(string $chain, string $suffix): string
    {
        return 'LEDGER_DIRECT_' . strtoupper($chain) . '_' . $suffix;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
