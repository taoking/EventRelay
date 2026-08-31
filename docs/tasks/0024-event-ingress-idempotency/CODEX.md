# EventRelay Issue #24 — CODEX Execution Contract

> **唯一执行合同**
>
> 读取并严格执行本文件。不要依赖聊天历史补充需求。
>
> GitHub Issue：`#24`  
> https://github.com/taoking/EventRelay/issues/24
>
> 目标分支：`feature/event-ingress-idempotency`
>
> PR 必须创建为 **Draft**，必须 `Closes #24`。  
> **不要 Merge，不要 Ready for review。**
>
> Independent Review 前最终状态只能是 `INCOMPLETE`。

---

## 0. Baseline Gate

开始前重新确认：

```text
main@78ace320b0dc49ebb600381b5802e5c5eaca5e41
post-merge CI 33359084169 = PASS
Issue #22 = closed
Issue #24 = open
```

若 `main` 已前进，只允许使用更新且 CI PASS 的绿色 main。

任何 baseline 失败：

```text
BLOCKED
```

不要继续开发。

---

## 1. Goal

为：

```http
POST /api/events
```

增加**可选** `Idempotency-Key`。

锁定语义：

```text
无 key
→ 保持现有语义
→ 每次合法 POST 创建新 Event

有 key
→ 同 key + 同逻辑请求只创建一个 Event graph
→ 首次 201
→ 后续 200 same Event

同 key + 不同逻辑请求
→ 409 idempotency_key_conflict
```

这里的 Event graph 指：

```text
Event
+
matching primary Deliveries
+
对应 attempt:1 Outbox intents
+
ingress idempotency binding
```

---

## 2. Read First — 只读探索

编码前必须阅读：

```text
AGENTS.md
AGENTS.md 引用的 mandatory docs

Issue #24
docs/开发记录.md
plan.md

app/Application/Event/CreateEvent.php
EventRepository
Event Domain
EventData

EventController
StoreEventRequest
EventResource

SubscriptionMatcher
CreateDelivery
DeliverySnapshotCreator
DeliveryOutboxWriter
DeliveryExecutionIntent

Transactional Outbox 实现与 tests
Replay Idempotency 实现：
- ReplayFailedDelivery
- DeliveryReplayCreator
- Replay concurrency tests
- docs/tasks/0022-delivery-replay/

Event receive / matching / concurrency tests
MySQL transaction manager
migrations
```

先输出简短中文架构地图：

```text
current Event request
→ validation
→ CreateEvent transaction
→ Event
→ matcher
→ Delivery
→ Outbox
```

再给出：

```text
AC → production change → automated evidence → runtime evidence
```

映射。

如果发现本合同与仓库事实实质冲突：

`BLOCKED`

否则不要等待人工批准，直接继续实现。

---

## 3. Acceptance Matrix

| ID | 必须证明 | 最低证据 |
|---|---|---|
| AC-01 | 无 key 时 identical POST 仍创建不同 Event | API regression |
| AC-02 | same key + same request：201 → 200 same Event；Delivery/Outbox 不重复 | API + DB |
| AC-03 | same key + different fingerprint → 409 `idempotency_key_conflict` | API regression |
| AC-04 | key validation；raw key 不泄漏 | API/security |
| AC-05 | fingerprint v1 object-order stable / array-order sensitive / fixed vector | Unit |
| AC-06 | Event + Deliveries + Outbox + binding 原子 commit/rollback | Feature/MySQL |
| AC-07 | 双 MySQL process same-key/same-request → 1 Event graph | barrier concurrency |
| AC-08 | 双 MySQL process same-key/different-request → 1 winner + 1 conflict | barrier concurrency |
| AC-09 | subscriptions/Endpoint 后续变化不改变已提交 same-key 结果 | Feature + R-02 |
| AC-10 | Redis down：首次 keyed POST 成功；same-key retry 不重复；恢复后 Outbox 可发布 | R-01 |
| AC-11 | Replay/Outbox/Retry/Stale/HMAC/SSRF/primary Delivery 不回归 | full quality |
| AC-12 | task/evidence traceability 按新目录规则落地 | Git diff |

最终报告必须逐项给出：

```text
AC-01 PASS/FAIL
...
AC-12 PASS/FAIL
```

任何一项 FAIL：

不得声称完成。

---

