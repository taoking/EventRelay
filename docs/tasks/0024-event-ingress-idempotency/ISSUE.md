# Issue #24 — Event Ingress Idempotency-Key

GitHub Issue: https://github.com/taoking/EventRelay/issues/24

## 目标

为 `POST /api/events` 增加第一版 **Event Ingress Idempotency-Key**，防止客户端在“服务端已提交、客户端超时/重试”场景重复创建 Event、Delivery 与 Outbox。

本 Issue 的核心语义：

> `Idempotency-Key` **可选**。未提供时保持现有行为：每次合法 POST 都创建新的 Event；提供时启用强幂等语义。

当前绿色基线：

- `main@78ace320b0dc49ebb600381b5802e5c5eaca5e41`
- post-merge CI `33359084169 — PASS`
- Issue #22 已关闭，PR #23 已 squash merge。

Coding Agent 开始前必须重新确认最新 `main` 与 CI；失败则 `BLOCKED`。

---

## API 语义

请求：

```http
POST /api/events
Idempotency-Key: <optional>
Content-Type: application/json

{
  "type": "order.paid",
  "payload": { ... }
}
```

### 无 Idempotency-Key

保持当前兼容语义：

```text
same request body
POST #1 → E1 / 201
POST #2 → E2 / 201
E1 != E2
```

现有“identical event posts create distinct events”回归必须继续通过。

### 有 Idempotency-Key

第一次同 key + 同逻辑请求：

```text
→ 创建 E1
→ 匹配当前 subscriptions
→ 创建对应 primary Deliveries
→ 创建对应 Outbox attempt:1 intents
→ 201 Created
```

再次同 key + 同逻辑请求：

```text
→ 不创建新 Event
→ 不重新匹配 subscriptions
→ 不创建新 Delivery
→ 不创建新 Outbox
→ 返回原 E1
→ 200 OK
```

Application result 必须显式表达 `created=true|false`，不要由 Controller 事后猜测。

---

## 同 key 不同请求必须冲突

如果一个 key 已绑定到请求 A：

```text
key=A
body fingerprint=F1
→ E1
```

之后同 key 发送不同 event type 或不同 payload：

```text
key=A
body fingerprint=F2
F2 != F1
```

必须：

```text
409 Conflict
code = idempotency_key_conflict
```

不得返回 E1 假装成功，也不得创建 E2。

---

## Key validation 与泄漏规则

Header 存在时采用与 Replay 一致的边界：

```text
1..128 chars
[A-Za-z0-9._:-]
```

blank / invalid / overlong：

```text
422
code = invalid_idempotency_key
```

Header 缺失不是错误。

禁止保存或输出 raw Idempotency-Key：

- DB；
- logs；
- API response；
- Event / Delivery / Outbox；
- Redis Job；
- Runtime evidence；
- PR body / 开发记录。

可以只保存稳定 digest，例如：

```text
sha256("event-ingress\n" + rawKey)
```

真实 Runtime key 只写 `REDACTED`。

---

## Request fingerprint v1

同 key 是否为“同一逻辑请求”不能只比较 key。

建立稳定的 request fingerprint v1：

```text
sha256(
  "v1\n"
  + eventType
  + "\n"
  + canonicalPayloadJson
)
```

`canonicalPayloadJson` 必须基于**已解析/已验证 payload**，规则锁定为：

- JSON object key 递归按字典序排序；
- array 元素顺序保持；
- scalar 类型和值保持；
- 不依赖请求原始 JSON 的空格、缩进或 object key 输入顺序；
- 输出无额外格式化空白；
- 保留现有 `{}` / nested `{}` object shape 语义。

因此以下应视为同一请求：

```json
{"a":1,"b":2}
{"b":2,"a":1}
```

但 array 顺序变化、type 变化或值变化必须产生不同 fingerprint。

为 fingerprint 增加固定 deterministic test vector；expected value 不得由测试中同一实现即时计算。

---

## 持久化与事务边界

推荐建立独立的 ingress idempotency persistence，例如：

```text
event_ingress_idempotencies
```

至少能够表达：

- key digest（唯一）；
- request fingerprint；
- 对应 Event；
- 创建时间。

具体 schema/claim 顺序可根据现有 Repository / MySQL 约束选择，但必须满足：

1. keyed request 的 Event、matching Deliveries、Outbox intents、idempotency binding 处于同一原子 MySQL 成功/失败边界；
2. 事务失败不得留下 orphan key claim；
3. 也不得留下“Event 已提交但 key 没绑定”的 publication/identity gap；
4. Redis/HTTP 不进入该 transaction；
5. 不修改历史 migration，只新增 migration；
6. 不依赖 application-only check 代替 DB unique constraint。

如果采用“先创建 loser Event、unique conflict 后回滚再读 winner”等实现，必须明确证明整个 loser transaction 的 Event/Delivery/Outbox 全部回滚。

---

## 并发与 MySQL REPEATABLE READ

必须有真实 MySQL 双进程/独立连接 + barrier 回归。

