# Issue #28 — RabbitMQ Delivery Transport 验证证据

## 验证对象

- `validated_implementation_head`：`e38e980dc10f5152f9d9d54416e03e27736550a2`
- 分支：`feature/rabbitmq-delivery-transport`
- 基线：`main@f5a60a4df2842f6e63178178058e58b4791d718a`
- 基线 CI：GitHub Actions `33382822797`，`PASS`
- 本文件只记录验证事实；不包含 Event payload、target URL、签名材料、Idempotency-Key、RabbitMQ 密码、claim token 或内部数值 ID。

## 架构结论

- MySQL `delivery_outbox_messages.available_at` 是唯一的业务调度时间事实源。Publisher 仅 claim `pending` 或过期 lease，且 `available_at IS NULL OR available_at <= now` 的 intent；未来 intent 不会被预先 claim。
- Application `DeliveryTransport` 只表达 `publish(DeliveryId): void` 的即时 UUID publication。它不暴露 RabbitMQ、Redis、Laravel Job 或业务 delay。
- Redis 和 RabbitMQ 均为可选的瞬时传输层；业务 Retry 10 秒 / 60 秒、最大 3 次和 stale recovery 均保持在 MySQL/Application。
- RabbitMQ 使用 durable direct exchange `eventrelay.delivery`、durable quorum queue `eventrelay.deliveries` 与 routing key `delivery.process`。发布 envelope 是固定 UUID-only JSON；Consumer 在 `ProcessPendingDelivery` 正常返回后才 manual ACK。
- 系统仍为 `at-least-once`：confirm 后 mark-published 前崩溃、lease 过期、Broker 状态丢失和 Consumer 崩溃均可能造成重复 Broker message；Delivery atomic claim、Attempt 唯一性和 stale guard 防止并发的第二次业务 HTTP 执行。

## Acceptance Matrix

| ID | 状态 | 证据 |
|---|---|---|
| AC-01 | PASS | `RabbitMqDeliveryTransportTest::test_future_outbox_rows_are_not_claimed_or_published_until_they_are_due` 与 retry 10/60 秒物理 Rabbit message 回归；Docker 实跑。 |
| AC-02 | PASS | `DeliveryTransport` 只有即时 UUID `publish`；`PublishDeliveryOutbox` 在 short claim transaction 外调用传输层；Deptrac/PHPStan PASS。 |
| AC-03 | PASS | `DeliveryQueueRedisIntegrationTest` 继续验证 Redis 默认配置、Outbox 后发布与 UUID-only Redis Job；Docker 实跑。 |
| AC-04 | PASS | `RabbitMqDeliveryTransportTest::test_confirmed_publication_uses_the_canonical_uuid_only_envelope_and_manual_consumer_executes_it` 验证 direct/quorum topology、`content_type=application/json`、persistent delivery mode 与 canonical envelope。 |
| AC-05 | PASS | Publisher 使用 `confirm_select`、`wait_for_pending_acks_returns` 与 mandatory；实际 `amq.direct` 无路由绑定触发 return，连接失败、known unavailable 翻译与 unknown programming error 传播均有回归。 |
| AC-06 | PASS | malformed envelope 被 `reject(false)`，不触发 Application；正常消费只经 `ProcessPendingDelivery` 后 ACK。 |
| AC-07 | PASS | Docker MySQL + RabbitMQ + `pcntl` barrier：Consumer claim/start 后被 `SIGKILL`，Broker redelivery 被 no-op claim 吸收，仍仅有 Attempt #1；随后 stale recovery 产生 attempt #2 intent。 |
| AC-08 | PASS | `WorkDeliveryOutboxCommandTest` 验证 `--once` 仅一轮、limit/sleep 参数 fail fast；实现提供 idle sleep、SIGTERM/SIGINT stop，unknown exception 不吞掉。 |
| AC-09 | PASS | Redis/Rabbit physical outage 的 Docker Runtime 证据见 R-01、R-03；已提交 MySQL Outbox intent 保持 pending/recoverable。 |
| AC-10 | PASS | 实际 Rabbit confirm 后故意跳过 `markPublished`，lease 到期重发两条 physical message；Consumer 处理后仅一条 Attempt 和一次 HTTP mock 调用。 |
| AC-11 | PASS | Redis 既有 retry integration 与 Rabbit FrozenClock physical publication 均覆盖 10 秒、60 秒和最大 3 次；Rabbit test 终态为 Attempt #1/#2=500、Attempt #3=200。 |
| AC-12 | PASS | 同一 pending Outbox 在 publisher 前切为 Rabbit 只写 Rabbit；后续切为 Redis 只写 Redis；无 dual write。invalid transport resolve fail fast，Redis 与 Rabbit 各自 outage 不互相阻断所选路径。 |
| AC-13 | PASS | Rabbit envelope、Application payload、日志 contract 与本文件均只使用 Delivery UUID 和非敏感 topology 元数据；回归拒绝 body/密码日志字段，Queue serialization 继续不含 payload、URL、secret、ciphertext、signature 或 Eloquent graph。 |
| AC-14 | NOT RUN | Docker Compose 已实际启动 MySQL 8.4、Redis、RabbitMQ 4.3 并执行 R-01..R-05；GitHub CI 尚未因本分支尚未推送而运行。 |
| AC-15 | PASS | `docker compose exec -T app composer quality` 通过 229 tests / 1600 assertions，覆盖 Replay、Ingress、DLQ、Outbox、Retry、Stale、HMAC、SSRF、Redis 与 RabbitMQ。 |
| AC-16 | NOT RUN | `ISSUE.md`、`CODEX.md`、首个功能 commit 与本 Evidence 均已在仓库；尚未执行唯一 push、Draft PR 与 GitHub CI。 |

