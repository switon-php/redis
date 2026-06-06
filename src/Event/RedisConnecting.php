<?php

declare(strict_types=1);

namespace Switon\Redis\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Redis\Connection;

use function strpos;
use function substr;

/**
 * Event emitted before Redis connection attempts.
 *
 * Log category: redis connection lifecycle.
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Event\RedisConnected
 */
#[EventLevel(Severity::DEBUG)]
class RedisConnecting implements JsonSerializable
{
    /**
     * @param Connection $connection Connection attempting to connect
     * @param string $uri Target Redis URI
     */
    public function __construct(
        public Connection $connection,
        public string     $uri,
    ) {

    }

    /**
     * Returns a JSON payload with URI query parameters removed.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $uri = $this->uri;
        $pos = strpos($uri, '?');
        return ['uri' => $pos > 0 ? substr($uri, 0, $pos) : $uri];
    }
}
