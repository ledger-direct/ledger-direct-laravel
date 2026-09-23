<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests;

use Hardcastle\LedgerDirect\Core\LedgerDirect;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The reason the core took a PSR-20 clock: an application's frozen "now"
 * reaches the core without the application knowing the core has a clock.
 */
final class LaravelClockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function testSetTestNowMovesTheQuoteExpiry(): void
    {
        $this->app['config']->set('ledger-direct.chains.xrpl.assets.RLUSD', true);
        Date::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

        // USD-peg fast path: no oracle request.
        $intent = $this->app->make(LedgerDirect::class)->paymentIntentService()->quoteForOrder(25.0, 'USD', 'RLUSD');

        self::assertSame(1_800_000_300, $intent->expiry);
    }

    public function testSetTestNowMovesSecondsLeftAndExpiresTheQuote(): void
    {
        $core = $this->app->make(LedgerDirect::class);
        $intent = self::xrpIntent(10.0, expiry: 1_800_000_300);

        Date::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        self::assertSame(300, $core->paymentStatus($intent)->toArray()['seconds_left']);

        $this->travel(299)->seconds();
        self::assertSame(1, $core->paymentStatus($intent)->toArray()['seconds_left']);

        $this->travel(1)->seconds();
        self::assertSame(PaymentStatus::EXPIRED, $core->paymentStatus($intent)->state());
    }
}
