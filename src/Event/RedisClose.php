<?php

declare(strict_types=1);

namespace Switon\Redis\Event;

use Redis;
use RedisCluster;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Redis\Connection;

/**
 * Event emitted when Redis connections are closed.
 *
 * Log category: redis connection lifecycle.
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Event\RedisConnected
 */
#[EventLevel(Severity::DEBUG)]
class RedisClose implements SenderAwareInterface
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
}
