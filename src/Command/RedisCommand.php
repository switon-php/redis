<?php

declare(strict_types=1);

namespace Switon\Redis\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Console\Colors;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Redis\ClientInterface;
use Switon\Redis\Exception\ConnectionException;
use Throwable;

use function count;
use function explode;
use function gettype;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function microtime;
use function number_format;
use function parse_url;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function ucfirst;

/**
 * Redis diagnostics and server inspection.
 *
 * Use when checking connectivity, machine-readable connection info, or raw INFO output from the console.
 *
 * Road-signs:
 * - pingAction() for health checks
 * - infoAction() for JSON/table status output
 * - serverAction() for raw INFO sections
 *
 * @see \Switon\Redis\ClientInterface
 * @see \Switon\Di\NamedLookupInterface
 * @see \Switon\Redis\Exception\ConnectionException
 */
class RedisCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ClientInterface $redis;
    /** @var NamedLookupInterface<ClientInterface> */
    #[Autowired] protected NamedLookupInterface $namedLookup;

    /**
     * Checks whether a Redis connection is reachable.
     *
     * @param string $connection Named Redis client or empty for default
     */
    #[Tool('[connection]. Exit 0 = OK, exit 1 on error.')]
    public function pingAction(string $connection = ''): int
    {
        try {
            $redis = $this->getRedis($connection);
            $pong = $redis->ping();
            if ($pong !== 'PONG' && $pong !== true) {
                return $this->console->error('Ping failed: unexpected response');
            }
            $this->console->writeLn('OK');
            return 0;
        } catch (ConnectionException $e) {
            return $this->console->error('Ping failed: {message}', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            return $this->console->error('Ping failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Shows one Redis connection status and server info.
     *
     * @param string $connection Named Redis client or empty for default
     * @param bool $uri Include full connection URI with credentials (for agent/tooling to connect)
     * @param bool $json Output machine-readable JSON
     */
    #[Hidden, Tool('[connection] [--uri]. Returns JSON: connection, status, latency_ms, host, port, database, version, keys, uri.')]
    public function infoAction(string $connection = '', bool $uri = false, bool $json = true): int
    {
        try {
            $redis = $this->getRedis($connection);

            $start = microtime(true);
            $pong = $redis->ping();
            $latency = microtime(true) - $start;

            if ($pong !== 'PONG' && $pong !== true) {
                return $this->console->error('Connection check failed: Unexpected response from PING');
            }

            $info = $redis->info('server');
            $serverVersion = $info['redis_version'] ?? 'unknown';
            $dbSize = $redis->dbsize();
            $connName = $connection !== '' ? $connection : 'default';

            $connectionUri = $redis->getUri();
            $host = $port = $database = null;
            if ($connectionUri !== null) {
                $parsed = parse_url($connectionUri);
                $host = $parsed['host'] ?? 'localhost';
                $port = $parsed['port'] ?? 6379;
                $path = $parsed['path'] ?? '/0';
                $database = preg_match('#/(\d+)#', $path, $matches) === 1 ? (int)$matches[1] : 0;
            }
            $host = $host ?? '';
            $port = $port ?? '';
            $database = $database ?? '';

            if ($json) {
                $out = [
                    'connection' => $connName,
                    'status' => 'Connected',
                    'latency_ms' => round($latency * 1000, 2),
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'version' => $serverVersion,
                    'keys' => $dbSize,
                ];
                if ($uri && $connectionUri !== null) {
                    $out['uri'] = $connectionUri;
                }
                $this->console->writeLn(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 0;
            }

            $headers = ['connection', 'status', 'latency', 'host', 'port', 'database', 'version', 'keys'];
            $row = [$connName, 'Connected', sprintf('%.2fms', $latency * 1000), $host, $port, $database, $serverVersion, $dbSize];
            if ($uri) {
                $headers[] = 'uri';
                $row[] = $connectionUri ?? '';
            }
            $this->console->table($headers, [$row]);

            return 0;
        } catch (ConnectionException $e) {
            $connName = $connection !== '' ? $connection : 'default';
            if ($json) {
                $this->console->writeLn(json_encode([
                    'error' => $e->getMessage(),
                    'connection' => $connName,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 1;
            }
            return $this->console->error('Info failed: {message}', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $connName = $connection !== '' ? $connection : 'default';
            if ($json) {
                $this->console->writeLn(json_encode([
                    'error' => $e->getMessage(),
                    'connection' => $connName,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 1;
            }
            return $this->console->error('Info failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Shows raw Redis INFO output, optionally filtered by section.
     *
     * @param string $connection Named Redis client or empty for default
     * @param string $section INFO section name or empty for all
     */
    public function serverAction(string $connection = '', string $section = ''): int
    {
        try {
            $redis = $this->getRedis($connection);

            // Print the connection target first (machine-friendly), as requested.
            $connectionUri = $redis->getUri();
            if ($connectionUri !== null) {
                $parsed = parse_url($connectionUri);
                $host = $parsed['host'] ?? 'localhost';
                $port = $parsed['port'] ?? 6379;
                $path = $parsed['path'] ?? '/0';
                $database = preg_match('#/(\d+)#', $path, $matches) === 1 ? (int)$matches[1] : 0;
                $this->console->writeLn(sprintf('redis://%s:%s/%d', $host, $port, $database));
            }

            if ($connection !== '') {
                $this->console->writeLn(
                    $this->console->colorize('Connection: ', Colors::FC_CYAN) . $connection
                );
                $this->console->writeLn();
            }

            // Get info - According to Redis doc, no args should return all fields
            // Pass no argument instead of null for full info to avoid segfault
            try {
                $response = $section !== '' ? $redis->info($section) : $redis->info();
            } catch (Throwable $e) {
                return $this->console->error('Exception in info(): {message}', [
                    'message' => $e->getMessage(),
                ]);
            }

            if ($response === false) {
                $this->console->writeLn('No information available (Redis returned false).');
                return 0;
            }

            if ($response === null) {
                $this->console->writeLn('No information available (Redis returned null).');
                return 0;
            }

            if (is_string($response) && $response === '') {
                $this->console->writeLn('No information available (Redis returned empty string).');
                return 0;
            }

            if (is_string($response)) {
                $info = $this->parseInfoString($response);
            } elseif (is_array($response)) {
                $info = $response;
            } else {
                $this->console->writeLn('Unexpected response type from Redis INFO command: ' . gettype($response));
                return 1;
            }

            if (count($info) === 0) {
                $this->console->writeLn('No information available (empty result).');
                return 0;
            }

            // Display info in formatted output
            if ($section !== '') {
                $this->console->writeLn(
                    $this->console->colorize(
                        sprintf('Redis %s Information:', ucfirst($section)),
                        Colors::FC_GREEN | Colors::AT_BOLD
                    )
                );
                $this->console->writeLn();
            } else {
                $this->console->writeLn(
                    $this->console->colorize('Redis Server Information:', Colors::FC_GREEN | Colors::AT_BOLD)
                );
                $this->console->writeLn();
            }

            $currentSection = '';
            foreach ($info as $key => $value) {

                // Ensure key is a string
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                $keyStr = (string)$key;

                // Handle section headers (keys starting with #)
                if (str_starts_with($keyStr, '#')) {
                    if ($currentSection !== '') {
                        $this->console->writeLn();
                    }
                    $currentSection = $keyStr;
                    $this->console->writeLn(
                        $this->console->colorize($currentSection, Colors::FC_YELLOW | Colors::AT_BOLD)
                    );
                    continue;
                }

                // Display key-value pairs - simplified to avoid potential issues
                $displayKey = str_replace('_', ' ', $keyStr);

                // Safe value formatting
                if (is_bool($value)) {
                    $displayValue = $value ? 'yes' : 'no';
                } elseif (is_int($value) || is_float($value)) {
                    $displayValue = (string)$value;
                } else {
                    $displayValue = (string)$value;
                    // Limit value length to prevent issues
                    if (strlen($displayValue) > 200) {
                        $displayValue = substr($displayValue, 0, 197) . '...';
                    }
                }

                $this->console->writeLn('  ' . $displayKey . ': ' . $displayValue);
            }

            return 0;
        } catch (ConnectionException $e) {
            return $this->console->error('Connection failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            return $this->console->error('Failed to get server information: {message}', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Resolves a Redis client by connection name.
     *
     * @param string $connection Connection name; empty string uses default client
     *
     * @return ClientInterface
     */
    protected function getRedis(string $connection): ClientInterface
    {
        if ($connection === '') {
            return $this->redis;
        }

        return $this->namedLookup->by(ClientInterface::class, $connection);
    }

    /**
     * Formats one Redis metric key for terminal output.
     *
     * @param string $key Raw key
     *
     * @return string
     */
    protected function formatKey(string $key): string
    {
        // Convert snake_case to Title Case for better readability
        return str_replace('_', ' ', $key);
    }

    /**
     * Format one Redis metric value for terminal output.
     *
     * @param mixed $value Raw value
     *
     * @return string
     */
    protected function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? $this->console->colorize('yes', Colors::FC_GREEN) : $this->console->colorize('no', Colors::FC_RED);
        }

        if (is_int($value) || is_float($value)) {
            // Format large numbers with commas
            return number_format((float)$value);
        }

        return (string)$value;
    }

    /**
     * Parse Redis `INFO` plain-text output into key-value entries.
     *
     * @param string $infoString Raw `INFO` output
     *
     * @return array<string, mixed>
     */
    protected function parseInfoString(string $infoString): array
    {
        $info = [];

        // Handle both \r\n and \n line endings
        $lines = preg_split('/\r?\n/', $infoString);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Section header (keys starting with #)
            if (str_starts_with($line, '#')) {
                $info[$line] = '';
                continue;
            }

            // Parse key:value format
            if (str_contains($line, ':')) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);

                    if ($key !== '') {
                        // Try to convert numeric values
                        if ($value !== '' && is_numeric($value)) {
                            $info[$key] = str_contains($value, '.') ? (float)$value : (int)$value;
                        } else {
                            $info[$key] = $value;
                        }
                    }
                }
            }
        }

        return $info;
    }

    /**
     * Check whether a metric key is in the highlighted set.
     *
     * @param string $key Metric key
     *
     * @return bool
     */
    protected function isImportantMetric(string $key): bool
    {
        $importantKeys = [
            'redis_version',
            'used_memory_human',
            'used_memory_peak_human',
            'connected_clients',
            'total_commands_processed',
            'keyspace_hits',
            'keyspace_misses',
            'uptime_in_seconds',
        ];

        return in_array($key, $importantKeys, true);
    }
}
