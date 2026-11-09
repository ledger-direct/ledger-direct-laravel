<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Checkout\PaymentOptions;
use Hardcastle\LedgerDirect\Laravel\Clock\LaravelClock;
use Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Hardcastle\LedgerDirect\Laravel\Console\PriceCommand;
use Hardcastle\LedgerDirect\Laravel\Console\SyncCommand;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseStellarPaymentRepository;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Binds the core's composition roots — Core\LedgerDirect for the XRP Ledger,
 * a second one of that class for Xahau, Stellar\LedgerDirectStellar for
 * Stellar — the ports they need, and Chains\LedgerDirectChains, which picks
 * the root for an intent.
 *
 * Only the roots are services here. Whoever needs PriceService, SyncService
 * and so on takes them from a root (`LedgerDirect::priceService()`), so a
 * constructor change in the core touches create() and nothing in this
 * provider.
 *
 * The infrastructure the roots are built from sits behind the container
 * keys below, shared by all, so an application — or a test — can swap the
 * HTTP client, the cache, the logger or the clock without re-implementing
 * the wiring.
 */
final class LedgerDirectServiceProvider extends ServiceProvider
{
    /** Core\LedgerDirect created for Xahau; the class alone names the XRP Ledger's. */
    public const XAHAU_ROOT = 'ledger-direct.root.xahau';

    /** XrplTransactionRepositoryInterface on the Xahau tables. */
    public const XAHAU_REPOSITORY = 'ledger-direct.repository.xahau';

    /** PSR-18 client for the XRPL and Xahau nodes, Horizon and the price oracles. */
    public const HTTP_CLIENT = 'ledger-direct.http-client';

    /** PSR-16 cache behind the rate cache and the sync throttle; may resolve to null. */
    public const CACHE = 'ledger-direct.cache';

    /** PSR-3 logger. */
    public const LOGGER = 'ledger-direct.logger';

    /** PSR-20 clock. */
    public const CLOCK = 'ledger-direct.clock';

    /** Outbound HTTP: bounded, and TLS always verified (INVARIANTS.md, "Security"). */
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;
    private const HTTP_TIMEOUT_SECONDS = 15;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ledger-direct.php', 'ledger-direct');

        $this->app->singleton(ConfigProviderInterface::class, static fn (Application $app): ConfigProviderInterface
            => new LaravelConfigProvider($app->make('config')));

        $this->app->singleton(XrplTransactionRepositoryInterface::class, static fn (Application $app): XrplTransactionRepositoryInterface
            => new DatabaseXrplTransactionRepository(
                $app->make('db')->connection($app->make('config')->get('ledger-direct.connection')),
            ));

        $this->app->singleton(self::XAHAU_REPOSITORY, static fn (Application $app): XrplTransactionRepositoryInterface
            => new DatabaseXrplTransactionRepository(
                $app->make('db')->connection($app->make('config')->get('ledger-direct.connection')),
                ChainCatalog::XAHAU,
            ));

        $this->app->singleton(self::HTTP_CLIENT, static fn (): Client => new Client([
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'verify' => true,
        ]));

        $this->app->singleton(self::LOGGER, static fn (Application $app): LoggerInterface
            => $app->make('log')->channel($app->make('config')->get('ledger-direct.log_channel')));

        $this->app->singleton(StellarPaymentRepositoryInterface::class, static fn (Application $app): StellarPaymentRepositoryInterface
            => new DatabaseStellarPaymentRepository(
                $app->make('db')->connection($app->make('config')->get('ledger-direct.connection')),
            ));

        // One store for all roots: the rate cache, the sync throttle and the
        // Stellar account inspector. Its keys carry network and account, so
        // the chains never collide. Warned about once, when first resolved.
        $this->app->singleton(self::CACHE, static function (Application $app): CacheInterface {
            $cache = $app->make('cache')->store($app->make('config')->get('ledger-direct.cache_store'));
            self::warnAboutAShortLivedCache($cache, $app->make(self::LOGGER));

            return $cache;
        });

        $this->app->singleton(self::CLOCK, static fn (): LaravelClock => new LaravelClock());

        $this->app->singleton(LedgerDirect::class, static fn (Application $app): LedgerDirect
            => self::xrplProtocolRoot($app, $app->make(XrplTransactionRepositoryInterface::class), ChainCatalog::XRPL));

        // Xahau speaks the XRP Ledger's protocol: the same class, its own
        // chain and tables. Resolved only when Xahau is actually used.
        $this->app->singleton(self::XAHAU_ROOT, static function (Application $app): LedgerDirect {
            self::warnAboutASharedAccount($app->make(ConfigProviderInterface::class), $app->make(self::LOGGER));

            return self::xrplProtocolRoot($app, $app->make(self::XAHAU_REPOSITORY), ChainCatalog::XAHAU);
        });

        // The same infrastructure as the XRPL root; resolved only when a
        // Stellar intent, command or check needs it.
        $this->app->singleton(LedgerDirectStellar::class, static function (Application $app): LedgerDirectStellar {
            $config = $app->make('config');

            return LedgerDirectStellar::create(
                $app->make(self::HTTP_CLIENT),
                new HttpFactory(),
                $app->make(self::LOGGER),
                $app->make(StellarPaymentRepositoryInterface::class),
                $app->make(ConfigProviderInterface::class),
                $app->make(self::CACHE),
                $app->make(self::CLOCK),
                self::tolerance($config->get('ledger-direct.native_asset_tolerance')),
                self::freshTtl($config->get('ledger-direct.rate_fresh_ttl_seconds')),
            );
        });

        $this->app->singleton(LedgerDirectChains::class, static fn (Application $app): LedgerDirectChains
            => new LedgerDirectChains($app, $app->make(ConfigProviderInterface::class)));

