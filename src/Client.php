<?php

declare(strict_types=1);

namespace Switon\Redis;

use Switon\Core\Attribute\Autowired;
use Switon\Pooling\PoolGuard;
use Switon\Pooling\PoolManagerInterface;
use Switon\Redis\Exception\CallInPoolException;

use function is_object;
use function preg_match;

/**
 * Redis client implementation backed by Switon connection pools.
 *
 * Use when you need Redis commands through dependency injection, with transient
 * dedicated clients for pool-incompatible operations.
 *
 * Guidance: In pooled mode, commands that require connection state (`watch`, `unwatch`, `multi`, `pipeline`, `select`, `scan` and variants) must use {@see getTransient()} first.
 *
 * Road-signs:
 * - pooled call guard in __call
 * - transient client for stateful commands
 * - Connection call dispatch
 *
 * @see \Switon\Redis\ClientInterface
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Exception
 * @see \Switon\Redis\Exception\CallInPoolException
 * @see \Switon\Pooling\PoolManagerInterface
 * @see \Switon\Redis\Client::__call()
 * @see \Switon\Redis\Client::getTransient()
 */
class Client implements ClientInterface
{
    /** @var PoolManagerInterface<Client, Connection> */
    #[Autowired] protected PoolManagerInterface $poolManager;

    #[Autowired] protected ?string $uri; #redis://127.0.0.1/1?timeout=3&retry_interval=0&auth=&persistent=0
    #[Autowired] protected int $pool_timeout = 1;
    #[Autowired] protected int $pool_size = 4;

    /** @var PoolGuard<Client, Connection>|null */
    protected ?PoolGuard $guard = null;

    public function getUri(): ?string
    {
        return $this->uri ?? null;
    }

    /**
     * Register this client in the pool manager and parse optional pool URI options.
     *
     * Supports `pool_timeout` and `pool_size` query parameters.
     */
    public function __construct()
    {
        if (isset($this->uri)) {
            if (preg_match('#pool_timeout=(\d+)#', $this->uri, $matches) === 1) {
                $this->pool_timeout = (int)$matches[1];
            }

            if (preg_match('#pool_size=(\d+)#', $this->uri, $matches) === 1) {
                $this->pool_size = (int)$matches[1];
            }

            $this->poolManager->add($this, [Connection::class, ['uri' => $this->uri]], $this->pool_size);
        }
    }

    /**
     * Dispatch Redis calls through pooled or dedicated connections.
     *
     * Methods that require dedicated connection state (for example transactions,
     * scans, or Redis object returns) throw `CallInPoolException` in pooled mode.
     *
     * @param string $method Redis method name
     * @param array<int, mixed> $arguments Redis method arguments
     *
     * @return mixed
     *
     * @throws CallInPoolException
     */
    public function __call(string $method, array $arguments): mixed
    {
        // Check for pooled restriction when not using a transient client.
        if (!$this->guard) {
            if (preg_match('#^(watch|unwatch|multi|pipeline|select|scan|[shz]Scan)$#', $method) === 1) {
                CallInPoolException::raise('Method "{method}" cannot be called in connection pool', ['method' => $method]);
            }
        }

        // Use the transient guard or borrow one connection from the pool.
        $guard = $this->guard ?? $this->poolManager->guard($this, $this->pool_timeout);
        /** @var Connection $connection */
        $connection = $guard->resource;

        $return = $connection->__call($method, $arguments);

        // Object returns are not allowed on pooled calls.
        if (!$this->guard && is_object($return)) {
            CallInPoolException::raise('Method "{method}" cannot be called in connection pool', ['method' => $method]);
        }

        return $return;
    }

    /**
     * Create a transient client bound to one dedicated pooled connection.
     *
     * @return static
     */
    public function getTransient(): static
    {
        $transient = clone $this;
        $transient->guard = $this->poolManager->guard($this, $this->pool_timeout);
        return $transient;
    }
}
