# Issue #30 — Operational Health and Durable-State Metrics 证据

`validated_implementation_head: 6ada0b20940e07cbb1abdab0ee6ddc71c004e2e1`

## 基线与范围

- 基线：`main@ad02f97f9137f5bd9a0fecd2df82012eac5dd5ff`。
- 基线 CI：`33472861373 — PASS`。
- Issue #28：已关闭；Issue #30：开发开始时保持开放。
- 分支：`feature/operational-health-metrics`。
- 本轮只增加 internal operations 健康与 durable-state metrics；未增加 Grafana、Alertmanager、OpenTelemetry、in-memory counter、Prometheus 容器、RBAC、per-endpoint/event 指标或 broker direct ping metrics。

## 实现与修复摘要

- `GET /internal/health/live`、`/ready`、`/metrics` 不属于 `/api/*`。它们通过 `EnsureOperationsAccess` 执行默认 404、启用后 Bearer token 的 constant-time 校验及空 token fail-fast。
- internal routes 从 `web` middleware group 分离，避免数据库 session middleware 破坏 live 的“无依赖”合同；自动回归锁定没有 `StartSession`。
- MySQL 是唯一的 collector/readiness durable truth。renderer 不访问数据库，controller 不直接访问 Eloquent。
- Docker PHP 8.5 镜像补齐 `sockets`（以及编译所需 `linux-headers`），恢复 RabbitMQ runtime 与 Composer platform contract；Compose 的 `DELIVERY_TRANSPORT` 改为显式可切换，默认仍为 `redis`。
- R-04 物理 Redis outage 暴露 Predis `TimeoutException` 未在已有“明确 Redis publication failure”白名单内；已最小补齐该具体类型并增加回归。未知编程异常仍不捕获。

## Acceptance Matrix

| AC | 状态 | 证据 |
|---|---|---|
| AC-01 | PASS | 默认 disabled=404；enabled 但 missing/wrong Bearer=401 generic；正确 token=200；enabled+empty token bootstrap 失败。token 仅以 `REDACTED` 形式处理。 |
| AC-02 | PASS | live 只返回 `{"status":"alive"}`；自动 fake readiness throw 与 Docker MySQL stop 均证明 live=200。 |
| AC-03 | PASS | MySQL stop 时 ready=503、metrics=503；恢复后两者均=200。 |
| AC-04 | PASS | Rabbit/Redis 物理 stop 时 ready=200、POST Event=201；Event/Delivery/Outbox durable 提交并经恢复 publisher/worker 完成。 |
| AC-05 | PASS | `text/plain; version=0.0.4; charset=utf-8`，8 个 HELP/TYPE family，稳定顺序、UTF-8/LF/final LF 自动与 Docker runtime 均验证。 |
| AC-06 | PASS | 从 `DeliveryStatus` 读取 `pending`、`processing`、`retry_scheduled`、`succeeded`、`failed`，所有 series（含 0）稳定输出。 |
| AC-07 | PASS | Outbox due/oldest age 复用 `EloquentDeliveryOutboxDueQuery`；真实 MySQL + FrozenClock A–F collector/production claim 等价。 |
| AC-08 | PASS | retry/stale 使用 `EloquentDueRetryQuery`、`EloquentStaleDeliveryQuery` 与 `RecoverStaleDelivery::StaleThresholdSeconds`；before/at/after 等价回归通过。 |
| AC-09 | PASS | dead letters 直接取 grouped `Delivery.status=failed`，未 join Attempt、未增加 lifecycle。 |
| AC-10 | PASS | labels 仅为 bounded `transport`、`status`；自动和 Runtime 均确认无 ID、event_type、target、error、secret、token、claim token 等 labels。 |
| AC-11 | PASS | 每次 scrape 固定 5 个 aggregate query；无 model materialization/N+1；Docker MySQL EXPLAIN 见下。 |
| AC-12 | PASS | query log 回归确认 5 query 且 Delivery/Attempt/Outbox/Redis/Rabbit 前后不变；没有 claim/recover/enqueue/publish。 |
| AC-13 | PASS | R-01 至 R-05 均在真实 Docker service outage/恢复中完成。 |
| AC-14 | PASS | 本机 `composer quality` 与 Docker `composer quality` 通过；含 Replay/Ingress/DLQ/Outbox/Retry/Stale/Rabbit/Redis/HMAC/SSRF 历史回归。 |
| AC-15 | PASS | ISSUE/CODEX 与首个功能提交同在仓库；本文件记录 validated head。single push、Draft PR、PR CI 在本 evidence 提交后执行。 |

## 自动化验证

| 命令 | 状态 | 结果 |
|---|---|---|
| `php artisan test tests/Feature/Operations tests/Unit/Infrastructure/Operations` | PASS | 13 tests / 100 assertions。 |
| `docker compose exec -T app php artisan test tests/Feature/Operations tests/Unit/Infrastructure/Operations tests/Feature/Queue/DeliveryQueueRedisIntegrationTest.php` | PASS | 22 tests / 170 assertions；operations tests 未 skip。 |
| `composer quality` | PASS | Pint、PHPStan、Deptrac、negative validation；249 tests，209 passed，1298 assertions，40 host SQLite/external-service 条件 skip。 |
| `docker compose exec -T app composer quality` | PASS | Pint（316 files）、PHPStan、Deptrac、negative validation；249 tests / 1749 assertions，无 skip。 |

额外 Redis timeout 回归：`DeliveryQueueRedisIntegrationTest::test_real_publisher_translates_predis_timeouts_as_recoverable_publication_failures` 在 Docker MySQL/Redis 中 PASS；它锁定 known Redis timeout 会保留 Outbox 可恢复，而不是泄漏为未知异常。

