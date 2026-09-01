# EventRelay Issue #28 — CODEX Execution Contract

> **唯一执行合同**
>
> 严格读取并执行本文件，不要依赖聊天历史补全要求。
>
> GitHub Issue：`#28`
> https://github.com/taoking/EventRelay/issues/28
>
> 目标分支：`feature/rabbitmq-delivery-transport`
>
> Draft PR 必须 `Closes #28`。
> **不要 Merge，不要 Ready for review。**
>
> Independent Review 前最终状态只能是 `INCOMPLETE`。

## 0. Baseline Gate

开始前重新确认：

```text
main@f5a60a4df2842f6e63178178058e58b4791d718a
post-merge CI 33382822797 = PASS
Issue #26 = closed
Issue #28 = open
```

如 main 已前进，只允许更新且 CI PASS 的绿色 main。

失败：

```text
BLOCKED
```

## 1. Goal

完成一次真正的 broker abstraction：

```text
MySQL Outbox
= durable scheduling truth

Transport
= immediate UUID execution transport
├── Redis
└── RabbitMQ
```

核心变化：

```text
before:
DeliveryQueue.enqueue()
DeliveryQueue.schedule()

after:
DeliveryTransport.publish()
```

业务未来时间 `available_at` 不再交给 Broker。

## 2. Read First

编码前阅读：

```text
AGENTS.md
mandatory docs
Issue #28
plan.md
docs/开发记录.md

docs/tasks/0020... Outbox
docs/tasks/0022... Replay
docs/tasks/0024... Ingress Idempotency
docs/tasks/0026... DLQ

DeliveryQueue
DeliveryQueueUnavailable
LaravelRedisDeliveryQueue
ProcessDeliveryJob
DeliveryQueueServiceProvider

PublishDeliveryOutbox
DeliveryOutboxPublisherRepository
EloquentDeliveryOutboxPublisherRepository
DeliveryOutboxWriter/Recovery
Outbox commands/tests

ProcessPendingDelivery
EloquentDeliveryExecutionRepository
RetryPolicy
RecoverStaleDelivery

docker-compose.yml
.env.example
config/queue.php
CI workflow
composer.json
```

先输出：

```text
current publish path
current delayed retry path
target broker-neutral path
AC → code → tests → runtime
```

合同冲突则 `BLOCKED`，否则连续执行。

## 3. Acceptance Matrix

| ID | 必须证明 | 最低证据 |
|---|---|---|
| AC-01 | due-aware Outbox，future intent due 前不 publish | MySQL/FrozenClock |
| AC-02 | Application transport 只有 immediate UUID publish | architecture |
| AC-03 | Redis default transport 兼容、UUID-only | Redis integration |
| AC-04 | Rabbit durable exchange/quorum queue/persistent UUID envelope | Rabbit integration |
| AC-05 | confirms + mandatory/unroutable；failure 不误标 published | Rabbit integration |
| AC-06 | manual ack consumer strict envelope，只调用 ProcessPendingDelivery | Consumer tests |
| AC-07 | unknown consumer crash/redelivery/stale 不产生并发第二 Attempt | MySQL+Rabbit barrier |
| AC-08 | outbox:work bounded/sleep/once/graceful stop | command tests |
| AC-09 | Redis/Rabbit outage 后 durable intent 恢复 | Runtime |
| AC-10 | confirmed publish→mark missing duplicate被 claim 吸收 | Rabbit crash regression |
| AC-11 | Retry 10s/60s/max3 两 transport 不变 | timing tests/runtime |
| AC-12 | mode switch 不双写、互不强依赖 | config/integration |
| AC-13 | Rabbit payload/log/evidence 无敏感数据 | security |
| AC-14 | Docker/CI Rabbit4.3+MySQL+Redis + R01..R05 | runtime/CI |
| AC-15 | Replay/Ingress/DLQ/Outbox/Retry/Stale/HMAC/SSRF 回归 | full quality |
| AC-16 | repository-native traceability + single push | Git/PR |

任何 FAIL：不得声称完成。

## 4. Locked Architecture

### 4.1 MySQL owns scheduling

`DeliveryExecutionIntent.availableAt` / Outbox `available_at` 保留。

Publisher repository 只 claim：

```text
(status=pending OR expired publishing lease)
AND
(available_at IS NULL OR available_at <= now)
```

注意括号优先级必须正确。

future rows：

```text
status=pending
publication_attempts unchanged
claim_token null
```

不能先 claim 再“发现太早”release。

### 4.2 Transport only immediate

推荐：

