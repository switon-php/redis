<?php

declare(strict_types=1);

namespace Switon\Redis;

use Redis;
use RedisCluster;

/**
 * Contract for protocol-specific Redis URI connectors.
 *
 * Guidance: Match by URI scheme only; keep connection side effects inside `connect()`.
 *
 * Road-signs:
 * - supports() for scheme routing
 * - connect() for native Redis/RedisCluster creation
 *
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Connector\RedisConnector
 * @see \Switon\Redis\Connector\SentinelConnector
 * @see \Switon\Redis\Connector\ClusterConnector
 */
interface ConnectorInterface
{
    /**
     * Returns whether this connector can handle the given URI scheme.
     */
    public function supports(string $scheme): bool;

    /**
     * Connects from one URI and returns a native Redis client.
     *
     * @throws \Switon\Redis\Exception\InvalidUriFormatException
     * @throws \Switon\Redis\Exception\ConnectionException
     * @throws \Switon\Redis\Exception\AuthException
     */
    public function connect(string $uri): Redis|RedisCluster;
}
