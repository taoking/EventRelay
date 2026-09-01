# EventRelay Issue #32 — CODEX Execution Contract

> 本文件是本轮唯一执行合同。
>
> Issue: #32
> https://github.com/taoking/EventRelay/issues/32
>
> Branch: `feature/core-list-cursor-pagination`
>
> Draft PR 必须 `Closes #32`。
> 不要 Merge。
> 不要 Ready for review。
> Independent Review 前 Final status = `INCOMPLETE`。

## 0. Baseline Gate

开始前确认：

```text
main@b4d89fd835671496b1b884aa1a77a0303af699fe
post-merge CI 33519988281 = PASS
Issue #30 closed
Issue #32 open
```

如果 main 已前进，只允许从新的 exact green main 开始，并记录新 SHA/CI；无法证明绿色 baseline → `BLOCKED`。

## 1. Explore First

完整阅读：

```text
AGENTS.md
docs mandatory rules
Issue #32
plan.md
docs/开发记录.md

Event:
  ListEvents
  EventRepository
  Eloquent implementation
  controller/resource
  schema/indexes

Delivery:
  ListDeliveries
  DeliveryRepository
  Eloquent implementation
  controller/resource
  schema/indexes

Endpoint:
  ListEndpoints
  EndpointRepository
  Eloquent implementation
  controller/resource
  SoftDeletes/schema/indexes

DeadLetter:
  DeadLetterCursor
  CursorCodec
  Laravel Encrypter adapter
  keyset query/tests
```

搜索所有：

```text
->all()
all(): array
ListEvents
ListDeliveries
ListEndpoints
```

先输出中文 Explore 结论：

```text
current ordering
persistence sort keys
immutable tie-break candidates
indexes
DLQ cursor infrastructure
all() usages
proposed cursor payload
snapshot algorithm
AC → implementation/test/runtime map
```

## 2. Locked HTTP Contract

Endpoints：

```http
GET /api/events
GET /api/deliveries
GET /api/endpoints
```

Query：

```text
limit   optional, default 50, min 1, max 100
cursor  optional opaque cursor
```

Response：

```json
{
  "data": [],
  "meta": {
    "limit": 50,
    "next_cursor": null
  }
}
```

禁止：

```text
total
total_pages
page
offset
previous_cursor
```

无 query 参数也必须 bounded 50。

Item JSON shape 不变。

## 3. Error Contract

Invalid limit：

```text
0
negative
>100
non-integer
array/object malformed
```

→ 422：

```text
invalid_pagination_limit
```

Invalid cursor：

```text
malformed
tampered
unknown version
wrong resource
invalid payload/key
crypto failure
```

→ 422：

```text
invalid_pagination_cursor
```

禁止泄漏 decrypted payload、internal ID、crypto exception、APP_KEY/MAC/cipher details。

## 4. Ordering

保持当前 documented ascending order：

```text
Events     creation/received ASC
Deliveries creation ASC
Endpoints  creation ASC
```

不要改 newest-first。

必须稳定 total order。

具体 persistence key在 Explore 后依据 schema/query决定。

允许：

```text
created_at + immutable public UUID
```

或：

```text
internal monotonic surrogate key
```

如果使用 internal key：

- 只存在于 encrypted cursor/persistence adapter；
- 不进入 Domain/public DTO；
- 不回显/log/evidence。

不要依赖 SQL 默认顺序。

## 5. Cursor Contract

General list cursor，不能直接拿 `DeadLetterCursor` 当类型。

允许抽取既有 DLQ encryption/auth infrastructure，但不要做“大一统 pagination framework”。

Conceptual payload：

```json
{
  "v": 1,
  "resource": "events",
  "after": "...",
  "upper": "..."
}
```

必须：

```text
opaque
authenticated
versioned
resource-bound
tamper-detected
confidential if internal IDs are encoded
```

`limit` 默认不绑定 cursor，允许下一页改变 limit；snapshot/after 不得改变。

## 6. Snapshot Upper Boundary

### First Page

确定：

```text
upper = 当前 eligible rows maximum stable key
```

第一页：

```text
row <= upper
ORDER BY stable key ASC
LIMIT limit+1
```

无数据：

```text
data=[]
next_cursor=null
```

### Next Pages

decode：

```text
v/resource/after/upper
```

query：

```text
after < row <= upper
ORDER BY key ASC
LIMIT limit+1
```

page1 后 new inserts > upper，旧 traversal 永不读取。

禁止：

```sql
OFFSET
```

也禁止 Laravel `offset/skip/forPage/paginate` 等产生 offset 的路径。

## 7. next_cursor Correctness

若使用 `limit + 1`：

- fetched <= limit → `next_cursor=null`
- fetched == limit+1 → response 只返回前 limit
- cursor.after = **最后一条实际返回 row**
- upper = 原 snapshot upper

绝不能把 lookahead row 当 `after`，否则会漏数据。

必须有 focused regression。

## 8. Event Semantics

