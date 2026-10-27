<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Console;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Stellar\Schema as StellarSchema;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function testSyncCallsTheNodeExactlyOnceAndStoresTheRows(): void
    {
        $http = $this->fakeHttp();
        $http->queueResponse('s.altnet.rippletest.net', self::accountTx([
            self::xrpPayment('HASH_1', 101, 1_000_000, 500),
            self::xrpPayment('HASH_2', 102, 2_000_000, 501),
        ]));

        $this->artisan('ledger-direct:sync')
            ->expectsOutputToContain('Synced ' . self::ACCOUNT . ' on XRPL testnet')
            ->assertSuccessful();

        self::assertCount(1, $http->sentRequests());
        self::assertSame(2, DB::table(Schema::TABLE_TX)->count());
    }

    public function testSyncIsNotThrottled(): void
    {
        $http = $this->fakeHttp();
        $http->queueResponse('s.altnet.rippletest.net', self::accountTx());
        $http->queueResponse('s.altnet.rippletest.net', self::accountTx());

        $this->artisan('ledger-direct:sync')->assertSuccessful();
        $this->artisan('ledger-direct:sync')->assertSuccessful();

        self::assertCount(2, $http->sentRequests());
    }

    public function testSyncReportsANodeErrorAndFails(): void
    {
        $this->fakeHttp()->queueResponse('s.altnet.rippletest.net', new Response(503));

        $this->artisan('ledger-direct:sync')
            ->expectsOutputToContain('returned HTTP 503')
            ->assertFailed();
    }

    public function testSyncExplainsAMissingAccount(): void
    {
        $this->app['config']->set('ledger-direct.chains.xrpl.destination_account', '');
        $this->fakeHttp();

        $this->artisan('ledger-direct:sync')
            ->expectsOutputToContain('LEDGER_DIRECT_XRPL_ACCOUNT')
            ->assertFailed();
    }

    public function testSyncRejectsAnUnknownChain(): void
    {
        $this->artisan('ledger-direct:sync', ['--chain' => 'xahau'])
            ->expectsOutputToContain('Unsupported chain "xahau". Supported: xrpl, stellar.')
            ->assertExitCode(2);
    }

    public function testSyncRefusesADisabledChain(): void
    {
        $this->artisan('ledger-direct:sync', ['--chain' => 'stellar'])
            ->expectsOutputToContain('Stellar is not enabled')
            ->assertFailed();
    }

    public function testSyncOfStellarStoresThePaymentsFromHorizon(): void
    {
        $this->enableStellar();
        $http = $this->fakeHttp();
        $http->queueResponse('/payments', self::horizonPayments([
            self::stellarPayment('TX1', 1001, '5.0000000', ledger: 2000),
            self::stellarPayment('TX2', 1002, '1.5000000', ledger: 2001),
        ]));

        $this->artisan('ledger-direct:sync', ['--chain' => 'stellar'])
            ->expectsOutputToContain('Synced ' . self::STELLAR_ACCOUNT . ' on STELLAR testnet')
            ->assertSuccessful();

        self::assertCount(1, $http->sentRequests());
        self::assertSame(['1001', '1002'], DB::table(StellarSchema::TABLE_PAYMENT)->orderBy('payment_id')->pluck('memo_id')->map(static fn ($m): string => (string) $m)->all());
    }

    /** Without --chain, the scheduled sync covers every enabled chain. */
    public function testSyncWithoutAChainSyncsEveryEnabledChain(): void
    {
        $this->enableStellar();
        $http = $this->fakeHttp();
        $http->queueResponse('s.altnet.rippletest.net', self::accountTx());
        $http->queueResponse('/payments', self::horizonPayments());

        $this->artisan('ledger-direct:sync')
            ->expectsOutputToContain('on XRPL testnet')
            ->expectsOutputToContain('on STELLAR testnet')
            ->assertSuccessful();

        self::assertCount(2, $http->sentRequests());
    }

    public function testOneChainFailingDoesNotStopTheOther(): void
    {
        $this->enableStellar();
        $http = $this->fakeHttp();
        $http->queueResponse('s.altnet.rippletest.net', new Response(503));
        $http->queueResponse('/payments', self::horizonPayments([self::stellarPayment('TX1', 1001, '5.0000000')]));

        $this->artisan('ledger-direct:sync')
            ->expectsOutputToContain('XRP Ledger: ')
            ->expectsOutputToContain('on STELLAR testnet')
            ->assertFailed();

        self::assertSame(1, DB::table(StellarSchema::TABLE_PAYMENT)->count());
    }

    public function testPriceShowsTheRate(): void
    {
        $http = $this->fakeHttp();
        $http->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.50"}'));
        $http->queueResponse('api.coingecko.com', new Response(200, [], '{"ripple":{"usd":0.50}}'));
        $http->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXRPZUSD":{"c":["0.50","100"]}}}'));

        $this->artisan('ledger-direct:price', ['asset' => 'xrp', 'currency' => 'usd'])
            ->expectsOutputToContain('XRP/USD')
            ->expectsOutputToContain('1 XRP = 0.5 USD')
            ->expectsOutputToContain('2 XRP')
            ->assertSuccessful();
    }

    public function testPriceOfXlmUsesTheStellarOracleSet(): void
    {
        $http = $this->fakeHttp();
        $http->queueResponse('api.binance.com', new Response(200, [], '{"price":"0.25"}'));
        $http->queueResponse('api.coingecko.com', new Response(200, [], '{"stellar":{"eur":0.25}}'));
        $http->queueResponse('api.kraken.com', new Response(200, [], '{"result":{"XXLMZEUR":{"c":["0.25","100"]}}}'));

        $this->artisan('ledger-direct:price', ['asset' => 'xlm', 'currency' => 'eur'])
            ->expectsOutputToContain('XLM/EUR')
            ->expectsOutputToContain('Stellar')
            ->expectsOutputToContain('1 XLM = 0.25 EUR')
            ->expectsOutputToContain('4 XLM')
            ->assertSuccessful();
    }

    /** EURC is pegged to the euro: no oracle is asked. */
    public function testPriceOfEurcInEuroTakesThePeg(): void
    {
        $this->fakeHttp();

        $this->artisan('ledger-direct:price', ['asset' => 'EURC', 'currency' => 'EUR'])
            ->expectsOutputToContain('1 EURC = 1 EUR')
            ->assertSuccessful();
    }

    public function testPriceRefusesAnAssetTheNamedChainDoesNotList(): void
    {
        $this->fakeHttp();

        $this->artisan('ledger-direct:price', ['asset' => 'XLM', 'currency' => 'EUR', '--chain' => 'xrpl'])
            ->expectsOutputToContain('no chain lists XLM on the chain "xrpl"')
            ->assertExitCode(2);
    }

    public function testPriceNamesAnUnsupportedAsset(): void
    {
        $this->fakeHttp();

        // The bridge's own wording; the core's reason follows it and is the core's to phrase.
        $this->artisan('ledger-direct:price', ['asset' => 'DOGE', 'currency' => 'EUR'])
            ->expectsOutputToContain('Cannot price DOGE in EUR')
            ->assertExitCode(2);
    }
}
