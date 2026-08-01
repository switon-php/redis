# Redis FAQ {#redis-faq}

Short answers for pooled mode, transient clients, URI routing, and diagnostics.

> **Prerequisites:** [Redis Package](README.md)

## Getting Started {#getting-started}

### When should I use `getTransient()`? {#use-gettransient}

Use it when one workflow needs connection state, such as transactions, scans, or blocking Redis commands.

### Can I keep using ordinary Redis commands on the pooled client? {#pooled-commands}

Yes. `get()`, `set()`, `del()`, and similar commands are the intended pooled path.

### Why do `multi()` or `scan()` fail on the pooled client? {#multi-scan-fail}

They need a stable connection. Switch that workflow to `getTransient()`.

## Connection {#connection}

### Which URI scheme should I use? {#which-uri-scheme}

Use `redis://` for single-node Redis, `rediss://` for TLS, `sentinel://` for Sentinel, and `cluster://` for Redis Cluster.

`master=` in the query string overrides the Sentinel master name in the path.

### What does `heartbeat=0` mean? {#heartbeat-zero}

It does not disable the check. The existing connection is checked on later calls.

### Does `cluster://` honor `db=`? {#cluster-db}

No. Cluster connections ignore database selection.

## Diagnostics {#quality-and-diagnostics}

### What commands does the package ship? {#commands}

`redis:ping`, `redis:info`, and `redis:server`.

Pass a named connection when you want a non-default Redis client; empty means the default injected client.
