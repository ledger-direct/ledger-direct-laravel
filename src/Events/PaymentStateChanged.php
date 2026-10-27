<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Events;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Common shape of the three payment events, dispatched when a sync moves an
 * order into the state — never for a poll that finds the order where it
 * already was, and through StatusResponder::respondFor() only by the request
 * whose compare-and-set stored the change.
 *
 * Delivery is at most once per stored change: a synchronous listener that
 * throws loses the event, because the change is already stored and the next
 * poll sees nothing new. Make listeners queued (ShouldQueue) so the queue
 * retries them, and idempotent, because a retry can repeat work. Inside a
 * database transaction the event waits for the commit and is dropped on a
 * rollback (ShouldDispatchAfterCommit).
 *
 * `$orderRef` is whatever the shop passed to the StatusResponder, so a
 * listener can find its order; null when the shop called respond() without
 * one.
 */
abstract class PaymentStateChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly ?string $orderRef,
        public readonly PaymentIntent $intent,
        public readonly PaymentIntent $previousIntent,
        public readonly PaymentStatus $status,
        public readonly string $previousState,
    ) {
    }
}
