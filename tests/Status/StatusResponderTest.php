<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Status;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentPartiallyReceived;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentReceivedInWrongAsset;
use Hardcastle\LedgerDirect\Laravel\Events\PaymentSettled;
use Hardcastle\LedgerDirect\Laravel\LedgerDirectServiceProvider;
use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Status\StatusResponder;
use Hardcastle\LedgerDirect\Laravel\Testing\InMemoryPaymentIntentStore;
use Hardcastle\LedgerDirect\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class StatusResponderTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const NODE = 's.altnet.rippletest.net';
    private const RLUSD_HEX = '524C555344000000000000000000000000000000';

    private FakeHttpClient $http;
    private FrozenClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->http = $this->fakeHttp();
        $this->logger = $this->recordLogs();
        $this->clock = new FrozenClock(self::NOW);
        $this->app->instance(LedgerDirectServiceProvider::CLOCK, $this->clock);
        $this->app->instance(LedgerDirectServiceProvider::CACHE, new InMemoryCache($this->clock));
    }

    public function testAnswersWithTheCoreStatusPayload(): void
    {
        $this->http->queueResponse(self::NODE, self::accountTx());

        $result = $this->responder()->respond(self::xrpIntent(10.0, expiry: self::NOW + 300));

        self::assertSame([
            'schema_version' => 1,
            'state' => 'waiting',
            'base_asset' => 'XRP',
            'amount_requested' => 10.0,
            'amount_paid' => null,
            'shortfall' => null,
            'seconds_left' => 300,
        ], $result->toArray());
        self::assertTrue($result->synced);
        self::assertFalse($result->changed());
    }

    public function testASecondPollInsideTheIntervalMakesNoNodeRequest(): void
    {
        $this->http->queueResponse(self::NODE, self::accountTx());
        $intent = self::xrpIntent(10.0, expiry: self::NOW + 300);

        $this->responder()->respond($intent);
        $this->clock->advance(PaymentStatus::MIN_SYNC_INTERVAL_SECONDS - 1);
        $second = $this->responder()->respond($intent);

        self::assertCount(1, $this->http->sentRequests());
        self::assertFalse($second->synced);
        self::assertSame('waiting', $second->status->state(), 'answered from what is stored, same shape');

        $this->clock->advance(1);
        $this->http->queueResponse(self::NODE, self::accountTx());
        self::assertTrue($this->responder()->respond($intent)->synced);
        self::assertCount(2, $this->http->sentRequests());
    }

    public function testTheThrottleIsPerAccountNotPerOrder(): void
    {
        $this->http->queueResponse(self::NODE, self::accountTx());

        $this->responder()->respond(self::xrpIntent(10.0, tag: 1001));
        $this->responder()->respond(self::xrpIntent(20.0, tag: 1002));

        self::assertCount(1, $this->http->sentRequests(), 'two waiting customers, one node request');
    }

    public function testTheMarkIsSetBeforeTheRequestSoAFailingNodeIsNotHammered(): void
    {
        $this->http->queueResponse(self::NODE, new ConnectException('timed out', new Request('POST', 'https://' . self::NODE)));
        $intent = self::xrpIntent(10.0, expiry: self::NOW + 300);

        $first = $this->responder()->respond($intent);
        $second = $this->responder()->respond($intent);

        self::assertSame('waiting', $first->status->state(), 'a node failure still answers');
        self::assertFalse($second->synced, 'marked although the request failed');
        self::assertCount(1, $this->http->sentRequests());
        self::assertStringContainsString('status sync failed', $this->lastWarning());
    }

    public function testASettledIntentMakesNoRequestAndFiresNothing(): void
    {
        $this->fakeEvents();
        $settled = self::xrpIntent(10.0)->withFulfillment('HASH', 10.0, 'C000');

        $result = $this->responder()->respond($settled, 'order-1');

        self::assertSame('settled', $result->status->state());
        self::assertSame([], $this->http->sentRequests());
        Event::assertNothingDispatched();
    }

    public function testAPartialPaymentThenATopUpFireOneEventEach(): void
    {
        $this->fakeEvents();
        $intent = self::xrpIntent(10.0, expiry: self::NOW + 300, tag: 777);

        $this->http->queueResponse(self::NODE, self::accountTx([self::xrpPayment('H1', 777, 4_000_000, 100)]));
        $partial = $this->responder()->respond($intent, 'order-7');

        self::assertSame('partial', $partial->status->state());
        self::assertTrue($partial->changed());
        Event::assertDispatchedTimes(PaymentPartiallyReceived::class, 1);
        Event::assertDispatched(PaymentPartiallyReceived::class, static fn (PaymentPartiallyReceived $e): bool
            => $e->orderRef === 'order-7' && $e->previousState === 'waiting' && $e->intent->amountPaid === 4.0);

        // Polled again with the saved intent: still partial, nothing new.
        $this->clock->advance(5);
        $this->http->queueResponse(self::NODE, self::accountTx());
        $again = $this->responder()->respond($partial->intent, 'order-7');
        self::assertFalse($again->changed());
        Event::assertDispatchedTimes(PaymentPartiallyReceived::class, 1);

        $this->clock->advance(5);
        $this->http->queueResponse(self::NODE, self::accountTx([self::xrpPayment('H2', 777, 6_000_000, 101)]));
        $settled = $this->responder()->respond($again->intent, 'order-7');

        self::assertSame('settled', $settled->status->state());
        self::assertSame(10.0, $settled->intent->amountPaid);
        self::assertSame('H2', $settled->intent->hash);
        Event::assertDispatchedTimes(PaymentSettled::class, 1);
        Event::assertDispatchedTimes(PaymentPartiallyReceived::class, 1);
    }

    public function testAPaymentFromAnotherIssuerFiresWrongAsset(): void
    {
        $this->fakeEvents();
        $intent = self::rlusdIntent('25', tag: 555);
        $foreign = self::xrpPayment('HX', 555, 0, 100);
        $foreign['meta']['delivered_amount'] = ['currency' => self::RLUSD_HEX, 'value' => '25', 'issuer' => 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe'];

        $this->http->queueResponse(self::NODE, self::accountTx([$foreign]));
        $result = $this->responder()->respond($intent, 'order-5');

        self::assertSame('wrong_asset', $result->status->state());
        Event::assertDispatched(PaymentReceivedInWrongAsset::class, static fn (PaymentReceivedInWrongAsset $e): bool => $e->orderRef === 'order-5');
        Event::assertNotDispatched(PaymentSettled::class);
    }

    public function testTheQuoteRunningOutFiresNothing(): void
    {
        $this->fakeEvents();
        $intent = self::xrpIntent(10.0, expiry: self::NOW + 3);
        $this->clock->advance(10);
        $this->http->queueResponse(self::NODE, self::accountTx());

        self::assertSame('expired', $this->responder()->respond($intent)->status->state());
        Event::assertNothingDispatched();
    }

    public function testSyncsTheAccountOnTheIntentNotTheConfiguredOne(): void
    {
        $this->app['config']->set('ledger-direct.chains.xrpl.destination_account', 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe');
        $this->http->queueResponse(self::NODE, self::accountTx());

        $this->responder()->respond(self::xrpIntent(10.0));

        $body = json_decode((string) $this->http->lastRequest()?->getBody(), true);
        self::assertSame(self::ACCOUNT, $body['params'][0]['account']);
    }

    /**
     * The same account and the same tag exist on testnet and mainnet. A
     * merchant who switched networks still has testnet payments stored; one
     * of them must not settle a mainnet order. Fixed in core 0.10.0 — the
     * reason this package requires it.
     */
    public function testAPaymentFromTheOtherNetworkDoesNotSettle(): void
    {
        $this->fakeEvents();
        $this->app->make(XrplTransactionRepositoryInterface::class)->saveTransactions([
            new XrplTransaction(
                network: 'testnet',
                ledgerIndex: '100',
                hash: 'HASH_TESTNET',
                ctid: 'C000006400000001',
                account: 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe',
                destination: self::ACCOUNT,
                destinationTag: 4242,
                date: 800000000,
                meta: ['delivered_amount' => '10000000'],
                tx: [],
            ),
        ]);
        $mainnetIntent = PaymentIntent::fromArray(['network' => 'mainnet'] + self::xrpIntent(10.0, expiry: self::NOW + 300)->toArray());
        $this->http->queueResponse('xrplcluster.com', self::accountTx());

        $result = $this->responder()->respond($mainnetIntent, 'order-main');

        self::assertSame('waiting', $result->status->state());
        self::assertNull($result->intent->hash);
        Event::assertNothingDispatched();
    }

    public function testRespondForSavesBeforeTheEventAndOnlyWhenChanged(): void
    {
        $store = new InMemoryPaymentIntentStore();
        $store->save('order-9', self::xrpIntent(10.0, tag: 999));
        $seenByListener = null;
        Event::listen(PaymentSettled::class, static function () use ($store, &$seenByListener): void {
            $seenByListener = $store->find('order-9');
        });

        $this->http->queueResponse(self::NODE, self::accountTx());
        $this->responder()->respondFor('order-9', $store);
        self::assertSame(1, $store->saveCount(), 'nothing changed, nothing written');

        $this->clock->advance(5);
        $this->http->queueResponse(self::NODE, self::accountTx([self::xrpPayment('H9', 999, 10_000_000)]));
        $result = $this->responder()->respondFor('order-9', $store);

        self::assertSame('settled', $result?->status->state());
        self::assertSame(2, $store->saveCount());
        self::assertSame('H9', $store->find('order-9')?->hash);
        self::assertSame('H9', $seenByListener?->hash, 'the listener already reads the saved intent');
    }

    /**
     * Two polls for the same order load the same intent before either has
     * saved; the second does not sync (throttled) but matches the payment the
     * first one stored. Only the compare-and-set winner may dispatch, or the
     * shop invoices twice.
     */
    public function testConcurrentPollsForOneOrderDispatchOnce(): void
    {
        $this->fakeEvents();
        $inner = new InMemoryPaymentIntentStore();
        $inner->save('order-9', self::xrpIntent(10.0, tag: 999));
        $store = new class ($inner) implements PaymentIntentStore {
            private int $staleReads = 2;
            private readonly ?PaymentIntent $snapshot;

            public function __construct(private readonly InMemoryPaymentIntentStore $inner)
            {
                $this->snapshot = $inner->find('order-9');
            }

            // The first two reads happen before either poll has written.
            public function find(string $orderRef): ?PaymentIntent
            {
                return $this->staleReads-- > 0 ? $this->snapshot : $this->inner->find($orderRef);
            }

            public function save(string $orderRef, PaymentIntent $intent): void
            {
                $this->inner->save($orderRef, $intent);
            }

            public function replace(string $orderRef, PaymentIntent $expected, PaymentIntent $intent): bool
            {
                return $this->inner->replace($orderRef, $expected, $intent);
            }
        };
        $this->http->queueResponse(self::NODE, self::accountTx([self::xrpPayment('H9', 999, 10_000_000)]));

        $first = $this->responder()->respondFor('order-9', $store);
        $second = $this->responder()->respondFor('order-9', $store);

        self::assertSame('settled', $first?->status->state());
        self::assertSame('settled', $second?->status->state(), 'the loser answers from what the winner stored');
        self::assertFalse($second->synced);
        Event::assertDispatchedTimes(PaymentSettled::class, 1);
        self::assertSame(2, $inner->saveCount(), 'the initial save and one replace');
    }

    public function testAnEventInsideARolledBackTransactionIsNeverDispatched(): void
    {
        $store = new InMemoryPaymentIntentStore();
        $store->save('order-9', self::xrpIntent(10.0, tag: 999));
        $dispatched = 0;
        Event::listen(PaymentSettled::class, static function () use (&$dispatched): void {
            $dispatched++;
        });
        $this->http->queueResponse(self::NODE, self::accountTx([self::xrpPayment('H9', 999, 10_000_000)]));

        try {
            DB::transaction(function () use ($store): void {
                $this->responder()->respondFor('order-9', $store);
                throw new RuntimeException('the shop rolls back');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $dispatched);
    }

    /** Listeners should be queued so the queue retries them; the events have to survive serialization. */
    public function testTheEventsSurviveSerializationForQueuedListeners(): void
    {
        $intent = self::xrpIntent(10.0)->withFulfillment('H', 10.0, 'C000006400000001');
        $event = new PaymentSettled('order-1', $intent, self::xrpIntent(10.0), $this->app->make(\Hardcastle\LedgerDirect\Core\LedgerDirect::class)->paymentStatus($intent), 'waiting');

        $copy = unserialize(serialize($event));

        self::assertInstanceOf(PaymentSettled::class, $copy);
        self::assertSame($intent->toArray(), $copy->intent->toArray());
        self::assertSame('settled', $copy->status->state());
    }

    public function testRespondForAnUnknownOrderIsNull(): void
    {
        self::assertNull($this->responder()->respondFor('nope', new InMemoryPaymentIntentStore()));
        self::assertSame([], $this->http->sentRequests());
    }

    private static function rlusdIntent(string $value, int $tag): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'USD',
            pairing: 'RLUSD/USD',
            exchangeRate: 1.0,
            amountRequested: ['currency' => self::RLUSD_HEX, 'value' => $value, 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'],
            destinationAccount: self::ACCOUNT,
            destinationTag: $tag,
        );
    }

    private function fakeEvents(): void
    {
        Event::fake([PaymentSettled::class, PaymentPartiallyReceived::class, PaymentReceivedInWrongAsset::class]);
    }

    private function responder(): StatusResponder
    {
        return $this->app->make(StatusResponder::class);
    }

    private function lastWarning(): string
    {
        $warnings = array_filter($this->logger->records(), static fn (array $r): bool => $r['level'] === 'warning');

        return (string) (end($warnings)['message'] ?? '');
    }
}
