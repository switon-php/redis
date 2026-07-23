<?php

declare(strict_types=1);

namespace Switon\Redis\Event;

use JsonSerializable;
use Redis;
use RedisCluster;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Redis\Connection;

use function strpos;
use function substr;

/**
 * Event emitted after Redis connections are established.
 *
 * Log category: `switon.redis.connected`
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Event\RedisConnecting
 * @see \Switon\Redis\Event\RedisClose
 */
#[EventLevel(Severity::DEBUG)]
class RedisConnected implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $uri Connected Redis URI
     * @param Redis|RedisCluster $redis Native Redis client instance
     */
    public function __construct(
        public Connection $sender,
        public string $uri,
        public Redis|RedisCluster $redis,
    ) {

    }

    public function getSender(): Connection
    {
        return $this->sender;
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
