<?php

declare(strict_types=1);

namespace Switon\Redis\Exception;

use Switon\Redis\Exception;

/**
 * Exception for Redis operations that require a dedicated connection.
 *
 * Raised when pool-incompatible commands are executed on pooled clients.
 *
 * @see \Switon\Redis\Exception
 * @see \Switon\Redis\Client
 */
class CallInPoolException extends Exception
{
}
