<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Stellar;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentPartiallyReceived;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentReceivedInWrongAsset;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentSettled;
use Hardcastle\LedgerDirect\Laravel\LedgerDirectServiceProvider;
use Hardcastle\LedgerDirect\Laravel\Status\StatusResponder;
use Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The same status flow as on the XRP Ledger, for an intent that names
 * Stellar: the responder picks the Stellar root by the intent's chain,
 * syncs Horizon through it (throttled), matches by memo id and dispatches
 * the same events.
 */
final class StellarStatusTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeHttpClient $http;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->enableStellar();

        $this->http = $this->fakeHttp();
        $this->recordLogs();
        $this->clock = new FrozenClock(self::NOW);
        $this->app->instance(LedgerDirectServiceProvider::CLOCK, $this->clock);
        $this->app->instance(LedgerDirectServiceProvider::CACHE, new InMemoryCache($this->clock));
        Event::fake([PaymentSettled::class, PaymentPartiallyReceived::class, PaymentReceivedInWrongAsset::class]);
    }

    public function testAPartialPaymentThenATopUpByMemoIdSettle(): void
    {
        $store = new InMemoryPaymentIntentStore();
        $store->save('order-x', self::xlmIntent(10.0, expiry: self::NOW + 300, memoId: 777001));

        $this->http->queueResponse('/payments', self::horizonPayments([self::stellarPayment('TXA', 777001, '4.0000000', ledger: 1000)]));
        $partial = $this->responder()->respondFor('order-x', $store);

        self::assertSame(PaymentStatus::PARTIAL, $partial?->status->state());
        self::assertSame(4.0, $store->find('order-x')?->amountPaid);
        Event::assertDispatched(PaymentPartiallyReceived::class, static fn (PaymentPartiallyReceived $e): bool
            => $e->orderRef === 'order-x' && $e->intent->chain === 'STELLAR');

        // The second sync runs from the stored cursor: Horizon's root first
        // (testnet-reset check), then the payments after it.
        $this->clock->advance(PaymentStatus::MIN_SYNC_INTERVAL_SECONDS);
        $this->http->queueResponse('/payments', self::horizonPayments([self::stellarPayment('TXB', 777001, '6.0000000', ledger: 1001)]));
        $this->http->queueResponse(self::HORIZON, new Response(200, [], '{"history_latest_ledger": 1001}'));
        $settled = $this->responder()->respondFor('order-x', $store);

        self::assertSame(PaymentStatus::SETTLED, $settled?->status->state());
        self::assertSame(10.0, $store->find('order-x')?->amountPaid);
        self::assertSame('TXB', $store->find('order-x')?->hash);
        self::assertNull($store->find('order-x')?->ctid, 'a CTID is XRPL only');
        Event::assertDispatchedTimes(PaymentSettled::class, 1);
    }

    public function testTheThrottleHoldsForAStellarAccountToo(): void
    {
        $this->http->queueResponse('/payments', self::horizonPayments());

        $this->responder()->respond(self::xlmIntent(10.0, memoId: 1));
        $second = $this->responder()->respond(self::xlmIntent(20.0, memoId: 2));

        self::assertFalse($second->synced);
        self::assertCount(1, $this->http->sentRequests(), 'two waiting customers, one Horizon request');
    }

    public function testHorizonDownAnswersFromWhatIsStored(): void
    {
        $this->http->queueResponse('/payments', new Response(503));

        $result = $this->responder()->respond(self::xlmIntent(10.0, expiry: self::NOW + 300));

        self::assertSame(PaymentStatus::WAITING, $result->status->state());
        Event::assertNothingDispatched();
    }

    public function testAnXrplIntentStillGoesToTheXrplRoot(): void
    {
        $this->http->queueResponse('s.altnet.rippletest.net', self::accountTx([self::xrpPayment('HX', 4242, 10_000_000)]));

        $result = $this->responder()->respond(self::xrpIntent(10.0));

        self::assertSame(PaymentStatus::SETTLED, $result->status->state());
        self::assertStringContainsString('rippletest.net', (string) $this->http->lastRequest()?->getUri());
    }

    private function responder(): StatusResponder
    {
        return $this->app->make(StatusResponder::class);
    }
}
