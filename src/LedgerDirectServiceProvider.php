<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Laravel\Clock\LaravelClock;
use Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Hardcastle\LedgerDirect\Laravel\Console\PriceCommand;
use Hardcastle\LedgerDirect\Laravel\Console\SyncCommand;
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
 * Binds the core's composition root and the two ports it needs.
 *
 * Only the root is a service here. Whoever needs PriceService, SyncService
 * and so on takes them from it (`LedgerDirect::priceService()`), so a
 * constructor change in the core touches LedgerDirect::create() and nothing
 * in this provider.
 *
 * The infrastructure the root is built from sits behind the container keys
 * below, so an application — or a test — can swap the HTTP client, the
 * cache, the logger or the clock without re-implementing the wiring.
 */
final class LedgerDirectServiceProvider extends ServiceProvider
{
    /** PSR-18 client for the XRPL node and the price oracles. */
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

        $this->app->singleton(self::HTTP_CLIENT, static fn (): Client => new Client([
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'verify' => true,
        ]));

        $this->app->singleton(self::LOGGER, static fn (Application $app): LoggerInterface
            => $app->make('log')->channel($app->make('config')->get('ledger-direct.log_channel')));

        $this->app->singleton(self::CACHE, static fn (Application $app): CacheInterface
            => $app->make('cache')->store($app->make('config')->get('ledger-direct.cache_store')));

        $this->app->singleton(self::CLOCK, static fn (): LaravelClock => new LaravelClock());

        $this->app->singleton(LedgerDirect::class, static function (Application $app): LedgerDirect {
            $config = $app->make('config');
            $logger = $app->make(self::LOGGER);
            $cache = $app->make(self::CACHE);
            $httpFactory = new HttpFactory();

            self::warnAboutAShortLivedCache($cache, $logger);

            return LedgerDirect::create(
                $app->make(self::HTTP_CLIENT),
                $httpFactory,
                $httpFactory,
                $logger,
                $app->make(XrplTransactionRepositoryInterface::class),
                $app->make(ConfigProviderInterface::class),
                $cache,
                $app->make(self::CLOCK),
                self::tolerance($config->get('ledger-direct.native_asset_tolerance')),
                self::freshTtl($config->get('ledger-direct.rate_fresh_ttl_seconds')),
            );
        });
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

        try {
            $account = $configProvider->getDestinationAccount('XRPL');
            $section['XRPL network'] = $configProvider->getNetwork('XRPL');
            $section['XRPL account'] = substr($account, 0, 6) . '…' . substr($account, -4);
            $section['XRPL assets'] = implode(', ', array_filter(
                ['XRP', 'RLUSD', 'USDC'],
                static fn (string $asset): bool => $configProvider->isAssetEnabled('XRPL', $asset),
            )) ?: 'none';
        } catch (LedgerDirectException $exception) {
            $section['XRPL'] = '<fg=red;options=bold>' . $exception->getMessage() . '</>';
        }

        return $section;
    }
}
