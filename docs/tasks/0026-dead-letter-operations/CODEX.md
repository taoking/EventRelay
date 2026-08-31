# EventRelay Issue #26 — CODEX Execution Contract

> **唯一执行合同**
>
> 严格读取并执行本文件，不要依赖聊天历史补全要求。
>
> GitHub Issue：`#26`
> https://github.com/taoking/EventRelay/issues/26
>
> 目标分支：`feature/dead-letter-operations`
>
> Draft PR 必须 `Closes #26`。
> **不要 Merge，不要 Ready for review。**
>
> Independent Review 前最终状态只能是 `INCOMPLETE`。

## 0. Baseline Gate

开始前重新确认：

```text
main@09b3c27c56663eafb382fb00f1c4de0c8027c18f
post-merge CI 33373781905 = PASS
Issue #24 = closed
Issue #26 = open
```

如果 main 已前进，只允许使用更新且 CI PASS 的绿色 main。

任何 baseline 失败：

```text
BLOCKED
```

## 1. Goal

实现：

```http
GET /api/dead-letters
```

锁定定义：

```text
Dead Letter = Delivery.status == failed 的只读运维投影视图
```

绝不新增：

```text
DeliveryStatus::DeadLettered
独立 DLQ lifecycle
独立 retry/replay 状态机
独立 broker DLQ queue
```

恢复继续使用：

```http
POST /api/deliveries/{id}/replay
```

目标流程：

```text
发现失败
→ 筛选定位
→ 查看 Attempts
→ 修复配置
→ Replay
```

## 2. Read First

编码前完整阅读：

```text
AGENTS.md
AGENTS.md mandatory docs
Issue #26
plan.md
docs/开发记录.md

docs/tasks/0020... Outbox
docs/tasks/0022... Replay
docs/tasks/0024... Ingress Idempotency

Delivery Domain / DeliveryStatus
DeliveryAttempt Domain
DeliveryFailureType
DeliveryAttemptStatus
DeliveryData / DeliveryAttemptData

DeliveryRepository
EloquentDeliveryRepository
ListDeliveries
ListDeliveryAttempts
DeliveryController
DeliveryResource
DeliveryAttemptResource
routes/api.php

ProcessPendingDelivery
RetryPolicy
RecoverStaleDelivery
ReplayFailedDelivery
Transactional Outbox

Delivery / Attempt / Event / Endpoint migrations
现有 indexes
相关 MySQL concurrency tests
```

当前 `DeliveryFailureType` 只允许：

```text
http_status
timeout
network_error
unsafe_target
stale_processing
```

当前 `DeliveryAttemptStatus`：

```text
started
succeeded
failed
abandoned
```

不要发明新 enum 值。

先输出简短中文架构地图和：

```text
AC → production change → automated evidence → runtime evidence
```

如合同与仓库事实实质冲突：`BLOCKED`；否则直接继续。

## 3. Acceptance Matrix

| ID | 必须证明 | 最低证据 |
|---|---|---|
| AC-01 | DLQ 只是 failed Delivery read model，没有新状态机 | architecture/diff |
| AC-02 | dead-letter item 与最高 attempt number 一一匹配，无 duplicate | Feature/MySQL |
| AC-03 | endpoint/event_type/failure_type/response_status filter + invalid 422 | API |
| AC-04 | limit 1..100、keyset cursor、failed_at DESC + UUID DESC | API/MySQL |
| AC-05 | 并发插入/同 failed_at 下跨页无重复/边界漏项；坏 cursor 422 | barrier concurrency |
| AC-06 | Endpoint soft-delete/config 变化不影响历史 DLQ | Feature |
| AC-07 | failed Replay 进入 DLQ；successful Replay 不移除 source failed | Feature + R-02 |
| AC-08 | 不泄漏 secret/idempotency/outbox internal/failure_message | Security |
| AC-09 | HTTP→Application→Infrastructure；Domain 无死信概念 | Architecture |
| AC-10 | SQL 无 N+1/duplicate，MySQL EXPLAIN/索引策略有证据 | Integration |
| AC-11 | R-01 mixed failures + R-02 repair/replay flow PASS | Docker Runtime |
| AC-12 | Retry/Stale/Replay/Outbox/HMAC/SSRF/Ingress Idempotency 不回归 | Full quality |
| AC-13 | ISSUE/CODEX/EVIDENCE + single push/single PR CI | Git/PR evidence |

