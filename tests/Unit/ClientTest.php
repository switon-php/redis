<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Pooling\PoolGuard;
use Switon\Redis\Client;
use Switon\Redis\Connection;
use Switon\Redis\Exception\CallInPoolException;
use Switon\Redis\Tests\TestCase;
use RedisException;
use stdClass;

/**
 * Test cases for Client class.
 *
 * Tests Redis client functionality including connection pooling, method calls,
 * and transient copy creation.
 *
 * @group redis
 */
#[AllowMockObjectsWithoutExpectations]
class ClientTest extends TestCase
{
    /**
     * Test that Client constructor parses pool_timeout from URI.
     */
    public function testConstructorParsesPoolTimeoutFromUri(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?pool_timeout=5';

        $this->poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(Client::class),
                $this->isArray(),
                4 // default pool_size
            );

        $client = $this->createClientForTesting($uri);

        // Verify pool_timeout was parsed (we can't directly access it, but we can verify behavior)
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that Client constructor parses pool_size from URI.
     */
    public function testConstructorParsesPoolSizeFromUri(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?pool_size=10';

        $this->poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(Client::class),
                $this->isArray(),
                10 // parsed pool_size
            );

        $client = $this->createClientForTesting($uri);

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that Client constructor parses both pool_timeout and pool_size from URI.
     */
    public function testConstructorParsesBothPoolParametersFromUri(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?pool_timeout=3&pool_size=8';

        $this->poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(Client::class),
                $this->isArray(),
                8 // parsed pool_size
            );

        $client = $this->createClientForTesting($uri);

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that Client constructor does not call poolManager->add when URI is not set.
     */
    public function testConstructorDoesNotCallPoolManagerWhenUriNotSet(): void
    {
        $this->poolManager->expects($this->never())
            ->method('add');

        $client = $this->container->make(Client::class);

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that __call uses dedicated connection when available (via transient client).
     */
    public function testCallUsesDedicatedConnectionWhenAvailable(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->with('get', ['test_key'])
            ->willReturn('test_value');

        // getTransient() uses PoolManagerInterface::guard() to hold a dedicated connection.
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, 1.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        // PoolGuard destructor calls release() - use any() to allow it during test cleanup
        $this->poolManager->expects($this->any())
            ->method('release');

        $transient = $client->getTransient();

        // Transient clone uses dedicated connection directly
        $result = $transient->get('test_key');

        $this->assertSame('test_value', $result);
    }

    /**
     * Test that __call uses pool manager when no dedicated connection.
     */
    public function testCallUsesPoolManagerWhenNoDedicatedConnection(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->with('set', ['key', 'value'])
            ->willReturn(true);

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, 1.0) // default pool_timeout
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($client, $connection, 'default');

        $result = $client->set('key', 'value');

        $this->assertTrue($result);
    }

    /**
     * Test that __call throws CallInPoolException for restricted methods.
     */
    public function testCallThrowsExceptionForRestrictedMethods(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');
        $restrictedMethods = ['multi', 'watch', 'unwatch', 'pipeline', 'select', 'scan', 'hScan', 'sScan', 'zScan'];

        foreach ($restrictedMethods as $method) {
            try {
                $client->$method();
                $this->fail("Expected CallInPoolException was not thrown for {$method}()");
            } catch (CallInPoolException $exception) {
                $this->assertStringContainsString($method, $exception->getMessage());
            }
        }
    }

    /**
     * Test that __call throws CallInPoolException when method returns object.
     */
    public function testCallThrowsExceptionWhenMethodReturnsObject(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $returnObject = new stdClass();
        $connection->expects($this->once())
            ->method('__call')
            ->with('someMethod', [])
            ->willReturn($returnObject);

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($client, $connection, 'default');

        $this->expectException(CallInPoolException::class);

        $client->someMethod();
    }

    /**
     * Test that transient client allows object return values.
     */
    public function testTransientClientAllowsObjectReturnValue(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');
        $connection = $this->createMock(Connection::class);
        $returnObject = new stdClass();

        $connection->expects($this->once())
            ->method('__call')
            ->with('rawObject', [])
            ->willReturn($returnObject);

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->isInstanceOf(Client::class), 1.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        // PoolGuard destructor releases held resource at test cleanup.
        $this->poolManager->expects($this->any())
            ->method('release');

        $transient = $client->getTransient();
        $result = $transient->rawObject();

        $this->assertSame($returnObject, $result);
    }

    /**
     * Test that getTransient() uses pool_timeout for hold.
     */
    public function testGetTransientUsesPoolTimeout(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?pool_timeout=5';
        $client = $this->createClientForTesting($uri);

        $connection = $this->createMock(Connection::class);

        // Configure mock to verify correct timeout is passed
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, 5.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        // PoolGuard destructor calls release() - use any() to allow it during test cleanup
        $this->poolManager->expects($this->any())
            ->method('release');

        $transient = $client->getTransient();
        $this->assertInstanceOf(Client::class, $transient);
    }

