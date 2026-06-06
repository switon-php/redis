<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Pooling\PoolGuard;
use Switon\Redis\Client;
use Switon\Redis\Connection;
use Switon\Redis\Tests\TestCase;

/**
 * Test cases for transient Client behavior.
 *
 * Redis no longer uses a separate TransientRedis class. Instead, getTransient() creates
 * a transient instance with a held connection from the pool.
 *
 * @group redis
 */
#[AllowMockObjectsWithoutExpectations]
class TransientRedisTest extends TestCase
{
    public function testGetTransientCreatesTransientClientWithHold(): void
    {
        $client = $this->createClientForTesting('redis://127.0.0.1:6379/0');

        $connection = $this->createMock(Connection::class);

        // Dedicated connection allows restricted methods
        $connection->expects($this->once())
            ->method('__call')
            ->with('multi', [])
            ->willReturn(true);

        // Configure mock to return connection when guard is called by getTransient().
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($client, 1.0)
            ->willReturnCallback(function (object $owner, ?float $timeout = null, string $type = 'default') use ($connection) {
                return new PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        $transient = $client->getTransient();
        $this->assertInstanceOf(Client::class, $transient);

        // Verify that transient instance can call restricted methods (like multi)
        // that are not allowed on pooled connections
        $this->assertTrue($transient->multi());
    }

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
