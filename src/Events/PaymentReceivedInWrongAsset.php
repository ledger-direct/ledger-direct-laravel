<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Events;

/**
 * Money arrived, but in another currency or from another issuer, and
 * nothing in the quoted asset. Nothing is credited; the merchant has to
 * look at it.
 */
final class PaymentReceivedInWrongAsset extends PaymentStateChanged
{
}
