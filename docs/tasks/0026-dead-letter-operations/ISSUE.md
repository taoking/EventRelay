# Issue #26 — Operational Dead-Letter Queue

GitHub Issue: https://github.com/taoking/EventRelay/issues/26

## 目标

为 EventRelay 增加第一版 **DLQ / Operational Recovery** 能力，但不新增第二套 `dead_lettered` 状态机。

核心定义：

> Dead Letter Queue 是 `Delivery.status = failed` 的只读运维投影视图；失败历史继续由 Delivery / DeliveryAttempt 保存，恢复继续复用现有 Replay API。

当前绿色基线：

- `main@09b3c27c56663eafb382fb00f1c4de0c8027c18f`
- post-merge CI `33373781905 — PASS`
- Issue #24 已关闭，PR #25 已 squash merge。

Coding Agent 开始前必须重新确认最新绿色 main；失败则 `BLOCKED`。

## 不新增第二套状态机

禁止新增：

```text
DeliveryStatus::DeadLettered
DLQ lifecycle state machine
独立 retry counter
独立 replay state
```

继续使用：

```text
Delivery.status = failed
DeliveryAttempt failure metadata
POST /api/deliveries/{id}/replay
Transactional Outbox
```

一个 failed Replay Delivery 自身也属于 dead-letter 视图。

## API

新增：

```http
GET /api/dead-letters
```

只返回当前 `Delivery.status = failed` 的记录。

不新增重复的 detail / replay endpoint：

```text
GET /api/deliveries/{id}
GET /api/deliveries/{id}/attempts
POST /api/deliveries/{id}/replay
```

## Dead-letter item

至少返回：

```text
delivery_id
event_id
endpoint_id
replay_of_delivery_id
event_type
attempt_count
last_attempt_number
failure_type
response_status
failed_at
created_at
```

`failure_type` / `response_status` / `failed_at` 来自最高 attempt number 的最终 Attempt。

列表不返回 `failure_message`，也不暴露 target URL、签名密钥、HMAC、Outbox 内部字段、DB internal ID。

如果 `failed Delivery` 没有可解析的 latest terminal Attempt，不得伪造 `unknown`，必须 fail closed 并由测试锁定。

## Filters

支持精确过滤：

```text
endpoint_id=<UUID>
event_type=<exact EventType>
failure_type=<existing DeliveryFailureType>
response_status=<100..599>
```

组合条件为 AND。

非法 filter：

```text
422
code = invalid_dead_letter_filter
```

## Pagination / ordering

禁止无界 `all()`，使用 keyset/cursor pagination：

```text
limit default = 50
limit range = 1..100
```

稳定排序：

```text
failed_at DESC
delivery public UUID DESC
```

响应：

```json
{
  "data": [],
  "meta": {
    "next_cursor": "opaque-or-null"
  }
}
```

cursor 必须 opaque、可验证、与 filter 绑定。坏 cursor：

```text
422
code = invalid_dead_letter_cursor
```

不得用 offset pagination 冒充 concurrency-stable。

## Query / architecture

边界：

```text
HTTP
→ Application read use case / contract
→ Infrastructure query
```

Controller 不得直接 DB/Eloquent；Domain 不新增死信状态。

Latest Attempt 必须与 failed Delivery 一一关联，不能因为 join 产生重复 Delivery rows，也不能 N+1。

必须覆盖：

- HTTP 400 attempt #1 failed；
- HTTP 500 最终 attempt #3 failed；
- timeout/network failure；
- stale-processing/abandoned 历史后最终 failed；
- failed Replay Delivery；
- 同 failed_at UUID tie-break；
- soft-deleted Endpoint 历史仍可查询；
- Endpoint/config 后续变化不改变历史 metadata。

## Recovery integration

DLQ 只负责发现/筛选，不执行 replay：

```text
GET /api/dead-letters
→ GET /api/deliveries/{id}/attempts
→ 修复 Endpoint/config
→ POST /api/deliveries/{id}/replay
→ new Delivery
```

若 D1 failed、Replay D2 succeeded：

