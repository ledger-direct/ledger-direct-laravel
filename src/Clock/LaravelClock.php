<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Clock;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * The core's PSR-20 clock, answered by Laravel's date factory. Whatever an
 * application does to "now" in its tests — Date::setTestNow(),
 * Carbon::setTestNow(), $this->travel() — therefore reaches the core as
 * well: quote expiry, rate-cache freshness, the sync throttle and
 * `seconds_left` all move with it.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }
}
