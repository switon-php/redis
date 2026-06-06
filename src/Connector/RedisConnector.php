<?php

declare(strict_types=1);

namespace Switon\Redis\Connector;

use Redis;
use RedisCluster;
use Switon\Core\MakerInterface;
use Switon\Core\Runtime;
use Switon\Redis\ConnectorInterface;
use Switon\Redis\Exception\AuthException;
use Switon\Redis\Exception\ConnectionException;

use function md5;
use function parse_str;
use function parse_url;

/**
 * Connector for single-node Redis URIs (`redis://` and `rediss://`).
 *
 * Uses `tls://host` when scheme is `rediss`.
 *
 * Guidance: authentication is applied after connect/pconnect succeeds.
 *
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\ConnectorInterface
 */
class RedisConnector implements ConnectorInterface
{
    public function __construct(protected MakerInterface $maker)
    {
    }

    public function supports(string $scheme): bool
    {
        return $scheme === 'redis' || $scheme === 'rediss';
    }

    public function connect(string $uri): Redis|RedisCluster
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME) ?: 'redis';
        $isTls = $scheme === 'rediss';
        $host = parse_url($uri, PHP_URL_HOST) ?? '127.0.0.1';
        $port = parse_url($uri, PHP_URL_PORT) ?? 6379;

        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        $timeout = isset($query['timeout']) ? (int)$query['timeout'] : 1;
        $persistent = Runtime::isCoroutineEnabled() && isset($query['persistent']) && $query['persistent'] !== '0';

        /** @var Redis $redis */
        $redis = $this->maker->make(Redis::class);
        $connectHost = $isTls ? 'tls://' . $host : $host;
        $persistentId = md5($uri);
        if ($persistent) {
            if (!@$redis->pconnect($connectHost, (int)$port, $timeout, $persistentId)) {
                ConnectionException::raise('Failed to connect to Redis server at "{uri}"', ['uri' => $uri]);
            }
        } elseif (!@$redis->connect($connectHost, (int)$port, $timeout)) {
            ConnectionException::raise('Failed to connect to Redis server at "{uri}"', ['uri' => $uri]);
        }

        if (($auth = $query['auth'] ?? '') !== '' && !$redis->auth($auth)) {
            AuthException::raise('Redis authentication failed for server at "{uri}"', ['auth' => $auth, 'uri' => $uri]);
        }

        return $redis;
    }
}