```php
interface DeliveryTransport
{
    public function publish(DeliveryId $deliveryId): void;
}
```

不要让 Application contract出现：

```text
RabbitMQChannel
AMQPMessage
Redis
Laravel Job
delay()/schedule()
```

### 4.3 Business retry stays Application/MySQL

保持：

```text
HTTP 500 / timeout / network / 408 / 429 / 5xx
→ Delivery retry_scheduled
→ next_attempt_at
→ Outbox available_at
```

RabbitMQ consumer 不计算 10s/60s，不增加 Broker business retry counter。

## 5. Due-aware Outbox Regression

必须覆盖：

### T-01 retry attempt2 not early

FrozenClock：

```text
Attempt1 at 12:00:00 → 500
next=12:00:10
Outbox attempt2 available_at=12:00:10
```

在：

```text
12:00:09 → PublishDeliveryOutbox = 0
12:00:10 → = 1
```

Redis/Rabbit 两模式都验证 physical transport message count。

### T-02 attempt3 60s

同样证明 60s。

### T-03 outage crosses due

Broker down until T+N：

恢复时 publisher 立即 publication，不重新增加 delay。

### T-04 expired publishing lease future row

即使 lease 已 expired，但 `available_at > now` 也不得 reclaim。

## 6. Outbox Worker

新增：

```bash
php artisan outbox:work
```

推荐 options：

```text
--limit=100
--sleep=1
--once
```

要求：

- limit 复用现有合法范围；
- sleep >= 合理下限，invalid fail fast；
- 每轮调用 `PublishDeliveryOutbox`；
- `--once` 恰好一轮；
- continuous mode idle sleep；
- 不因 published=0 做 busy loop；
- graceful SIGTERM/SIGINT；
- unknown exception 终止 non-zero，不 catch 后继续；
- known transport unavailable 由 PublishDeliveryOutbox 正常计数后继续循环。

不要修改 `outbox:publish` 的一次性运维语义。

## 7. Redis Transport Migration

将 `LaravelRedisDeliveryQueue` 演进/重命名成 transport 实现。

要求：

```text
publish(UUID)
→ immediate ProcessDeliveryJob
```

删除 production 对 delayed Job 的使用。

保留：

- `$tries=1`；
- UUID-only；
- duplicate physical Jobs；
- known Redis exception translation；
- unknown propagate；
- 不清理 queue unique lock（已经没有）。

默认：

```text
DELIVERY_TRANSPORT=redis
```

现有无 RabbitMQ 环境的测试仍可启动。

## 8. RabbitMQ Dependency

优先使用：

```text
php-amqplib/php-amqplib ^3.7
```

不要使用不维护的 Rabbit delayed-message plugin。

依赖升级必须：

```text
composer.lock committed
PHP 8.5 compatibility PASS
composer audit 如仓库已有/可合理执行
```

不要引入 Laravel RabbitMQ queue package把 Infrastructure contract重新绑死 Laravel Queue。

## 9. RabbitMQ Topology

默认配置：

```text
exchange=eventrelay.delivery
type=direct
durable=true

queue=eventrelay.deliveries
durable=true
exclusive=false
auto_delete=false
x-queue-type=quorum

routing_key=delivery.process
```

Topology declaration必须幂等。

RabbitMQ 4.3 Docker service加入：

```text
docker-compose.yml
CI services
```

加 healthcheck。

不要求 multi-node quorum HA；单节点 Docker quorum仅用于协议/行为集成测试。

## 10. Rabbit Publisher

### Envelope

canonical JSON：

```json
{"v":1,"type":"delivery.process","delivery_id":"<uuid>"}
```

建议固定 encoding flags，禁止 pretty-print。

properties至少：

```text
content_type=application/json
delivery_mode=2 persistent
type=delivery.process（如使用）
message_id 可用 delivery UUID，但不能被错误当 exactly-once identity
```

### Confirm contract

Outbox success必须基于 broker confirm。

必须测试：

```text
confirm ack → success
confirm nack → transport unavailable
connection close before confirm → unavailable
channel close → unavailable
mandatory unroutable → unavailable
```

不要只以 `basic_publish()` 不抛异常就认为 success。

如果 php-amqplib callback/return 与 confirm 顺序复杂，写 focused adapter tests 锁死。

### Known vs unknown exceptions

定义 transport-level known failure，例如复用/改名：

```text
DeliveryTransportUnavailable
```

Known Rabbit connection/channel/confirm/unroutable：
→ known unavailable。

其它 coding/programming exception：
→ propagate。

日志只记录：

```text
delivery_id
transport=rabbitmq
exchange/queue/routing key（非 secret）
exception class
```

