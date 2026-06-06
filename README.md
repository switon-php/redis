# Switon Redis Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/redis/ci.yml?branch=main&label=CI)](https://github.com/switon-php/redis/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's Redis client package with a native Redis-style API, pooled-mode safety, URI-based connectors, and connect/call
lifecycle events.

## Highlights

- **Redis-style command calls:** the client keeps Redis's native method style, so Redis users can pick it up quickly.
- **Separate connection modes:** pooled and transient clients stay separate.
- **URI-based routing:** `Connection` supports `redis://`, `rediss://`, `sentinel://`, and `cluster://` targets.
- **Connection visibility:** connect, call, and close activity can be observed.
- **Pool-safe calls:** pooled clients keep a `getTransient()` escape hatch for stateful commands.

## Installation

```bash
composer require switon/redis
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class UserCache
{
    #[Autowired] protected ClientInterface $redis;

    public function save(int $userId, array $data): void
    {
        $this->redis->set("user:{$userId}", json_encode($data), 3600);
    }

    public function replaceSession(string $sessionId, array $data): void
    {
        $redis = $this->redis->getTransient();
        $redis->watch("session:{$sessionId}");
        $redis->multi();
        $redis->set("session:{$sessionId}", json_encode($data));
        $redis->set("session:{$sessionId}:updated_at", (string)time());
        $redis->exec();
    }
}
```

Docs: https://docs.switon.dev/latest/redis

## License

MIT.
