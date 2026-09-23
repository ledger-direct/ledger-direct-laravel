<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Events;

/**
 * The order is paid — the only terminal state. What that means for the
 * order (status, invoice, mails) is the listener's business.
 */
final class PaymentSettled extends PaymentStateChanged
{
}