## 4. Locked API Semantics

### 4.1 Header optional

缺失：

```text
Idempotency-Key: absent
```

不是错误。

现有：

```text
identical body
→ distinct Events
```

必须保留。

### 4.2 Header present

合法字符：

```regex
^[A-Za-z0-9._:-]{1,128}$
```

invalid / blank / overlong：

```text
422
code = invalid_idempotency_key
```

建议复用或最小提取 Replay 已存在的 validation 规则。

如果抽取共享 `IdempotencyKey` value object / validator：

必须保证 Replay API 所有现有行为和测试完全不变。

不要顺手建设大型 generic idempotency framework。

---

## 5. Key Digest

raw key 只允许在请求生命周期的最小内存范围内存在。

DB 只保存 digest。

锁定 event-ingress scope：

```text
sha256(
  "event-ingress\n"
  + rawIdempotencyKey
)
```

固定 vector：

```text
raw key:
evt-test-001

input bytes:
event-ingress\nevt-test-001

expected sha256:
1311614042ef2747b331fd9ccc6a570dd8b4e8a9a0a7b6772334457336abc8c8
```

expected 不得在测试中用 production digest 实现即时生成。

---

## 6. Request Fingerprint v1

Fingerprint 输入：

```text
"v1\n"
+ eventType
+ "\n"
+ canonicalPayloadJson
```

最终：

```text
sha256(bytes)
```

### Canonical payload rules

基于已解析/已验证 payload：

1. JSON object keys 递归按字典序排序；
2. arrays 保持原元素顺序；
3. scalar 类型和值保持；
4. `{}` 必须保持 object，不能变 `[]`；
5. nested empty object 同样保持；
6. 不受原 JSON whitespace / indentation / object input order 影响；
7. 不额外 pretty-print；
8. 使用明确稳定 JSON encoding flags，并写测试锁定。

推荐：

```text
JSON_UNESCAPED_UNICODE
JSON_UNESCAPED_SLASHES
JSON_PRESERVE_ZERO_FRACTION
JSON_THROW_ON_ERROR
```

如果现有 payload representation 需要等价 flags 调整，必须在 docs 说明并锁测试。

### Fixed fingerprint vector

逻辑 payload：

```json
{
  "b": 2,
  "a": {
    "z": true,
    "m": [3, 2, 1]
  },
  "empty": {}
}
```

canonical JSON 必须为：

```json
{"a":{"m":[3,2,1],"z":true},"b":2,"empty":{}}
```

event type：

```text
order.paid
```

fingerprint input：

```text
v1
order.paid
{"a":{"m":[3,2,1],"z":true},"b":2,"empty":{}}
```

无尾随换行。

expected SHA-256：

```text
58da2d82e6edc34c487fdaed21df917d424398d2f0cf7ef0b56d653d66f79aff
```

必须增加独立 fixed-vector test。

---

## 7. Persistence Design

优先采用独立 persistence：

```text
event_ingress_idempotencies
```

建议字段：

```text
id
key_digest CHAR(64) UNIQUE NOT NULL
request_fingerprint CHAR(64) NOT NULL
event_id BIGINT NOT NULL FK events.id
created_at
updated_at
```

不要修改历史 migrations。

新增 migration。

### 推荐 transaction strategy

为了保持 `event_id NOT NULL + FK`，推荐使用：

```text
TRY transaction:

  create Event E
  insert idempotency binding(keyDigest, fingerprint, E.internalId)
  match subscriptions
  create primary Deliveries
  create Outbox intents
  COMMIT

IF narrow idempotency unique conflict:
  loser transaction 必须完整 rollback
  新 transaction / current locking read：
      load winner binding
      compare fingerprint
      same → return winner Event / created=false
      different → idempotency_key_conflict
```

重点：

- idempotency binding 应尽量在创建 Event 后、subscription matching 前写入；
- loser 应尽早触发 unique conflict，避免做无意义 Delivery 工作；
- unique conflict 必须只识别 `key_digest` 对应的指定约束；
- loser transaction 的临时 Event 必须 rollback；
- winner recovery 必须使用 locking/current read 或同等级方案；
- 不得在 duplicate path 重新执行 matcher / CreateDelivery / Outbox。

也允许等价且更可靠的 claim-first schema，但必须明确证明：

```text
不会 commit orphan claim
不会 commit null/unbound claim
不会产生 Event without binding gap
```

