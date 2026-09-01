# EventRelay Issue #30 — CODEX Execution Contract

> 本文件是本轮唯一执行合同。
>
> Issue: #30
> https://github.com/taoking/EventRelay/issues/30
>
> Branch: `feature/operational-health-metrics`
>
> Draft PR 必须 `Closes #30`。
> 不要 Merge。
> 不要 Ready for review。
> Independent Review 前最终状态只能是 `INCOMPLETE`。

## 0. Baseline

开始前确认：

```text
main@ad02f97f9137f5bd9a0fecd2df82012eac5dd5ff
post-merge CI 33472861373 = PASS
Issue #28 = closed
Issue #30 = open
```

如 main 已前进，只允许更新且 CI PASS 的绿色 main；否则 `BLOCKED`。

## 1. Explore First

完整阅读：

```text
AGENTS.md + mandatory docs
Issue #30
plan.md
docs/开发记录.md

docs/tasks/0020... Outbox
docs/tasks/0024... Ingress Idempotency
docs/tasks/0026... DLQ
docs/tasks/0028... Rabbit + REMEDIATION

DeliveryStatus
Retry/Stale finders/specifications
DeliveryOutbox publisher repository + due claim
Outbox schema/status
Clock
DeadLetter query

routes/*
bootstrap/app.php
ServiceProviders
config/*
.env.example
docker-compose.yml
CI
```

先输出中文：

```text
current durable truths
current due/retry/stale semantics
target operations architecture
AC → production → automated → runtime
```

不能凭 Prompt 发明 enum/status。

## 2. Locked Architecture

新增：

```http
GET /internal/health/live
GET /internal/health/ready
GET /internal/metrics
```

不要放到 `/api/*`。

推荐边界：

```text
Internal Controller
→ Application CollectOperationalSnapshot / Readiness contract
→ Infrastructure MySQL repository
```

Prometheus renderer 与 collector 分离。

Domain 不依赖 Prometheus/HTTP/Laravel DB。

## 3. Operations Security

Config：

```text
OPERATIONS_ENDPOINTS_ENABLED=false
OPERATIONS_BEARER_TOKEN=
```

### disabled

internal path → 404。

### enabled

enabled=true 时 token 必须非空；不得自动变成无鉴权开放。

### auth

```http
Authorization: Bearer <token>
```

使用 `hash_equals()` 或等价 constant-time compare。

missing/wrong → 401 generic body。

禁止 log Authorization、token、token hash；evidence 只写 `REDACTED`。

## 4. Liveness

`GET /internal/health/live`

严格：no DB / Redis / Rabbit / business filesystem probe。

```json
{"status":"alive"}
```

200。

focused test 要证明即使 DB probe 会 throw，live 也不调用它。

## 5. Readiness

定义：API 是否能安全 commit Event + Delivery + Outbox durable transaction。

第一版只检查 MySQL。

200：

```json
{"status":"ready","checks":{"mysql":"up"}}
```

MySQL error → 503：

```json
{"status":"not_ready","checks":{"mysql":"down"}}
```

禁止输出 SQLSTATE、host、port、DB、user、DSN、exception class/message。

### Critical regression

物理 Redis/Rabbit outage：`ready = 200`。

不要加入 Broker readiness failure。

## 6. Prometheus Format

使用 Prometheus text exposition 0.0.4。

```text
Content-Type: text/plain; version=0.0.4; charset=utf-8
```

必须：UTF-8、LF、final newline、每 family HELP/TYPE、deterministic order、正确 label escaping。

不要实现不需要的 histogram/summary。

默认倾向小型 renderer，不新增大 metrics dependency；如引 dependency，先证明必要性。

## 7. Metrics Contract

namespace：`eventrelay_`。

### build info

```text
eventrelay_build_info{transport="redis|rabbitmq"} 1
```

TYPE gauge。

禁止 hostname/pod/PID/commit SHA/branch 等动态高基数 metadata。

### deliveries

从实际 `DeliveryStatus` enum 读取全部合法值：

```text
eventrelay_deliveries{status="<value>"} N
```

每个 enum value 即使 0 也输出。

DB 若出现未知 status → fail closed/internal consistency error；不得动态输出未知 label。

### outbox

从实际 Outbox status contract：

