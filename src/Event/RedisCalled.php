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

use function strlen;
use function substr;

/**
 * Event emitted after Redis commands are executed.
 *
 * Log category: redis command lifecycle.
 *
 * @see \Switon\Redis\Client
 * @see \Switon\Redis\Connection
 * @see \Switon\Redis\Event\RedisCalling
 */
#[EventLevel(Severity::DEBUG)]
class RedisCalled implements EventLogInterface
{
    protected bool $verbose = true;

    /**
     * @param Connection $redis Connection that executed the command
     * @param string $method Redis method name
     * @param array<int, mixed> $arguments Redis method arguments
     * @param float $elapsed Execution time in seconds
     * @param mixed $return Redis command return value
     */
    public function __construct(
        public Connection $redis,
        public string     $method,
        public array      $arguments,
        public float      $elapsed,
        public mixed      $return,
    ) {

    }

    /**
     * Logs executed Redis commands with argument and return-value truncation.
     *
     * @param object $event Unused event argument required by the interface
     * @param LoggerInterface $logger Target logger
     */
    public function log(object $event, LoggerInterface $logger): void
    {
        $arguments = $this->arguments;
        foreach ($arguments as $k => $v) {
            if (is_string($v) && strlen($v) > 128) {
                $arguments[$k] = substr($v, 0, 128) . '...';
            }
        }

        if ($this->verbose) {
            $arguments = Json::stringify($arguments, JSON_PARTIAL_OUTPUT_ON_ERROR);
            $return = Json::stringify($this->return, JSON_PARTIAL_OUTPUT_ON_ERROR);

            $ret = strlen($return) > 64 ? substr($return, 0, 64) . '...' : $return;
            $args = strlen($arguments) > 256 ? substr($arguments, 1, 256) . '...)' : substr($arguments, 1, -1);
            $logger->debug(Categorized::of('switon.redis.' . $this->method, "\$redis->$this->method({0}) => {1}"), [$args, $ret]);
        } else {
            $arguments = Json::stringify($arguments, JSON_PARTIAL_OUTPUT_ON_ERROR);
            $args = strlen($arguments) > 256 ? substr($arguments, 1, 256) . '...)' : substr($arguments, 1, -1);
            $logger->debug(Categorized::of('switon.redis.' . $this->method, "\$redis->$this->method({0})"), [$args]);
        }
    }
}
