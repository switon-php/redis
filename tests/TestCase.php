<?php

declare(strict_types=1);

namespace Switon\Redis\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface as SwitonEventDispatcherInterface;
use Switon\Pooling\PoolManagerInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;

abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    #[Autowired] protected PoolManagerInterface $poolManager;
    protected EventDispatcherInterface|MockObject $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->container->set(SwitonEventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $this->eventDispatcher);

        if ($this->container->has(PoolManagerInterface::class)) {
            $this->container->remove(PoolManagerInterface::class);
        }
        $this->container->set(PoolManagerInterface::class, $this->createMock(PoolManagerInterface::class));

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }
}