最终报告必须逐项给出 `AC-01..AC-13 PASS/FAIL`。

## 4. Read Model Boundary

新增专用 Application read-side 类型，推荐：

```text
ListDeadLetters
DeadLetterQueryRepository
DeadLetterFilter
DeadLetterItem
DeadLetterPage
DeadLetterCursorCodec
```

具体命名可调整。

边界：

```text
HTTP Controller
→ Application query/use case
→ Infrastructure query implementation
```

禁止：

```text
Controller → DB/Eloquent
Domain → Laravel/Eloquent
```

不要给 Delivery Domain 新增 DeadLetter 状态。

## 5. API / Response Contract

新增：

```http
GET /api/dead-letters
```

响应至少：

```json
{
  "data": [
    {
      "delivery_id": "uuid",
      "event_id": "uuid",
      "endpoint_id": "uuid",
      "replay_of_delivery_id": null,
      "event_type": "order.paid",
      "attempt_count": 3,
      "last_attempt_number": 3,
      "failure_type": "http_status",
      "response_status": 500,
      "failed_at": "2026-08-31T...+00:00",
      "created_at": "2026-08-31T...+00:00"
    }
  ],
  "meta": {
    "next_cursor": "opaque-or-null"
  }
}
```

明确禁止返回：

```text
failure_message
target_url
signing_secret_id
secret/ciphertext
HMAC signature
raw ingress/replay Idempotency-Key
Outbox id/claim token
internal numeric DB id
```

需要 failure text 时继续使用：

```http
GET /api/deliveries/{id}/attempts
```

## 6. Latest Attempt Invariant

Dead-letter item 的：

```text
last_attempt_number
failure_type
response_status
failed_at
```

来自该 Delivery **最高 attempt_number** 的 Attempt。

对于 `Delivery.status=failed`，latest Attempt 必须满足：

```text
status in [failed, abandoned]
finished_at != null
failure_type != null
```

如果出现：

```text
failed Delivery 无 Attempt
latest Attempt = started/succeeded
finished_at/failure_type 缺失
```

属于内部不变量破坏。

禁止静默：

```text
failure_type = unknown
failed_at = delivery.updated_at
```

应 fail closed / surface internal consistency error，并增加 regression。

不要改变生产状态机来掩盖坏数据。

## 7. Query Shape / No N+1

Infrastructure 必须用单个有界 query，或固定常数数量 query 完成一页。

禁止：

```text
查 50 Delivery
→ 每条查 Event
→ 每条查 Attempt
```

推荐 SQL 形状：

```text
deliveries d
JOIN events e
JOIN endpoints ep
JOIN latest-attempt derived/subquery la
```

Endpoint join 必须能读取 soft-deleted row 的历史 public UUID。

latest attempt 可使用：

```text
MAX(attempt_number) GROUP BY delivery_id
```

再 join 回 attempts，或等价 window/subquery。

必须保证：

```text
1 Delivery → 1 dead-letter row
```

即使 Delivery 有 3 Attempts。

## 8. Filters

支持：

```text
endpoint_id=<UUID>
event_type=<exact EventType>
failure_type=<DeliveryFailureType>
response_status=<100..599>
limit=<1..100>
cursor=<opaque>
```

业务 filter 组合为 AND。

非法 endpoint UUID / EventType / failure type / response status / limit：

```text
422
code = invalid_dead_letter_filter
```

无效值不能静默变成空结果。