禁止 body/password。

## 11. Rabbit Consumer

命令：

```bash
php artisan deliveries:consume-rabbitmq
```

options建议：

```text
--once
--prefetch=<config/default>
```

### Manual ack

只有以下完成后 ack：

```text
ProcessPendingDelivery returns normally
```

其中包括：

- success；
- known webhook failure已经写 DB；
- retry scheduled；
- duplicate/no-op claim。

### Envelope validation

必须精确：

```text
object keys exactly/至少严格允许 v,type,delivery_id
v === 1
type === delivery.process
delivery_id UUIDv4
```

malformed：

- 不调用 ProcessPendingDelivery；
- reject/no-requeue 或等价 fail-closed；
- 日志不得打印原 payload；
- deterministic test证明不会 poison-loop。

### Unknown exception

如果 `ProcessPendingDelivery` 抛 unknown：

```text
不要 ack
不要转换成成功
消费者停止/连接关闭，使 Broker 可 redeliver
```

如果异常发生在 DB claim后，则 Delivery可能 processing；redelivery会因 claim guard no-op，但原 started Attempt由 stale recovery恢复。

必须真实测试这一窗口。

## 12. Rabbit Redelivery / Quorum Semantics

不要用 RabbitMQ redelivery替代业务 Retry。

Rabbit redelivery只处理：

```text
consumer crash
connection/channel loss
```

可以使用 RabbitMQ 4.3 quorum queue的安全 redelivery能力，但不要在 Application里依赖其 retry count实现 10s/60s。

不建立 Rabbit broker DLQ替代现有 operational DLQ。

## 13. Configuration / Provider

`DeliveryQueueServiceProvider` 演进为 config-select binding：

```text
redis → RedisDeliveryTransport
rabbitmq → RabbitMqDeliveryTransport
other → fail fast
```

测试：

```text
redis config + Rabbit down → app正常
rabbitmq config + Redis Queue unavailable → Rabbit path仍能工作
invalid → boot/resolve fail fast
```

注意 Redis可仍作为 cache；“Rabbit mode不依赖Redis Queue”不是要求整个应用不依赖Redis任何功能。

## 14. Broker Switch

明确不做 dual write。

必须 regression：

```text
Outbox row pending
transport=redis → switch rabbitmq before publish
publisher → Rabbit only
```

以及反向。

已 published row不重新镜像另一个 broker。

## 15. Crash / Concurrency Gates

### C-01 Outbox two publishers

保留现有 skip locked / lease。

Rabbit transport 下双进程 claim disjoint。

### C-02 confirm success / mark missing

真实 Rabbit：

```text
publish confirmed
不执行 markPublished
lease expires
republish
```

queue获得 duplicate delivery UUID envelope。

两个 message被 consumer处理：

```text
只有一个真实 Attempt/HTTP execution
```

### C-03 connection loss before confirm

Outbox保持 recoverable，不得 published。

### C-04 consumer crash after claim

用 barrier卡在：

```text
Delivery=processing
Attempt=started
```

consumer进程退出且未 ack。

redelivery不能创建 Attempt2。

随后：

```text
stale recovery
→ abandoned attempt1
→ retry scheduled / outbox
```

按现有 policy恢复。

### C-05 due gate

future Outbox在任何 transport下消息数=0。

## 16. Runtime R-01 — Redis Compatibility

真实 Docker：

```text
DELIVERY_TRANSPORT=redis
```

覆盖：

- Event initial success；
- Retry 500；
- due前 queue=0；
- due后 publish；
- Stale recovery；
- Redis outage / recovery；
- duplicate jobs no duplicate execution。

## 17. Runtime R-02 — Rabbit End-to-End

真实 RabbitMQ：

```text
Event API
→ Delivery/Outbox
→ outbox:publish/worker
→ publisher confirm
→ quorum queue
→ deliveries:consume-rabbitmq --once
→ ProcessPendingDelivery
```

最终 success。

通过 Rabbit API/AMQP test helper确认 envelope只有 UUID字段。

## 18. Runtime R-03 — Rabbit Outage

停止 RabbitMQ后：

```text
Event API 201
Outbox pending
publisher failed known
Delivery pending
```

恢复 Rabbit：

```text
publish confirmed
consume
success
```

## 19. Runtime R-04 — Retry Timing

Rabbit模式：

```text
Attempt1=500
T+9 no Rabbit message
T+10 Rabbit message
Attempt2
Attempt2=500
T+59 no
T+60 message
Attempt3
```

最终 max=3。

