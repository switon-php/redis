<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Redis;
use RedisCluster;
use Switon\Core\MakerInterface;
use Switon\Core\Runtime;
use Switon\Redis\Connection;
use Switon\Redis\Connector\ClusterConnector;
use Switon\Redis\Connector\RedisConnector;
use Switon\Redis\Connector\SentinelConnector;
use Switon\Redis\ConnectorInterface;
use Switon\Redis\Event\RedisCalled;
use Switon\Redis\Event\RedisCalling;
use Switon\Redis\Event\RedisClose;
use Switon\Redis\Event\RedisConnected;
use Switon\Redis\Event\RedisConnecting;
use Switon\Redis\Exception\AuthException;
use Switon\Redis\Exception\ConnectionException;
use Switon\Redis\Exception\DatabaseSelectException;
use Switon\Redis\Exception\InvalidUriFormatException;
use Switon\Redis\Tests\TestCase;
use RedisException;
use ReflectionClass;
use RuntimeException;

/**
 * Test cases for Connection class.
 *
 * Tests Redis connection functionality including connection establishment,
 * heartbeat monitoring, event dispatching, and error handling.
 *
 * @group redis
 */
#[AllowMockObjectsWithoutExpectations]
class ConnectionTest extends TestCase
{
    protected MakerInterface $mockContainer;

    /**
     * Test that Connection constructor parses heartbeat from URI.
     */
    public function testConstructorParsesHeartbeatFromUri(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?heartbeat=30';

        $connection = $this->createConnectionForTesting($uri);

        $this->assertInstanceOf(Connection::class, $connection);
    }

    /**
     * Test that Connection constructor uses default heartbeat when not specified.
     */
    public function testConstructorUsesDefaultHeartbeatWhenNotSpecified(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';

        $connection = $this->createConnectionForTesting($uri);

        $this->assertInstanceOf(Connection::class, $connection);
    }

    /**
     * Test that getConnect() dispatches RedisConnecting event.
     */
    public function testGetConnectDispatchesRedisConnectingEvent(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $eventSequence = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        $connection->getConnect();

        // Verify RedisConnecting event was dispatched
        $this->assertContains(RedisConnecting::class, $eventSequence);
    }

    /**
     * Test that getConnect() dispatches RedisConnected event after successful connection.
     */
    public function testGetConnectDispatchesRedisConnectedEvent(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $eventSequence = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        $connection->getConnect();

        // Verify RedisConnected event was dispatched (after RedisConnecting)
        $this->assertContains(RedisConnected::class, $eventSequence);
    }

