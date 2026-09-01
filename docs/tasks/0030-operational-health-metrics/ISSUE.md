# Issue #30 — Operational Health and Durable-State Metrics

GitHub Issue: https://github.com/taoking/EventRelay/issues/30

## Baseline

- `main@ad02f97f9137f5bd9a0fecd2df82012eac5dd5ff`
- post-merge CI `33472861373 — PASS`
- Issue #28 closed / PR #29 merged.

Coding Agent 开始前必须重新确认最新绿色 main；失败则 `BLOCKED`。

## Goal

增加第一版 production-grade internal operations surface：

```http
GET /internal/health/live
GET /internal/health/ready
GET /internal/metrics
```

监控建立在 MySQL durable state 上，不使用 PHP 进程内伪 counters。

## Locked Principles

### Durable observability

第一版不实现进程内 `requests_total` / `webhook_success_total` / `retry_total`；只输出可从 durable MySQL state 重算的 gauges/snapshots。

### Readiness semantics

```text
MySQL down    → NOT READY
Redis down    → API READY
RabbitMQ down → API READY
```

Broker outage 不应阻止可以安全 commit 到 Event + Delivery + Outbox 的 ingress。

### Internal security

```text
OPERATIONS_ENDPOINTS_ENABLED=false
OPERATIONS_BEARER_TOKEN=
```

- disabled → 404
- enabled=true + empty token → fail fast
- missing/wrong token → 401
- token constant-time compare
- token 不进入 logs/body/metrics/evidence

## Health

### Liveness

`/internal/health/live`

- no DB/Redis/Rabbit access
- `200 {"status":"alive"}`

### Readiness

`/internal/health/ready`

仅检查 MySQL durable capability。

Success:

```json
{"status":"ready","checks":{"mysql":"up"}}
```

MySQL down：503 + generic body，禁止泄漏 DB exception/host/DSN/user/password。

Redis/Rabbit outage 不得改变 readiness。

## Metrics

Prometheus text exposition `0.0.4`：

```text
Content-Type: text/plain; version=0.0.4; charset=utf-8
```

要求 UTF-8、HELP/TYPE、deterministic order、final newline；MySQL down → HTTP 503，不伪造 0。

### Required families

```text
eventrelay_build_info{transport="redis|rabbitmq"} 1
eventrelay_deliveries{status="<actual bounded enum>"} <count>
eventrelay_outbox_messages{status="<actual bounded enum>"} <count>
eventrelay_outbox_due_pending <count>
eventrelay_outbox_oldest_due_age_seconds <seconds>
eventrelay_delivery_retries_due <count>
eventrelay_delivery_stale_processing_candidates <count>
eventrelay_dead_letters <count>
```

实际 status 必须从仓库当前 enum/contract 读取。所有 bounded status 即使 count=0 也输出。

## Shared business semantics

### Outbox due

`due_pending` 必须与 production Outbox `claim()` 使用同一 due rule。不能复制一套 `available_at` / lease / status 逻辑。

oldest due age：

```text
max(0, now - effective_due_at)
```

无 due rows → 0。

### Retry/Stale

`eventrelay_delivery_retries_due` 与 `eventrelay_delivery_stale_processing_candidates` 必须复用 production finder/specification 的业务条件和 Clock；禁止复制阈值。

### DLQ

```text
eventrelay_dead_letters = count(Delivery.status == failed)
```

不新增 dead-letter lifecycle。

## Cardinality / confidentiality

metric labels 禁止：delivery/event/endpoint ID、event_type、target、failure_message、Idempotency-Key、secret/key、Rabbit payload、claim token、exception。

只允许 bounded low-cardinality labels，例如 `status`、`transport`。

## Architecture

```text
Internal HTTP
→ Application Operational Snapshot / Readiness
→ Infrastructure MySQL read model
```

Prometheus renderer 与数据采集分离。

禁止 Controller direct DB/Eloquent；Domain 不依赖 Prometheus/HTTP；scrape 不 claim Outbox、不 recover stale、不 enqueue/publish、不修改 state。

## Query / performance

每次 scrape 固定常数数量 aggregate queries。

禁止 load all rows、per-status query loop、per-delivery N+1、latest Attempt scan。

优先 `GROUP BY bounded status`、`COUNT(*)`、`MIN(effective due timestamp)`。

Docker MySQL 对核心 query 做 EXPLAIN/EXPLAIN ANALYZE。没有 query-plan 证据则不新增索引。

## Runtime

### R-01 Healthy + auth

```text
/live 200
/ready 200
/metrics 200
```

验证 Content-Type、final newline、HELP/TYPE、stable families、无敏感数据。

### R-02 MySQL outage

物理停止 MySQL：

```text
/live 200
/ready 503
/metrics 503
```

恢复后 200。

### R-03 Rabbit outage

Rabbit transport：stop Rabbit → `/ready=200` → POST event=201 → Outbox durable → due_pending>0；恢复 Rabbit + publisher/consumer → backlog 回落、Delivery 完成。

### R-04 Redis outage

Redis transport同样证明 readiness=200、Event=201、Outbox durable backlog可观察、恢复后回落。

### R-05 Disabled/auth

```text
disabled          → 404
enabled no token  → 401
enabled bad token → 401
enabled good      → expected result
```

## Acceptance Criteria

| ID | Requirement |
|---|---|
| AC-01 | internal endpoints 默认关闭；enabled 强制 token 且不泄漏 |
| AC-02 | liveness 无依赖访问，应用活着即 200 |
| AC-03 | readiness 只依赖 MySQL；MySQL down 503 |
| AC-04 | Redis/Rabbit outage 不影响 API readiness / durable ingress |
| AC-05 | Prometheus 0.0.4 Content-Type、HELP/TYPE、final newline、deterministic |
| AC-06 | Delivery gauges 使用实际 bounded enum，0 status 稳定输出 |
| AC-07 | Outbox status/due/oldest-age 与 production due semantics 一致 |
| AC-08 | Retry due / stale candidate 复用 production conditions |
| AC-09 | dead-letter gauge = failed Delivery，无新 lifecycle |
| AC-10 | 无高基数/敏感 labels |
| AC-11 | 固定常数 aggregate queries，无 materialization/N+1，EXPLAIN 审计 |
| AC-12 | operations reads 纯读，不 claim/recover/enqueue/修改状态 |
| AC-13 | R-01..R-05 真实 Docker outage Runtime PASS |
| AC-14 | Replay/Ingress/DLQ/Outbox/Retry/Stale/Rabbit/Redis/HMAC/SSRF 回归 PASS |
| AC-15 | repository-native traceability + single-push/single-PR-CI |

## Out of Scope

Grafana、Alertmanager、OpenTelemetry tracing、request latency histograms、PHP in-memory counters、metrics event table、external Prometheus server/container、full auth/RBAC、per Endpoint/EventType metrics、logs aggregation、broker direct health probe、DLQ lifecycle、DLQ query scale refactor、exactly-once。

## Delivery

Branch:

```text
feature/operational-health-metrics
```

Directory:

```text
docs/tasks/0030-operational-health-metrics/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

Flow：Explore → Implement → targeted → first functional commit（不 push）→ exact commit validation → Runtime → EVIDENCE/docs commit → single push → Draft PR → one PR CI。

Independent Review 前最终状态 `INCOMPLETE`。不要 Merge。不要 Ready for review。
