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

## Remediation #1

- Independent Review #1：`5075157949`。
- finding：M-01 — 原 readiness 仅执行 `SELECT 1`，无法证明 application connection 能对 durable MySQL transaction 执行写入与 commit。
- validated_remediation_head：`99452ff02d7f587973e0cc3d300b681f87c2072f`。这是执行 targeted、Docker Runtime、迁移验证及两套质量门的代码 HEAD；本节之后的证据文档提交不是验证 HEAD。

### M-01 实现

- 新增隔离表 `operational_readiness_probes`，只有随机 32 字符 probe 主键，没有自增业务事实、时间戳或业务外键。
- 每次 `/internal/health/ready` 通过相同 application MySQL connection 开启短 transaction，执行随机行 `INSERT`、同一行 `DELETE`，随后实际 `COMMIT`。这不是写后 rollback：两条 DML 与 commit 都由 MySQL 执行，从而验证 write permission/read-only/transaction/commit 路径；最终逻辑 persistent row count 回到零。
- 随机 `bin2hex(random_bytes(16))` 主键避免单一固定 probe row 的热点和并发唯一键冲突。任何显式 MySQL/PDO/Query 写入、事务或提交错误都映射为 `mysql=down`；`LogicException`、配置错误等未知程序错误不被 `catch (Throwable)` 吞掉。
- Probe 未读取或修改 Event、Delivery、Attempt、Outbox、Endpoint、Replay、Ingress Idempotency，也不 enqueue、publish、claim 或 recover。readiness 仍只依赖 MySQL；liveness、Redis/RabbitMQ readiness 语义未变。

### M-AC 验收矩阵

| AC | 状态 | 当前整改代码证据 |
|---|---|---|
| M-AC-01 | PASS | Docker MySQL 可写时 `/ready=200`；`MySqlWritableReadinessTest` 的实际 write/delete/commit 成功。 |
| M-AC-02 | PASS | 物理 `docker compose stop mysql` 后 `/live=200`、`/ready=503`；恢复后服务健康。 |
| M-AC-03 | PASS | Docker MySQL `super_read_only=ON` 时 application `SELECT 1` 成功，而 `/ready=503`。 |
| M-AC-04 | PASS | 关闭 `super_read_only` 与 `read_only` 后未重启 app 即 `/ready=200`。 |
| M-AC-05 | PASS | 恢复后 `POST /api/events=201`；Event、matching Delivery、Outbox 均已提交。 |
| M-AC-06 | PASS | RabbitMQ 物理停止时 `/ready=200`。 |
| M-AC-07 | PASS | Redis 物理停止时 `/ready=200`。 |
| M-AC-08 | PASS | 五次 probe 前后 Event/Delivery/Attempt/Outbox count 不变；不调用业务 publication。 |
| M-AC-09 | PASS | 五次 probe 与双进程并发完成后 `operational_readiness_probes` logical row count 均为 0。 |
| M-AC-10 | PASS | 两个独立 MySQL process 由 socket barrier 同时启动，均 `available`，无 deadlock/unique collision/false-negative。 |
| M-AC-11 | PASS | 真实只读 DB 返回严格 generic `{"status":"not_ready","checks":{"mysql":"down"}}`，不含 SQLSTATE、HY000 或 READ ONLY 细节。 |
| M-AC-12 | PASS | 本机与 Docker `composer quality` 均通过所有历史回归。 |

### 自动化验证

