<?php

declare(strict_types=1);

namespace Switon\Redis;

use Psr\EventDispatcher\EventDispatcherInterface;
use Redis;
use RedisCluster;
use RedisException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Redis\Connector\ClusterConnector;
use Switon\Redis\Connector\RedisConnector;
use Switon\Redis\Connector\SentinelConnector;
use Switon\Redis\Event\RedisCalled;
use Switon\Redis\Event\RedisCalling;
use Switon\Redis\Event\RedisClose;
use Switon\Redis\Event\RedisConnected;
use Switon\Redis\Event\RedisConnecting;
use Switon\Redis\Exception\AuthException;
use Switon\Redis\Exception\ConnectionException;
use Switon\Redis\Exception\DatabaseSelectException;
use Switon\Redis\Exception\InvalidUriFormatException;

use function in_array;
use function is_string;
use function microtime;
use function parse_str;
use function parse_url;
use function preg_match;

/**
 * Manages Redis single-node, TLS, Sentinel, and Cluster connections from URI definitions.
 *
 * Guidance: Use `sentinel://.../<master>` for HA master discovery and `rediss://` for TLS transport.
 *
 * Road-signs:
 * - scheme router in getConnect()
 * - rediss uses tls:// host for Redis connect
 * - sentinel resolves master then connects Redis
 * - cluster seeds build RedisCluster
 * - heartbeat failure triggers reconnect + re-resolve
 *
 * @see \Switon\Redis\ClientInterface
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Event\RedisConnecting
 * @see \Switon\Redis\Event\RedisConnected
 * @see \Switon\Redis\Event\RedisCalling
 * @see \Switon\Redis\Event\RedisCalled
 * @see \Switon\Redis\Event\RedisClose
 */
class Connection
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected MakerInterface $maker;

    #[Autowired] protected string $uri;
    /** @var array<string, ConnectorInterface|string> URI scheme to connector mapping. */
    #[Autowired] protected array $connectors = [
        'cluster' => ClusterConnector::class,
        'redis' => RedisConnector::class,
        'rediss' => RedisConnector::class,
        'sentinel' => SentinelConnector::class,
    ];

    protected Redis|RedisCluster|null $redis = null;
    protected ?float $last_heartbeat = null;

    protected int $heartbeat;

    /**
     * Initialize heartbeat interval from URI query.
     *
     * Uses `heartbeat` when provided; defaults to 60 seconds.
     */
    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        if (preg_match('#heartbeat=(\d+)#', $this->uri, $match) === 1) {
            $this->heartbeat = (int)$match[1];
        } else {
            $this->heartbeat = 60;
        }
    }

    /**
     * Reset runtime connection state on clone.
     */
    public function __clone()
    {
        $this->redis = null;
        $this->last_heartbeat = null;
    }

    /**
     * Resolves a native Redis client by URI scheme through protocol connectors.
     */
    protected function connectByUri(string $uri): Redis|RedisCluster
    {
        $scheme = (parse_url($uri, PHP_URL_SCHEME) ?? '');

        $connector = $this->connectors[$scheme] ?? null;
        if (is_string($connector)) {
            $connector = $this->maker->make($connector);
        }
        if ($connector instanceof ConnectorInterface) {
            return $connector->connect($uri);
        }

        InvalidUriFormatException::raise('Invalid Redis URI format: "{uri}"', ['uri' => $uri]);
    }

    /**
     * Gets the active Redis connection and reconnects when heartbeat checks fail.
     *
     * Dispatches `RedisConnecting`/`RedisConnected` and applies URI options
     * for auth, database selection, and read timeout.
     *
     * @return Redis|RedisCluster
     *
     * @throws InvalidUriFormatException
     * @throws ConnectionException
     * @throws AuthException
     * @throws DatabaseSelectException
     */
    public function getConnect(): Redis|RedisCluster
    {
        if ($this->redis !== null && microtime(true) - $this->last_heartbeat > $this->heartbeat) {
            try {
                if (@$this->redis->echo('heartbeat') === false) {
                    $this->close();
                }
            } catch (RedisException) {
                $this->close();
            }
        }

        if ($this->redis === null) {
            $uri = $this->uri;

            $this->eventDispatcher->dispatch(new RedisConnecting($this, $uri));

            $redis = $this->connectByUri($uri);

            parse_str((parse_url($uri, PHP_URL_QUERY) ?: ''), $query);

            if (isset($query['db'])) {
                $db = (int)$query['db'];
            } elseif (preg_match('#/(\d+)#', (parse_url($uri, PHP_URL_PATH) ?: ''), $match) === 1) {
                $db = (int)$match[1];
            } else {
                $db = 0;
            }

            // Database selection and read_timeout are Redis-only options.
            if ($redis instanceof Redis) {
                if ($db !== 0 && !$redis->select($db)) {
                    DatabaseSelectException::raise('Failed to select Redis database {db}', ['db' => $db]);
                }

                if (($read_timeout = $query['read_timeout'] ?? null) !== null) {
                    // Note: read_timeout should be numeric, but we pass it as-is to Redis
                    $redis->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
                }
            }

            $this->eventDispatcher->dispatch(new RedisConnected($this, $uri, $redis));

            $this->redis = $redis;
        }

        $this->last_heartbeat = microtime(true);

        return $this->redis;
    }

    /**
     * Closes the current Redis connection and dispatches `RedisClose`.
     */
    public function close(): void
    {
        if ($this->redis) {
            $this->eventDispatcher->dispatch(new RedisClose($this, $this->uri, $this->redis));

            $this->redis->close();
            $this->redis = null;
            $this->last_heartbeat = null;
        }
    }

    /**
     * Executes one Redis command with blocking-command timeout handling.
     *
     * `subscribe`, `psubscribe`, and `monitor` temporarily force `OPT_READ_TIMEOUT=-1`.
     *
     * @param string $name Redis command name
     * @param array<int, mixed> $arguments Redis command arguments
     *
     * @return mixed
     */
    public function call(string $name, array $arguments): mixed
    {
        $redis = $this->getConnect();

        $read_timeout = null;
        if (in_array($name, ['subscribe', 'psubscribe', 'monitor'], true)) {
            $read_timeout = $redis->getOption(Redis::OPT_READ_TIMEOUT);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
        }

        try {
            $return = @$redis->$name(...$arguments);
        } finally {
            if ($read_timeout !== null) {
                $redis->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
            }
        }

        return $return;
    }

    /**
     * Wraps Redis command execution with `RedisCalling` and `RedisCalled` events.
     *
     * @param string $method Redis method name
     * @param array<int, mixed> $arguments Redis method arguments
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        $this->eventDispatcher->dispatch(new RedisCalling($this, $method, $arguments));

        $start_time = microtime(true);
        $return = $this->call($method, $arguments);
        $elapsed = microtime(true) - $start_time;

        $this->eventDispatcher->dispatch(new RedisCalled($this, $method, $arguments, $elapsed, $return));

        return $return;
    }
}
