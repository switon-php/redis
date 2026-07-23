<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Fixtures;

use Switon\Redis\Connection;
use Switon\Redis\ConnectorInterface;

class ConnectionProbe extends Connection
{
    /**
     * @param array<string, ConnectorInterface|string> $connectors
     */
    public function setConnectorsForTest(array $connectors): void
    {
        $this->connectors = $connectors;
    }
}