        $this->app->bind(PaymentOptions::class, static fn (Application $app): PaymentOptions
            => new PaymentOptions($app->make(LedgerDirectChains::class), $app->make(ConfigProviderInterface::class), $app->make(self::LOGGER)));
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/ledger-direct.php' => $this->app->configPath('ledger-direct.php'),
        ], 'ledger-direct-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'ledger-direct-migrations');

        $this->commands([
            SyncCommand::class,
            PriceCommand::class,
        ]);

        // Part of laravel/framework, not of the illuminate/* components this package requires.
        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('LedgerDirect', fn (): array => $this->aboutSection());
        }
    }

    private static function xrplProtocolRoot(Application $app, XrplTransactionRepositoryInterface $repository, string $chain): LedgerDirect
    {
        $config = $app->make('config');
        $httpFactory = new HttpFactory();

        return LedgerDirect::create(
            $app->make(self::HTTP_CLIENT),
            $httpFactory,
            $httpFactory,
            $app->make(self::LOGGER),
            $repository,
            $app->make(ConfigProviderInterface::class),
            $app->make(self::CACHE),
            $app->make(self::CLOCK),
            self::tolerance($config->get('ledger-direct.native_asset_tolerance')),
            self::freshTtl($config->get('ledger-direct.rate_fresh_ttl_seconds')),
            $chain,
        );
    }

    /**
     * The same r-address is valid on the XRP Ledger and on Xahau, so a shared
     * account cannot be refused, only pointed out (INVARIANTS.md, "Xahau",
     * Tables). A customer paying on the wrong chain reaches the merchant's
     * account and no order.
     */
    private static function warnAboutASharedAccount(ConfigProviderInterface $config, LoggerInterface $logger): void
    {
        if (!$config instanceof LaravelConfigProvider) {
            return;
        }

        foreach ($config->accountsOnSeveralChains() as $account => $chains) {
            $logger->warning(
                'LedgerDirect: the same receiving account is configured on several chains. A payment sent on the'
                    . ' wrong one reaches the account but no order; use one account per chain.',
                ['account' => $account, 'chains' => $chains],
            );
        }
    }

    /**
     * The rate cache and the throttle only work if an entry survives the
     * request that wrote it. The array store forgets everything at the end
     * of the request, the null store never remembers; the throttle then
     * never engages and every status poll hits the node. Logged, not
     * thrown: in tests the array store is exactly right.
     */
    private static function warnAboutAShortLivedCache(mixed $cache, LoggerInterface $logger): void
    {
        if (!$cache instanceof CacheRepository) {
            return;
        }

        $store = $cache->getStore();

        if ($store instanceof ArrayStore || $store instanceof NullStore) {
            $logger->warning(
                'LedgerDirect: the cache store does not persist between requests, so exchange rates are not cached'
                    . ' and the status sync throttle never engages. Set ledger-direct.cache_store to a persistent store'
                    . ' (redis, database, file).',
                ['store' => get_class($store)],
            );
        }
    }

    private static function tolerance(mixed $value): string
    {
        if ($value === null || $value === '') {
            return SettlementPolicy::DEFAULT_NATIVE_ASSET_TOLERANCE;
        }

        if (is_float($value)) {
            // Not (string): that would turn 0.00001 into "1.0E-5".
            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (string) $value;
        }

        throw new InvalidConfigurationException(sprintf(
            'LedgerDirect: ledger-direct.native_asset_tolerance must be a decimal string such as "0.0015", got %s.',
            get_debug_type($value),
        ));
    }

    private static function freshTtl(mixed $value): int
    {
        if ($value === null || $value === '') {
            return PriceService::DEFAULT_FRESH_TTL_SECONDS;
        }

        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidConfigurationException(
            'LedgerDirect: ledger-direct.rate_fresh_ttl_seconds must be a positive whole number of seconds.',
        );
    }

    /**
     * One line per chain the core knows: off, or network, account and the
     * accepted assets — or the configuration error that keeps it from working.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $config = $this->app->make('config');
        $configProvider = $this->app->make(ConfigProviderInterface::class);

        $section = [
            'Core' => InstalledVersions::getPrettyVersion('hardcastle/ledger-direct-core') ?? 'unknown',
            'Cache store' => (string) ($config->get('ledger-direct.cache_store') ?? $config->get('cache.default')),
        ];

        $enabled = $this->app->make(LedgerDirectChains::class)->enabledChains();

        foreach (ChainCatalog::chains() as $chain) {
            if (!in_array($chain->chain, $enabled, true)) {
                $section[$chain->label] = 'off';

                continue;
            }

            try {
                $account = $configProvider->getDestinationAccount($chain->chain);
                $assets = array_values(array_filter(
                    $chain->assetCodes(),
                    static fn (string $asset): bool => $configProvider->isAssetEnabled($chain->chain, $asset),
                ));

                $section[$chain->label] = implode(' · ', [
                    $configProvider->getNetwork($chain->chain),
                    substr($account, 0, 6) . '…' . substr($account, -4),
                    $assets === [] ? 'no assets' : implode(', ', $assets),
                ]);
            } catch (LedgerDirectException $exception) {
                $section[$chain->label] = '<fg=red;options=bold>' . $exception->getMessage() . '</>';
            }
        }

        if ($configProvider instanceof LaravelConfigProvider) {
            foreach ($configProvider->accountsOnSeveralChains() as $account => $chains) {
                $section['Shared account'] = sprintf(
                    '<fg=yellow;options=bold>%s on %s: use one account per chain</>',
                    substr($account, 0, 6) . '…' . substr($account, -4),
                    implode(', ', $chains),
                );
            }
        }

        return $section;
    }
}
