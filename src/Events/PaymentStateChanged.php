<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Events;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;

/**
 * Common shape of the three payment events. Each is dispatched once, when a
 * sync moves an order into its state — never again for a poll that finds
 * the order where it already was.
 *
 * `$orderRef` is whatever the shop passed to the StatusResponder, so a
 * listener can find its order; null when the shop called respond() without
 * one.
 */
abstract class PaymentStateChanged
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