failure_type 只接受：

```text
http_status
timeout
network_error
unsafe_target
stale_processing
```

## 9. Cursor Design

禁止 offset pagination。

固定排序：

```text
failed_at DESC
delivery public UUID DESC
```

下一页 predicate：

```text
failed_at < cursor.failed_at
OR
(
  failed_at = cursor.failed_at
  AND delivery_public_uuid < cursor.delivery_uuid
)
```

UUID 比较必须使用明确 deterministic/binary semantics。

### 推荐实现

Application 定义：

```text
DeadLetterCursorCodec
```

Infrastructure 使用 Laravel Encrypter / APP_KEY 产生 authenticated opaque cursor：

```json
{
  "v": 1,
  "failed_at": "...",
  "delivery_id": "uuid",
  "filter_fingerprint": "sha256..."
}
```

`filter_fingerprint` 基于规范化：

```text
endpoint_id
event_type
failure_type
response_status
```

不绑定 limit，允许后续页调整 limit。

以下全部：

```text
decrypt fail
payload shape/version fail
invalid UUID/time
filter fingerprint mismatch
```

统一：

```text
422
code = invalid_dead_letter_cursor
```

不要泄漏 `DecryptException`。

如果采用等价设计，也必须满足：

```text
opaque
tamper detection
filter compatibility
```

## 10. Pagination Regression

### T-01 Basic keyset

创建超过 limit 的 failed records：

```text
page1 → cursor → page2
```

验证无重复、顺序正确、边界完整。

### T-02 Concurrent newer insert

受控顺序：

```text
read page1
↓
另一个 connection commit 一个 failed_at 更晚的新 failed Delivery
↓
read page2 with old cursor
```

新记录允许只在新的 page1 中出现。

page2 不得重复 page1，也不得漏原 cursor 后的数据。

### T-03 Same failed_at

多条记录完全相同 `failed_at`，靠 UUID binary tie-break 稳定分页。

### T-04 Filter mismatch cursor

```text
page1 endpoint=A → cursor C
page2 endpoint=B + C
```

必须 422。

### T-05 Tampered cursor

修改 cursor 一个字符：422。

## 11. Commit Visibility Concurrency Gate

真实 MySQL + 独立 connections + socket barrier。

Worker/Retry 正在执行最终失败 transaction：

```text
Attempt finalize
Delivery → failed
COMMIT
```

并发 DLQ query 只能看到：

```text
提交前：不在 DLQ
或
提交后：在 DLQ + latest Attempt metadata 完整
```

禁止 torn view：

```text
Delivery = failed
但 Attempt metadata 仍 started/旧值
```

不要随机 sleep。

如果现有 finalization 已原子完成，只需证明，不要重做状态机。

## 12. Historical Stability

创建 failed D1 后：

```text
Endpoint URL 更新
signing key rotation
Endpoint inactive
Endpoint soft delete
subscriptions change
```

D1 必须：

```text
仍在 DLQ
failure_type 不变
response_status 不变
failed_at 不变
endpoint_id 仍能读取历史 public UUID
```

历史 DLQ 可见性不能依赖 Endpoint 当前 active 状态。

## 13. Replay Integration

不要新增：

```http
POST /api/dead-letters/{id}/replay
```

继续：

```http
POST /api/deliveries/{id}/replay
```

### D1 failed → D2 succeeded

```text
D1 在 DLQ
Replay D2
D2 succeeded
```

最终：

```text
D1 仍在 DLQ
D2 不在 DLQ
D2.replay_of = D1
```

### D2 也 failed

D1、D2 都在 DLQ，D2 lineage 保留。

禁止自动“解决/删除”D1。

## 14. Index / EXPLAIN Gate

先审计现有 indexes。

在 Docker MySQL 上记录真实：

```sql
EXPLAIN
```

或支持时使用 `EXPLAIN ANALYZE`。

记录：

```text
chosen index/key
rows estimate
join strategy
filesort/temp table 如存在
```