不要为了理论通用性设计复杂状态机。

---

## 8. Application Result

不要让 Controller 事后猜测是否 duplicate。

新增明确 result，例如：

```text
CreatedEventResult
- EventData / Event
- created: bool
```

或等价结构。

HTTP：

```text
created=true  → 201
created=false → 200
```

无 key 永远：

```text
created=true
```

---

## 9. Stable Existing Result

一旦：

```text
key=A + fingerprint=F1 → E1
```

以后：

- subscription set 变化；
- Endpoint active/inactive 变化；
- Endpoint soft delete；
- URL 更新；
- signing secret rotation；

same key + F1：

```text
→ E1
→ created=false
→ 不重新 matcher
→ 不新增 Delivery
→ 不新增 Outbox
```

不要做：

```text
lookup E1
→ 再校验当前 subscriptions/endpoints
```

Idempotency result 是已经提交的历史结果。

不同 key 才读取当前配置并创建新 Event graph。

---

## 10. Conflict Semantics

existing binding：

```text
keyDigest = K
stored fingerprint = F1
```

incoming：

```text
same K
fingerprint = F2
```

如果：

```text
F1 != F2
```

返回：

```text
409
code = idempotency_key_conflict
```

不得：

- 返回 E1 200；
- 创建 E2；
- 更新 binding fingerprint；
- 修改原 Event / Deliveries。

同 key 是永久绑定，不实现 TTL。

---

## 11. Required Automated Tests

### T-01 No-key backward compatibility

```text
same body
no key
POST #1 → E1 / 201
POST #2 → E2 / 201
E1 != E2
```

继续保留旧 regression。

### T-02 Same key same body

```text
key=A
POST #1 → 201 E1
POST #2 → 200 E1
```

DB：

```text
Event = 1
binding = 1
matching primary Delivery = exactly once
Outbox attempt:1 = exactly once
```

### T-03 Same key conflict — type

```text
key=A
order.paid → E1

same key
order.cancelled
→ 409 idempotency_key_conflict
```

### T-04 Same key conflict — payload

值变化：

```text
{"amount":1}
vs
{"amount":2}
```

→ conflict。

### T-05 Object order canonicalization

```json
{"a":1,"b":2}
{"b":2,"a":1}
```

same key：

→ same Event，不 conflict。

### T-06 Array order

```json
{"items":[1,2]}
{"items":[2,1]}
```

same key：

→ conflict。

### T-07 `{}` / nested `{}` shape

继续证明 empty object 不被转成 array。

### T-08 Fixed fingerprint vector

使用本合同固定 expected SHA-256。

### T-09 Raw-key leak

检查：

```text
events
event_ingress_idempotencies
deliveries
delivery_outbox_messages
Redis serialized payload
API response
application logs
```

不得包含 raw fixture key。

### T-10 Atomic rollback

人为让 Delivery / Outbox persistence 抛出未知异常。

最终：

```text
new Event = 0
new binding = 0
new Delivery = 0
new Outbox = 0
```

故障解除后 same key 可正常创建。

### T-11 Existing result after subscription change

```text
Endpoint A subscribes order.paid
key=A → E1 + D(A)

change subscriptions:
A removed / B added

same key=A → same E1
Delivery set unchanged

new key=B → E2
uses current subscription set
```

### T-12 Same-key / same-request true concurrency

真实 MySQL：

- 2 process；
- independent connections；
- barrier；
- no random sleep。

最终：

```text
1 binding
1 Event
matching Deliveries exactly once
Outbox exactly once
same Event UUID returned
```

### T-13 Same-key / different-request true concurrency

同 key、不同 fingerprint。

最终：

```text
1 winner Event
1 binding
1 winner Delivery graph
1 request success
1 request idempotency conflict
```

哪一方胜出不应依赖硬编码进程顺序，测试应验证集合语义。

### T-14 Existing regressions

必须继续真实执行：

```text
EventDeliveryMatchingConcurrencyTest
DeliveryConcurrencyTest
DeliveryReplayConcurrencyTest
Outbox publisher / broker loss
Retry
Stale recovery
HMAC signing
SSRF / DNS pinning
```

---

## 12. Runtime Gates

### R-01 — Redis physical outage

准备至少一个 matching subscription。

物理停止 Redis。

