<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Contracts;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * Where a shop keeps an order's PaymentIntent. A contract of this bridge,
 * not of the core: the core deliberately has no storage port for intents,
 * because where an order's payment record lives is the shop's business —
 * Bagisto keeps it in `order_payment.additional`, a custom application in a
 * column of its own orders table.
 *
 * It exists so every Laravel shop does not invent the same two methods, and
 * so StatusResponder::respondFor() can load, match and save in one call.
 *
 * Store the record as PaymentIntent::toArray() and read it back with
 * PaymentIntent::fromArray(); the serialized shape is the cross-plugin
 * contract, schema_version included.
 */
interface PaymentIntentStore
{
    /**
     * The intent last saved for this order, or null when the order has none
     * (unknown reference, or not paid with LedgerDirect).
     */
    public function find(string $orderRef): ?PaymentIntent;

    public function save(string $orderRef, PaymentIntent $intent): void;
}