不要用实际 sleep 70 秒；使用 FrozenClock +受控 publisher/DB，但 Rabbit physical message必须真实。

## 20. Runtime R-05 — Duplicate Confirm Window

真实 Rabbit duplicate envelope。

验证：

```text
queue physical messages >=2
business Attempt/HTTP execution exactly once
```

## 21. Security / Confidentiality

Rabbit body/logs/config evidence禁止：

```text
Event payload
target URL
failure_message
signing secret/ciphertext
HMAC signature
raw ingress Idempotency-Key
raw replay Idempotency-Key
RabbitMQ password
Outbox claim token
internal DB id
```

Runtime credentials用固定测试账号可以在 compose config存在，但最终报告不要输出 password。

## 22. Mechanical Gate

最终：

```bash
composer quality
docker compose exec -T app composer quality
```

CI必须真实启动：

```text
MySQL 8.4
Redis
RabbitMQ 4.3
```

并运行 Rabbit integration，不得条件 skip。

全量保持：

```text
Pint
PHPStan
Deptrac
negative architecture
Event/Endpoint/Delivery
Retry/Stale
Outbox
Replay
Ingress Idempotency
DLQ
HMAC
SSRF
Redis Queue
Rabbit Transport
```

## 23. Repository-native Traceability

目录：

```text
docs/tasks/0028-rabbitmq-delivery-transport/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

首个功能 commit：

```text
production
tests
composer/docker/CI changes
ISSUE.md
CODEX.md
docs/开发记录.md index
```

推荐：

```text
feat: add rabbitmq delivery transport (#28)
```

不要把长合同复制进 `docs/开发记录.md`。

## 24. Optimized Single-Push Flow

继续：

```text
Explore
→ Implement
→ targeted tests
→ First Functional Commit（不 push）
→ exact commit full validation/runtime
→ EVIDENCE + plan/docs summary commit
→ first/single push
→ Draft PR
→ PR CI
```

CI PASS后只更新 PR Body，不 commit CI号。

如果实现验证后发现代码问题，新的 implementation commit成为新的 validated head并重跑。

## 25. EVIDENCE.md

至少记录：

```text
validated_implementation_head

AC-01..AC-16

Redis targeted
Rabbit targeted
MySQL/Rabbit concurrency
composer quality
Docker quality

R-01..R-05

Rabbit topology
publisher confirm/unroutable evidence
consumer ack/crash evidence
retry due-time evidence

Risks
NOT RUN:
GitHub CI
Independent Review
```

## 26. PR Body

Draft PR至少：

```text
Baseline
broker-neutral scheduling
transport contract
Redis migration
Rabbit topology
publisher confirm
consumer ack/redelivery
outbox worker
config switch
AC-01..AC-16
validated head
current head
latest CI
Runtime R-01..R-05
Risks
Independent Review=NOT RUN
```

不要 Merge。
不要 Ready。

## 27. Scope Guard

禁止：

```text
delayed-message plugin
Broker business retry
Rabbit broker DLQ替代 operational DLQ
dual write
Kafka
cluster/HA production deployment
K8s/Supervisor manifests
metrics/dashboard
auth/RBAC
exactly-once
DLQ lifecycle
unrelated DLQ query optimization
```

## 28. Self Review

提交前确认：

```text
future Outbox真的没有被 claim？
Redis是否完全没有 delayed Job production path？
Rabbit publish是否真的等 confirm？
mandatory unroutable是否失败？
consumer是否 manual ack？
unknown exception是否不会 ack？
malformed message是否不会调用业务？
Rabbit payload是否 UUID-only？
Retry 10/60是否仍由 MySQL控制？
Rabbit outage是否不影响 Event API commit？
duplicate Rabbit message是否不重复业务执行？
transport switch是否无双写？
```

## 29. Final Status

Independent Review前：

```text
INCOMPLETE
```

不要自行 Merge。
不要自行 Ready for review。

## 30. Final Chinese Report

返回：

```text
Issue #28

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

Architecture:
- scheduling truth:
- transport contract:
- Redis:
- Rabbit topology:
- publisher confirm:
- consumer:
- outbox worker:
- config switch:

Acceptance Matrix:
AC-01 PASS — ...
...
AC-16 PASS — ...

Automated:
...

Runtime:
R-01 ...
...
R-05 ...

Traceability:
ISSUE.md committed: YES
CODEX.md committed: YES
EVIDENCE.md: ...
validated_implementation_head: ...

CI optimization:
push count:
CI runs:
extra docs-only CI: NO

Risks / NOT RUN:
...

Independent Review:
NOT RUN

Final status:
INCOMPLETE
```