如需新 index：

- 新 forward migration；
- 不改历史 migration；
- 只添加对核心 query/filter 有证据价值的 index；
- 不为所有排列组合建 index。

优先审计：

```text
deliveries(status, endpoint_id, ...)
delivery_attempts(delivery_id, attempt_number)
events(event_type)
```

注意 `failed_at` 来自 latest Attempt，不要盲目给 deliveries 建无效 failed_at index。

AC-10 不要求绝无 filesort，但要求：

```text
无 N+1
无 duplicate
查询有界
真实 query plan 已审计
```

## 15. Required Feature Cases

至少覆盖：

```text
HTTP 400 permanent → Attempt #1 failed
HTTP 500 → retry → Attempt #3 failed
timeout
network_error
unsafe_target
stale-processing/abandoned history 后最终 failed
failed Replay Delivery
soft-deleted Endpoint
same failed_at ordering
```

stale 场景必须使用现有状态机真实可达历史；不要伪造生产不可能状态。

## 16. Runtime R-01 — Mixed Failure Triage

Docker 生成至少：

```text
D1: permanent HTTP 400
D2: HTTP 500 exhausting max attempts
D3: network_error / timeout
```

D3 优先复用仓库已有 test/runtime transport/resolver support。

禁止为了 Runtime：

```text
削弱 production SSRF
production resolver 允许 localhost/private IP
关闭 TLS verify
```

如果不存在安全方式生成 network_error/timeout：

```text
R-01 = BLOCKED
```

并报告具体原因；不要偷偷改变生产安全策略。

验证：

```text
GET /api/dead-letters
只返回 failed
D1/D2/D3 metadata 正确
filter/order/cursor 正确
failure_message 不出现
```

## 17. Runtime R-02 — Repair → Replay → Success

选择 failed D1：

```text
GET /api/dead-letters
→ D1
GET /api/deliveries/D1/attempts
→ inspect
修复 Endpoint current config
POST /api/deliveries/D1/replay + Idempotency-Key
→ D2
outbox:publish
queue:work
→ D2 succeeded
```

最终：

```text
D1 仍在 DLQ
D2 不在 DLQ
D2.replay_of = D1
```

Runtime Replay key 在 evidence 里只写 `REDACTED`。

## 18. Security Gate

确认 DLQ response/query/cursor 不含：

```text
failure_message
raw Idempotency-Key
target URL
signing secret/key/ciphertext
HMAC signature
Outbox claim token
internal numeric IDs
```

现有全部继续 PASS：

```text
HMAC frozen signing key
secret encrypted at rest
HMAC fail closed
SSRF global-unicast
mixed DNS fail closed
DNS pinning
IPv6 pinning
proxy disabled
redirect disabled
TLS verification
Ingress Idempotency
Replay Idempotency
```

## 19. Mechanical Gate

最终：

```bash
composer quality
docker compose exec -T app composer quality
```

必须 PASS：

```text
Pint
PHPStan/Larastan
Deptrac
Deptrac negative
Unit
Feature
MySQL
Redis
DLQ
pagination concurrency
Retry/Stale
Replay
Outbox/broker loss
HMAC/SSRF
Ingress Idempotency
```

禁止删除/skip/弱化 Gate。

## 20. Repository-native Traceability

目标目录：

