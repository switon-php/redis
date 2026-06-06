<?php

declare(strict_types=1);

namespace Switon\Redis\Exception;

use Switon\Redis\Exception;

/**
 * Exception for Redis database selection failures.
 *
 * Raised when configured database index cannot be selected.
 *
 * @see \Switon\Redis\Exception
 * @see \Switon\Redis\Connection
 */
class DatabaseSelectException extends Exception
{
}
