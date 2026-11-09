<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Xahau;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentPartiallyReceived;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentReceivedInWrongAsset;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentSettled;
use Hardcastle\LedgerDirect\Laravel\LedgerDirectServiceProvider;
use Hardcastle\LedgerDirect\Laravel\Status\StatusResponder;
use Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * The same status flow for an intent that names Xahau: the responder takes
 * the Xahau root by the intent's chain, syncs Xahau's node through it and
 * matches against Xahau's own table.
 */
final class XahauStatusTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeHttpClient $http;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->enableXahau();

        $this->http = $this->fakeHttp();
        $this->recordLogs();
        $this->clock = new FrozenClock(self::NOW);
        $this->app->instance(LedgerDirectServiceProvider::CLOCK, $this->clock);
        $this->app->instance(LedgerDirectServiceProvider::CACHE, new InMemoryCache($this->clock));
        Event::fake([PaymentSettled::class, PaymentPartiallyReceived::class, PaymentReceivedInWrongAsset::class]);
    }

    public function testAPartialPaymentThenATopUpSettleOnXahau(): void
    {
        $store = new InMemoryPaymentIntentStore();
        $store->save('order-x', self::xahIntent(10.0, expiry: self::NOW + 300, tag: 5150));

        $this->http->queueResponse(self::XAHAU_NODE, self::accountTx([self::xrpPayment('XA', 5150, 4_000_000, 100, self::XAHAU_ACCOUNT)]));
        $partial = $this->responder()->respondFor('order-x', $store);

        self::assertSame(PaymentStatus::PARTIAL, $partial?->status->state());
        Event::assertDispatched(PaymentPartiallyReceived::class, static fn (PaymentPartiallyReceived $e): bool
            => $e->orderRef === 'order-x' && $e->intent->chain === 'XAHAU');

        $this->clock->advance(PaymentStatus::MIN_SYNC_INTERVAL_SECONDS);
        $this->http->queueResponse(self::XAHAU_NODE, self::accountTx([self::xrpPayment('XB', 5150, 6_000_000, 101, self::XAHAU_ACCOUNT)]));
        $settled = $this->responder()->respondFor('order-x', $store);

        self::assertSame(PaymentStatus::SETTLED, $settled?->status->state());
        self::assertSame(10.0, $store->find('order-x')?->amountPaid);
        self::assertSame('XB', $store->find('order-x')?->hash);
        Event::assertDispatchedTimes(PaymentSettled::class, 1);
        self::assertStringContainsString(self::XAHAU_NODE, (string) $this->http->lastRequest()?->getUri());
    }

    /**
     * The same keys open the same address on both ledgers. A payment on the
     * XRP Ledger, same account and tag, sits in the other chain's table and
     * must not settle a Xahau order.
     */
    public function testAPaymentOnTheXrpLedgerDoesNotSettleAXahauOrder(): void
    {
        $this->app->make(XrplTransactionRepositoryInterface::class)->saveTransactions([
            new XrplTransaction(
                network: 'testnet',
                ledgerIndex: '100',
                hash: 'HASH_ON_XRPL',
                ctid: 'C000006400000001',
                account: 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh',
                destination: self::XAHAU_ACCOUNT,
                destinationTag: 4242,
                date: 800000000,
                meta: ['delivered_amount' => '10000000'],
                tx: [],
            ),
        ]);
        $this->http->queueResponse(self::XAHAU_NODE, self::accountTx());

        $result = $this->responder()->respond(self::xahIntent(10.0, expiry: self::NOW + 300));

        self::assertSame(PaymentStatus::WAITING, $result->status->state());
        self::assertNull($result->intent->hash);
        Event::assertNothingDispatched();
    }

    /** The throttle key carries the chain: the same address on both ledgers is two accounts. */
    public function testTheThrottleIsPerChainForTheSameAddress(): void
    {
        $this->http->queueResponse(self::XAHAU_NODE, self::accountTx());
        $this->http->queueResponse('s.altnet.rippletest.net', self::accountTx());

        $xahau = $this->responder()->respond(self::xahIntent(10.0));
        $intentOnXrpl = PaymentIntent::fromArray(['destination_account' => self::XAHAU_ACCOUNT] + self::xrpIntent(10.0)->toArray());
        $xrpl = $this->responder()->respond($intentOnXrpl);

        self::assertTrue($xahau->synced);
        self::assertTrue($xrpl->synced, 'throttled on Xahau says nothing about the XRP Ledger');
        self::assertCount(2, $this->http->sentRequests());
    }

    public function testANodeRefusingTheCallAnswersFromWhatIsStored(): void
    {
        $this->http->queueResponse(self::XAHAU_NODE, new Response(429, ['Retry-After' => '30']));

        $result = $this->responder()->respond(self::xahIntent(10.0, expiry: self::NOW + 300));

        self::assertSame(PaymentStatus::WAITING, $result->status->state());
        Event::assertNothingDispatched();
    }

    private function responder(): StatusResponder
    {
        return $this->app->make(StatusResponder::class);
    }
}