```text
eventrelay_outbox_messages{status="<bounded>"} N
eventrelay_outbox_due_pending N
eventrelay_outbox_oldest_due_age_seconds S
```

#### Due semantic

`due_pending` 必须等于：同一 Clock 时刻 production publisher repository 有资格 claim 的 durable intents。

检查：pending、expired publishing lease、available_at null/now/future、lease expiry。

优先共享 predicate/specification；若安全共享 SQL helper 不合适，则用 contract test 对 collector 与 production claim 做真实 DB 等价证明。

oldest age：

```text
effective_due_at = 当前业务规则下实际开始 due 的时间
age = max(0, now - min(effective_due_at))
```

无 due → 0。不要用 updated_at 近似。

## 8. Retry / Stale Metrics

```text
eventrelay_delivery_retries_due
eventrelay_delivery_stale_processing_candidates
```

必须复用 production finder/specification 的条件和 Clock。

不要复制 stale threshold / retry predicate 到 metrics config。

threshold before/at/after 与 production finder做真实 equivalence test。

## 9. DLQ Metric

```text
eventrelay_dead_letters N
```

严格等于 Delivery.status=failed。

不 join latest Attempt；不新增 DLQ lifecycle；不修 DLQ query scale。

## 10. Cardinality Gate

允许 labels 默认仅：

```text
transport
status
```

新增其它 label 前必须证明固定、极小、bounded。

禁止：delivery_id、event_id、endpoint_id、event_type、URL/host、failure/error、Idempotency-Key、secret/key、Rabbit payload、claim token、exception、DB id。

## 11. Collector Query Gate

每次 scrape 固定常数 queries。

建议：

```text
1 grouped delivery status count
1 grouped outbox status count
1 outbox due count/min
1 retry due count
1 stale candidate count
```

Dead letters从 delivery grouped result得到，不重复 query。

禁止 foreach enum→query、all()->count in PHP、load Models、N+1、Attempt latest joins。

## 12. Query Plan

Docker MySQL 对以下执行 EXPLAIN/ANALYZE（可用时）：

- delivery group status
- outbox group status
- due count/min
- retry due
- stale candidates

记录 chosen key、rows estimate、join/temporary/filesort及是否可接受。

只有真实证据需要时才新增 forward migration/index。

## 13. Read-only Invariant

metrics scrape 前后证明：

```text
Delivery unchanged
Attempt unchanged
Outbox status/claim_token/publication_attempts unchanged
Redis queue unchanged
Rabbit queue unchanged
```

collector 不得触发 claim/publish/recover/enqueue。

## 14. Due Equivalence Gate

真实 MySQL + FrozenClock。

vectors：

```text
A pending, available_at=null
B pending, available_at=now
C pending, available_at=future
D publishing lease expired, available_at<=now
E publishing lease active
F publishing lease expired but available_at=future
```

同一 Clock：collector `due_pending` 与 production claim eligible set/count一致。

重点防止 future expired-lease 被错误算 due，或 immediate null row漏算。

## 15. Retry/Stale Equivalence

retry 与 stale 均做 threshold before/at/after，对 collector count 与 production finder结果。

禁止随机 sleep。

## 16. MySQL Failure Behavior

物理 DB down：

```text
live = 200
ready = 503
metrics = 503
```

metrics body generic；禁止 stack trace/SQLSTATE/connection details；不得伪造全 0 metrics。

恢复 DB 后不重启 app 即恢复正常（框架能力允许范围内）。

## 17. Runtime R-01 — Healthy/Auth

enabled + correct token：live/ready/metrics 均 200。

检查 Content-Type exact/compatible、final byte LF、HELP/TYPE、stable order、all enum zero series、无敏感/高基数 labels。

## 18. Runtime R-02 — Physical MySQL Outage

物理 stop MySQL，验证 live 200 / ready 503 / metrics 503；恢复后 ready/metrics 200。

不能 mock 冒充 Runtime。

## 19. Runtime R-03 — RabbitMQ Outage

`DELIVERY_TRANSPORT=rabbitmq`：

```text
stop Rabbit
ready remains 200
POST Event → 201
Event/Delivery/Outbox committed
publisher known failure/recoverable
metrics due_pending > 0
```

恢复 Rabbit → publish → consume → Delivery success → backlog回落。

## 20. Runtime R-04 — Redis Outage

