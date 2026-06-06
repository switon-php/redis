<?php

declare(strict_types=1);

namespace Switon\Redis\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Pooling\PoolManagerInterface;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Base test case for Redis tests.
 */
abstract class TestCase extends BaseTestCase
{
    #[Autowired] protected PoolManagerInterface $poolManager;
    protected EventDispatcherInterface|MockObject $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Replace default MockEventDispatcher with mock (Redis tests need to verify events)
        $this->container->remove(EventDispatcherInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);

        // Create mock pool manager by default
        // Tests can override this to use real PoolManager if needed
        // Remove it first if it was already resolved
        if ($this->container->has(PoolManagerInterface::class)) {
            $this->container->remove(PoolManagerInterface::class);
        }
        $mockPoolManager = $this->createMock(PoolManagerInterface::class);

        $this->container->set(PoolManagerInterface::class, $mockPoolManager);

        // Inject all dependencies into TestCase via #[Autowired] attributes
        $this->injector->inject($this);
    }
}
