<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\LedgerDirectStellar;
use Hardcastle\LedgerDirect\Laravel\Chains\LedgerDirectChains;
use Hardcastle\LedgerDirect\Laravel\Chains\UnsupportedChainException;
use Hardcastle\LedgerDirect\Laravel\Clock\LaravelClock;
use Hardcastle\LedgerDirect\Laravel\Config\InvalidConfigurationException;
use Hardcastle\LedgerDirect\Laravel\Config\LaravelConfigProvider;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirect as LedgerDirectFacade;
use Hardcastle\LedgerDirect\Laravel\Facades\LedgerDirectStellar as LedgerDirectStellarFacade;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseStellarPaymentRepository;
use Hardcastle\LedgerDirect\Laravel\Repository\DatabaseXrplTransactionRepository;
use Illuminate\Support\Facades\Artisan;

final class ServiceProviderTest extends TestCase
{
    public function testTheRootIsASingletonAndTheFacadePointsAtIt(): void
    {
        $root = $this->app->make(LedgerDirect::class);

        self::assertSame($root, $this->app->make(LedgerDirect::class));
        self::assertSame($root, LedgerDirectFacade::getFacadeRoot());
        self::assertSame($root->priceService(), LedgerDirectFacade::priceService());
    }

    public function testThePortsResolveToTheBridgeImplementations(): void
    {
        $root = $this->app->make(LedgerDirect::class);

        self::assertInstanceOf(DatabaseXrplTransactionRepository::class, $root->transactionRepository());
        self::assertInstanceOf(LaravelConfigProvider::class, $root->configProvider());
        self::assertInstanceOf(LaravelClock::class, $root->clock());
        self::assertSame($root->transactionRepository(), $this->app->make(XrplTransactionRepositoryInterface::class));
        self::assertSame($root->configProvider(), $this->app->make(ConfigProviderInterface::class));
    }

    public function testTheStellarRootIsASingletonOnTheSameInfrastructure(): void
    {
        $stellar = $this->app->make(LedgerDirectStellar::class);
        $xrpl = $this->app->make(LedgerDirect::class);

        self::assertSame($stellar, $this->app->make(LedgerDirectStellar::class));
        self::assertSame($stellar, LedgerDirectStellarFacade::getFacadeRoot());
        self::assertInstanceOf(DatabaseStellarPaymentRepository::class, $stellar->paymentRepository());
        self::assertSame($xrpl->configProvider(), $stellar->configProvider());
        self::assertSame($xrpl->clock(), $stellar->clock());
    }

    /** One cache store for both roots, warned about once. */
    public function testAnArrayCacheWarnsOnceForBothRoots(): void
    {
        $logger = $this->recordLogs();

        $this->app->make(LedgerDirect::class);
        $this->app->make(LedgerDirectStellar::class);

        $warnings = array_filter($logger->records(), static fn (array $r): bool => $r['level'] === 'warning');
        self::assertCount(1, $warnings);
    }

    public function testTheChainsPickTheRootByTheIntent(): void
    {
        $chains = $this->app->make(LedgerDirectChains::class);

        self::assertSame($this->app->make(LedgerDirect::class), $chains->forIntent(self::xrpIntent(1.0)));
        self::assertSame($this->app->make(LedgerDirectStellar::class), $chains->forIntent(self::xlmIntent(1.0)));
        self::assertStringStartsWith('https://xrplf.org//send?', $chains->paymentUri(self::xrpIntent(1.0), '1'));
        self::assertStringStartsWith('web+stellar:pay?destination=' . self::STELLAR_ACCOUNT, $chains->paymentUri(self::xlmIntent(1.0), '1'));
        self::assertStringContainsString('memo=4242', $chains->paymentUri(self::xlmIntent(1.0), '1'));
    }

    public function testAnUnknownChainHasNoRoot(): void
    {
        $this->expectException(UnsupportedChainException::class);

        $this->app->make(LedgerDirectChains::class)->forChain('XAHAU');
    }