| 项目 | 状态 | 结果 |
|---|---|---|
| 本机 `php artisan test tests/Feature/Operations tests/Unit/Infrastructure/Operations` | PASS | 17 tests：14 passed / 3 MySQL-only SKIPPED；102 assertions。 |
| Docker `php artisan test tests/Feature/Operations tests/Unit/Infrastructure/Operations` | PASS | 17 passed / 136 assertions；MySQL 写入、session read-only、恢复和双进程并发均实际执行。 |
| T-01 writable success | PASS | 专用表随机写入、删除和 commit 成功，业务表 count 未变。 |
| T-02 readable-but-not-writable | PASS | MySQL session `SET SESSION TRANSACTION READ ONLY` 后 `SELECT 1=1`，真实 `/ready=503`。 |
| T-03 recovery | PASS | `SET SESSION TRANSACTION READ WRITE` 后 `/ready=200`，匹配 Endpoint/subscription 下 Event/Delivery/Outbox commit。 |
| T-04 probe state invariant | PASS | 同一测试五次调用前后业务 count 不变，probe table row count=0。 |
| T-05 concurrency | PASS | Docker MySQL/InnoDB + `pcntl_fork` + 两个 socket barrier child，两个 result=available，probe table row count=0。 |
| T-06 exception boundary | PASS | MySQL `QueryException/PDOException` 仍为 false/503；缺失 connection configuration 的 `InvalidArgumentException` 继续上抛。 |
| clean DB migration | PASS | 独立临时 MySQL schema 完整前向迁移，`operational_readiness_probes` 存在；随后撤销临时 grant 并删除该精确临时 schema。 |

### Docker Runtime

所有 Runtime operations bearer token 均为短生命周期 fixture，文档中不保存其值。

#### R-06 — 可读但不可写

- Docker MySQL 8.4 保持 running；root 将 `super_read_only=ON`，application connection 的 `SELECT 1 AS probe_select` 仍返回 `1`。
- 同时 `/internal/health/live=200`、`/internal/health/ready=503`，body 为 `{"status":"not_ready","checks":{"mysql":"down"}}`；`/metrics=200`，保持 durable read availability 语义。
- 结束时恢复 `super_read_only=OFF` 与 `read_only=OFF`；没有变更业务表。

#### R-07 — 恢复可写 ingress

- 无需重启 app 即 `/ready=200`。
- Endpoint `c01975c3-3fe9-4dfd-b855-e19d4827127a` 创建并订阅后，`POST /api/events=201`，Event `1ac78685-8d7d-4099-900b-b332dcef9562`。
- MySQL 结果：Delivery `39a1a2bf-f079-4e8e-81c8-c43661c47c8d`=`pending`，Outbox `96e515f7-b598-4776-ac03-6b8dd768a260`=`pending`。这证明恢复后 Event + matching Delivery + Outbox durable transaction 已提交。

#### R-08 — Broker regressions

- `docker compose stop rabbitmq` 后 `/ready=200`，RabbitMQ 重启后恢复。
- `docker compose stop redis` 后 `/ready=200`，Redis 重启后恢复。
- readiness 没有新增 Redis/RabbitMQ probe；两个 broker outage 均不改变 MySQL ingress write readiness。

### 质量门与自审

- 本机 `composer quality`：PASS。Pint、PHPStan、Deptrac、negative validation 均 PASS；PHPUnit `253 tests / 210 passed / 1300 assertions / 43 SKIPPED`（host SQLite/external-service 条件）。
- Docker `docker compose exec -T app composer quality`：PASS。Pint（318 files）、PHPStan、Deptrac、negative validation 均 PASS；PHPUnit `253 passed / 1785 assertions`。
- 自审：实现没有 `SELECT 1` only fallback、没有 `catch (Throwable)`、没有 probe auto-increment/history、没有业务表写入或 broker interaction；随机行避免明显的单行锁。`super_read_only` Runtime 明确证明 SELECT 成功而 write 被拒绝；恢复后真实 API commit PASS。
- Risks：readiness 仅回答当前 MySQL durable write capability，不代表 Redis/RabbitMQ 可用或 receiver 可达；系统整体仍为 at-least-once。MySQL probe 的逻辑状态为 zero-net-state，但每次仍故意执行数据库 DML/commit，故应只由内部 health infrastructure 调用。
- NOT RUN：新的 GitHub PR CI、Independent Review #2、生产 daemon/scheduler 部署。当前状态：`INCOMPLETE`。
