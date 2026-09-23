<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Events;

/**
 * Money arrived in the quoted asset, but not enough to settle. `amount_paid`
 * is the sum of every such payment on the tag, `shortfall` what is missing.
 */
final class PaymentPartiallyReceived extends PaymentStateChanged
{
}
