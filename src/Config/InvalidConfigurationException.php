<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Config;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;

/**
 * The merchant's configuration is missing or unusable. Carries a message
 * that names the config key and, where there is one, the .env variable to
 * set — so a fresh install fails with an instruction, not a TypeError.
 *
 * A LedgerDirectException like every other runtime failure of the core:
 * one catch in a shop's exception handler covers it too.
 */
final class InvalidConfigurationException extends RuntimeException implements LedgerDirectException
{
}
