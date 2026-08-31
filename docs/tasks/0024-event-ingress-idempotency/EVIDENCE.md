# Issue #24 Evidence

validated_implementation_head: `1f1bab579af9713e3c470de772b307923fee611a`

## Acceptance Matrix

- AC-01: PASS — `EventIngressIdempotencyApiTest` 证明无 `Idempotency-Key` 的相同请求仍分别返回 201 并创建两个 Event graph。
- AC-02: PASS — 同 key、同逻辑请求返回 201 后 200 与同一 Event UUID；Event、binding、primary Delivery 与 attempt:1 Outbox 均仅一条。
- AC-03: PASS — 同 key 的 event type 或 payload 变化均返回 409，`code=idempotency_key_conflict`，不产生第二个 graph。
- AC-04: PASS — blank、非法字符、超长 key 返回 422 `invalid_idempotency_key`；raw key 不在 API、Event/binding/Delivery/Outbox 行或真实 Redis serialized Job 中。生产路径没有记录该 raw key 的日志调用。
- AC-05: PASS — Unit fixed vectors 锁定 `event-ingress\nevt-test-001` 的 digest 与 v1 fingerprint；对象键递归排序、数组顺序敏感，空对象保持 object shape。
- AC-06: PASS — binding 在 Event 后、matcher 前插入；强制 Outbox persistence unknown failure 时 Event、binding、Delivery、Outbox 全部回滚，故障解除后同 key 可以正常创建。
- AC-07: PASS — Docker MySQL/InnoDB + `pcntl_fork` + socket barrier：同 key/同请求的两个独立进程返回同一 Event UUID，最终 Event、binding、Delivery、Outbox 各一。
- AC-08: PASS — 同一真实并发 Gate 中，同 key/不同请求得到一个 success 与一个 `idempotency_key_conflict`，只保留一个 winner graph。
- AC-09: PASS — 自动化与 R-02 证明：同 key 重试不重新匹配订阅；新 key 才按变更后的订阅创建新 graph。
- AC-10: PASS — R-01 物理停止 Redis 后首次 keyed POST 仍为 201，重复为 200；MySQL durable graph 完整。Redis 恢复后 `outbox:publish` 成功发布一个真实 job。
- AC-11: PASS — 本机与 Docker 完整 `composer quality` 均通过，涵盖 Replay、Outbox、Retry、Stale、HMAC、SSRF/DNS、并发与队列既有回归。
- AC-12: PASS — `ISSUE.md`、`CODEX.md` 与本 `EVIDENCE.md` 位于仓库原生任务目录；开发记录仅保留索引与摘要。

## Automated Gates

Targeted:

```text
php artisan test tests/Unit/Application/Event/EventIngressRequestFingerprintTest.php tests/Feature/Api/EventIngressIdempotencyApiTest.php tests/Feature/Api/EventReceiveApiTest.php tests/Feature/Api/EventDeliveryMatchingTest.php
PASS — 25 tests / 230 assertions

docker compose exec -T app php artisan test tests/Feature/Queue/DeliveryQueueRedisIntegrationTest.php tests/Feature/Event/EventIngressIdempotencyConcurrencyTest.php tests/Feature/Api/EventIngressIdempotencyApiTest.php
PASS — 19 tests / 180 assertions
```

MySQL concurrency:

```text
docker compose exec -T app php artisan test tests/Feature/Event/EventIngressIdempotencyConcurrencyTest.php tests/Feature/Api/EventDeliveryMatchingConcurrencyTest.php tests/Feature/Api/DeliveryConcurrencyTest.php tests/Feature/Delivery/DeliveryReplayConcurrencyTest.php tests/Feature/Outbox/DeliveryOutboxTest.php tests/Feature/Outbox/DeliveryOutboxBrokerLossRecoveryTest.php
PASS — 26 tests / 246 assertions
```

composer quality:

```text
PASS — Pint、PHPStan、Deptrac、Deptrac negative validation、PHPUnit。
本机：201 tests，176 passed，1033 assertions，25 skipped（SQLite 环境不具备 MySQL/Redis/pcntl 专项）。
```

Docker composer quality:

```text
PASS — Pint（254 files）、PHPStan、Deptrac、Deptrac negative validation、PHPUnit。
Docker PHP 8.5 / MySQL / Redis：201 passed，1322 assertions。
```

## Runtime

### R-01 — Redis physical outage

- `migrate:fresh`、匹配 subscription 后停止 Docker Redis。
- 首次 `POST /api/events`（`Idempotency-Key: REDACTED`）返回 201，Event `57657228-0630-4575-8525-76d03b01368f`。
- 同 key、同 body 重试返回 200，Event UUID 相同。
- Redis 不可用时 MySQL 查询：Event=1、binding=1、Delivery=1、Outbox=1。
- 重启 Redis 后 `php artisan outbox:publish --limit=100`：成功 1、Redis 发布失败 0；真实前缀队列 `eventrelay-database-queues:deliveries` 长度为 1，payload 仅含 Delivery UUID 与 Laravel queue metadata。

### R-02 — 配置变化后的稳定历史结果

- Endpoint A `00720c03-ac6e-41ca-8166-99030999c1d9` 初始订阅；key A（`REDACTED`）首次创建 Event `f1ec17c6-f685-4217-a70e-71cd8820f627`，HTTP 201。
- 订阅改为 A 移除、Endpoint B `ec2b7d20-856f-4942-8ccc-87ff34fe00d8` 增加后，同 key 重试返回 200 与同一 Event；其 Delivery 仍只指向 A。
- 新 key B（`REDACTED`）创建 Event `98018d12-ed5b-4e6c-aa6b-d62f1a189557`，HTTP 201；其 Delivery 只指向 B。
- 最终 MySQL：Events=2、bindings=2、Deliveries=2、Outbox=2。

## Risks

- Idempotency binding 第一版永久保留，没有 TTL 或 cleanup。
- 当前没有 producer namespace 或 auth；key scope 是全局 Event ingress。
- EventRelay 整体仍为 at-least-once；Outbox/Redis broker 的重复 publication 仍由 Delivery atomic claim 与接收方幂等处理。

## NOT RUN

- GitHub PR CI（外部事实，创建 Draft PR 后在 PR Body 记录）。
- Independent Review。
