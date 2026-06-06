<?php

declare(strict_types=1);

namespace Switon\Redis\Exception;

/**
 * Exception for Redis authentication failures.
 *
 * Raised when Redis rejects provided credentials.
 *
 * @see \Switon\Redis\Exception\ConnectionException
 * @see \Switon\Redis\Connection
 */
class AuthException extends ConnectionException
{
}
