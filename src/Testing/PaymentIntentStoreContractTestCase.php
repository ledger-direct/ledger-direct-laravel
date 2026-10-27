<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Testing;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;
use PHPUnit\Framework\TestCase;

/**
 * The contract every PaymentIntentStore has to keep, as a test a shop
 * package extends: implement store() — and, for a store that needs a
 * booted Laravel application, override setUp() or extend this from a
 * Testbench-based copy.
 *
 * Atomicity of replace() under real concurrency is the implementation's to
 * prove against its own database; this suite pins the semantics.
 *
 * Needs PHPUnit, which this package only requires for development: the
 * class is only ever loaded by a test that extends it.
 */
abstract class PaymentIntentStoreContractTestCase extends TestCase
{
    /** A fresh store, in which the order references used here are unknown. */
    abstract protected function store(): PaymentIntentStore;

    public function testAnUnknownOrderHasNoIntent(): void
    {
        self::assertNull($this->store()->find('order-unknown'));
    }

    public function testSaveThenFindReturnsAnEqualRecord(): void
    {
        $store = $this->store();
        $intent = self::intent(4242)->withFulfillment('HASH', 12.5, 'C000006400000001');

        $store->save('order-1', $intent);

        self::assertSame($intent->toArray(), $store->find('order-1')?->toArray());
    }

    public function testSaveOverwrites(): void
    {
        $store = $this->store();
        $store->save('order-1', self::intent(1));
        $store->save('order-1', self::intent(2));

        self::assertSame(2, $store->find('order-1')?->destinationTag);
    }

    /** "Still expected" means an equal record, not the same object. */
    public function testReplaceSucceedsWhenTheStoredIntentIsStillTheExpectedOne(): void
    {
        $store = $this->store();
        $stored = self::intent(4242);
        $store->save('order-1', $stored);
        $paid = $stored->withFulfillment('HASH', 20.0, 'C000006400000001');

        self::assertTrue($store->replace('order-1', PaymentIntent::fromArray($stored->toArray()), $paid));
        self::assertSame($paid->toArray(), $store->find('order-1')?->toArray());
    }

    /** The losing poll of two concurrent ones: its expectation is stale. */
    public function testReplaceRefusesAStaleExpectationAndChangesNothing(): void
    {
        $store = $this->store();
        $original = self::intent(4242);
        $store->save('order-1', $original);
        $winner = $original->withFulfillment('HASH_A', 20.0, 'C000006400000001');
        self::assertTrue($store->replace('order-1', $original, $winner));

        $loser = $original->withFulfillment('HASH_B', 20.0, 'C000006500000001');

        self::assertFalse($store->replace('order-1', $original, $loser));
        self::assertSame('HASH_A', $store->find('order-1')?->hash);
    }

    public function testReplaceRefusesAnUnknownOrderAndCreatesNothing(): void
    {
        $store = $this->store();

        self::assertFalse($store->replace('order-unknown', self::intent(1), self::intent(2)));
        self::assertNull($store->find('order-unknown'));
    }

    public function testOrdersAreIndependent(): void
    {
        $store = $this->store();
        $store->save('order-1', self::intent(1));
        $store->save('order-2', self::intent(2));

        self::assertTrue($store->replace('order-2', self::intent(2), self::intent(3)));
        self::assertSame(1, $store->find('order-1')?->destinationTag);
        self::assertSame(3, $store->find('order-2')?->destinationTag);
    }

    /**
     * An XRP quote of 20.0 on testnet. Override for a store that only holds
     * other assets.
     */
    protected static function intent(int $destinationTag): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 0.5,
            amountRequested: 20.0,
            destinationAccount: 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh',
            destinationTag: $destinationTag,
            expiry: 1_800_000_300,
        );
    }
}
