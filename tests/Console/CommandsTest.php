<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Console;

use GuzzleHttp\Psr7\Response;
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
        $this->artisan('ledger-direct:sync', ['--chain' => 'stellar'])
            ->expectsOutputToContain('Unsupported chain "stellar"')
            ->assertExitCode(2);
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

    public function testPriceNamesAnUnsupportedAsset(): void
    {
        $this->fakeHttp();

        $this->artisan('ledger-direct:price', ['asset' => 'DOGE', 'currency' => 'EUR'])
            ->expectsOutputToContain("Unsupported base_asset 'DOGE'")
            ->assertExitCode(2);
    }
}