```text
POST /api/events
Idempotency-Key: REDACTED
→ 201 E1
```

确认 MySQL：

```text
Event E1 = 1
binding = 1
matching Delivery = expected
Outbox = expected
```

Redis unavailable。

再次 same key + same body：

```text
→ 200 same E1
```

确认：

```text
Event still 1
binding still 1
Delivery unchanged
Outbox unchanged
```

恢复 Redis：

```bash
php artisan outbox:publish --limit=100
```

确认真实 Redis queue 得到预期 job。

如果现有 runtime support 能在**不修改 production SSRF policy**情况下安全运行真实 worker，可继续 worker；否则不要为本 Issue 建 test-only production bypass，worker path 由现有 CI integration gates 继续证明。

### R-02 — Stable result after config change

```text
subscriptions S1
key=A → E1 / D1

change subscriptions to S2

same key=A
→ same E1
→ original Delivery set unchanged

new key=B
→ E2
→ current S2 Delivery set
```

记录 UUID / counts；Runtime raw key 一律 `REDACTED`。

---

## 13. Security / Failure Rules

禁止：

```text
catch(Throwable) → idempotency conflict
```

只转换明确的：

- invalid key；
- idempotency key conflict；
- narrow DB unique constraint signal。

未知 programming / persistence error：

继续传播并 rollback。

不要把 key/fingerprint 记录到普通业务 log，除非只记录不可逆 digest 且确有诊断必要；默认不记录。

---

## 14. Mechanical Gate

最终执行：

```bash
composer quality
docker compose exec -T app composer quality
```

必须 PASS：

- Pint；
- PHPStan / Larastan；
- Deptrac；
- Deptrac negative；
- Unit；
- Feature；
- MySQL；
- Redis；
- Event ingress idempotency；
- matching concurrency；
- Replay；
- Outbox；
- Retry；
- Stale；
- HMAC；
- SSRF。

不得删除/skip/弱化 Gate 获得绿色结果。

---

## 15. Repository-native Traceability

目标目录：

