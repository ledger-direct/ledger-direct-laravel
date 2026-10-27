<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Testing;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Laravel\Contracts\PaymentIntentStore;

/**
 * A PaymentIntentStore in an array, for tests of a shop package. Goes
 * through toArray()/fromArray() like a real store, so a test also proves
 * the record survives serialization.
 */
final class InMemoryPaymentIntentStore implements PaymentIntentStore
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    private int $saves = 0;

    public function find(string $orderRef): ?PaymentIntent
    {
        return isset($this->records[$orderRef]) ? PaymentIntent::fromArray($this->records[$orderRef]) : null;
    }

    public function save(string $orderRef, PaymentIntent $intent): void
    {
        $this->records[$orderRef] = $intent->toArray();
        $this->saves++;
    }

    public function replace(string $orderRef, PaymentIntent $expected, PaymentIntent $intent): bool
    {
        $stored = $this->find($orderRef);

        if ($stored === null || $stored->toArray() !== $expected->toArray()) {
            return false;
        }

        $this->records[$orderRef] = $intent->toArray();
        $this->saves++;

        return true;
    }

    /** How often save() or a successful replace() wrote — for asserting that nothing unchanged is written. */
    public function saveCount(): int
    {
        return $this->saves;
    }
}