    /**
     * Test that getConnect() throws ConnectionException on connection failure.
     */
    public function testGetConnectThrowsConnectionExceptionOnFailure(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(false);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . ConnectionException::class . ' to be thrown.');
        } catch (ConnectionException $e) {
            $this->assertSame('Failed to connect to Redis server at "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    /**
     * Test that getConnect() throws AuthException on authentication failure.
     */
    public function testGetConnectThrowsAuthExceptionOnAuthFailure(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?auth=wrongpassword';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('auth')
            ->with('wrongpassword')
            ->willReturn(false);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . AuthException::class . ' to be thrown.');
        } catch (AuthException $e) {
            $this->assertSame('Redis authentication failed for server at "' . $uri . '"', $e->getMessage());
            $this->assertSame(['auth' => 'wrongpassword'], $e->getContext());
        }
    }

    /**
     * Test that getConnect() selects database from URI path.
     */
    public function testGetConnectSelectsDatabaseFromUriPath(): void
    {
        $uri = 'redis://127.0.0.1:6379/1';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('select')
            ->with(1)
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that getConnect() selects database from query parameter.
     */
    public function testGetConnectSelectsDatabaseFromQueryParameter(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?db=2';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('select')
            ->with(2) // Query parameter takes precedence
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that getConnect() throws DatabaseSelectException on select failure.
     */
    public function testGetConnectThrowsDatabaseSelectExceptionOnSelectFailure(): void
    {
        $uri = 'redis://127.0.0.1:6379/1';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('select')
            ->with(1)
            ->willReturn(false);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . DatabaseSelectException::class . ' to be thrown.');
        } catch (DatabaseSelectException $e) {
            $this->assertSame('Failed to select Redis database 1', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    /**
     * Test that getConnect() throws InvalidUriFormatException for unsupported scheme.
     */
    public function testGetConnectThrowsInvalidUriFormatExceptionForUnsupportedScheme(): void
    {
        $uri = 'http://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . InvalidUriFormatException::class . ' to be thrown.');
        } catch (InvalidUriFormatException $e) {
            $this->assertSame('Invalid Redis URI format: "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    public function testGetConnectUsesInjectedConnectorInstanceForRedisScheme(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redisConnector = new class ($redis, $uri) implements ConnectorInterface {
            public bool $called = false;

            public function __construct(
                private Redis  $redis,
                private string $expectedUri
            ) {
            }

            public function supports(string $scheme): bool
            {
                return true;
            }

            public function connect(string $uri): Redis|RedisCluster
            {
                $this->called = true;
                if ($uri !== $this->expectedUri) {
                    throw new RuntimeException('unexpected uri');
                }

                return $this->redis;
            }
        };

        $this->mockContainer->expects($this->never())
            ->method('make');

        $this->injectConnectionProperty($connection, 'connectors', [
            'redis' => $redisConnector,
            'rediss' => $redisConnector,
            'sentinel' => new SentinelConnector($this->mockContainer),
            'cluster' => new ClusterConnector($this->mockContainer),
        ]);

        $result = $connection->getConnect();

        $this->assertTrue($redisConnector->called);
        $this->assertSame($redis, $result);
    }

    public function testGetConnectUsesInjectedConnectorInstanceForClusterScheme(): void
    {
        $uri = 'cluster://127.0.0.1?timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);
        $clusterConnector = new class ($cluster, $uri) implements ConnectorInterface {
            public bool $called = false;

            public function __construct(
                private RedisCluster $cluster,
                private string       $expectedUri
            ) {
            }

            public function supports(string $scheme): bool
            {
                return true;
            }

            public function connect(string $uri): Redis|RedisCluster
            {
                $this->called = true;
                if ($uri !== $this->expectedUri) {
                    throw new RuntimeException('unexpected uri');
                }

                return $this->cluster;
            }
        };

        $this->mockContainer->expects($this->never())
            ->method('make');

        $this->injectConnectionProperty($connection, 'connectors', [
            'redis' => new RedisConnector($this->mockContainer),
            'rediss' => new RedisConnector($this->mockContainer),
            'sentinel' => new SentinelConnector($this->mockContainer),
            'cluster' => $clusterConnector,
        ]);

        $result = $connection->getConnect();

        $this->assertTrue($clusterConnector->called);
        $this->assertSame($cluster, $result);
    }

    /**
     * Test that getConnect() builds RedisCluster for cluster:// URIs.
     */
    public function testGetConnectBuildsRedisClusterFromClusterUri(): void
    {
        $uri = 'cluster://127.0.0.1?timeout=2&auth=secret';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['127.0.0.1:6379'], 2, null, false, 'secret']
            )
            ->willReturn($cluster);

        $eventSequence = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        $result = $connection->getConnect();

        $this->assertSame($cluster, $result);
        $this->assertContains(RedisConnecting::class, $eventSequence);
        $this->assertContains(RedisConnected::class, $eventSequence);
    }

    /**
     * Test that cluster:// URIs ignore db selection options.
     */
    public function testGetConnectClusterUriIgnoresDatabaseSelection(): void
    {
        $uri = 'cluster://127.0.0.1/3?db=5&timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['127.0.0.1:6379'], 2, null, false, null]
            )
            ->willReturn($cluster);

        $result = $connection->getConnect();
        $this->assertSame($cluster, $result);
    }

    /**
     * Test that cluster:// URI parses multiple seeds with explicit ports.
     */
    public function testGetConnectBuildsRedisClusterWithMultipleSeeds(): void
    {
        $uri = 'cluster://10.0.0.1:7000,10.0.0.2:7001?timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['10.0.0.1:7000', '10.0.0.2:7001'], 2, null, false, null]
            )
            ->willReturn($cluster);

        $result = $connection->getConnect();
        $this->assertSame($cluster, $result);
    }

    /**
     * Test that cluster:// URI applies default port for seeds without explicit port.
     */
    public function testGetConnectBuildsRedisClusterWithDefaultPortsForSeeds(): void
    {
        $uri = 'cluster://10.0.0.1,10.0.0.2?timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(
                RedisCluster::class,
                [null, ['10.0.0.1:6379', '10.0.0.2:6379'], 2, null, false, null]
            )
            ->willReturn($cluster);

        $result = $connection->getConnect();
        $this->assertSame($cluster, $result);
    }

    /**
     * Test that cluster:// URI without seeds throws InvalidUriFormatException.
     */
    public function testGetConnectClusterUriWithoutSeedsThrowsInvalidUriFormatException(): void
    {
        $uri = 'cluster://?timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . InvalidUriFormatException::class . ' to be thrown.');
        } catch (InvalidUriFormatException $e) {
            $this->assertSame('Invalid Redis URI format: "' . $uri . '"', $e->getMessage());
            $this->assertSame([], $e->getContext());
        }
    }

    /**
     * Test that __call() dispatches RedisCalling event.
     */
    public function testCallDispatchesRedisCallingEvent(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with('key')
            ->willReturn('value');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $eventSequence = [];
        $callingEvent = null;
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence, &$callingEvent) {
                $eventSequence[] = $event::class;
                if ($event instanceof RedisCalling) {
                    $callingEvent = $event;
                }
                return $event;
            });

        $connection->get('key');

        // Verify RedisCalling event was dispatched (after RedisConnecting/RedisConnected from getConnect)
        $this->assertContains(RedisCalling::class, $eventSequence, 'RedisCalling event should be dispatched');
        $this->assertNotNull($callingEvent, 'RedisCalling event should be captured');
        $this->assertSame('get', $callingEvent->method);
        $this->assertSame(['key'], $callingEvent->arguments);
    }

    /**
     * Test that __call() dispatches RedisCalled event.
     */
    public function testCallDispatchesRedisCalledEvent(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('set')
            ->with('key', 'value')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $eventSequence = [];
        $calledEvent = null;
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence, &$calledEvent) {
                $eventSequence[] = $event::class;
                if ($event instanceof RedisCalled) {
                    $calledEvent = $event;
                }
                return $event;
            });

        $connection->set('key', 'value');

        // Verify RedisCalled event was dispatched (getConnect dispatches RedisConnecting/RedisConnected first)
        $this->assertContains(RedisCalled::class, $eventSequence);
        $this->assertNotNull($calledEvent, 'RedisCalled event should be captured');
        $this->assertSame('set', $calledEvent->method);
        $this->assertSame(['key', 'value'], $calledEvent->arguments);
        $this->assertIsFloat($calledEvent->elapsed);
        $this->assertGreaterThan(0.0, $calledEvent->elapsed);
        $this->assertSame(true, $calledEvent->return);
    }

    /**
     * Test that close() dispatches RedisClose event.
     */
    public function testCloseDispatchesRedisCloseEvent(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('close')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        // Establish connection first
        $connection->getConnect();

        $closeEvent = null;
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$closeEvent) {
                $closeEvent = $event;
                return $event;
            });

        $connection->close();

        $this->assertInstanceOf(RedisClose::class, $closeEvent);
        $this->assertSame($uri, $closeEvent->uri);
        $this->assertSame($redis, $closeEvent->redis);
        $this->assertSame($connection, $closeEvent->connection);
    }

    /**
     * Test that close() resets connection state.
     */
    public function testCloseResetsConnectionState(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('close')
            ->willReturn(true);

        $redis2 = $this->createMock(Redis::class);
        $redis2->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $callCount = 0;
        $this->mockContainer->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $redis, $redis2) {
                $callCount++;
                return $callCount === 1 ? $redis : $redis2;
            });

        // Establish connection (first call to make)
        $connection->getConnect();

        // Close connection
        $connection->close();

        // Connection should be reset, so getConnect() should create new connection (second call to make)
        $connection->getConnect();
    }

    /**
     * Test that __clone() resets connection state.
     */
    public function testCloneResetsConnectionState(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $redis2 = $this->createMock(Redis::class);
        $redis2->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $callCount = 0;
        $this->mockContainer->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $redis, $redis2) {
                $callCount++;
                return $callCount === 1 ? $redis : $redis2;
            });

        // Establish connection
        $connection->getConnect();

        // Clone connection
        $cloned = clone $connection;

        // Cloned connection should need new connection
        $cloned->getConnect();
    }

    /**
     * Test that getConnect() reuses existing connection when heartbeat is valid.
     */
    public function testGetConnectReusesExistingConnectionWhenHeartbeatValid(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?heartbeat=60';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        // First call creates connection
        $connection->getConnect();

        // Second call should reuse connection (no new connect call)
        $this->mockContainer->expects($this->never())
            ->method('make');

        $result = $connection->getConnect();
        $this->assertSame($redis, $result);
    }

    /**
     * Test that getConnect() reconnects when heartbeat check fails.
     */
    public function testGetConnectReconnectsWhenHeartbeatFails(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?heartbeat=0';
        $connection = $this->createConnectionForTesting($uri);

        $redis1 = $this->createMock(Redis::class);
        $redis1->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis1->expects($this->once())
            ->method('echo')
            ->with('heartbeat')
            ->willThrowException(new RedisException('heartbeat failed'));
        $redis1->expects($this->once())
            ->method('close');

        $redis2 = $this->createMock(Redis::class);
        $redis2->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $callCount = 0;
        $eventSequence = [];
        $this->mockContainer->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $redis1, $redis2) {
                $callCount++;
                return $callCount === 1 ? $redis1 : $redis2;
            });

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        // First call establishes initial connection.
        $this->assertSame($redis1, $connection->getConnect());

        // Second call performs heartbeat check, then reconnects due to failure.
        $this->assertSame($redis2, $connection->getConnect());

        $connectingIndices = [];
        $closeIndex = null;
        foreach ($eventSequence as $i => $class) {
            if ($class === RedisConnecting::class) {
                $connectingIndices[] = $i;
            }
            if ($class === RedisClose::class) {
                $closeIndex = $i;
            }
        }

        $this->assertCount(2, $connectingIndices, 'Should dispatch RedisConnecting twice (initial + reconnect)');
        $this->assertNotNull($closeIndex, 'Should dispatch RedisClose on heartbeat failure');
        $this->assertGreaterThan($connectingIndices[0], $closeIndex);
        $this->assertLessThan($connectingIndices[1], $closeIndex);
    }

    /**
     * Test that heartbeat success keeps existing connection without reconnect.
     */
    public function testGetConnectKeepsConnectionWhenHeartbeatSucceeds(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?heartbeat=0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('connect')->willReturn(true);
        $redis->expects($this->once())->method('echo')->with('heartbeat')->willReturn('heartbeat');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $eventSequence = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        $this->assertSame($redis, $connection->getConnect());
        $this->assertSame($redis, $connection->getConnect());

        $this->assertSame(1, count(array_filter($eventSequence, fn ($e) => $e === RedisConnecting::class)));
        $this->assertSame(0, count(array_filter($eventSequence, fn ($e) => $e === RedisClose::class)));
    }

    /**
     * Test that close() on unopened connection is a no-op.
     */
    public function testCloseDoesNothingWhenNoActiveConnection(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $this->eventDispatcher->expects($this->never())->method('dispatch');
        $connection->close();
    }

    /**
     * Test that close() properly closes connection.
     *
     * Note: Heartbeat check failure requires time to pass, which is not easily testable
     * through public methods only. This test verifies that close() works correctly,
     * which is called when heartbeat check fails.
     */
    public function testCloseProperlyClosesConnection(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('close');

        $redis2 = $this->createMock(Redis::class);
        $redis2->expects($this->once())
            ->method('connect')
            ->willReturn(true);

        $callCount = 0;
        $this->mockContainer->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $redis, $redis2) {
                $callCount++;
                return $callCount === 1 ? $redis : $redis2;
            });

        // Establish connection (first call to make)
        $connection->getConnect();

        // Close connection using public method
        $connection->close();

        // Verify connection is reset by trying to get connect again (should create new connection, second call to make)
        $connection->getConnect();
    }

    /**
     * Test that getConnect() handles read_timeout option.
     */
    public function testGetConnectHandlesReadTimeoutOption(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?read_timeout=30';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, 30);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that getConnect() passes string read_timeout value through unchanged.
     */
    public function testGetConnectPassesStringReadTimeoutValueAsIs(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?read_timeout=2.5';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('connect')->willReturn(true);
        $redis->expects($this->once())
            ->method('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, '2.5');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that cluster connections do not attempt Redis-only read_timeout option.
     */
    public function testGetConnectClusterDoesNotApplyReadTimeoutOption(): void
    {
        $uri = 'cluster://127.0.0.1?read_timeout=3';
        $connection = $this->createConnectionForTesting($uri);

        $cluster = $this->createMock(RedisCluster::class);
        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(RedisCluster::class, [null, ['127.0.0.1:6379'], 1, null, false, null])
            ->willReturn($cluster);

        $result = $connection->getConnect();
        $this->assertSame($cluster, $result);
    }

    /**
     * Test that call() handles monitor operation with read_timeout adjustment.
     */
    public function testCallHandlesMonitorOperationWithReadTimeoutAdjustment(): void
    {
        if (!method_exists(Redis::class, 'monitor')) {
            $this->markTestSkipped('Current Redis extension does not expose monitor().');
        }

        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(2.5);

        $setOptionCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setOption')
            ->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
                $setOptionCalls[] = [$option, $value];
                return true;
            });
        $redis->expects($this->once())
            ->method('monitor')
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->monitor();

        $this->assertTrue($result);
        $this->assertSame(
            [
                [Redis::OPT_READ_TIMEOUT, -1],
                [Redis::OPT_READ_TIMEOUT, 2.5],
            ],
            $setOptionCalls,
            'monitor() should set timeout to -1 and restore previous timeout'
        );
    }

    /**
     * Test that call() handles subscribe operations with read_timeout adjustment.
     */
    public function testCallHandlesSubscribeOperationsWithReadTimeoutAdjustment(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(5.0);
        $setOptionCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setOption')
            ->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
                $setOptionCalls[] = [$option, $value];
                return true;
            });
        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['channel'], $this->anything())
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->subscribe(['channel'], function () {
        });

        $this->assertSame(
            [
                [Redis::OPT_READ_TIMEOUT, -1],
                [Redis::OPT_READ_TIMEOUT, 5.0],
            ],
            $setOptionCalls,
            'subscribe() should set timeout to -1 and restore previous timeout'
        );
    }

    public function testCallDoesNotRestoreReadTimeoutWhenSubscribeGetOptionReturnsNull(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(null);

        // When previous read_timeout is unknown (null), call() should not attempt to restore it.
        $redis->expects($this->exactly(1))
            ->method('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, -1)
            ->willReturn(true);

        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['channel'], $this->anything())
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->subscribe(['channel'], function () {
        });

        $this->assertTrue($result);
    }

    /**
     * Test that call() restores read_timeout even when subscribe throws.
     */
    public function testCallRestoresReadTimeoutWhenSubscribeThrowsException(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(7.5);

        $setOptionCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setOption')
            ->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
                $setOptionCalls[] = [$option, $value];
                return true;
            });
        $redis->expects($this->once())
            ->method('subscribe')
            ->with(['channel'], $this->anything())
            ->willThrowException(new RedisException('subscribe failed'));

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        try {
            $connection->subscribe(['channel'], function () {
            });
            $this->fail('Expected RedisException was not thrown');
        } catch (RedisException $exception) {
            $this->assertSame('subscribe failed', $exception->getMessage());
        }

        $this->assertSame(
            [
                [Redis::OPT_READ_TIMEOUT, -1],
                [Redis::OPT_READ_TIMEOUT, 7.5],
            ],
            $setOptionCalls,
            'subscribe() should always restore read timeout in finally block'
        );
    }

    /**
     * Test that call() handles psubscribe operations with read_timeout adjustment.
     */
    public function testCallHandlesPsubscribeOperationsWithReadTimeoutAdjustment(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(3.0);

        $setOptionCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setOption')
            ->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
                $setOptionCalls[] = [$option, $value];
                return true;
            });

        $redis->expects($this->once())
            ->method('psubscribe')
            ->with(['news.*'], $this->anything())
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->psubscribe(['news.*'], function () {
        });

        $this->assertTrue($result);
        $this->assertSame(
            [
                [Redis::OPT_READ_TIMEOUT, -1],
                [Redis::OPT_READ_TIMEOUT, 3.0],
            ],
            $setOptionCalls,
            'psubscribe() should set timeout to -1 and restore previous timeout'
        );
    }

    /**
     * Test that call() restores read_timeout even when psubscribe throws.
     */
    public function testCallRestoresReadTimeoutWhenPsubscribeThrowsException(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(7.5);

        $setOptionCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setOption')
            ->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
                $setOptionCalls[] = [$option, $value];
                return true;
            });

        $redis->expects($this->once())
            ->method('psubscribe')
            ->with(['news.*'], $this->anything())
            ->willThrowException(new RedisException('psubscribe failed'));

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        try {
            $connection->psubscribe(['news.*'], function () {
            });
            $this->fail('Expected RedisException was not thrown');
        } catch (RedisException $exception) {
            $this->assertSame('psubscribe failed', $exception->getMessage());
        }

        $this->assertSame(
            [
                [Redis::OPT_READ_TIMEOUT, -1],
                [Redis::OPT_READ_TIMEOUT, 7.5],
            ],
            $setOptionCalls,
            'psubscribe() should always restore read timeout in finally block'
        );
    }

    /**
     * Test that call() does not restore read_timeout when psubscribe getOption returns null.
     */
    public function testCallDoesNotRestoreReadTimeoutWhenPsubscribeGetOptionReturnsNull(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->willReturn(null);

        // Previous read_timeout is unknown (null), so restore is not attempted.
        $redis->expects($this->once())
            ->method('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, -1)
            ->willReturn(true);

        $redis->expects($this->once())
            ->method('psubscribe')
            ->with(['news.*'], $this->anything())
            ->willReturn(true);

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->psubscribe(['news.*'], function () {
        });

        $this->assertTrue($result);
    }

    /**
     * Test that call() handles regular operations without read_timeout adjustment.
     */
    public function testCallHandlesRegularOperationsWithoutReadTimeoutAdjustment(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('getOption');
        $redis->expects($this->never())
            ->method('setOption');
        $redis->expects($this->once())
            ->method('get')
            ->with('key')
            ->willReturn('value');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->get('key');
        $this->assertSame('value', $result);
    }

    /**
     * Test that RedisCalled event carries method arguments and return value.
     */
    public function testCallDispatchesRedisCalledWithReturnPayload(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('connect')->willReturn(true);
        $redis->expects($this->once())->method('get')->with('k')->willReturn('v');

        $this->mockContainer->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

        $calledEvents = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$calledEvents) {
                if ($event instanceof RedisCalled) {
                    $calledEvents[] = $event;
                }
                return $event;
            });

        $result = $connection->get('k');

        $this->assertSame('v', $result);
        $this->assertCount(1, $calledEvents);
        $this->assertSame('get', $calledEvents[0]->method);
        $this->assertSame(['k'], $calledEvents[0]->arguments);
        $this->assertSame('v', $calledEvents[0]->return);
        $this->assertGreaterThanOrEqual(0.0, $calledEvents[0]->elapsed);
    }

    /**
     * Test that RedisCalling event is emitted before command execution.
     */
    public function testCallDispatchesRedisCallingBeforeRedisCalled(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('connect')->willReturn(true);
        $redis->expects($this->once())->method('set')->with('k', 'v')->willReturn(true);

        $this->mockContainer->expects($this->once())->method('make')->with(Redis::class)->willReturn($redis);

        $order = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$order) {
                if ($event instanceof RedisCalling) {
                    $order[] = 'calling';
                }
                if ($event instanceof RedisCalled) {
                    $order[] = 'called';
                }
                return $event;
            });

        $connection->set('k', 'v');

        $this->assertContains('calling', $order);
        $this->assertContains('called', $order);
        $this->assertGreaterThan(
            array_search('calling', $order, true),
            array_search('called', $order, true),
            'RedisCalling should be dispatched before RedisCalled'
        );
    }

    /**
     * Test that getConnect() does not call select() when database is 0.
     */
    public function testGetConnectDoesNotCallSelectWhenDatabaseIsZero(): void
    {
        $uri = 'redis://127.0.0.1:6379/0';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('select');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that getConnect() handles empty auth string (no auth call).
     */
    public function testGetConnectHandlesEmptyAuthString(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?auth=';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('auth');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $connection->getConnect();
    }

    /**
     * Test that getConnect() supports rediss:// URIs via TLS host prefix.
     */
    public function testGetConnectBuildsTlsRedisFromRedissUri(): void
    {
        $uri = 'rediss://127.0.0.1:6380/0?timeout=2';
        $connection = $this->createConnectionForTesting($uri);

        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->with('tls://127.0.0.1', 6380, 2)
            ->willReturn(true);
        $redis->expects($this->never())
            ->method('select');

        $this->mockContainer->expects($this->once())
            ->method('make')
            ->with(Redis::class)
            ->willReturn($redis);

        $result = $connection->getConnect();
        $this->assertSame($redis, $result);
    }

    /**
     * Test that sentinel URI resolves master and then connects to master Redis.
     */
    public function testGetConnectBuildsRedisFromSentinelUri(): void
    {
        $uri = 'sentinel://10.0.0.1:26379/mymaster?timeout=2&auth=redis-secret&sentinel_auth=sentinel-secret&db=3';
        $connection = $this->createConnectionForTesting($uri);

        $sentinel = $this->createMock(Redis::class);
        $sentinel->expects($this->once())
            ->method('connect')
            ->with('10.0.0.1', 26379, 2)
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

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.1.0.9', 6380, 2)
            ->willReturn(true);
        $master->expects($this->once())
            ->method('auth')
            ->with('redis-secret')
            ->willReturn(true);
        $master->expects($this->once())
            ->method('select')
            ->with(3)
            ->willReturn(true);

        $callCount = 0;
        $this->mockContainer->expects($this->exactly(2))
            ->method('make')
            ->with(Redis::class)
            ->willReturnCallback(function () use (&$callCount, $sentinel, $master) {
                $callCount++;
                return $callCount === 1 ? $sentinel : $master;
            });

        $result = $connection->getConnect();
        $this->assertSame($master, $result);
    }

    /**
     * Test that sentinel URI falls back to next seed when previous seed fails.
     */
    public function testGetConnectSentinelFallsBackToNextSeed(): void
    {
        $uri = 'sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?timeout=1';
        $connection = $this->createConnectionForTesting($uri);

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
            ->willReturn(['10.2.0.9', '6379']);
        $sentinel2->expects($this->once())
            ->method('close');

        $master = $this->createMock(Redis::class);
        $master->expects($this->once())
            ->method('connect')
            ->with('10.2.0.9', 6379, 1)
            ->willReturn(true);
        $master->expects($this->never())
            ->method('auth');

        $callCount = 0;
        $this->mockContainer->expects($this->exactly(3))
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

        $result = $connection->getConnect();
        $this->assertSame($master, $result);
    }

    /**
     * Test that sentinel URI without master name is rejected.
     */
    public function testGetConnectSentinelUriWithoutMasterThrowsInvalidUriFormatException(): void
    {
        $uri = 'sentinel://10.0.0.1:26379?timeout=1';
        $connection = $this->createConnectionForTesting($uri);

        try {
            $connection->getConnect();
            $this->fail('Expected ' . InvalidUriFormatException::class . ' to be thrown.');
        } catch (InvalidUriFormatException $e) {
            $this->assertSame(
                'Invalid Redis sentinel URI format: missing master name in "' . $uri . '"',
                $e->getMessage()
            );
            $this->assertSame([], $e->getContext());
        }
    }

    /**
     * Test that getConnect() uses pconnect when coroutine is enabled and persistent=1.
     */
    public function testGetConnectUsesPconnectWhenCoroutineEnabledAndPersistentIsSet(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'redis://127.0.0.1:6379/0?persistent=1';
            $connection = $this->createConnectionForTesting($uri);

            $redis = $this->createMock(Redis::class);
            $redis->expects($this->once())
                ->method('pconnect')
                ->with('127.0.0.1', 6379, 1, md5($uri))
                ->willReturn(true);
            $redis->expects($this->never())
                ->method('connect');

            $this->mockContainer->expects($this->once())
                ->method('make')
                ->with(Redis::class)
                ->willReturn($redis);

            $result = $connection->getConnect();
            $this->assertSame($redis, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    /**
     * Test that cluster getConnect() passes persistent=true when coroutine is enabled.
     */
    public function testGetConnectClusterUsesPersistentWhenCoroutineEnabled(): void
    {
        Runtime::setCoroutineEnabled(true);
        try {
            $uri = 'cluster://127.0.0.1?persistent=1';
            $connection = $this->createConnectionForTesting($uri);

            $cluster = $this->createMock(RedisCluster::class);
            $this->mockContainer->expects($this->once())
                ->method('make')
                ->with(
                    RedisCluster::class,
                    [null, ['127.0.0.1:6379'], 1, null, true, null]
                )
                ->willReturn($cluster);

            $result = $connection->getConnect();
            $this->assertSame($cluster, $result);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testRedisConnectedJsonSerializeRemovesUriQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $redis = $this->createMock(Redis::class);

        $uriWithQuery = 'redis://127.0.0.1:6379/0?auth=secret&timeout=2';
        $event = new RedisConnected($connection, $uriWithQuery, $redis);
        $this->assertSame(['uri' => 'redis://127.0.0.1:6379/0'], $event->jsonSerialize());

        $uriWithoutQuery = 'redis://127.0.0.1:6379/0';
        $event2 = new RedisConnected($connection, $uriWithoutQuery, $redis);
        $this->assertSame(['uri' => $uriWithoutQuery], $event2->jsonSerialize());
    }

    /**
     * Helper method to create Connection instance for testing.
     */
    protected function createConnectionForTesting(string $uri): Connection
    {
        // Create mock container for make() function calls
        $this->mockContainer = $this->createMock(MakerInterface::class);

        $redisConnector = new RedisConnector($this->mockContainer);
        $sentinelConnector = new SentinelConnector($this->mockContainer);
        $clusterConnector = new ClusterConnector($this->mockContainer);

        // Use container's make() method to properly inject dependencies
        return $this->make(Connection::class, [
            'uri' => $uri,
            'eventDispatcher' => $this->eventDispatcher,
            'connectors' => [
                'redis' => $redisConnector,
                'rediss' => $redisConnector,
                'sentinel' => $sentinelConnector,
                'cluster' => $clusterConnector,
            ],
        ]);
    }

    protected function injectConnectionProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }
}
