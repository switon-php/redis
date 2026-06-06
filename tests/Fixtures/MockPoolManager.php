<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Fixtures;

use Switon\Pooling\PoolManagerInterface;
use RuntimeException;

/**
 * Mock pool manager for testing.
 *
 * Note: This class cannot use PHPUnit's createMock() because it's not a test class.
 * Tests should provide proper Connection mocks via add() method.
 */
class MockPoolManager implements PoolManagerInterface
{
    public array $pools = [];
    public array $added = [];
    public array $popped = [];
    public array $pushed = [];
    public array $created = [];

    public function remove(object $owner, ?string $type = null): void
    {
        $ownerId = spl_object_id($owner);
        unset($this->pools[$ownerId]);
        unset($this->added[$ownerId]);
        unset($this->popped[$ownerId]);
        unset($this->pushed[$ownerId]);
        unset($this->created[$ownerId]);
    }

    public function create(object $owner, int $capacity, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        $this->created[$ownerId][$type] = ['capacity' => $capacity];
    }

    public function add(object $owner, object|array $sample, int $size = 1, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        if (!isset($this->pools[$ownerId][$type])) {
            $this->pools[$ownerId][$type] = [];
        }

        for ($i = 0; $i < $size; $i++) {
            $this->pools[$ownerId][$type][] = $sample;
        }

        $this->added[$ownerId][$type][] = ['sample' => $sample, 'size' => $size];
    }

    public function push(object $owner, object $instance, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        if (!isset($this->pools[$ownerId][$type])) {
            $this->pools[$ownerId][$type] = [];
        }

        $this->pools[$ownerId][$type][] = $instance;
        $this->pushed[$ownerId][$type][] = $instance;
    }

    public function pop(object $owner, ?float $timeout = null, string $type = 'default'): object
    {
        $ownerId = spl_object_id($owner);
        if (empty($this->pools[$ownerId][$type])) {
            $connection = $this->createMockConnection();
            $this->popped[$ownerId][$type][] = $connection;
            return $connection;
        }

        $instance = array_shift($this->pools[$ownerId][$type]);
        $this->popped[$ownerId][$type][] = $instance;
        return $instance;
    }

    public function acquire(object $owner, ?float $timeout = null, string $type = 'default'): object
    {
        return $this->pop($owner, $timeout, $type);
    }

    public function release(object $owner, object $instance, string $type = 'default', ?float $elapsed = null): void
    {
        $this->push($owner, $instance, $type);
    }

    public function guard(object $owner, ?float $timeout = null, string $type = 'default'): \Switon\Pooling\PoolGuard
    {
        return new \Switon\Pooling\PoolGuard($this, $owner, $this->acquire($owner, $timeout, $type), $type);
    }

    public function exists(object $owner, string $type = 'default'): bool
    {
        $ownerId = spl_object_id($owner);
        return isset($this->pools[$ownerId][$type]) && !empty($this->pools[$ownerId][$type]);
    }

    public function size(object $owner, string $type = 'default'): int
    {
        $ownerId = spl_object_id($owner);
        return isset($this->pools[$ownerId][$type]) ? count($this->pools[$ownerId][$type]) : 0;
    }

    public function isEmpty(object $owner, string $type = 'default'): bool
    {
        $ownerId = spl_object_id($owner);
        return empty($this->pools[$ownerId][$type]);
    }

    protected function createMockConnection(): object
    {
        throw new RuntimeException(
            'MockPoolManager::createMockConnection() should not be called. ' .
            'Tests must add Connection mocks to the pool using add() before calling pop().'
        );
    }
}