```text
docs/tasks/0024-event-ingress-idempotency/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

### ISSUE.md

保存 GitHub Issue #24 的稳定任务快照。

如果用户已经把随任务提供的 `ISSUE.md` 放入目录：

直接使用，禁止改写。

否则从 GitHub Issue #24 获取并保存。

### CODEX.md

就是本文件。

禁止把本文件全文复制进：

```text
docs/开发记录.md
```

### docs/开发记录.md

只添加索引与摘要：

```text
Issue #24:
docs/tasks/0024-event-ingress-idempotency/ISSUE.md
docs/tasks/0024-event-ingress-idempotency/CODEX.md
docs/tasks/0024-event-ingress-idempotency/EVIDENCE.md
```

以及：
- implementation commit；
- validated implementation head；
- Draft PR；
- 当前状态。

---

## 16. Optimized Git / Validation Flow — 尽量只触发一次 CI

这是本轮必须采用的优化流程。

### Phase A — Explore / Plan

只读。

不 push。

### Phase B — Implement

完成 production code + tests。

先跑 targeted tests。

### Phase C — First Functional Commit

第一份功能 commit 同时包含：

```text
production code
tests
ISSUE.md
CODEX.md
docs/开发记录.md 索引
必要的 migration/docs
```

推荐：

```text
feat: make event ingestion idempotent (#24)
```

**此时不要 push。**

### Phase D — Validate Exact Implementation Commit

记录该 commit SHA：

```text
validated_implementation_head
```

在这个 commit 上执行：

```text
targeted tests
composer quality
Docker targeted tests
Docker composer quality
Runtime R-01
Runtime R-02
self review AC-01..AC-12
```

如果发现代码问题：

1. 修改代码；
2. 新建/修正 implementation commit；
3. 把新的代码 commit 作为 validated head；
4. 重新运行受影响 + full gates。

不要先 push 一个已知未完成版本。

### Phase E — Evidence Commit

验证全部 PASS 后创建：

```text
docs/tasks/0024-event-ingress-idempotency/EVIDENCE.md
```

记录：

```text
validated_implementation_head: <code commit sha>

AC-01 PASS ...
...
AC-12 PASS ...

targeted tests
local quality
Docker quality
Runtime R-01/R-02

Risks
NOT RUN:
- GitHub CI
- Independent Review
```

同时在**这个 evidence commit**里完成：

```text
plan.md
docs/开发记录.md 的最终本轮状态
```

不要后续再为了 plan.md 单独创建一个 docs commit。

EVIDENCE commit 只改 docs/plan，不改 production/test code。

### Phase F — Single Push

到此为止才第一次 push：

```text
git push -u origin feature/event-ingress-idempotency
```

这样本地已经有：

```text
implementation commit
+
evidence/plan commit
```

一次 push 即可。

### Phase G — Draft PR + CI

创建 Draft PR：

```text
Closes #24
```

等待 GitHub Actions。

理想情况下只触发这一轮 PR CI。

如果 CI PASS：

只更新 **PR Body**：

```text
current PR HEAD
latest CI URL
CI PASS
AC summary
Independent Review = NOT RUN
```

**不要为了写 CI 结果再提交仓库 docs。**

如果 CI FAIL：

只有真正需要代码/测试修复时才继续 push。

---

## 17. `EVIDENCE.md` Template

```text
# Issue #24 Evidence

validated_implementation_head: <sha>

## Acceptance Matrix

AC-01: PASS — ...
...
AC-12: PASS — ...

## Automated Gates

Targeted:
...

composer quality:
PASS

Docker targeted:
...

Docker composer quality:
PASS

## Runtime

R-01:
PASS — ...

R-02:
PASS — ...

## Risks

- Idempotency binding 第一版永久保留，无 TTL。
- 当前无 producer namespace / auth；key scope 为 Event ingress 全局 scope。
- 系统整体仍为 at-least-once。

## NOT RUN

- GitHub PR CI（外部事实，见 PR Body）
- Independent Review
```

---

## 18. PR Body

Draft PR 至少包含：

```text
Baseline
Goal
API semantics
Persistence/transaction design
Fingerprint v1
Key digest/no-leak
Concurrency
Subscription/config stable result
Outbox / Redis outage
Acceptance Matrix AC-01..AC-12
validated_implementation_head
current PR HEAD
latest CI
Risks
Independent Review = NOT RUN
```

不要把 raw key 写进去。

不要 Merge。

不要 Ready for review。

---

## 19. Scope Guard

禁止本轮实现：

- mandatory Idempotency-Key；
- TTL / cleanup；
- producer namespace；
- auth/RBAC/multi-tenancy；
- generic idempotency middleware/framework；
- Replay redesign；
- bulk Event ingest；
- DLQ；
- Outbox daemon；
- RabbitMQ/Kafka；
- exactly-once；
- Runtime harness refactor；
- Dashboard/UI。

如果为了本 Issue 需要抽取一个很小的共享 Idempotency-Key validator/value object，可以做；不要扩大成框架。

---

## 20. Self Review Checklist

提交前确认：

```text
无 key 是否仍创建不同 Event？
same key 是否不重新 matcher？
same key same request 是否稳定返回原 Event？
same key different request 是否 conflict？
object key order 是否 canonical？
array order 是否敏感？
raw key 是否完全不泄漏？
unique conflict 是否只识别正确 constraint？
REPEATABLE READ winner recovery 是否 current/locking read？
loser Event/Delivery/Outbox 是否完整 rollback？
binding 与 Event graph 是否没有原子性 gap？
Redis down 是否不影响 commit？
Replay/Outbox/HMAC/SSRF 是否全部回归 PASS？
```

---

## 21. Final Status

即使代码、Runtime、Docker、CI 全部 PASS：

Independent Review 前最终状态仍然：

```text
INCOMPLETE
```

不要自行 Merge。  
不要自行 Ready for review。

---

## 22. Final Chinese Report

只返回精炼结果，不要重抄合同：

```text
Issue #24

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
- optional key:
- key digest:
- fingerprint:
- persistence:
- transaction:
- duplicate recovery:

Acceptance Matrix:
AC-01 PASS — ...
...
AC-12 PASS — ...

Automated:
...

Runtime:
R-01 PASS — ...
R-02 PASS — ...

Traceability:
ISSUE.md committed: YES
CODEX.md committed: YES
EVIDENCE.md: ...
validated_implementation_head: ...

CI optimization:
push count:
CI runs triggered:
extra docs-only CI after PASS: NO

Risks:
...

Independent Review:
NOT RUN

Final status:
INCOMPLETE
```
