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
 * It exists so every Laravel shop does not invent the same methods, and so
 * StatusResponder::respondFor() can load, match and save in one call.
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

    /**
     * Stores the intent unconditionally — for a new quote or a refreshed one,
     * where nothing else competes for the record.
     */
    public function save(string $orderRef, PaymentIntent $intent): void;

    /**
     * Compare-and-set: stores $intent only if the order's stored intent is
     * still $expected, and says whether it did. Returns false, and changes
     * nothing, when the order has no intent or a different one.
     *
     * Two status polls for the same order can run at once, load the same
     * intent and match the same payment. Only the one whose replace()
     * succeeds dispatches the event; without this, both would, and the shop
     * would invoice or mail twice.
     *
     * MUST be atomic against a concurrent replace() or save() for the same
     * order: compare and write in one statement (`UPDATE … WHERE` on the
     * serialized record), or read the row with lockForUpdate() and write it
     * in the same transaction. "Still $expected" means equal records —
     * compare `toArray()` of both sides, not object identity and not raw
     * JSON strings, whose key order or float formatting may differ.
     * Testing\PaymentIntentStoreContractTestCase checks the semantics.
     */
    public function replace(string $orderRef, PaymentIntent $expected, PaymentIntent $intent): bool;
}
