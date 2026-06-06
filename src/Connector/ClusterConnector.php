<?php

declare(strict_types=1);

namespace Switon\Redis\Connector;

use Redis;
use RedisCluster;
use Switon\Core\MakerInterface;
use Switon\Core\Runtime;
use Switon\Redis\ConnectorInterface;
use Switon\Redis\Exception\InvalidUriFormatException;

use function array_filter;
use function count;
use function explode;
use function parse_str;
use function parse_url;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Connector for `cluster://` Redis Cluster URIs.
 *
 * Parses seed list and creates `RedisCluster` via maker.
 *
 * Guidance: seed hosts default to port `6379` when omitted.
 *
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\ConnectorInterface
 */
class ClusterConnector implements ConnectorInterface
{
    public function __construct(protected MakerInterface $maker)
    {
    }

    public function supports(string $scheme): bool
    {
        return $scheme === 'cluster';
    }

    public function connect(string $uri): Redis|RedisCluster
    {
        $seedPart = $uri;
        if (str_starts_with($seedPart, 'cluster://')) {
            $seedPart = substr($seedPart, 10);
        }
        $seedPart = explode('?', $seedPart, 2)[0];
        $seedPart = explode('/', $seedPart, 2)[0];

        $seeds = [];
        foreach (array_filter(explode(',', $seedPart)) as $host) {
            $host = trim($host);
            $seeds[] = str_contains($host, ':') ? $host : "$host:6379";
        }
        if (count($seeds) === 0) {
            InvalidUriFormatException::raise('Invalid Redis cluster URI format: "{uri}"', ['uri' => $uri]);
        }

        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        $timeout = isset($query['timeout']) ? (int)$query['timeout'] : 1;
        $persistent = Runtime::isCoroutineEnabled() && isset($query['persistent']) && $query['persistent'] !== '0';
        $auth = $query['auth'] ?? null;

        /** @var RedisCluster $cluster */
        $cluster = $this->maker->make(RedisCluster::class, [null, $seeds, $timeout, null, $persistent, $auth]);
        return $cluster;
    }
}