Event 是 immutable history。

旧 snapshot traversal：

```text
exactly once
no duplicate
no loss
stable order
new rows after page1 excluded
```

不得 `all()` 后 PHP slice。

Event payload再大也只加载本页 rows。

## 9. Delivery Semantics

Delivery creation identity immutable，但 status 会变化。

排序 key禁止使用：

```text
status
updated_at
```

必须基于 immutable creation identity。

锁定：

```text
membership snapshot = page1 creation upper boundary
resource fields      = each request current state
```

status 在翻页期间变化不应造成 membership duplicate/loss。

## 10. Endpoint Semantics

Endpoint mutable + soft delete。

排序基于 immutable creation identity。

page1 后：

```text
new Endpoint → excluded from old snapshot
soft-deleted Endpoint → not exposed on later page
```

不承诺跨 HTTP 请求 Serializable snapshot。

必须明确：

```text
insert snapshot guarantee != visibility snapshot
```

禁止用长事务/snapshot table/返回已删除 row 来伪造 Serializable。

## 11. Repository Boundary

目标：

```text
HTTP
→ Application List*Page
→ Repository bounded page query
→ Page DTO
→ Resource
```

禁止 Controller direct Eloquent/DB。

禁止：

```text
all()
collect all
array_slice
```

用于这三个 list endpoint。

审计 `all()` 其它用途：

- 无合法用途可删除；
- 有其它 legitimate internal use 可保留，但三个 list不得再调用。

## 12. Query Gate

First page：

```text
固定常数 queries
```

允许 upper-bound query + page query。

Next page：

```text
bounded page query
```

禁止：

```text
COUNT(*)
N+1
per-item lookup
full materialization
```

Endpoint soft-delete必须在 SQL predicate，不得 PHP过滤。

## 13. EXPLAIN / Index Gate

Docker MySQL 8.4 对三个资源：

```text
first page
middle/deep page
```

执行 EXPLAIN / 可用时 EXPLAIN ANALYZE。

记录：

```text
selected key
access type
rows estimate
range predicate
Extra/filesort
```

只有 query-plan证据证明需要时新增 index。

不要为了形式上“有索引”随意建 composite index。

## 14. Limit Tests

覆盖：

```text
omitted → 50
1 → 1
100 → 100
0 → 422
101 → 422
-1 → 422
abc → 422
1.5 → 422
array → 422
```

字符串 `"50"` 按 Laravel query string真实输入定义并锁测试，不凭感觉。

## 15. Cursor Security Tests

至少：

```text
valid Event cursor
valid Delivery cursor
valid Endpoint cursor
single-byte tamper
truncated
garbage
Event cursor on Deliveries
Delivery cursor on Endpoints
unknown version
invalid key type
```

Invalid均：

```text
422 invalid_pagination_cursor
```

catch boundary narrow，不要 catch Throwable。

## 16. Tie-break Gate

构造同秒 `created_at` rows，让 page boundary落在 tie内部。

证明：

```text
deterministic
no duplicate
no skip
exact row count
```

如果最终 key是 monotonic PK，不使用 created_at 作为 keyset，也要证明 creation order与原 API一致且同 timestamp 不影响顺序。

## 17. Real Concurrency

使用真实 MySQL + independent connection/process + barrier。

不要随机 sleep 作为主要证据。

### C-01 Event

```text
seed >2 pages
page1
barrier
insert new Events
continue old cursor
```

old snapshot exact old IDs，新 IDs excluded。

### C-02 Delivery

同上。

### C-03 Same Timestamp

跨页 tie。

### C-04 Endpoint

page1 后：

```text
insert new endpoint
soft-delete old endpoint not yet read
```

继续旧 cursor：

```text
new excluded
deleted hidden
others no duplicate
```

### C-05 Cursor Misuse

真实 HTTP cross-resource/tampered/malformed → 422。

## 18. Runtime R-01 Events

Docker HTTP + MySQL：

至少 125 Event。

验证 default：

```text
first page = 50
```

`limit=40`：

```text
40
40
40
5
next=null
```

旧 snapshot UUID exactly once，ascending。

## 19. Runtime R-02 Deliveries

>100 Delivery。

page1 后创建 new Delivery。

旧 cursor traversal：

```text
new excluded
old exactly once
```

让一条 existing Delivery status变化，证明 membership key不依赖 status。

## 20. Runtime R-03 Endpoints

>100 Endpoint + soft-deletes。

验证：

```text
default bounded
existing deleted excluded
page1-after new insert excluded
page1-after delete old row hidden
no keyset duplicate
```

## 21. Runtime R-04 Security

真实 HTTP：

```text
tampered
cross-resource
garbage
```

全部422。

检查 body/log不出现：

```text
internal integer id
decrypted payload
crypto exception
APP_KEY
MAC/cipher details
```

## 22. Runtime R-05 Large Dataset

目标：

```text
>=5000 Events
>=5000 Deliveries
```