```text
docs/tasks/0026-dead-letter-operations/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

`ISSUE.md` 使用本次提供的稳定 Issue 快照。
`CODEX.md` 使用本文件。
`EVIDENCE.md` 只记录稳定验证事实。

`docs/开发记录.md` 只加索引/摘要，不复制长合同全文。

首个功能 commit 同时包含：

```text
production code
tests
ISSUE.md
CODEX.md
docs/开发记录.md index
必要 migration/docs
```

推荐 commit：

```text
feat: expose failed deliveries as dead letters (#26)
```

## 21. Optimized Single-Push Flow

继续沿用 Issue #24 成功流程。

### Phase A — Explore / Plan
不 push。

### Phase B — Implement
production + tests，先 targeted tests。

### Phase C — First Functional Commit
提交 code + tests + ISSUE/CODEX + index。
**不要 push。**

### Phase D — Validate Exact Implementation Commit

记录：

```text
validated_implementation_head
```

在准确实现 commit 上执行：

```text
targeted tests
composer quality
Docker targeted
Docker composer quality
MySQL concurrency
EXPLAIN
Runtime R-01/R-02
AC-01..AC-13 self review
```

代码如变化，新 code commit 成为新的 validated head，并重跑受影响/full gate。

### Phase E — Evidence Commit

全部 PASS 后写：

```text
docs/tasks/0026-dead-letter-operations/EVIDENCE.md
```

同时完成：

```text
plan.md
docs/开发记录.md 本轮最终摘要
```

Evidence commit 只改 docs/plan，不改 production/tests。

### Phase F — Single Push

第一次 push 整个分支。

### Phase G — Draft PR + CI

创建 Draft PR 并等待 CI。

CI PASS 后只更新 PR Body：

```text
current PR HEAD
latest CI URL
AC summary
Independent Review = NOT RUN
```

**不要为了写最终 CI 号再 commit docs。**

如果 GitHub trigger 客观产生多次 workflow run，准确记录实际数量；目标是“不因 docs-only 同步人为追加 CI”。

## 22. EVIDENCE.md Template

```text
# Issue #26 Evidence

validated_implementation_head: <sha>

## Acceptance Matrix
AC-01: PASS — ...
...
AC-13: PASS — ...

## Automated Gates
Targeted: ...
composer quality: PASS
Docker targeted: ...
Docker composer quality: PASS
MySQL concurrency: PASS
EXPLAIN: ...

## Runtime
R-01: PASS/BLOCKED — ...
R-02: PASS/BLOCKED — ...

## Risks
- DLQ 是 failed Delivery read model，不是独立 broker queue。
- 第一版无 acknowledge/archive/retention。
- 系统仍是 at-least-once。

## NOT RUN
- GitHub PR CI（外部事实，见 PR Body）
- Independent Review
```

## 23. PR Body

Draft PR 至少包含：

```text
Baseline
DLQ read-model semantics
API/filter/cursor
latest Attempt strategy
query/index/EXPLAIN
concurrency
Replay integration
security
AC-01..AC-13
validated_implementation_head
current PR HEAD
latest CI
Runtime R-01/R-02
Risks
Independent Review = NOT RUN
```

不要 Merge。
不要 Ready for review。

## 24. Scope Guard

禁止：

```text
DeliveryStatus::DeadLettered
DLQ write table/state machine
broker DLQ queue
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

尤其不要顺手把现有 `/deliveries` 重构成通用复杂查询框架。

## 25. Final Status

即使全部实现/Runtime/CI PASS：

```text
Independent Review = NOT RUN
Final status = INCOMPLETE
```

不要自行 Merge。
不要自行 Ready for review。

## 26. Final Chinese Report

只返回精炼结果：

```text
Issue #26

Baseline:
...

Branch:
...

Commits:
...

Draft PR:
...

Current HEAD:
...

Latest CI:
...

Design:
- DLQ semantics:
- query strategy:
- latest attempt:
- filters:
- cursor:
- indexes/EXPLAIN:
- replay integration:

Acceptance Matrix:
AC-01 PASS — ...
...
AC-13 PASS — ...

Automated:
...

Runtime:
R-01 ...
R-02 ...

Traceability:
ISSUE.md committed: YES
CODEX.md committed: YES
EVIDENCE.md: ...
validated_implementation_head: ...

CI optimization:
push count: ...
CI runs triggered: ...
extra docs-only CI after PASS: NO

Risks / NOT RUN:
...

Independent Review:
NOT RUN

Final status:
INCOMPLETE
```