## 自动化验证

| 命令 | 状态 | 结果 |
|---|---|---|
| `composer quality` | PASS | Pint、PHPStan、Deptrac、negative validation 均 PASS；229 tests，192 passed，1180 assertions，37 个依赖 MySQL/Redis/RabbitMQ/pcntl 的环境跳过。 |
| `docker compose exec -T app composer quality` | PASS | Pint、PHPStan、Deptrac、negative validation 均 PASS；229 tests / 1600 assertions。 |
| `docker compose exec -T app php artisan test tests/Feature/RabbitMq/RabbitMqDeliveryTransportTest.php --compact` | PASS | 12 tests / 115 assertions；Rabbit topology、confirm、mandatory return、lease duplicate、switch、Consumer crash/redelivery、due timing 均实际运行。 |
| `composer audit` | PASS | 未发现 advisories；`php-amqplib/php-amqplib` 锁定为 `v3.7.4`。 |

## Runtime

### R-01 — Redis compatibility

`PASS`。Docker Redis integration、Outbox/Retry/Stale/MySQL concurrency 回归在 Redis 默认 transport 下真实运行：初始 publication、HTTP 500 后 retry intent、due 前不发布、due 后即时 publication、stale recovery、Redis known publication failure/recovery 与 duplicate Job 的 Delivery claim 吸收均通过。Redis 生产路径不再使用 Laravel delayed Job；delay 仅由 MySQL `available_at` 决定。

### R-02 — RabbitMQ end-to-end

`PASS`。Docker 运行期创建 Event `e37e464f-330c-4fdf-ba6c-357b8eda77e8`、Delivery `f24d150e-826b-4178-971c-59a6e3359279`、Outbox `1d5d52ff-a2eb-4966-83be-fcd52597cfdc`。`outbox:publish` 成功确认一条 Rabbit message，`deliveries:consume-rabbitmq --once` 后 Delivery 为 `succeeded`，Attempt `42b12f37-2b74-4224-b837-213c5fb11064` 为 `succeeded/204`。

为尊重生产 SSRF global-unicast policy，运行期使用未提交、仅本进程的预验证 resolver harness 指向受控本机 receiver；真正 HTTP 仍由生产 `CurlWebhookTransport` 发出。该 harness 未写入配置、源码或提交。

### R-03 — Rabbit outage and recovery

`PASS`。物理停止 RabbitMQ 后，Event `5108ee7f-1328-4cba-af4d-4809d8b33717` 的 Delivery `c6f78fe0-9d76-4de2-a63c-a8e3cbece6f4` 与 Outbox `070b7a11-81e7-4576-80fd-00c7294a48b0` 已由 MySQL 提交。Publisher 输出 `success=0, failure=1`，Outbox 保持 `pending`、`publication_attempts=1`、`last_error_code=rabbitmq_unavailable`，Delivery 仍 `pending`。恢复 RabbitMQ 并确认健康后，publisher 成功发布，Consumer 成功完成 Attempt #1（204）和 Delivery。

### R-04 — Rabbit retry due timing

`PASS`。`RabbitMqDeliveryTransportTest::test_rabbit_physical_publication_respects_the_mysql_retry_due_times_and_maximum_attempt_budget` 在 Docker MySQL/RabbitMQ 下以 FrozenClock 验证：T+9 Rabbit 队列为 0，T+10 出现 Attempt #2 message；第二次 500 后 T+59 为 0，T+60 出现 Attempt #3 message；最终 Attempt #1/#2 为 500，Attempt #3 为 200，未产生 Attempt #4。

### R-05 — duplicate confirm window

`PASS`。`RabbitMqDeliveryTransportTest::test_confirmed_rabbit_publication_replays_after_a_lease_expires_before_mark_published_and_the_duplicate_is_absorbed_by_the_delivery_claim` 在真实 RabbitMQ confirm 后跳过 mark，lease 到期重发，队列含两条同 UUID envelope；两次 manual consume 后仅一次 HTTP mock、一个 Attempt #1 与 `succeeded` Delivery。独立的 `test_duplicate_confirmed_rabbit_messages_are_absorbed_by_the_delivery_claim_before_a_second_http_execution` 也验证任意重复 confirmed message 的相同边界。

## Publisher / Consumer 专项证据

- 两个独立 `pcntl_fork` MySQL publisher 通过 socket barrier 同时启动，分别 claim 并确认不同 Outbox row；队列最终为两条 message，两个 row 均 `published` 且 `publication_attempts=1`。
- mandatory unroutable 回归使用真实 Rabbit `amq.direct` 与随机无绑定 routing key，收到 broker return；Application transport contract 将同类 confirm/nack/return 精确翻译为 `DeliveryTransportUnavailable`，unknown `LogicException` 不会伪装为 broker unavailable。
- Consumer crash 回归在 transport barrier 后向子进程发出 `SIGKILL`，连接关闭使 Rabbit requeue。第二 Consumer 通过 Delivery DB claim no-op 后 ACK；没有第二 Attempt。60 秒 stale threshold 后现有 recovery 将 Attempt #1 标记为 `abandoned/stale_processing` 并创建 attempt #2 Outbox intent。

## 风险与 NOT RUN

- 风险：仍为 `at-least-once`。Broker publish/consumer crash、lease 到期和 broker state loss 都可能给出重复 Delivery UUID；receiver 仍应使用稳定 Delivery ID 做去重。
- 风险：Docker 的 quorum queue 是单节点协议/行为验证，不代表多节点 RabbitMQ HA 部署。
- NOT RUN：GitHub CI、Draft PR、Independent Review、生产 daemon/scheduler 部署。未实现 delayed-message plugin、Broker business retry、broker DLQ、dual write、XA/2PC 或 exactly-once。
