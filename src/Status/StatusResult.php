<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Status;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;

/**
 * What StatusResponder found: the intent after matching, the one it started
 * from, and the status derived from the new one.
 */
final readonly class StatusResult
{
    public function __construct(
        public PaymentIntent $intent,
        public PaymentIntent $previousIntent,
        public PaymentStatus $status,
        /** Whether a node request was made for this answer (false inside the throttle window). */
        public bool $synced,
    ) {
    }

    /**
     * Whether the intent differs from the one passed in — a new payment was
     * matched. When true, the shop has to persist $intent; respondFor() has
     * already done so.
     */
    public function changed(): bool
    {
        return $this->intent->toArray() !== $this->previousIntent->toArray();
    }

    /**
     * The status payload as the core defines it. A shop appends `redirect`
     * itself, once the order has left the waiting page's concern.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->status->toArray();
    }
}