如果环境成本显著，可一个5k+、另一个1k+，但必须解释并提供 query plan；默认两类都5k。

验证：

```text
deep keyset query
no OFFSET
bounded loaded rows
EXPLAIN range/index evidence
```

不需要 latency SLA。

## 23. Backward Compatibility

这是 intentional early-API tightening：

```text
GET list without cursor/limit
```

从全量改为默认 50。

必须更新：

```text
README
Feature tests
API response assertions
```

Detail/create/update/delete/subscription/attempts/DLQ contract不改。

## 24. Full Regression

继续 PASS：

```text
Event create/detail
Endpoint CRUD/subscriptions
Delivery detail/attempts
Replay
Ingress Idempotency
Dead Letters
Outbox
Retry/Stale
Redis transport
Rabbit transport
Operations health/metrics
HMAC
SSRF
```

特别保护 DLQ cursor，不能因 codec抽取回归。

## 25. Mechanical Gates

执行：

```bash
composer quality
docker compose exec -T app composer quality
```

Docker pagination/concurrency integration不得 skip。

## 26. Repository-native Traceability

目录：

```text
docs/tasks/0032-core-list-cursor-pagination/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

首个 functional commit 包含：

```text
production code
tests
ISSUE.md
CODEX.md
README/API docs
docs/开发记录.md concise index
```

不要最后补 task contracts。

## 27. First Functional Commit

推荐：

```text
feat: add cursor pagination to core list APIs (#32)
```

targeted green 后 commit。

此时：

```text
DO NOT PUSH
```

## 28. Exact SHA Validation

记录：

```text
validated_implementation_head=<code SHA>
```

这个 exact SHA 上执行：

```text
targeted
composer quality
Docker targeted
Docker composer quality
C-01..C-05
R-01..R-05
EXPLAIN
self-review
```

如果代码再变：

```text
new code SHA → revalidate
```

## 29. EVIDENCE.md

必须记录：

```text
baseline SHA + CI
validated_implementation_head
AC-01..AC-15
C-01..C-05
R-01..R-05
targeted
composer quality
Docker quality
EXPLAIN
query counts
cursor leakage check
all() usage audit
```

只用：

```text
PASS / FAIL / BLOCKED / NOT RUN
```

不要用 final docs head冒充 validated code head。

## 30. Optimized Push / CI

```text
Explore
→ Implement
→ targeted
→ functional commit
→ DO NOT PUSH
→ exact SHA full validation
→ EVIDENCE/plan/dev-record docs commit
→ ONE PUSH
→ Draft PR
→ one PR CI
```

CI后只更新 PR Body current HEAD / latest CI。

禁止为了记录 CI run number再 docs-only commit。

## 31. Draft PR Body

至少：

```text
Closes #32
Baseline
API contract
Ordering/sort key
Cursor/security
Snapshot upper boundary
Endpoint mutation caveat
Query/index plan
Concurrency
R-01..R-05
AC-01..AC-15
validated implementation head
current PR HEAD
latest CI
Independent Review = NOT RUN
```

保持 Draft。

## 32. Scope Guard

禁止：

```text
filter/search DSL
sort options
newest-first
total/page count
OFFSET
previous cursor
retention
DLQ optimization
API auth/RBAC
multitenancy
rate limiting
circuit breaker
GraphQL
generic pagination package/framework
exactly-once
```

## 33. Self Review Checklist

```text
1. 三个列表是否无参数也最多50？
2. limit是否最大100？
3. list API是否还有 all()？
4. 是否完全没有 OFFSET？
5. next_cursor after是否最后返回 row而非lookahead？
6. page1后新 insert是否被 upper排除？
7. Event/Delivery旧 snapshot是否 no duplicate/no loss？
8. Endpoint是否没有夸大 Serializable？
9. tie是否稳定？
10. cursor是否 resource-bound？
11. internal id/crypto payload是否零泄漏？
12. 是否无 COUNT/N+1？
13. deep page EXPLAIN是否 keyset/range？
14. DLQ cursor是否不回归？
15. validated SHA是否真跑完 Runtime？
```

任一不确定：

```text
INCOMPLETE
```

## 34. Final Chinese Report

只返回：

```text
Issue #32

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

Pagination:
- default/max:
- ordering:
- sort key:
- cursor:
- snapshot upper:
- endpoint mutation semantics:

Performance:
- query counts:
- OFFSET:
- indexes:
- EXPLAIN:

Concurrency:
C-01...
...
C-05...

Runtime:
R-01...
...
R-05...

Acceptance:
AC-01 PASS
...
AC-15 PASS

Automated:
targeted...
composer quality...
Docker quality...

Traceability:
ISSUE.md YES
CODEX.md YES
EVIDENCE.md YES
validated_implementation_head...

CI optimization:
push count:
CI runs:
extra docs-only CI: NO

Risks / NOT RUN:
Independent Review: NOT RUN

Final status:
INCOMPLETE
```

不要 Merge。
不要 Ready for review。
