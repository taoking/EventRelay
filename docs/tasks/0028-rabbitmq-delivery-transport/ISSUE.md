# Issue #28 — RabbitMQ Delivery Transport

GitHub Issue: https://github.com/taoking/EventRelay/issues/28

## 目标

为 EventRelay 增加 RabbitMQ 执行传输，同时把未来执行时间的调度职责从具体 Broker 收回 MySQL Transactional Outbox。

核心架构：

```text
MySQL
= durable business truth
+ durable execution intent
+ available_at scheduling truth

Delivery Transport
= transient immediate execution transport
├── Redis
└── RabbitMQ
```

当前绿色基线：

- `main@f5a60a4df2842f6e63178178058e58b4791d718a`
- post-merge CI `33382822797 — PASS`
- Issue #26 已 closed，PR #27 已 squash merge。

Coding Agent 开始前必须重新确认最新绿色 main；失败则 `BLOCKED`。

## 关键架构决定

当前 `DeliveryQueue` 同时提供：

```text
enqueue(deliveryId)
schedule(deliveryId, availableAt)
```

本 Issue 演进为：

```text
Outbox.available_at
→ 决定何时允许 publication

Transport.publish(deliveryId)
→ 只负责立即投递 UUID
```

因此：

- future Outbox intent 在 `available_at > now` 时不得被 publisher claim；
- 到期后才 publication；
- Redis 不再承担 delayed scheduling；
- RabbitMQ 不使用 delayed-message plugin；
- Retry/Stale 的 10s/60s 业务时间语义继续以 MySQL `next_attempt_at` / Outbox `available_at` 为准。

## Application contract

将现有 `DeliveryQueue` 演进为只表达立即投递的 transport contract，例如：

```text
DeliveryTransport::publish(DeliveryId): void
```

具体命名可调整。

`PublishDeliveryOutbox`：

```text
claim due Outbox
→ transport.publish(delivery UUID)
→ success: mark published
→ known transport failure: release pending
→ unknown exception: propagate，等待 lease expiry recovery
```

Domain/Application 不得依赖 RabbitMQ/php-amqplib。

## Due-aware Outbox

`claim()` 必须增加：

```text
available_at IS NULL
OR available_at <= now
```

同时适用于 pending 和 expired publishing lease reclaim。

必须证明：

```text
retry scheduled at T
publisher at T-1s → 0
publisher at T → 1
```

Broker outage 跨过 due time后，恢复 Broker 时立即 publication。

## Outbox worker

新增 transport-neutral continuous publisher，例如：

```bash
php artisan outbox:work --limit=100 --sleep=1
```

要求：

- bounded batch；
- 空闲 sleep，禁止 DB busy loop；
- graceful SIGTERM/SIGINT；
- `--once` 或等价 deterministic mode；
- unknown exception 不得吞掉；
- 现有 `outbox:publish` 保留。

不负责 Supervisor/K8s manifests。

## Redis transport

Redis 保留且默认：

```text
DELIVERY_TRANSPORT=redis
```

要求：

- 只发布立即 `ProcessDeliveryJob`；
- UUID-only payload；
- 不再 delayed Job；
- known Redis publication failure → transport unavailable；
- unknown exception propagate；
- duplicate jobs 继续由 Delivery atomic claim 吸收。

## RabbitMQ transport

使用维护中的 AMQP 0-9-1 PHP client，优先 `php-amqplib/php-amqplib` 3.7.x。

Docker/CI 增加 RabbitMQ 4.3。

Topology：

```text
exchange: eventrelay.delivery
type: direct
durable: true

queue: eventrelay.deliveries
durable: true
x-queue-type: quorum

routing key: delivery.process
```

RabbitMQ message body：

```json
{
  "v": 1,
  "type": "delivery.process",
  "delivery_id": "uuid"
}
```

必须 persistent。

禁止包含 Event payload、target URL、HMAC/signing secret、Idempotency-Key、serialized Laravel Job、Outbox/internal DB metadata。

### Publisher acknowledgement

Outbox 只有在 RabbitMQ 明确 confirm 后才能 `markPublished`。

必须：

- publisher confirms；
- mandatory/unroutable detection；
- known connection/channel/confirm/unroutable failure → release pending；
- unknown exception propagate；
- confirmed publish → markPublished crash window继续允许 at-least-once duplicate。

## RabbitMQ consumer

新增：

```bash
php artisan deliveries:consume-rabbitmq
```

要求：

- manual ack；
- bounded prefetch；
- strict envelope；
- 合法消息只调用现有 `ProcessPendingDelivery`；
- business outcome 完成后 ack；
- malformed/unsupported envelope fail closed；
- unknown exception 不得 ack 成功，不得吞掉；
- consumer 可恢复终止/redelivery，并由现有 stale recovery 保护已 processing Delivery；
- 不复制 RetryPolicy / Attempt 状态机；
- `--once` 或等价 deterministic mode。

## Configuration

