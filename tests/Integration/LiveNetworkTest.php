<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Against the real world: the price oracles and the XRPL testnet. Not part
 * of the default run; `vendor/bin/phpunit --testsuite integration`.
 *
 * The sync test needs a funded testnet account in
 * LEDGER_DIRECT_TEST_XRPL_ACCOUNT — the testnet is reset now and then, so
 * no address is hard-coded here.
 */
final class LiveNetworkTest extends TestCase
{
    public function testPriceAsksTheRealOracles(): void
    {
        $this->artisan('ledger-direct:price', ['asset' => 'XRP', 'currency' => 'USD'])
            ->expectsOutputToContain('XRP/USD')
            ->assertSuccessful();
    }

    public function testSyncReadsTheRealTestnet(): void
    {
        $account = getenv('LEDGER_DIRECT_TEST_XRPL_ACCOUNT');

        if ($account === false || $account === '') {
            self::markTestSkipped('LEDGER_DIRECT_TEST_XRPL_ACCOUNT is not set.');
        }

        $this->createTables();
        $this->app['config']->set('ledger-direct.chains.xrpl.destination_account', $account);

        $this->artisan('ledger-direct:sync')->assertSuccessful();
        $this->artisan('ledger-direct:sync')->assertSuccessful();

        $hashes = DB::table(Schema::TABLE_TX)->pluck('hash');
        self::assertGreaterThan(0, $hashes->count(), 'a funded account has at least its funding payment');
        self::assertSame($hashes->count(), $hashes->unique()->count(), 'a second sync stores nothing twice');
    }
}
