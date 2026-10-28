<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Chains;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;

/**
 * A chain this version of the bridge has no composition root for — an
 * intent from a newer installation, or a chain the core gained before the
 * bridge did.
 */
final class UnsupportedChainException extends RuntimeException implements LedgerDirectException
{
}
