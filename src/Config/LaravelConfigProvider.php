<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Config;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads config/ledger-direct.php for the core.
 *
 * Chains and assets come from the core's ChainCatalog, never from a list of
 * the bridge's own: the config block of a chain is keyed by its name in lower
 * case ('XRPL' → 'xrpl', 'STELLAR' → 'stellar'), and a chain the catalog
 * gains needs a block, nothing else here.
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

    /**
     * Enabled unless the config says otherwise — only for the XRP Ledger, so
     * a config file published by 0.1, which has no `enabled` key, keeps
     * taking payments. Every chain added later is opt-in.
     */
    private const ENABLED_BY_DEFAULT = [ChainCatalog::XRPL];

    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * Whether the chain takes payments. A chain without a config block is
     * off — a config file published before the chain existed has none.
     */
    public function isChainEnabled(string $chain): bool
    {
        if (!ChainCatalog::has($chain)) {
            return false;
        }

        $block = $this->config->get('ledger-direct.' . self::chainKey($chain));

        if (!is_array($block)) {
            return false;
        }

        $enabled = $block['enabled'] ?? in_array($chain, self::ENABLED_BY_DEFAULT, true);

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    /**
     * The enabled chains, in catalog order — e.g. ['XRPL', 'STELLAR'].
     *
     * @return list<string>
     */
    public function enabledChains(): array
    {
        $chains = [];

        foreach (ChainCatalog::chains() as $descriptor) {
            if ($this->isChainEnabled($descriptor->chain)) {
                $chains[] = $descriptor->chain;
            }
        }

        return $chains;
    }

    /**
     * The chains this installation has a config block for, enabled or not,
     * in the core's naming — e.g. ['XRPL', 'STELLAR'].
     *
     * @return list<string>
     */
    public function configuredChains(): array
    {
        $chains = [];

        foreach (ChainCatalog::chains() as $descriptor) {
            if (is_array($this->config->get('ledger-direct.' . self::chainKey($descriptor->chain)))) {
                $chains[] = $descriptor->chain;
            }
        }

        return $chains;
    }

    public function getNetwork(string $chain): string
    {
        $key = $this->knownChainKey($chain) . '.network';
        $network = $this->config->get('ledger-direct.' . $key);
        $networks = ChainCatalog::chain($chain)->networks;

        if (!is_string($network) || !in_array($network, $networks, true)) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: ledger-direct.%s must be one of "%s", got %s. Set %s in your .env.',
                $key,
                implode('", "', $networks),
                self::describe($network),
                self::envName($chain, 'NETWORK'),
            ));
        }

        return $network;
    }

    public function getDestinationAccount(string $chain): string
    {
        $key = $this->knownChainKey($chain) . '.destination_account';
        $account = $this->config->get('ledger-direct.' . $key);
        $descriptor = ChainCatalog::chain($chain);

        if ($account === null || $account === '') {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: no receiving account configured for %s. Set %s in your .env (ledger-direct.%s).',
                $descriptor->label,
                self::envName($chain, 'ACCOUNT'),
                $key,
            ));
        }

        // The core's format check: catches an address of the other chain, a
        // pasted X-address, a truncated value or a trailing newline. Not a
        // checksum — that is a core ticket, and arrives here with the core.
        if (!is_string($account) || !$descriptor->isValidAccount($account)) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: ledger-direct.%s is not a valid %s receiving account, got %s. Check %s in your .env.',
                $key,
                $descriptor->label,
                self::describe($account),
                self::envName($chain, 'ACCOUNT'),
            ));
        }

        return $account;
    }

    /**
     * True only for an enabled chain, an asset the catalog lists for it, and
     * an explicit `true` in its config block. An asset the config does not
     * list is off — stablecoins are opt-in, and so is anything added later.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        if (!$this->isChainEnabled($chain) || !ChainCatalog::chain($chain)->hasAsset($baseAsset)) {
            return false;
        }

        $assets = $this->config->get('ledger-direct.' . self::chainKey($chain) . '.assets', []);

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
     * Maps a CLI-style chain name ('xrpl', 'stellar') to the core's, or null
     * for a chain the core does not know.
     */
    public static function coreChainName(string $name): ?string
    {
        $chain = strtoupper($name);

        return ChainCatalog::has($chain) ? $chain : null;
    }

    /** The config path of a chain's block, below `ledger-direct.`. */
    public static function chainKey(string $chain): string
    {
        return 'chains.' . strtolower($chain);
    }

    private function knownChainKey(string $chain): string
    {
        if (!ChainCatalog::has($chain)) {
            throw new InvalidConfigurationException(sprintf(
                'LedgerDirect: this version of the core does not know the chain "%s".',
                $chain,
            ));
        }

        return self::chainKey($chain);
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