```text
DELIVERY_TRANSPORT=redis|rabbitmq

RABBITMQ_HOST
RABBITMQ_PORT
RABBITMQ_USER
RABBITMQ_PASSWORD
RABBITMQ_VHOST
RABBITMQ_EXCHANGE
RABBITMQ_QUEUE
RABBITMQ_ROUTING_KEY
RABBITMQ_PREFETCH
```

要求 invalid config fail fast、secret 不泄漏。

Redis 模式不要求 RabbitMQ 可用；RabbitMQ 模式不依赖 Redis Queue。

## Broker switch invariant

不允许 Redis + RabbitMQ 双写同一个 Outbox intent。

Transport 切换前未 published 的 intent 使用当前配置 transport；已 published 的 broker 状态不做迁移。

MySQL 继续是 recovery truth。

## Concurrency / crash gates

必须真实覆盖：

1. 双 publisher claim 不重复持 lease。
2. Rabbit confirm 成功但 DB markPublished 缺失 → lease expiry republish → duplicate UUID message；业务只执行一次。
3. confirm 前 connection loss → Outbox 不得 published。
4. consumer claim 后、finalize 前异常退出 → redelivery/duplicate 不产生并发第二 Attempt；stale recovery 能恢复。
5. future Outbox due 前 Redis/RabbitMQ 都无 message。

真实 MySQL + Redis + RabbitMQ，关键并发使用 process/connection + barrier。

## Runtime

### R-01 Redis compatibility

```text
Event → Outbox → outbox worker/publish → Redis → queue worker
```

成功、Retry、Stale、broker-loss recovery继续工作，retry due 前不 publication。

### R-02 RabbitMQ end-to-end

```text
Event → Delivery + Outbox
→ Rabbit publisher confirm
→ quorum queue
→ Rabbit consumer
→ ProcessPendingDelivery
→ webhook success
```

验证 UUID-only envelope。

### R-03 RabbitMQ outage

RabbitMQ down：

```text
Event API 201
Delivery + Outbox durable
publication known failure
```

恢复后 publication + consumer success。

### R-04 Retry timing

Attempt #1 500：

```text
retry_scheduled
Outbox attempt2 available_at=T
T-before: Rabbit queue no attempt2
T-at/after: publish → consume → Attempt2
```

继续验证 Attempt3/max=3。

### R-05 Duplicate publication

confirmed publish + missing markPublished：

Rabbit receives duplicate UUID messages，但业务 HTTP/Attempt 不并发重复。

## Acceptance Criteria

| ID | 要求 |
|---|---|
| AC-01 | MySQL Outbox `available_at` 是唯一业务 scheduling truth，due 前不 publication |
| AC-02 | Application transport contract 只表达立即 UUID publication |
| AC-03 | Redis default transport 兼容，UUID-only |
| AC-04 | RabbitMQ durable direct exchange + quorum queue + persistent UUID envelope |
| AC-05 | RabbitMQ confirms + unroutable detection；failure 不误标 published |
| AC-06 | Rabbit manual-ack consumer 严格 envelope，只复用 ProcessPendingDelivery |
| AC-07 | unknown consumer exception 不伪装成功；redelivery/stale recovery 不产生并发第二 Attempt |
| AC-08 | Outbox continuous worker bounded/sleep/once/graceful stop |
| AC-09 | Redis/Rabbit outage 后 durable intent 可恢复 |
| AC-10 | confirm→mark-missing duplicate 仍由 Delivery claim 吸收 |
| AC-11 | Retry 10s/60s 与 max=3 在两 transport 下不变 |
| AC-12 | transport switch 不双写；模式互不强依赖 |
| AC-13 | secret/idempotency/Event payload/target URL 不进入 Rabbit message/evidence |
| AC-14 | Docker/CI 真实 RabbitMQ 4.3 + MySQL + Redis integration、并发、R-01..R-05 PASS |
| AC-15 | Replay/Ingress Idempotency/DLQ/Outbox/Retry/Stale/HMAC/SSRF 回归 PASS |
| AC-16 | repository-native ISSUE/CODEX/EVIDENCE + single-push/single-PR-CI 落地 |

## Out of Scope

不实现：

- RabbitMQ 作为 durable business truth；
- delayed-message community plugin；
- Broker-level业务 RetryPolicy；
- Rabbit broker DLQ 替代 failed Delivery DLQ；
- Redis/RabbitMQ 双写；
- Kafka；
- RabbitMQ cluster/HA 生产部署；
- Supervisor/K8s manifests；
- auth/RBAC/multi-tenancy；
- metrics/dashboard；
- exactly-once；
- DLQ acknowledge/archive/retention；
- DLQ query performance 重构。

## Delivery / Review

分支：

```text
feature/rabbitmq-delivery-transport
```

任务目录：

```text
docs/tasks/0028-rabbitmq-delivery-transport/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

首个功能 commit 同时包含 code + tests + ISSUE/CODEX + `docs/开发记录.md` 索引。

本地完整验证 → EVIDENCE/plan commit → 单次 push → Draft PR → PR CI；CI 结果只写 PR Body。

Independent Review 前最终状态只能是：

```text
INCOMPLETE
```

不要自行 Merge，不要自行 Ready for review。
