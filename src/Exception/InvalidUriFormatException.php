<?php

declare(strict_types=1);

namespace Switon\Redis\Exception;

use Switon\Redis\Exception;

/**
 * Exception for invalid Redis URI formats.
 *
 * Raised when URI scheme or URI structure is unsupported.
 *
 * @see \Switon\Redis\Exception
 * @see \Switon\Di\Factory
 */
class InvalidUriFormatException extends Exception
{
}
