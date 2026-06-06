<?php

declare(strict_types=1);

namespace Switon\Redis\Event;

use Psr\Log\LoggerInterface;
use Switon\Core\Categorized;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\EventLogInterface;
use Switon\Eventing\Severity;
use Switon\Redis\Connection;

use function stripos;
use function substr;

/**
 * Event emitted before Redis commands are executed.
 *
 * Log category: redis command lifecycle.
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Event\RedisCalled
 */
#[EventLevel(Severity::DEBUG)]
class RedisCalling implements EventLogInterface
{
    /**
     * @param Connection $redis Connection that will execute the command
     * @param string $method Redis method name
     * @param array<int, mixed> $arguments Redis method arguments
     */
    public function __construct(
        public Connection $redis,
        public string     $method,
        public array      $arguments
    ) {

    }

    /**
     * Logs blocking Redis commands before execution.
     *
     * @param object $event Unused event argument required by the interface
     * @param LoggerInterface $logger Target logger
     */
    public function log(object $event, LoggerInterface $logger): void
    {
        // Log blocking Redis commands
        if (stripos(',blPop,brPop,brpoplpush,subscribe,psubscribe,', ",$this->method,") !== false) {
            $args = substr(Json::stringify($this->arguments, JSON_PARTIAL_OUTPUT_ON_ERROR), 1, -1);
            $logger->debug(Categorized::of('switon.redis.' . $this->method, "\$redis->$this->method({0}) ... blocking"), [$args]);
        }
    }
}