### Same key + same request

两个进程同时：

```text
key=A
fingerprint=F1
```

最终：

```text
Event rows = 1
matching primary Deliveries = exactly once
Outbox attempt:1 intents = exactly once
两个调用得到同一 Event UUID
```

### Same key + different request

两个进程同时复用同 key，但 fingerprint 不同：

```text
A: key=K, F1
B: key=K, F2
```

最终：

```text
只有一个 Event 赢得 key
另一个返回 idempotency_key_conflict
不能产生第二组 Deliveries / Outbox
```

unique-conflict winner recovery 必须使用 locking/current read 或同等级方案，不能重犯历史 REPEATABLE READ stale snapshot 问题。

---

## Idempotent result 必须稳定

一旦：

```text
key=A → E1
```

以后 subscriptions、Endpoint 状态、URL、signing key 等外部配置发生变化，同 key + 同 fingerprint 重试仍必须：

```text
返回原 E1
不重新匹配 subscriptions
不新增/修改 E1 的 Deliveries
不新增 Outbox
```

只有新的 key 或无 key 请求才按当前 subscription/config 创建新的 Event/Delivery 集合。

这与 Replay 已锁定的“已提交幂等结果优先于后续外部状态变化”原则一致。

---

## Transactional Outbox

首次 keyed Event 创建继续复用当前：

```text
Event
+ matching primary Deliveries
+ delivery:{uuid}:attempt:1 Outbox
```

同一 MySQL transaction。

Redis 完全停止时：

```text
POST /api/events + key=A
→ 201
Event / Deliveries / Outbox / idempotency binding 均持久化
Redis Job = 0
```

随后同 key 重试：

```text
→ 200 same Event
→ 不产生第二份 Outbox
```

Redis 恢复后由现有 `outbox:publish` / worker 执行。

---

## Rollback / failure semantics

必须增加回归：

如果 Delivery/Outbox persistence 在首次 keyed request 中失败：

```text
Event = 0
new Delivery = 0
new Outbox = 0
idempotency binding = 0
```

随后相同 key 在故障消除后可以正常创建 Event；不能因 orphan key 永久卡死。

Known/unknown failure 继续遵循现有规则：不要 `catch(Throwable)` 把 programming error 伪装成幂等冲突。

---

## 数据保留语义

第一版 idempotency binding **永久保留**，不实现 TTL/expiry/cleanup。

因此同一个 key 一旦成功绑定，就不能用于不同请求。

TTL、producer namespace、auth/multi-tenancy 在后续独立设计。

---

## Acceptance Criteria

| ID | 要求 |
|---|---|
| AC-01 | 无 key 时保持旧行为：相同 POST 创建不同 Events |
| AC-02 | 同 key + 同 request：首次 201，后续 200 same Event，Delivery/Outbox 不重复 |
| AC-03 | 同 key + 不同 request fingerprint：409 `idempotency_key_conflict` |
| AC-04 | key validation 与 raw-key no-leak 全部通过 |
| AC-05 | fingerprint v1 对 object key order 稳定、array order 敏感，并有固定 test vector |
| AC-06 | Event + Deliveries + Outbox + idempotency binding 原子提交/回滚 |
| AC-07 | 双 MySQL process same-key/same-request 只创建一个 Event graph |
| AC-08 | 双 MySQL process same-key/different-request 只有一个 winner，另一个 conflict |
| AC-09 | subscription/Endpoint 后续变化不改变已提交 same-key 结果 |
| AC-10 | Redis down 时首次 keyed POST 仍成功，same-key retry 不重复，恢复后可执行 |
| AC-11 | Replay、Outbox、Retry、Stale、HMAC、SSRF、primary Delivery invariants 全部不回归 |
| AC-12 | repository-native task/evidence traceability 按 `docs/tasks/0024-event-ingress-idempotency/` 落地 |

---

## Out of Scope

本 Issue 不实现：

- Idempotency-Key 必填；
- idempotency TTL / cleanup；
- producer namespace；
- auth / RBAC / multi-tenancy；
- Replay idempotency 重新设计；
- bulk ingest；
- DLQ；
- Outbox daemon；
- RabbitMQ / Kafka；
- exactly-once；
- Runtime harness 重构；
- Dashboard/UI。

---

## Delivery / Review

建议分支：

`feature/event-ingress-idempotency`

Draft PR：

`Closes #24`

首个功能 commit 必须同时包含：

- production code；
- tests；
- `docs/tasks/0024-event-ingress-idempotency/ISSUE.md`；
- `docs/tasks/0024-event-ingress-idempotency/CODEX.md`；
- `docs/开发记录.md` 的索引条目。

`EVIDENCE.md` 只记录 `validated_implementation_head` 与 AC/Runtime 证据；current PR HEAD / latest CI 只写 PR Body 和最终报告，禁止为了同步最终 CI 制造自引用 docs commit。

Independent Review 前最终状态只能是：

`INCOMPLETE`

不要自行 Merge。