```text
D1 仍在 DLQ
D2 不在 DLQ
D2.replay_of = D1
```

若 D2 也 failed，则 D1/D2 都在 DLQ。

## Security

列表不得新增泄漏：

```text
failure_message
plaintext/ciphertext signing secret
HMAC signature
raw ingress/replay Idempotency-Key
Outbox claim token
internal DB IDs
```

HMAC/SSRF/DNS pinning/TLS/proxy/redirect 全量回归必须 PASS。

## Index / MySQL

审计真实 query plan 和现有 indexes。

必要时只新增有证据价值的 forward migration/index，不修改历史 migration，不为所有 filter 组合过量建索引。

必须在 Docker MySQL 记录 EXPLAIN/EXPLAIN ANALYZE 证据，并证明：

```text
无 N+1
无 duplicate rows
查询有界
```

## Concurrency Gate

真实 MySQL / 独立连接 / barrier：

1. page1 后插入更“新”的 failed Delivery，再取 page2：不得重复 page1，也不得漏 cursor 边界前已有数据。
2. 多条记录 failed_at 完全相同，UUID tie-break 稳定。
3. Worker/Retry 正在提交最终 failed 时查询：只能看到提交前“不在 DLQ”或提交后“failed + 完整 latest Attempt”，不得 torn view。

禁止随机 sleep 代替 barrier。

## Runtime

### R-01 Mixed failure triage

Docker 生成至少：

```text
HTTP 400
HTTP 500 exhausting retries
network_error/timeout
```

不得为了 Runtime 削弱 production SSRF/TLS。

验证 DLQ 只返回 failed，metadata/filter/order/cursor 正确。

### R-02 Repair + Replay

从 DLQ 定位 failed D1 → 修复 Endpoint → existing Replay API 创建 D2 → Outbox/worker 成功。

最终：

```text
D1 仍在 DLQ
D2 succeeded 不在 DLQ
D2.replay_of = D1
```

## Acceptance Criteria

| ID | 要求 |
|---|---|
| AC-01 | DLQ 仅是 failed Delivery 的只读运维视图，无新状态机 |
| AC-02 | latest Attempt metadata 正确且每个 Delivery 一条 |
| AC-03 | filter 组合正确，非法 filter 422 |
| AC-04 | keyset cursor、limit 1..100、稳定排序正确 |
| AC-05 | 并发插入/同时间戳跨页无重复/边界漏项，坏 cursor 422 |
| AC-06 | Endpoint soft-delete/config 变化不影响历史 DLQ |
| AC-07 | failed Replay 进入 DLQ；successful Replay 不移除 source failed |
| AC-08 | 不泄漏 secret/idempotency/outbox internal/failure_message |
| AC-09 | HTTP→Application→Infrastructure，Domain 无死信状态 |
| AC-10 | MySQL query/index 证据，无明显 N+1/duplicate |
| AC-11 | R-01 / R-02 运维闭环 PASS |
| AC-12 | Replay/Outbox/Retry/Stale/HMAC/SSRF/Ingress Idempotency 回归 PASS |
| AC-13 | repository-native traceability + single-push/single-PR-CI 落地 |

## Out of Scope

不实现：

```text
dead_lettered status
独立 DLQ table / broker queue
auto/bulk replay
ack/resolve/archive
retention cleanup
alerts/Prometheus
Dashboard/UI
auth/RBAC/multi-tenancy
RabbitMQ/Kafka
exactly-once
Runtime Harness refactor
```

## Delivery / Review

分支：

```text
feature/dead-letter-operations
```

Draft PR：

```text
Closes #26
```

任务目录：

```text
docs/tasks/0026-dead-letter-operations/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

首个功能 commit 同时包含 code + tests + ISSUE/CODEX + `docs/开发记录.md` 索引。

本地完整验证 → EVIDENCE/plan commit → 单次 push → Draft PR → PR CI；最终 CI 只写 PR Body，不回写仓库触发 docs-only CI。

Independent Review 前最终状态只能是 `INCOMPLETE`。

不要自行 Merge，不要自行 Ready for review。
