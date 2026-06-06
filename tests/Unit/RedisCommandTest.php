<?php

declare(strict_types=1);

namespace Switon\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Core\ConsoleInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Redis\ClientInterface;
use Switon\Redis\Command\RedisCommand;
use Switon\Redis\Exception\ConnectionException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use stdClass;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class RedisCommandTest extends TestCase
{
    protected RedisCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected NamedLookupInterface&MockObject $namedLookup;
    protected FakeRedisClient $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new RedisCommandProbe();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->namedLookup = $this->createMock(NamedLookupInterface::class);
        $this->redis = new FakeRedisClient();

        $this->command->setConsole($this->console);
        $this->command->setNamedLookup($this->namedLookup);
        $this->command->setRedis($this->redis);
    }

    public function testPingActionWritesOkWhenDefaultConnectionRespondsPong(): void
    {
        $this->redis->pingResponse = 'PONG';
        $this->console->expects($this->once())
            ->method('writeLn')
            ->with('OK');

        $code = $this->command->pingAction();

        $this->assertSame(0, $code);
    }

    public function testPingActionReturnsErrorWhenNamedConnectionReturnsUnexpectedResponse(): void
    {
        $named = new FakeRedisClient();
        $named->pingResponse = 'NOPE';

        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'cache')
            ->willReturn($named);

        $this->console->expects($this->once())
            ->method('error')
            ->with('Ping failed: unexpected response', [], 1)
            ->willReturn(1);

        $code = $this->command->pingAction('cache');

        $this->assertSame(1, $code);
    }

    public function testPingActionReturnsErrorWhenDefaultConnectionThrowsConnectionException(): void
    {
        $this->redis->pingThrowable = new RuntimeException('Redis connection failed for test');

        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'Ping failed: {message}',
                ['message' => 'Redis connection failed for test'],
                1
            )
            ->willReturn(1);

        $code = $this->command->pingAction();

        $this->assertSame(1, $code);
    }

    public function testInfoActionOutputsJsonWithParsedUriAndMetrics(): void
    {
        $this->redis->pingResponse = true;
        $this->redis->uri = 'redis://user:pass@127.0.0.1:6380/2';
        $this->redis->infoResponse = ['redis_version' => '7.2.5'];
        $this->redis->dbsizeResponse = 33;

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->infoAction('', true, true);

        $this->assertSame(0, $code);
        $this->assertSame('default', $captured['connection']);
        $this->assertSame('Connected', $captured['status']);
        $this->assertSame('127.0.0.1', $captured['host']);
        $this->assertSame(6380, $captured['port']);
        $this->assertSame(2, $captured['database']);
        $this->assertSame('7.2.5', $captured['version']);
        $this->assertSame(33, $captured['keys']);
        $this->assertSame('redis://user:pass@127.0.0.1:6380/2', $captured['uri']);
        $this->assertArrayHasKey('latency_ms', $captured);
    }

    public function testInfoActionOutputsJsonErrorWhenNamedConnectionResolutionFails(): void
    {
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'secondary')
            ->willThrowException(new RuntimeException('connection missing'));

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->infoAction('secondary', false, true);

        $this->assertSame(1, $code);
        $this->assertSame('connection missing', $captured['error']);
        $this->assertSame('secondary', $captured['connection']);
    }

    public function testInfoActionReturnsConsoleErrorWhenJsonIsFalseAndConnectionLookupFails(): void
    {
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'secondary')
            ->willThrowException(ConnectionException::of('lookup failed'));

        $this->console->expects($this->once())
            ->method('error')
            ->with('Info failed: {message}', ['message' => 'lookup failed'], 1)
            ->willReturn(1);

        $code = $this->command->infoAction('secondary', false, false);

        $this->assertSame(1, $code);
    }

    public function testInfoActionUsesConsoleTableWhenJsonIsFalse(): void
    {
        $this->redis->pingResponse = 'PONG';
        $this->redis->uri = 'redis://127.0.0.1:6379/1';
        $this->redis->infoResponse = ['redis_version' => '7.0.0'];
        $this->redis->dbsizeResponse = 10;

        $this->console->expects($this->once())
            ->method('table')
            ->with(
                ['connection', 'status', 'latency', 'host', 'port', 'database', 'version', 'keys'],
                $this->callback(static function (array $rows): bool {
                    return isset($rows[0]) && $rows[0][0] === 'default' && $rows[0][1] === 'Connected';
                }),
                8
            );

        $code = $this->command->infoAction('', false, false);

        $this->assertSame(0, $code);
    }

    public function testServerActionWritesNoInformationWhenRedisInfoReturnsFalse(): void
    {
        $this->redis->uri = 'redis://127.0.0.1:6379/0';
        $this->redis->infoResponse = false;

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('', '');

        $this->assertSame(0, $code);
        $this->assertSame('redis://127.0.0.1:6379/0', $lines[0] ?? null);
        $this->assertContains('No information available (Redis returned false).', $lines);
    }

    public function testServerActionWritesNoInformationWhenRedisInfoReturnsNull(): void
    {
        $this->redis->uri = 'redis://127.0.0.1:6379/0';
        $this->redis->infoResponse = null;

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('', '');

        $this->assertSame(0, $code);
        $this->assertSame('redis://127.0.0.1:6379/0', $lines[0] ?? null);
        $this->assertContains('No information available (Redis returned null).', $lines);
    }

    public function testServerActionWritesNoInformationWhenRedisInfoReturnsEmptyString(): void
    {
        $this->redis->uri = 'redis://127.0.0.1:6379/0';
        $this->redis->infoResponse = '';

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('', '');

        $this->assertSame(0, $code);
        $this->assertSame('redis://127.0.0.1:6379/0', $lines[0] ?? null);
        $this->assertContains('No information available (Redis returned empty string).', $lines);
    }

    public function testServerActionReturnsErrorWhenRedisInfoReturnsUnexpectedType(): void
    {
        $this->redis->uri = 'redis://127.0.0.1:6379/0';
        $this->redis->infoResponse = new stdClass();

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('', '');

        $this->assertSame(1, $code);
        $this->assertSame('redis://127.0.0.1:6379/0', $lines[0] ?? null);
        $this->assertTrue(
            (bool)array_filter($lines, static fn (string $l): bool => str_contains($l, 'Unexpected response type')),
            'should print unexpected type line'
        );
    }

    public function testServerActionReturnsErrorWhenInfoThrowsThrowable(): void
    {
        $this->redis->infoThrowable = new RuntimeException('boom');

        $this->console->expects($this->once())
            ->method('error')
            ->with('Exception in info(): {message}', ['message' => 'boom'])
            ->willReturn(1);

        $code = $this->command->serverAction('', '');

        $this->assertSame(1, $code);
    }

    public function testServerActionParsesPlainTextInfoStringAndPrintsSectionHeader(): void
    {
        $this->redis->uri = 'redis://127.0.0.1:6379/0';
        $this->redis->infoResponse = "# Server\r\nredis_version:7.2.0\r\nuptime_in_seconds:12\r\n";

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('', '');

        $this->assertSame(0, $code);
        $this->assertTrue(in_array('# Server', $lines, true), 'should print section header');
        $this->assertTrue(
            (bool)array_filter($lines, static fn (string $l): bool => str_contains($l, 'redis version: 7.2.0')),
            'should print parsed key/value line'
        );
    }

    public function testServerActionPrintsNamedConnectionAndSectionHeader(): void
    {
        $named = new FakeRedisClient();
        $named->uri = 'redis://127.0.0.1:6381/3';
        $named->infoResponse = ['# Clients' => '', 'connected_clients' => 12];

        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'cache')
            ->willReturn($named);

        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line = '') use (&$lines): void {
                $lines[] = $line;
            });
        $this->console->method('colorize')->willReturnCallback(static fn (string $text) => $text);

        $code = $this->command->serverAction('cache', 'clients');

        $this->assertSame(0, $code);
        $this->assertContains('redis://127.0.0.1:6381/3', $lines);
        $this->assertContains('Connection: cache', $lines);
        $this->assertContains('Redis Clients Information:', $lines);
    }

    public function testFormatKeyReplacesUnderscoresWithSpaces(): void
    {
        $formatKey = new ReflectionMethod(RedisCommand::class, 'formatKey');
        $formatKey->setAccessible(true);

        $this->assertSame('connected clients', $formatKey->invoke($this->command, 'connected_clients'));
    }

    public function testFormatValueFormatsBoolAndNumbers(): void
    {
        $this->console->method('colorize')->willReturnCallback(static function (string $text, int $options = 0): string {
            return $text;
        });

        $formatValue = new ReflectionMethod(RedisCommand::class, 'formatValue');
        $formatValue->setAccessible(true);

        $this->assertSame('yes', $formatValue->invoke($this->command, true));
        $this->assertSame('no', $formatValue->invoke($this->command, false));
        $this->assertSame('1,234', $formatValue->invoke($this->command, 1234));
        $this->assertSame('1,235', $formatValue->invoke($this->command, 1234.56));
        $this->assertSame('hello', $formatValue->invoke($this->command, 'hello'));
    }

    public function testIsImportantMetricMatchesKnownKeys(): void
    {
        $m = new ReflectionMethod(RedisCommand::class, 'isImportantMetric');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($this->command, 'redis_version'));
        $this->assertFalse($m->invoke($this->command, 'random_key'));
    }

}

class RedisCommandProbe extends RedisCommand
{
    public function setConsole(ConsoleInterface $console): void
    {
        $this->console = $console;
    }

    public function setNamedLookup(NamedLookupInterface $namedLookup): void
    {
        $this->namedLookup = $namedLookup;
    }

    public function setRedis(ClientInterface $redis): void
    {
        $this->redis = $redis;
    }
}

class FakeRedisClient implements ClientInterface
{
    public mixed $pingResponse = 'PONG';
    public ?Throwable $pingThrowable = null;

    public mixed $infoResponse = ['redis_version' => 'unknown'];

    public int $dbsizeResponse = 0;

    public ?string $uri = null;

    public ?Throwable $infoThrowable = null;

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function ping(): mixed
    {
        if ($this->pingThrowable instanceof Throwable) {
            throw $this->pingThrowable;
        }
        return $this->pingResponse;
    }

    public function info(?string $section = null): mixed
    {
        if ($this->infoThrowable instanceof Throwable) {
            throw $this->infoThrowable;
        }
        return $this->infoResponse;
    }

    public function dbsize(): int
    {
        return $this->dbsizeResponse;
    }

    public function getTransient(): static
    {
        return $this;
    }
}
