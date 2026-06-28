<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Fixtures;

use Switon\Redis\ClientInterface;

/** @internal Minimal {@see ClientInterface} stand-in for connection owner typing in tests. */
final class StubRedisClient implements ClientInterface
{
    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }
}