    public function testTheRepositoryUsesTheConfiguredConnection(): void
    {
        $this->app['config']->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->app['config']->set('ledger-direct.connection', 'other');

        $repository = $this->app->make(XrplTransactionRepositoryInterface::class);

        self::assertInstanceOf(DatabaseXrplTransactionRepository::class, $repository);
        self::assertSame('other', $repository->connection()->getName());
    }

    public function testAnArrayCacheLogsAWarningOnce(): void
    {
        $logger = $this->recordLogs();

        $this->app->make(LedgerDirect::class);
        $this->app->make(LedgerDirect::class);

        $warnings = array_values(array_filter($logger->records(), static fn (array $r): bool => $r['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('does not persist between requests', $warnings[0]['message']);
    }

    public function testAPersistentCacheLogsNothing(): void
    {
        $this->app['config']->set('cache.stores.files', ['driver' => 'file', 'path' => sys_get_temp_dir() . '/ledger-direct-test-cache']);
        $this->app['config']->set('ledger-direct.cache_store', 'files');
        $logger = $this->recordLogs();

        $root = $this->app->make(LedgerDirect::class);

        self::assertSame(0, $logger->count());
        self::assertNotNull($root->syncThrottle());
    }

    public function testAMissingAccountIsAnInstructionNotATypeError(): void
    {
        $this->app['config']->set('ledger-direct.chains.xrpl.destination_account', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set LEDGER_DIRECT_XRPL_ACCOUNT in your .env');

        // USD-peg fast path for RLUSD: no oracle call before the account is needed.
        $this->app['config']->set('ledger-direct.chains.xrpl.assets.RLUSD', true);
        $this->app->make(LedgerDirect::class)->paymentIntentService()->quoteForOrder(10.0, 'USD', 'RLUSD');
    }

    public function testAnUnconfiguredAppStillBoots(): void
    {
        $this->app['config']->set('ledger-direct.chains.xrpl.destination_account', null);

        self::assertInstanceOf(LedgerDirect::class, $this->app->make(LedgerDirect::class));
    }

    public function testToleranceAndFreshTtlComeFromTheConfig(): void
    {
        $this->app['config']->set('ledger-direct.native_asset_tolerance', 0.00001);

        $policy = $this->app->make(LedgerDirect::class)->settlementPolicy();

        self::assertTrue($policy->isSettled(self::xrpIntent(100.0)->withFulfillment('H', 99.999)));
        self::assertFalse($policy->isSettled(self::xrpIntent(100.0)->withFulfillment('H', 99.99)));
    }

    public function testAnInvalidToleranceIsReportedByKey(): void
    {
        $this->app['config']->set('ledger-direct.native_asset_tolerance', ['nope']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ledger-direct.native_asset_tolerance');

        $this->app->make(LedgerDirect::class);
    }

    public function testConfigAndMigrationsArePublishable(): void
    {
        Artisan::call('vendor:publish', ['--tag' => 'ledger-direct-config', '--force' => true]);
        Artisan::call('vendor:publish', ['--tag' => 'ledger-direct-migrations', '--force' => true]);

        self::assertFileExists(config_path('ledger-direct.php'));
        $published = glob(database_path('migrations/*_create_ledger_direct_xrpl_tables.php')) ?: [];
        self::assertCount(1, $published);

        @unlink(config_path('ledger-direct.php'));
        array_map('unlink', $published);
    }

    public function testTheAboutCommandShowsTheSection(): void
    {
        Artisan::call('about', ['--only' => 'ledgerdirect']);
        $output = Artisan::output();

        self::assertStringContainsString('LedgerDirect', $output);
        self::assertMatchesRegularExpression('/XRP Ledger \\.+ testnet · rHb9CJ…tyTh · XRP/u', $output);
        self::assertMatchesRegularExpression('/Stellar \\.+ off/u', $output);
    }

    public function testTheAboutCommandShowsAnEnabledStellar(): void
    {
        $this->enableStellar(['EURC' => true]);

        Artisan::call('about', ['--only' => 'ledgerdirect']);

        self::assertMatchesRegularExpression('/Stellar \\.+ testnet · GAHK7E…DB4A · XLM, EURC/u', Artisan::output());
    }
}
