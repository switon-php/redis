<?php

declare(strict_types=1);

namespace Switon\Redis;

use Redis;

/**
 * Contract for Redis operations with Switon connection and event integration.
 *
 * Use when application code needs Redis-style method calls through DI while
 * keeping pooled and transient connection modes explicit.
 *
 * Road-signs:
 * - @mixin Redis
 * - Client __call pooled guard
 * - Client getTransient for stateful commands
 * - Connection
 * - RedisConnecting/Connected
 * - RedisCalling/RedisCalled/RedisClose
 *
 * @mixin Redis
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Client::__call()
 * @see \Switon\Redis\Client::getTransient()
 * @see \Switon\Di\Factory
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Exception
 * @see \Switon\Redis\Event\RedisConnecting
 * @see \Switon\Redis\Event\RedisConnected
 * @see \Switon\Redis\Event\RedisCalling
 * @see \Switon\Redis\Event\RedisCalled
 * @see \Switon\Redis\Event\RedisClose
 */
interface ClientInterface
{
    /**
     * Returns the configured Redis URI for inspection.
     *
     * Guidance: redact credentials or query secrets before displaying this value to end users.
     *
     * @return string|null URI or null when not available
     */
    public function getUri(): ?string;

    /**
     * Creates a transient client bound to one dedicated connection.
     *
     * Use this before stateful commands such as transactions, WATCH, or cursor scans.
     *
     * @return static
     */
    public function getTransient(): static;
}