`DELIVERY_TRANSPORT=redis`：物理停止 Redis Queue，仍 ready=200、Event=201、Outbox durable、metrics backlog可见；恢复后完成。

以当前仓库真实 Redis cache依赖为准，不要求整个应用完全不使用 Redis。

## 21. Runtime R-05 — Security

```text
enabled=false → 404
enabled=true no auth → 401
wrong bearer → 401
correct bearer → 200
enabled + empty configured token → fail fast
```

验证 token 不在 response/log/metrics/evidence。

## 22. Automated Gate

```bash
composer quality
docker compose exec -T app composer quality
```

继续覆盖 Rabbit lifecycle、Outbox worker、Redis/Rabbit transport、Outbox due、Retry/Stale、Replay、Ingress Idempotency、DLQ、HMAC、SSRF。

新增 operations tests 在 Docker 不得条件 skip。

## 23. Acceptance Matrix

| ID | Requirement |
|---|---|
| AC-01 | internal endpoints 默认关闭；enabled token 且不泄漏 |
| AC-02 | live 无依赖，应用活着即 200 |
| AC-03 | ready 只依赖 MySQL，DB down 503 |
| AC-04 | Broker outage不影响 API readiness/durable ingress |
| AC-05 | Prometheus 0.0.4格式/Content-Type/HELP/TYPE/final newline/deterministic |
| AC-06 | Delivery bounded enum gauges含0值 |
| AC-07 | Outbox due/age与production claim等价 |
| AC-08 | Retry/Stale复用production conditions |
| AC-09 | dead-letter=failed Delivery |
| AC-10 | 无高基数/敏感 labels |
| AC-11 | 固定常数 aggregates + EXPLAIN，无 materialization/N+1 |
| AC-12 | operations完全只读 |
| AC-13 | R-01..R-05真实Docker outage PASS |
| AC-14 | 全历史可靠性/安全回归 PASS |
| AC-15 | repository-native + single push/CI |

最终逐项 PASS/FAIL。

## 24. Traceability

```text
docs/tasks/0030-operational-health-metrics/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

首个 functional commit 同时包含 production、tests、ISSUE/CODEX、`docs/开发记录.md` concise index、必要 config/.env docs。

推荐：

```text
feat: add operational health and metrics (#30)
```

## 25. Evidence

记录：

```text
validated_implementation_head: <sha>
```

至少包含 AC-01..15、targeted、composer quality、Docker targeted/full、EXPLAIN、due equivalence、retry/stale equivalence、R01..R05、security leakage check。

NOT RUN：GitHub PR CI、Independent Review。

## 26. Single-Push Flow

```text
Explore
→ Plan
→ Implement
→ targeted
→ first functional commit
→ DO NOT PUSH
→ exact commit full validation
→ R-01..R-05
→ self review
→ EVIDENCE/plan/dev-record docs commit
→ first/single push
→ Draft PR
→ PR CI
```

CI PASS 后只更新 PR Body，不 commit CI run number。

## 27. PR Body

至少：Baseline、security、readiness semantics、metrics families、due/retry/stale equivalence、query plan、cardinality、R01..R05、AC01..15、validated head、current head、latest CI、Independent Review=NOT RUN。

## 28. Scope Guard

禁止：Grafana、Alertmanager、OpenTelemetry、request histograms、in-memory counters、metrics event table、Prometheus container、full RBAC、per-endpoint/event metrics、broker direct ping metrics、DLQ lifecycle/scale、unrelated Rabbit/Redis changes。

## 29. Self Review

```text
Broker down 时 ready 是否仍 200？
MySQL down 时 live 是否仍 200？
Metrics DB down 是否503而非假0？
due metrics与publisher claim真一致？
stale/retry是否production thresholds？
有没有 endpoint/event UUID/event_type label？
有没有 token/secret/error信息？
scrape有没有修改业务状态？
query数量是否固定？
all enum zero series稳定？
Content-Type/final newline正确？
```

## 30. Final Status / Report

即使全部 PASS：

```text
Independent Review = NOT RUN
Final status = INCOMPLETE
```

不要 Merge。不要 Ready。

最终中文报告包含：Baseline、Branch、Commits、Draft PR、Current HEAD、Latest CI、Architecture、AC01..15、Automated、R01..05、Traceability/validated head、CI optimization、Risks/NOT RUN、Independent Review=NOT RUN、Final=INCOMPLETE。
