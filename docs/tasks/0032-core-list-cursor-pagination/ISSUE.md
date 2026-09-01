# Issue #32 — Stable Cursor Pagination for Core List APIs

GitHub Issue: https://github.com/taoking/EventRelay/issues/32

## Baseline

- `main@b4d89fd835671496b1b884aa1a77a0303af699fe`
- post-merge CI `33519988281 — PASS`
- Issue #30 closed / PR #31 squash merged.

Coding Agent 开始前必须重新确认最新绿色 main；否则 `BLOCKED`。

## Problem

当前核心列表 API 是无界读取：

```text
GET /api/events       → EventRepository::all()
GET /api/deliveries   → DeliveryRepository::all()
GET /api/endpoints    → EndpointRepository::all()
```

数据增长后会形成无界 DB materialization、PHP 内存与 HTTP response。

## Goal

为 Events、Deliveries、Endpoints 增加统一 bounded cursor pagination：

- 默认 `limit=50`
- `limit` 范围 `1..100`
- opaque authenticated cursor
- stable keyset pagination
- snapshot upper boundary
- no OFFSET
- no total/count
- 保留既有 ascending creation/received order

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

错误：

```text
invalid limit → 422 invalid_pagination_limit
invalid/tampered/cross-resource cursor → 422 invalid_pagination_cursor
```

## Snapshot Semantics

第一页锁定当前 eligible rows 的 upper boundary；后续：

```text
after < row <= upper
```

因此 page1 后新插入 rows 不进入旧 traversal。

Events / Deliveries 为 immutable history：旧 snapshot 必须 no duplicate / no loss / exactly once。

Endpoint 是 mutable + soft-delete：只保证新 insert excluded、soft-deleted row 不暴露、keyset 不重复；不承诺跨 HTTP 请求 Serializable visibility snapshot。

## Cursor Security

Cursor 必须：

- opaque
- tamper detected
- versioned
- resource-bound (`events|deliveries|endpoints`)
- carrying after + upper
- 如使用 internal surrogate id，则必须 encrypted，且不得回显/log/evidence

可复用 DLQ cursor encryption infrastructure，但不要复用 `DeadLetterCursor` 类型或做无关 pagination framework 重构。

## Architecture / Performance

```text
HTTP
→ Application List*Page
→ Repository bounded keyset query
→ Page DTO
→ response
```

禁止：

- Controller direct DB
- `all()` + `array_slice`
- OFFSET
- full materialization
- total COUNT
- N+1

First page 可有固定 1 次 upper-bound query + 1 次 bounded page query；后续页固定 bounded query。

Docker MySQL 8.4 必须 EXPLAIN/EXPLAIN ANALYZE 核心 page query；只有 query-plan 证据需要时才加 index。

## Concurrency / Runtime

- C-01 Event：page1 后插入新 Event，旧 snapshot exactly once，新 row excluded。
- C-02 Delivery：同上。
- C-03 same timestamp tie：跨页稳定，无 skip/repeat。
- C-04 Endpoint：page1 后 insert + soft-delete，new excluded、deleted hidden、no duplicate。
- C-05 tampered/malformed/cross-resource cursor → 422。
- R-01 至少 125 Event，default 50，limit=40 遍历完整 snapshot。
- R-02 >100 Delivery，page1 后 new rows excluded。
- R-03 >100 Endpoint + soft-delete。
- R-04 HTTP cursor security/leakage。
- R-05 大数据量 + EXPLAIN + no OFFSET + bounded loaded rows。

## Acceptance Criteria

| ID | Requirement |
|---|---|
| AC-01 | Events/Deliveries/Endpoints 默认 50，limit 1..100 |
| AC-02 | data + meta.limit + meta.next_cursor，无 total |
| AC-03 | 保留 ascending order 与 item shape |
| AC-04 | keyset only，无 OFFSET/全量 materialization |
| AC-05 | cursor opaque/authenticated/versioned/resource-bound |
| AC-06 | upper boundary 排除 page1 后新增 rows |
| AC-07 | Event snapshot no duplicate/no loss |
| AC-08 | Delivery snapshot no duplicate/no loss |
| AC-09 | Endpoint insert/soft-delete语义正确，不夸大 Serializable |
| AC-10 | same-timestamp tie stable |
| AC-11 | invalid limit/cursor/cross-resource = 422，无泄漏 |
| AC-12 | fixed bounded queries，无 COUNT/N+1，EXPLAIN |
| AC-13 | R-01..R-05 PASS |
| AC-14 | DLQ/Replay/Ingress/Outbox/Retry/Stale/Rabbit/Redis/Operations/HMAC/SSRF 回归 PASS |
| AC-15 | repository-native traceability + single-push/single-PR-CI |

## Out of Scope

filter/search/sort DSL、total/page number、OFFSET、previous cursor、retention、DLQ optimization、API auth/RBAC/multitenancy、rate limiting/circuit breaker、GraphQL、newest-first、exactly-once。

## Delivery

Branch：

```text
feature/core-list-cursor-pagination
```

Repository-native：

```text
docs/tasks/0032-core-list-cursor-pagination/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

Independent Review 前最终状态：`INCOMPLETE`。

不要 Merge。不要 Ready for review。