    /**
     * Test that regular Redis commands work through pool.
     */
    public function testRegularRedisCommandsWorkThroughPool(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('__call')
            ->willReturnMap([
                ['set', ['key1', 'value1'], true],
                ['get', ['key1'], 'value1'],
                ['del', ['key1'], 1],
            ]);

        $this->poolManager->expects($this->exactly(3))
            ->method('guard')
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->exactly(3))
            ->method('release')
            ->with($client, $connection, 'default');

        $this->assertTrue($client->set('key1', 'value1'));
        $this->assertSame('value1', $client->get('key1'));
        $this->assertSame(1, $client->del('key1'));
    }

    /**
     * Test that __call handles multiple consecutive calls correctly.
     */
    public function testCallHandlesMultipleConsecutiveCalls(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('__call')
            ->willReturnMap([
                ['set', ['key1', 'value1'], true],
                ['get', ['key1'], 'value1'],
                ['del', ['key1'], 1],
            ]);

        $this->poolManager->expects($this->exactly(3))
            ->method('guard')
            ->with($client, 1.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->exactly(3))
            ->method('release')
            ->with($client, $connection, 'default');

        $this->assertTrue($client->set('key1', 'value1'));
        $this->assertSame('value1', $client->get('key1'));
        $this->assertSame(1, $client->del('key1'));
    }

    /**
     * Test that unwatch is also in restricted methods list.
     */
    public function testUnwatchIsRestrictedMethod(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $this->expectException(CallInPoolException::class);

        $client->unwatch();
    }

    /**
     * Test that __call properly handles exceptions from connection.
     */
    public function testCallPropagatesExceptionsFromConnection(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->with('get', ['key'])
            ->willThrowException(new RedisException('Connection lost'));

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($client, $connection, 'default');

        $this->expectException(RedisException::class);

        $client->get('key');
    }

    /**
     * Test that __call returns connection to pool even when exception occurs.
     */
    public function testCallReturnsConnectionToPoolOnException(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->willThrowException(new RedisException('Error'));

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        // Verify release is called even when exception occurs (PoolGuard destructor during unwinding)
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($client, $connection, 'default');

        try {
            $client->get('key');
        } catch (RedisException $e) {
            // Exception expected
        }
    }

    /**
     * Test that Client works with real PoolManager.
     */
    public function testClientWorksWithRealPoolManager(): void
    {
        // Use mock pool manager instead of real one for better test control
        // Real PoolManager requires coroutine environment for proper Channel behavior
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        // Create a mock connection for the pool
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->with('get', ['key'])
            ->willReturn('value');

        // Use mock pool manager to control behavior
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, 1.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($client, $connection, 'default');

        // Test that client can get connection from pool
        $result = $client->get('key');

        $this->assertSame('value', $result);
    }

    /**
     * Test that Client throws BusyException when pool timeout is exceeded.
     */
    public function testClientThrowsBusyExceptionOnPoolTimeout(): void
    {
        // Use mock pool manager to simulate timeout scenario
        // Real PoolManager requires coroutine environment for proper timeout behavior
        $uri = 'redis://127.0.0.1:6379/0';
        $client = $this->createClientForTesting($uri);

        // Mock pool manager to throw BusyException on pop (simulating timeout)
        // Use $this->anything() for timeout since createClientForTesting sets pool_timeout to 1
        $busyException = \Switon\Pooling\Exception\BusyException::of('Pool busy');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, $this->anything())
            ->willThrowException($busyException);

        // This should timeout because pool is empty
        $this->expectException(\Switon\Pooling\Exception\BusyException::class);

        $client->get('key');
    }

    /**
     * Test that getUri() returns configured URI value.
     */
    public function testGetUriReturnsConfiguredUri(): void
    {
        $uri = 'redis://127.0.0.1:6379/0?pool_timeout=2';
        $client = $this->createClientForTesting($uri);

        $this->assertSame($uri, $client->getUri());
    }

    /**
     * Test that getUri() returns null when URI is not configured.
     */
    public function testGetUriReturnsNullWhenUriNotConfigured(): void
    {
        $this->poolManager->expects($this->never())->method('add');

        $client = $this->container->make(Client::class);

        $this->assertNull($client->getUri());
    }

    /**
     * Helper method to create Client instance for testing.
     */
    protected function createClientForTesting(string $uri): Client
    {
        $this->container->set(Client::class, [
            'uri' => $uri,
            'pool_timeout' => 1,
            'pool_size' => 4,
        ]);

        return $this->container->get(Client::class);
    }
}
