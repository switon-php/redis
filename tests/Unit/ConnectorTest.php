<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Redis;
use RedisCluster;
use Switon\Core\MakerInterface;
use Switon\Core\Runtime;
use Switon\Redis\Connector\ClusterConnector;
use Switon\Redis\Connector\RedisConnector;
use Switon\Redis\Connector\SentinelConnector;
use Switon\Redis\Exception\AuthException;
use Switon\Redis\Exception\ConnectionException;
use Switon\Redis\Exception\InvalidUriFormatException;
use Switon\Redis\Tests\TestCase;
use RedisException;

#[AllowMockObjectsWithoutExpectations]
class ConnectorTest extends TestCase
{
    public function testRedisConnectorSupportsRedisAndRedissSchemes(): void
    {
        $connector = new RedisConnector($this->createMock(MakerInterface::class));

        $this->assertTrue($connector->supports('redis'));
        $this->assertTrue($connector->supports('rediss'));
        $this->assertFalse($connector->supports('sentinel'));
    }

    public function testRedisConnectorSupportsIsCaseSensitive(): void
    {
        $connector = new RedisConnector($this->createMock(MakerInterface::class));

        $this->assertFalse($connector->supports('REDIS'));
        $this->assertFalse($connector->supports('ReDiSs'));
    }

    public function testRedisConnectorConnectsTlsHostForRedissUri(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('tls://127.0.0.1', 6380, 2)
            ->willReturn(true);

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('rediss://127.0.0.1:6380/0?timeout=2');

        $this->assertSame($redis, $result);
    }

    public function testRedisConnectorThrowsConnectionExceptionWhenConnectFails(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(false);

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);