## Query Plan（Docker MySQL）

针对真实 `eventrelay` schema 运行 `EXPLAIN`：

| 查询 | chosen key / rows | Extra | 结论 |
|---|---|---|---|
| delivery `GROUP BY status` | `deliveries_due_retry_index` / 1 | `Using index` | 覆盖索引 scan，无 temporary/filesort。 |
| outbox `GROUP BY status` | `delivery_outbox_claim_index` / 1 | `Using index` | 覆盖索引 scan，无 temporary/filesort。 |
| outbox due `COUNT/MIN` | `delivery_outbox_claim_index` range / 2 | `Using index condition; Using where` | 与共享 due predicate 一致；无 temporary/filesort。 |
| retry due | `deliveries_due_retry_index` / 1 | `Using where; Using index` | status+next_attempt_at 条件由现有索引覆盖。 |
| stale candidates | deliveries `deliveries_due_retry_index` ref / 1；两层 Attempt subquery 均 `delivery_attempts_delivery_id_attempt_number_unique` ref / 1 | `Using where`、latest/newer 均 index lookup | 相关 latest-attempt 判断走既有唯一索引；无 temporary/filesort。 |

真实数据量很小但五条计划均使用已有索引，未发现必须新增 migration/index 的证据；因此未引入无关 schema 变化。

## Runtime

所有 Runtime token 为短生命周期 fixture，文档中均为 `REDACTED`；没有将 token、Authorization、secret 或 ciphertext 写入本文件。

### R-01 — Healthy / Auth / Prometheus

- enabled + correct Bearer（`REDACTED`）：`/live=200`、`/ready=200`、`/metrics=200`。
- metrics Content-Type：`text/plain; version=0.0.4; charset=utf-8`；两次 body byte-for-byte 相同，末字节 `0a`，8 个 HELP 与 8 个 TYPE family。
- live body：`{"status":"alive"}`；ready body：`{"status":"ready","checks":{"mysql":"up"}}`。
- 成功 Delivery/Outbox 后仍输出所有 bounded enum series；敏感/高基数 label scan 为 none。

### R-02 — Physical MySQL Outage

- `docker compose stop mysql` 后：`live=200`，`ready=503` body `{"status":"not_ready","checks":{"mysql":"down"}}`，`metrics=503` generic `Service unavailable.`。
- `docker compose start mysql` 后无需重启 app：ready=200，metrics=200。

### R-03 — RabbitMQ Physical Outage 与恢复

- Rabbit transport 下物理 stop RabbitMQ；ready=200。
- Event `9643b05b-bc1c-464e-b929-179f13bb99a7`：POST=201；Delivery `c713ed5d-c9bb-4e98-b084-2695866e044f`；Outbox `479f9da8-1e85-4402-a998-07981d1bc0de`。
- outage publisher：success=0/failure=1；MySQL 为 Delivery `pending`、Outbox `pending`、`publication_attempts=1`、`last_error_code=rabbitmq_unavailable`；`eventrelay_outbox_due_pending 1`。
- Rabbit 恢复后 publisher success=1；真实 Rabbit consumer 通过仅运行期、未提交的 prevalidated resolver harness 使用生产 `CurlWebhookTransport` 调用受控 receiver。receiver 请求数=1，Attempt #1=`succeeded/204`，Delivery=`succeeded`，due backlog=0。

### R-04 — Physical Redis Outage 与恢复

- Redis transport 下物理 stop Redis；ready=200。
- Event `631c1fda-d224-49bb-bb2a-1465515d429e`：POST=201；Delivery `95dbf4e9-9630-4455-aef1-ac36d9c54bf0`；Outbox `a36b13d0-9008-40c1-94ee-48331bd9df75`。
- outage publisher：success=0/failure=1；MySQL 为 Delivery `pending`、Outbox `pending`、`publication_attempts=1`、`last_error_code=redis_unavailable`；`eventrelay_outbox_due_pending 1`。
- Redis 恢复后 publisher success=1；Redis physical queue 长度=1。真实 Laravel Redis Worker 的 `runNextJob(redis, deliveries)` runtime harness 只替换 resolver、仍使用生产 `CurlWebhookTransport`；receiver 请求数=1，Attempt #1=`succeeded/204`，Delivery=`succeeded`，due backlog=0。
- 运行期发现并修复明确的 `Predis\TimeoutException` translation 缺口；修复后重新执行全链路，本条证据为最终 PASS 结果。

### R-05 — Disabled / Authentication / Leak

- `OPERATIONS_ENDPOINTS_ENABLED=false`：live=404。
- enabled + no Authorization=401 generic `{"message":"Unauthorized."}`；wrong Bearer=401；correct Bearer（`REDACTED`）=200。
- enabled + empty token：独立 Docker application bootstrap exit=1，出现固定配置错误；没有降级为无鉴权 endpoint。
- response、metrics 与 `storage/logs` 检索均未发现 runtime token fixture。

## 自审与风险

- readiness 刻意不 probe Redis/Rabbit；broker availability 不是 durable ingress readiness 的条件。
- metrics 是 MySQL point-in-time aggregate，不承诺跨五条 query 的 serializable global snapshot；未知 persisted status 或 MySQL 读取失败 fail closed 为 503，不输出动态/伪零 metrics。
- Outbox/Rabbit/Redis 的系统语义仍为 `at-least-once`，receiver 仍应使用稳定 Delivery ID 去重；本轮没有宣称 exactly-once。
- 运行期 prevalidated receiver harness 仅存在于执行进程，已删除，未写入生产代码、配置或提交；生产 SSRF/DNS pinning/TLS/proxy policy 未变化。
- NOT RUN：GitHub Draft PR CI、Independent Review、生产 daemon/scheduler 部署。
