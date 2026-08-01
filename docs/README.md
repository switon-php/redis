# Redis Package {#redis-package}

`switon/redis` gives Switon apps a pooled Redis client with an explicit transient path for connection-bound work. Choose it when most calls are short and stateless; transactions, scans, and blocking commands opt into a dedicated connection. The package does not turn Redis into a cache abstraction or pick a default named client when the app has multiple Redis targets.

**Key features:**

- **Pooled mode:** ordinary Redis calls borrow a connection and return it automatically.
- **Transient escape hatch:** `getTransient()` keeps one dedicated connection for transactions, scans, and blocking flows.
- **URI-driven connectors:** `Switon\Redis\Connection` accepts `redis://`, `rediss://`, `sentinel://`, and `cluster://`.
- **Lifecycle events:** `RedisConnecting`, `RedisConnected`, `RedisCalling`, `RedisCalled`, and `RedisClose` are available.
- **CLI diagnostics:** `redis:ping`, `redis:info`, and `redis:server` for connectivity and server inspection.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-redis`, `switon/core`, `switon/di`, `switon/event`, `switon/pool`, `psr/event-dispatcher`

```bash
composer require switon/redis
```

---

## Quick Start {#quick-start}

Inject `Switon\Redis\ClientInterface`, then use ordinary Redis commands.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

final class SessionStore
{
    #[Autowired] protected ClientInterface $redis;

    public function save(int $userId, array $payload): void
    {
        $this->redis->set("user:{$userId}", json_encode($payload), 3600);
    }
}
```

Use `getTransient()` when one workflow needs connection state.

```php
use Switon\Redis\Client;

/** @var Client $redis */
$redis = $this->redis->getTransient();
$redis->watch('wallet:1');
$redis->multi();
$redis->set('wallet:1:balance', '900');
$redis->set('wallet:1:updated_at', (string) time());
$redis->exec();
```

---

## Core Concepts {#core-concepts}

### Pooled Mode {#pooled-mode}

Ordinary Redis commands borrow one connection from the pool and return it after each call. The pooled client rejects connection-bound commands (`watch`, `unwatch`, `multi`, `pipeline`, `select`, `scan`, `sScan`, `hScan`, `zScan`) with `CallInPoolException`, and also rejects object-returning commands so workflows that need state opt into `getTransient()`.

### Transient Client {#transient-client}

`getTransient()` returns a clone bound to one dedicated pooled connection. Use it for transactions, cursor scans, and blocking commands such as `subscribe()`, `psubscribe()`, or `monitor()`.

### URI Examples {#connection-uri}

Use one URI per Redis target:

```text
redis://127.0.0.1:6379/0?timeout=2&heartbeat=60
rediss://127.0.0.1:6380/0?auth=redis-secret&timeout=2
sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?auth=redis-secret&sentinel_auth=sentinel-secret&db=3
cluster://10.0.0.1:7000,10.0.0.2:7001?auth=redis-secret&timeout=2&persistent=1
```

Common query options: `auth`, `sentinel_auth`, `timeout`, `db`, `persistent`, `heartbeat`. The pooled client also reads `pool_timeout` and `pool_size`. `read_timeout` applies to single-node, TLS, and Sentinel master connections; Cluster ignores it. `cluster://` also ignores `db=`.

---

## Keep in Mind {#keep-in-mind}

- Stateful commands (`multi`, `watch`, `pipeline`, `select`, `scan` variants) and blocking commands (`subscribe`, `psubscribe`, `monitor`) only run on a transient client.
- `heartbeat=0` does not disable the check; the existing connection is still verified on later calls.
- `persistent=1` only takes effect when the runtime has coroutine support enabled.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#redis-faq)
- **Connection model and trade-offs:** [Design Rationale](design.md#design-rationale)
- **Pooling mechanics:** [Pool Package](https://github.com/switon-php/pool/blob/main/docs/README.md)
- **Redis as queue transport:** [Queue Package](https://github.com/switon-php/queue/blob/main/docs/README.md)
