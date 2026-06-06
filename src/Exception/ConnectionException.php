<?php

declare(strict_types=1);

namespace Switon\Redis\Exception;

use Switon\Redis\Exception;

/**
 * Exception for Redis connection failures.
 *
 * Raised when network connection to Redis cannot be established.
 *
 * @see \Switon\Redis\Exception
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Client
 */
class ConnectionException extends Exception
{
}
