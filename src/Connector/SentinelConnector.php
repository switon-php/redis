<?php

declare(strict_types=1);

namespace Switon\Redis\Connector;

use Redis;
use RedisCluster;
use RedisException;
use Switon\Core\MakerInterface;
use Switon\Core\Runtime;
use Switon\Redis\ConnectorInterface;
use Switon\Redis\Exception\AuthException;
use Switon\Redis\Exception\ConnectionException;
use Switon\Redis\Exception\InvalidUriFormatException;

use function array_filter;
use function count;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function ltrim;
use function md5;
use function parse_str;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Connector for `sentinel://` URIs with master discovery and failover fallback.
 *
 * URI format:
 * - sentinel://host1:26379,host2:26379/<master>?auth=<redis-pass>&sentinel_auth=<sentinel-pass>&timeout=1
 *
 * Guidance: the path or `master` query parameter must resolve to the Redis master name.
 *
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\ConnectorInterface
 */
class SentinelConnector implements ConnectorInterface
{
    public function __construct(protected MakerInterface $maker)
    {
    }

    public function supports(string $scheme): bool
    {
        return $scheme === 'sentinel';
    }

    public function connect(string $uri): Redis|RedisCluster
    {
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        $timeout = isset($query['timeout']) ? (int)$query['timeout'] : 1;
        $persistent = Runtime::isCoroutineEnabled() && isset($query['persistent']) && $query['persistent'] !== '0';

        $master = trim($query['master'] ?? ltrim((parse_url($uri, PHP_URL_PATH) ?? ''), '/'));
        if ($master === '') {
            InvalidUriFormatException::raise(
                'Invalid Redis sentinel URI format: missing master name in "{uri}"',
                ['uri' => $uri]
            );
        }

        $seedPart = $uri;
        if (str_starts_with($seedPart, 'sentinel://')) {
            $seedPart = substr($seedPart, 11);
        }
        $seedPart = explode('?', $seedPart, 2)[0];
        $seedPart = explode('/', $seedPart, 2)[0];

        $seeds = [];
        foreach (array_filter(explode(',', $seedPart)) as $seed) {
            $seed = trim($seed);
            $seeds[] = str_contains($seed, ':') ? $seed : "$seed:26379";
        }
        if (count($seeds) === 0) {
            InvalidUriFormatException::raise('Invalid Redis sentinel URI format: "{uri}"', ['uri' => $uri]);
        }

        $sentinelAuth = $query['sentinel_auth'] ?? '';
        $redisAuth = $query['auth'] ?? '';

        foreach ($seeds as $seed) {
            [$sentinelHost, $sentinelPort] = explode(':', $seed, 2);
            $sentinelPort = (int)$sentinelPort;

            /** @var Redis $sentinel */
            $sentinel = $this->maker->make(Redis::class);
            $sentinelPersistentId = md5(sprintf('%s|sentinel|%s:%d', $uri, $sentinelHost, $sentinelPort));
            $sentinelConnected = $persistent
                ? @$sentinel->pconnect($sentinelHost, $sentinelPort, $timeout, $sentinelPersistentId)
                : @$sentinel->connect($sentinelHost, $sentinelPort, $timeout);
            if (!$sentinelConnected) {
                continue;
            }

            if ($sentinelAuth !== '' && !$sentinel->auth($sentinelAuth)) {
                $sentinel->close();
                continue;
            }

            try {
                $masterAddress = $sentinel->rawCommand('SENTINEL', 'get-master-addr-by-name', $master);
            } catch (RedisException) {
                $sentinel->close();
                continue;
            }

            $sentinel->close();

            if (!is_array($masterAddress) || !isset($masterAddress[0], $masterAddress[1])) {
                continue;
            }
            if (!is_string($masterAddress[0]) || (!is_string($masterAddress[1]) && !is_numeric($masterAddress[1]))) {
                continue;
            }

            $masterHost = $masterAddress[0];
            $masterPort = (int)$masterAddress[1];

            /** @var Redis $redis */
            $redis = $this->maker->make(Redis::class);
            $redisPersistentId = md5(sprintf('%s|master|%s:%d', $uri, $masterHost, $masterPort));
            $connected = $persistent
                ? @$redis->pconnect($masterHost, $masterPort, $timeout, $redisPersistentId)
                : @$redis->connect($masterHost, $masterPort, $timeout);
            if (!$connected) {
                continue;
            }

            if ($redisAuth !== '' && !$redis->auth($redisAuth)) {
                $redis->close();
                AuthException::raise('Redis authentication failed for server at "{uri}"', ['auth' => $redisAuth, 'uri' => $uri]);
            }

            return $redis;
        }

        ConnectionException::raise(
            'Failed to resolve Redis master from sentinel URI "{uri}"',
            ['uri' => $uri]
        );
    }
}
