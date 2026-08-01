# Design Rationale {#design-rationale}
`switon/redis` standardizes pooled Redis access while keeping connection-bound workflows explicit.

## See also {#see-also}

- [Redis Package](README.md) — pooled client, transient client, URI routing, and diagnostics
- [Redis FAQ](faq.md#redis-faq) — short answers for common Redis usage questions

---

## Why This Package Exists {#purpose}

Redis is used for several different jobs in one app: cache, sessions, queues, counters, and coordination.
Those jobs do not all have the same connection-state needs.

This package gives the framework one Redis model for:

- pooled ordinary commands
- explicit connection affinity when state matters
- URI-driven routing for single-node, TLS, Sentinel, and Cluster
- lifecycle events for observability

## Why The Pooled Path Comes First {#pooled-path-comes}

Most Redis calls are short and stateless.

Pooled mode keeps that path simple and efficient. The trade-off is that stateful commands must opt into
`getTransient()`.

## Why The Guard Exists {#guard}

Commands that depend on connection continuity fail fast on the pooled client.

That is stricter than passing them through and hoping for the best, but it keeps hidden connection state out of the
common path.

## What The Package Leaves To The Application {#outside}

It does not hide blocking command semantics.

It does not turn Redis into a cache abstraction.

It does not guess which named client should be used when the app has multiple Redis targets.