        $this->expectException(ConnectionException::class);
        $connector->connect('redis://127.0.0.1:6379/0');
    }

    public function testRedisConnectorThrowsConnectionExceptionWithContextWhenConnectFails(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';

        Runtime::setCoroutineEnabled(false);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('connect')
                ->with('127.0.0.1', 6379, 1)
                ->willReturn(false);
            $redis->expects($this->never())
                ->method('pconnect');
            $redis->expects($this->never())
                ->method('auth');

            $maker->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $connector = new RedisConnector($maker);

            try {
                $connector->connect($uri);
                $this->fail('Expected ' . ConnectionException::class . ' to be thrown.');
            } catch (ConnectionException $e) {
                $this->assertSame('Failed to connect to Redis server at "' . $uri . '"', $e->getMessage());
                $this->assertSame([], $e->getContext());
            }
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorThrowsAuthExceptionWhenAuthFails(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('auth')
            ->with('secret')
            ->willReturn(false);

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);

        $this->expectException(AuthException::class);
        $connector->connect('redis://127.0.0.1:6379/0?auth=secret');
    }

    public function testRedisConnectorDoesNotCallAuthWhenAuthQueryIsEmpty(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('127.0.0.1', 6379, 1)
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('auth');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('redis://127.0.0.1:6379/0?auth=');

        $this->assertSame($redis, $result);
    }

    public function testRedisConnectorThrowsAuthExceptionWithContextWhenAuthFails(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?auth=secret';

        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('auth')
            ->with('secret')
            ->willReturn(false);

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);

        try {
            $connector->connect($uri);
            $this->fail('Expected ' . AuthException::class . ' to be thrown.');
        } catch (AuthException $e) {
            $this->assertSame('Redis authentication failed for server at "' . $uri . '"', $e->getMessage());
            $this->assertSame(['auth' => 'secret'], $e->getContext());
        }
    }

    public function testRedisConnectorUsesPconnectWhenCoroutineEnabledAndPersistentSet(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'redis://127.0.0.1:6379/0?timeout=2&persistent=1';
            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('pconnect')
                ->with('127.0.0.1', 6379, 2, md5($uri))
                ->willReturn(true);
            $redis->expects($this->never())
                ->method('connect');

            $maker->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $connector = new RedisConnector($maker);
            $result = $connector->connect($uri);

            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorUsesTlsHostForPconnectWhenRedissAndPersistent(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'rediss://127.0.0.1:6380/0?timeout=2&persistent=1';
            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('pconnect')
                ->with('tls://127.0.0.1', 6380, 2, md5($uri))
                ->willReturn(true);
            $redis->expects($this->never())->method('connect');

            $maker->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

            $connector = new RedisConnector($maker);
            $result = $connector->connect($uri);

            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorUsesDefaultPortWhenUriPortIsOmitted(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('127.0.0.1', 6379, 1)
            ->willReturn(true);

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('redis://127.0.0.1?timeout=1');

        $this->assertSame($redis, $result);
    }

    public function testRedisConnectorDefaultsTimeoutToOneWhenMissing(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('127.0.0.1', 6379, 1)
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('pconnect');
        $redis->expects($this->never())
            ->method('auth');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('redis://127.0.0.1:6379/0');

        $this->assertSame($redis, $result);
    }

    public function testRedisConnectorUsesConnectWhenCoroutineDisabledEvenIfPersistentSet(): void
    {
        Runtime::setCoroutineEnabled(false);
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('127.0.0.1', 6379, 1)
            ->willReturn(true);
        $redis->expects($this->never())->method('pconnect');

        $maker->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('redis://127.0.0.1:6379/0?persistent=1');

        $this->assertSame($redis, $result);
    }

    public function testRedisConnectorTreatsPersistentZeroAsNonPersistentWhenCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('connect')
                ->with('127.0.0.1', 6379, 1)
                ->willReturn(true);
            $redis->expects($this->never())->method('pconnect');

            $maker->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

            $connector = new RedisConnector($maker);
            $result = $connector->connect('redis://127.0.0.1:6379/0?persistent=0');

            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorUsesConnectWhenPersistentQueryIsMissingEvenIfCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('connect')
                ->with('127.0.0.1', 6379, 2)
                ->willReturn(true);
            $redis->expects($this->never())
                ->method('pconnect');

            $maker->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $connector = new RedisConnector($maker);
            $result = $connector->connect('redis://127.0.0.1:6379/0?timeout=2');

            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorTreatsPersistentTrueStringAsPersistentWhenCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'redis://127.0.0.1:6379/0?timeout=2&persistent=true';

            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('pconnect')
                ->with('127.0.0.1', 6379, 2, md5($uri))
                ->willReturn(true);
            $redis->expects($this->never())
                ->method('connect');

            $maker->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $connector = new RedisConnector($maker);
            $result = $connector->connect($uri);

            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorThrowsConnectionExceptionWhenPconnectFails(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'redis://127.0.0.1:6379/0?timeout=2&persistent=1';

            $maker = $this->createMock(MakerInterface::class);
            $redis = $this->createMock(Redis::class);

            $redis->expects($this->once())
                ->method('pconnect')
                ->with('127.0.0.1', 6379, 2, md5($uri))
                ->willReturn(false);
            $redis->expects($this->never())
                ->method('connect');

            $maker->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $connector = new RedisConnector($maker);

            try {
                $connector->connect($uri);
                $this->fail('Expected ' . ConnectionException::class . ' to be thrown.');
            } catch (ConnectionException $e) {
                $this->assertSame('Failed to connect to Redis server at "' . $uri . '"', $e->getMessage());
                $this->assertSame([], $e->getContext());
            }
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectorDefaultsSchemeToRedisWhenUriHasNoScheme(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('connect')
            ->with('127.0.0.1', 6379, 1)
            ->willReturn(true);

        $maker->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

        $connector = new RedisConnector($maker);
        $result = $connector->connect('//127.0.0.1:6379?timeout=1');

        $this->assertSame($redis, $result);
    }

    public function testClusterConnectorSupportsOnlyClusterScheme(): void
    {
        $connector = new ClusterConnector($this->createMock(MakerInterface::class));

        $this->assertTrue($connector->supports('cluster'));
        $this->assertFalse($connector->supports('redis'));
    }

    public function testClusterConnectorParsesSeedsAndBuildsClusterClient(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $cluster = $this->createMock(RedisCluster::class);

            $maker->expects($this->once())
                ->method('make')
                ->with(
                    RedisCluster::class,
                    [null, ['10.0.0.1:6379', '10.0.0.2:7001'], 2, null, true, 'pw']
                )
                ->willReturn($cluster);

            $connector = new ClusterConnector($maker);
            $result = $connector->connect('cluster://10.0.0.1,10.0.0.2:7001?timeout=2&persistent=1&auth=pw');

            $this->assertSame($cluster, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testClusterConnectorThrowsInvalidUriFormatExceptionWhenNoSeedProvided(): void
    {
        $connector = new ClusterConnector($this->createMock(MakerInterface::class));

        $this->expectException(InvalidUriFormatException::class);
        $connector->connect('cluster://');
    }

    public function testClusterConnectorThrowsInvalidUriFormatExceptionWithContextWhenNoSeedProvided(): void
    {
        $uri = 'cluster://';

        $connector = new ClusterConnector($this->createMock(MakerInterface::class));

        try {
            $connector->connect($uri);
            $this->fail('Expected ' . InvalidUriFormatException::class . ' to be thrown.');
        } catch (InvalidUriFormatException $e) {
            $this->assertSame('Invalid Redis cluster URI format: "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    public function testClusterConnectorTrimsSeedWhitespace(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $cluster = $this->createMock(RedisCluster::class);

        $maker->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['10.0.0.1:6379', '10.0.0.2:7001'], 1, null, false, null]
            )
            ->willReturn($cluster);

        $connector = new ClusterConnector($maker);
        $result = $connector->connect('cluster:// 10.0.0.1 ,10.0.0.2:7001?timeout=1');

        $this->assertSame($cluster, $result);
    }

    public function testClusterConnectorUsesDefaultTimeoutWhenNotProvided(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $cluster = $this->createMock(RedisCluster::class);

        $maker->expects($this->once())
            ->method('make')
            ->with(RedisCluster::class, [null, ['10.0.0.1:6379'], 1, null, false, null])
            ->willReturn($cluster);

        $connector = new ClusterConnector($maker);
        $result = $connector->connect('cluster://10.0.0.1');

        $this->assertSame($cluster, $result);
    }

    public function testClusterConnectorUsesPersistentFalseWhenCoroutineDisabled(): void
    {
        Runtime::setCoroutineEnabled(false);
        $maker = $this->createMock(MakerInterface::class);
        $cluster = $this->createMock(RedisCluster::class);

        $maker->expects($this->once())
            ->method('make')
            ->with(RedisCluster::class, [null, ['10.0.0.1:6379'], 1, null, false, null])
            ->willReturn($cluster);

        $connector = new ClusterConnector($maker);
        $result = $connector->connect('cluster://10.0.0.1?persistent=1');

        $this->assertSame($cluster, $result);
    }

    public function testClusterConnectorTreatsPersistentZeroAsNonPersistentWhenCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $cluster = $this->createMock(RedisCluster::class);

            $maker->expects($this->once())
                ->method('make')
                ->with(
                    RedisCluster::class,
                    [null, ['10.0.0.1:6379'], 2, null, false, null]
                )
                ->willReturn($cluster);

            $connector = new ClusterConnector($maker);
            $result = $connector->connect('cluster://10.0.0.1?timeout=2&persistent=0');

            $this->assertSame($cluster, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testClusterConnectorUsesEmptyAuthStringWhenAuthQueryIsEmpty(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $cluster = $this->createMock(RedisCluster::class);

        $maker->expects($this->once())
            ->method('make')
            ->with(RedisCluster::class, [null, ['10.0.0.1:6379'], 1, null, false, ''])
            ->willReturn($cluster);

        $connector = new ClusterConnector($maker);
        $result = $connector->connect('cluster://10.0.0.1?auth=');

        $this->assertSame($cluster, $result);
    }

    public function testClusterConnectorUsesDefaultPortWhenSeedPortIsOmitted(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $cluster = $this->createMock(RedisCluster::class);

        $maker->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['10.0.0.1:6379', '10.0.0.2:7001'], 2, null, false, null]
            )
            ->willReturn($cluster);

        $connector = new ClusterConnector($maker);
        $result = $connector->connect('cluster://10.0.0.1,10.0.0.2:7001?timeout=2');

        $this->assertSame($cluster, $result);
    }

    public function testClusterConnectorUsesPersistentFalseWhenPersistentQueryIsMissingEvenIfCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);
            $cluster = $this->createMock(RedisCluster::class);

            $maker->expects($this->once())
                ->method('make')
                ->with(
                    RedisCluster::class,
                    [null, ['10.0.0.1:6379'], 2, null, false, null]
                )
                ->willReturn($cluster);

            $connector = new ClusterConnector($maker);
            $result = $connector->connect('cluster://10.0.0.1?timeout=2');

            $this->assertSame($cluster, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testSentinelConnectorSupportsOnlySentinelScheme(): void
    {
        $connector = new SentinelConnector($this->createMock(MakerInterface::class));

        $this->assertTrue($connector->supports('sentinel'));
        $this->assertFalse($connector->supports('redis'));
    }

    public function testSentinelConnectorThrowsInvalidUriFormatExceptionWhenMasterMissing(): void
    {
        $connector = new SentinelConnector($this->createMock(MakerInterface::class));

        $this->expectException(InvalidUriFormatException::class);
        $connector->connect('sentinel://10.0.0.1:26379');
    }

    public function testSentinelConnectorThrowsInvalidUriFormatExceptionWithContextWhenMasterMissing(): void
    {
        $uri = 'sentinel://10.0.0.1:26379';

        $connector = new SentinelConnector($this->createMock(MakerInterface::class));

        try {
            $connector->connect($uri);
            $this->fail('Expected ' . InvalidUriFormatException::class . ' to be thrown.');
        } catch (InvalidUriFormatException $e) {
            $this->assertSame(
                'Invalid Redis sentinel URI format: missing master name in "' . $uri . '"',
                $e->getMessage()
            );
            $this->assertSame([], $e->getContext());
        }
    }

    public function testSentinelConnectorTrimsSeedWhitespace(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6380']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 1)
            ->willReturn(true);
        $master->expects($this->never())
            ->method('auth');

        $callCount = 0;
        $maker->expects($this->exactly(3))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2, $master) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $sentinel2,
                    default => $master,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel:// 10.0.0.1:26379, 10.0.0.2:26379/mymaster?timeout=1');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorFallsBackToNextSeedAndReturnsResolvedMasterConnection(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6380']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 1)
            ->willReturn(true);
        $master->expects($this->never())
            ->method('auth');

        $callCount = 0;
        $maker->expects($this->exactly(3))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2, $master) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $sentinel2,
                    default => $master,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorThrowsAuthExceptionWhenRedisAuthFails(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6379']);
        $sentinel->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6379, 1)
            ->willReturn(true);
        $master->expects($this->once())
            ->method('auth')
            ->with('bad-secret')
            ->willReturn(false);
        $master->expects($this->once())
            ->method('close');

        $callCount = 0;
        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel, $master) {
                $callCount++;
                return $callCount === 1 ? $sentinel : $master;
            });

        $connector = new SentinelConnector($maker);

        $this->expectException(AuthException::class);
        $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1&auth=bad-secret');
    }

    public function testSentinelConnectorDoesNotCallRedisAuthWhenAuthQueryIsEmpty(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->never())
            ->method('auth');
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6380']);
        $sentinel->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 1)
            ->willReturn(true);
        $master->expects($this->never())
            ->method('auth');
        $master->expects($this->never())
            ->method('close');

        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnOnConsecutiveCalls($sentinel, $master);

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1&auth=');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorFallsBackWhenSentinelAuthFails(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel1->expects($this->once())
            ->method('auth')
            ->with('bad-sentinel-secret')
            ->willReturn(false);
        $sentinel1->expects($this->once())
            ->method('close');
        $sentinel1->expects($this->never())
            ->method('rawCommand');

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('auth')
            ->with('bad-sentinel-secret')
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6380']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 1)
            ->willReturn(true);

        $callCount = 0;
        $maker->expects($this->exactly(3))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2, $master) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $sentinel2,
                    default => $master,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect(
            'sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1&sentinel_auth=bad-sentinel-secret'
        );

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorDoesNotCallSentinelAuthWhenSentinelAuthQueryIsEmpty(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->never())
            ->method('auth');
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6380']);
        $sentinel->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 1)
            ->willReturn(true);
        $master->expects($this->never())
            ->method('auth');
        $master->expects($this->never())
            ->method('close');

        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnOnConsecutiveCalls($sentinel, $master);

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1&sentinel_auth=');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorFallsBackWhenRawCommandThrowsRedisException(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel1->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willThrowException(new RedisException('sentinel error'));
        $sentinel1->expects($this->once())
            ->method('close');

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.2.0.9', '6379']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.2.0.9', 6379, 1)
            ->willReturn(true);

        $callCount = 0;
        $maker->expects($this->exactly(3))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2, $master) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $sentinel2,
                    default => $master,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorFallsBackAfterMasterConnectFailsWithNonNumericResolvedPort(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel1->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', 'bad-port']);
        $sentinel1->expects($this->once())
            ->method('close');

        $master1 = $this->createMock(Redis::class);
        $master1->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 0, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.2.0.9', '6380']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.2.0.9', 6380, 1)
            ->willReturn(true);

        $callCount = 0;
        $maker->expects($this->exactly(4))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $master1, $sentinel2, $master) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $master1,
                    3 => $sentinel2,
                    default => $master,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorTreatsPersistentZeroAsNonPersistentWhenCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $maker = $this->createMock(MakerInterface::class);

            $sentinel = $this->createMock(Redis::class);
            $sentinel->expects($this->once())
                ->method('connect')
                ->with('10.0.0.1', 26379, 2)
                ->willReturn(true);
            $sentinel->expects($this->never())
                ->method('pconnect');
            $sentinel->expects($this->once())
                ->method('rawCommand')
                ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
                ->willReturn(['10.1.0.9', '6379']);
            $sentinel->expects($this->once())
                ->method('close');
            $sentinel->expects($this->never())
                ->method('auth');

            $master = $this->createMock(Redis::class);
            $master->expects($this->once())
                ->method('connect')
                ->with('10.1.0.9', 6379, 2)
                ->willReturn(true);
            $master->expects($this->never())
                ->method('pconnect');
            $master->expects($this->never())
                ->method('auth');
            $master->expects($this->never())
                ->method('close');

            $maker->expects($this->exactly(2))
                ->method('make')
                ->with(Redis::class)
                ->willReturnOnConsecutiveCalls($sentinel, $master);

            $connector = new SentinelConnector($maker);
            $result = $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=2&persistent=0');

            $this->assertSame($master, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testSentinelConnectorUsesPconnectWhenCoroutineEnabledAndPersistentSet(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'sentinel://10.0.0.1:26379/mymaster?timeout=2&persistent=1&auth=redis-secret&sentinel_auth=sentinel-secret';
            $maker = $this->createMock(MakerInterface::class);

            $sentinel = $this->createMock(Redis::class);
            $sentinel->expects($this->once())
                ->method('pconnect')
                ->with('10.0.0.1', 26379, 2, md5(sprintf('%s|sentinel|%s:%d', $uri, '10.0.0.1', 26379)))
                ->willReturn(true);
            $sentinel->expects($this->once())
                ->method('auth')
                ->with('sentinel-secret')
                ->willReturn(true);
            $sentinel->expects($this->once())
                ->method('rawCommand')
                ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
                ->willReturn(['10.1.0.9', '6380']);
            $sentinel->expects($this->once())
                ->method('close');
            $sentinel->expects($this->never())
                ->method('connect');

            $master = $this->createMock(Redis::class);
            $master->expects($this->once())
                ->method('pconnect')
                ->with('10.1.0.9', 6380, 2, md5(sprintf('%s|master|%s:%d', $uri, '10.1.0.9', 6380)))
                ->willReturn(true);
            $master->expects($this->once())
                ->method('auth')
                ->with('redis-secret')
                ->willReturn(true);
            $master->expects($this->never())
                ->method('connect');

            $callCount = 0;
            $maker->expects($this->exactly(2))
                ->method('make')
                ->with(Redis::class)
                ->willReturnCallback(function () use (&$callCount, $sentinel, $master) {
                    $callCount++;
                    return $callCount === 1 ? $sentinel : $master;
                });

            $connector = new SentinelConnector($maker);
            $result = $connector->connect($uri);

            $this->assertSame($master, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testSentinelConnectorThrowsConnectionExceptionWhenAllSeedsFailToConnect(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(false);

        $callCount = 0;
        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2) {
                $callCount++;
                return $callCount === 1 ? $sentinel1 : $sentinel2;
            });

        $connector = new SentinelConnector($maker);

        $this->expectException(ConnectionException::class);
        $connector->connect('sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1');
    }

    public function testSentinelConnectorThrowsConnectionExceptionWithContextWhenAllSeedsFailToConnect(): void
    {
        $uri = 'sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1';

        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(false);

        $callCount = 0;
        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $sentinel2) {
                $callCount++;
                return $callCount === 1 ? $sentinel1 : $sentinel2;
            });

        $connector = new SentinelConnector($maker);

        try {
            $connector->connect($uri);
            $this->fail('Expected ' . ConnectionException::class . ' to be thrown.');
        } catch (ConnectionException $e) {
            $this->assertSame('Failed to resolve Redis master from sentinel URI "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    public function testSentinelConnectorSkipsInvalidMasterAddressAndThrowsConnectionException(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9']);
        $sentinel->expects($this->once())
            ->method('close');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($sentinel);

        $connector = new SentinelConnector($maker);

        $this->expectException(ConnectionException::class);
        $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1');
    }

    public function testSentinelConnectorSkipsInvalidMasterAddressAndThrowsConnectionExceptionWithContext(): void
    {
        $uri = 'sentinel://10.0.0.1:26379/mymaster?timeout=1';

        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9']);
        $sentinel->expects($this->once())
            ->method('close');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($sentinel);

        $connector = new SentinelConnector($maker);

        try {
            $connector->connect($uri);
            $this->fail('Expected ' . ConnectionException::class . ' to be thrown.');
        } catch (ConnectionException $e) {
            $this->assertSame('Failed to resolve Redis master from sentinel URI "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    public function testSentinelConnectorFallsBackWhenResolvedMasterConnectFails(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel1 = $this->createMock(Redis::class);
        $sentinel1->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel1->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6379']);
        $sentinel1->expects($this->once())
            ->method('close');

        $master1 = $this->createMock(Redis::class);
        $master1->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6379, 1)
            ->willReturn(false);

        $sentinel2 = $this->createMock(Redis::class);
        $sentinel2->expects($this->once())
            ->method('connect')
            ->with('10.0.0.2', 26379, 1)
            ->willReturn(true);
        $sentinel2->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.2.0.9', '6380']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master2 = $this->createMock(Redis::class);
        $master2->expects($this->once())
            ->method('connect')
            ->with('10.2.0.9', 6380, 1)
            ->willReturn(true);

        $callCount = 0;
        $maker->expects($this->exactly(4))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel1, $master1, $sentinel2, $master2) {
                $callCount++;
                return match ($callCount) {
                    1 => $sentinel1,
                    2 => $master1,
                    3 => $sentinel2,
                    default => $master2,
                };
            });

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1');

        $this->assertSame($master2, $result);
    }

    public function testSentinelConnectorSkipsMasterAddressWhenHostTypeInvalid(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn([123, '6379']);
        $sentinel->expects($this->once())
            ->method('close');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($sentinel);

        $connector = new SentinelConnector($maker);

        $this->expectException(ConnectionException::class);
        $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1');
    }

    public function testSentinelConnectorSkipsMasterAddressWhenResponseIsMissingPort(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 1)
            ->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9']);
        $sentinel->expects($this->once())
            ->method('close');

        $maker->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($sentinel);

        $connector = new SentinelConnector($maker);

        $this->expectException(ConnectionException::class);
        $connector->connect('sentinel://10.0.0.1:26379/mymaster?timeout=1');
    }

    public function testSentinelConnectorUsesQueryMasterOverPathMaster(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())->method('connect')->with('10.0.0.1', 26379, 1)->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'querymaster')
            ->willReturn(['10.1.0.9', '6379']);
        $sentinel->expects($this->once())->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())->method('connect')->with('10.1.0.9', 6379, 1)->willReturn(true);

        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnOnConsecutiveCalls($sentinel, $master);

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379/pathmaster?master=querymaster');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorTreatsBlankQueryMasterAsMissing(): void
    {
        $connector = new SentinelConnector($this->createMock(MakerInterface::class));

        $this->expectException(InvalidUriFormatException::class);
        $connector->connect('sentinel://10.0.0.1:26379/pathmaster?master=');
    }

    public function testSentinelConnectorDefaultsSeedPortTo26379WhenOmitted(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())->method('connect')->with('10.0.0.1', 26379, 1)->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6379']);
        $sentinel->expects($this->once())->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())->method('connect')->with('10.1.0.9', 6379, 1)->willReturn(true);

        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnOnConsecutiveCalls($sentinel, $master);

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1/mymaster');

        $this->assertSame($master, $result);
    }

    public function testSentinelConnectorUsesPconnectForSentinelButConnectForMasterWhenNotPersistent(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'sentinel://10.0.0.1:26379/mymaster?timeout=2';
            $maker = $this->createMock(MakerInterface::class);

            $sentinel = $this->createMock(Redis::class);
            $sentinel->expects($this->never())->method('pconnect');
            $sentinel->expects($this->once())->method('connect')->with('10.0.0.1', 26379, 2)->willReturn(true);
            $sentinel->expects($this->once())
                ->method('rawCommand')
                ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
                ->willReturn(['10.1.0.9', '6379']);
            $sentinel->expects($this->once())->method('close');

            $master = $this->createMock(Redis::class);
            $master->expects($this->once())->method('connect')->with('10.1.0.9', 6379, 2)->willReturn(true);
            $master->expects($this->never())->method('pconnect');

            $maker->expects($this->exactly(2))
                ->method('make')
                ->with(Redis::class)
                ->willReturnOnConsecutiveCalls($sentinel, $master);

            $connector = new SentinelConnector($maker);
            $result = $connector->connect($uri);
            $this->assertSame($master, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testSentinelConnectorDefaultsTimeoutToOneWhenMissing(): void
    {
        $maker = $this->createMock(MakerInterface::class);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())->method('connect')->with('10.0.0.1', 26379, 1)->willReturn(true);
        $sentinel->expects($this->once())
            ->method('rawCommand')
            ->with('SENTINEL', 'get-master-addr-by-name', 'mymaster')
            ->willReturn(['10.1.0.9', '6379']);
        $sentinel->expects($this->once())->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())->method('connect')->with('10.1.0.9', 6379, 1)->willReturn(true);

        $maker->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnOnConsecutiveCalls($sentinel, $master);

        $connector = new SentinelConnector($maker);
        $result = $connector->connect('sentinel://10.0.0.1:26379/mymaster');
        $this->assertSame($master, $result);
    }
}
