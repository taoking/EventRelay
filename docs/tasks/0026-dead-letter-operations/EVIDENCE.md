# Issue #26 Evidence

validated_implementation_head: `e0c53a57573a2785153890382539a9ffcd79e7e5`

## Acceptance Matrix

- AC-01: PASS — 新增的仅是 `Delivery.status=failed` 的 Application 读模型；没有新增 Delivery 状态、死信 Domain 实体、写表、Broker DLQ 或生命周期命令。
- AC-02: PASS — `EloquentDeadLetterQueryRepository` 以 `MAX(attempt_number)` 关联每个 Delivery 的最高 Attempt，并以 Attempt 聚合返回计数；API 与 MySQL Gate 均验证每 Delivery 恰一行。
- AC-03: PASS — endpoint UUID、event type、failure type、response status 四个 AND filter 已覆盖；未知字段、非法类型/范围和数组值均返回 `422 invalid_dead_letter_filter`。
- AC-04: PASS — `limit` 限定 1..100（默认 50）；认证 cursor 绑定 filter fingerprint；排序是 `failed_at DESC, delivery UUID DESC`，并使用对应 keyset predicate。
- AC-05: PASS — MySQL `pcntl_fork` + socket barrier 覆盖第一页后新失败记录提交与相同 `failed_at` UUID 边界；无重复、无旧集合漏项，坏/篡改/filter-mismatch cursor 均为 422。
- AC-06: PASS — Feature 回归验证 Endpoint URL 变更及 soft delete 后历史 failed Delivery 仍能查询。
- AC-07: PASS — Feature 与 R-02 均证明 source failed Delivery 留在投影中；Replay 新 Delivery 成功后不出现在投影，且保留 `replay_of_delivery_id`。
- AC-08: PASS — Resource、查询字段和 cursor 均不含 `failure_message`、target URL、签名材料、Idempotency-Key、Outbox 字段或内部数值 ID；安全回归通过。
- AC-09: PASS — 路径固定为 HTTP Controller → `ListDeadLetters` → Application query contract → Eloquent Infrastructure；Domain 未引入死信概念。
- AC-10: PASS — 每页仅一条带 derived latest/count Attempt 的 SQL；Docker 运行时 `EXPLAIN` 已审计，详见“查询计划”。
- AC-11: PASS — Docker Runtime R-01/R-02 均已执行，详见“Runtime”。
- AC-12: PASS — 本机和 Docker 全量质量门均通过，涵盖 Retry、Stale、Replay、Outbox、HMAC、SSRF 与 Ingress Idempotency 回归。
- AC-13: PASS（推送前仓库证据）— `ISSUE.md`、`CODEX.md` 已在首个功能提交中，本文为仅文档/计划证据提交；本分支尚未推送，Draft PR/CI 将在单次推送后创建并在 PR Body 记录。

## Automated Gates

- 本机定向：`php artisan test tests/Feature/Api/DeadLetterApiTest.php tests/Feature/DeadLetter/DeadLetterPaginationConcurrencyTest.php tests/Feature/DeadLetter/DeadLetterCommitVisibilityConcurrencyTest.php`：PASS，9 passed / 130 assertions；3 个 MySQL 专项因本机 SQLite 环境跳过。
- 本机静态定向：`vendor/bin/phpstan analyse --memory-limit=1G`：PASS，No errors。
- 本机完整：`composer quality`：PASS；Pint、PHPStan、Deptrac、Deptrac negative validation 均 PASS；PHPUnit 213 tests，185 passed / 1163 assertions，28 个 SQLite 环境不适用的 MySQL/Redis/pcntl 专项 skipped。
- Docker 定向：`docker compose exec -T app php artisan test`（DeadLetter API、两个新增 DeadLetter MySQL 并发、Replay、Outbox、Retry/Stale、真实 Queue integration 与 Ingress 并发集合）：PASS，52 passed / 573 assertions。
- Docker 完整：`docker compose exec -T app composer quality`：PASS；Pint（271 files）、PHPStan、Deptrac、Deptrac negative validation 均 PASS；PHPUnit 213 passed / 1475 assertions。
- MySQL 并发：`DeadLetterPaginationConcurrencyTest`（2 tests）与 `DeadLetterCommitVisibilityConcurrencyTest`（1 test）已在 Docker MySQL 8.4 实际执行，不是 skipped。

## 查询计划

Docker MySQL `eventrelay` 对真实读模型 SQL 运行 `EXPLAIN`：

- `deliveries` 使用现有 `deliveries_due_retry_index(status, next_attempt_at, id)` 过滤 `status=failed`。
- `delivery_attempts` 的最高 Attempt 与计数 derived query 均使用现有 `delivery_attempts_delivery_id_attempt_number_unique(delivery_id, attempt_number)` 覆盖索引。
- Event、Endpoint、replay source 均为主键 `eq_ref` join；由于最终排序键来自最高 Attempt 的 `finished_at`，计划显示 `Using temporary; Using filesort`。
- 查询始终以 `limit + 1` 有界执行，未观察到 N+1。现有运行数据和索引策略不足以证明增加新索引能改善该派生排序，因此没有臆测新增 migration。

## Runtime

以下场景在 Docker PHP 8.5、MySQL 8.4、Redis 7 中执行。为保持生产 SSRF global-unicast、DNS pinning、proxy、redirect 与 TLS policy 不变，运行时仅在单个未提交的 Artisan process 内绑定测试用、已验证公网 IP 的 `WebhookTargetResolver` 与 `WebhookTransport`；真实 Application Claim/Attempt/Retry/Outbox/Redis Job/`ProcessDeliveryJob` 路径没有改变，生产配置没有修改。

### R-01 — Mixed Failure Triage：PASS

- Endpoint：`e71f5c72-4fc9-4dae-836c-bd8667bc572d`。
- D1：`78fc2ce2-3655-4071-a54f-426721af2c85`，Attempt #1=`failed/http_status/400`，最终 `failed`，`failed_at=2026-09-09T12:00:00+00:00`。
- D2：`585d3e5e-fad7-43a2-be5a-97ae5beeb075`，HTTP 500 经 10s/60s 业务 backoff 后 Attempt #1/#2/#3 均 `failed/http_status/500`，最终 `failed`，最高 Attempt=3，`failed_at=2026-09-09T12:01:10+00:00`。
- D3：`05631cca-563e-4316-9b4c-99f2d0fcc7f9`，安全测试 transport 产生 `network_error`，Attempt #1/#2/#3 均失败，最终 `failed`，最高 Attempt=3，`failed_at=2026-09-09T12:03:10+00:00`。
- 实际 `GET /api/dead-letters?limit=2` 返回 D3、D2 与不透明 `next_cursor`；携带 cursor 的第二页返回 D1；`failure_type=network_error` 只返回 D3。响应没有 `failure_message`、target URL 或签名字段。

### R-02 — Repair → Replay → Success：PASS

- source D1 为 `78fc2ce2-3655-4071-a54f-426721af2c85`，其 Attempt API 仍显示原 Attempt #1=`failed/http_status/400`。
- 通过真实 `PATCH /api/endpoints/e71f5c72-4fc9-4dae-836c-bd8667bc572d` 将当前 URL 改为 `https://receiver.example/repaired`。
- 通过真实 `POST /api/deliveries/D1/replay`（`Idempotency-Key: REDACTED`）获得 HTTP 201 的 D2：`ffdb4f2d-ba9f-44c8-8a62-81462ee0df29`，`replay_of_delivery_id=D1`，初始为 `pending`。
- D2 Outbox：`cc2edf8e-3e2a-4d83-bae3-e8b60faa4241`，`delivery:{D2}:attempt:1`，`published`；真实 `outbox:publish --limit=100` 输出成功 5、Redis 发布失败 0、lease 已丢失 0。
- 在同一容器以内存测试 binding 执行真实 `queue:work redis --queue=deliveries --once --tries=1`，`ProcessDeliveryJob` DONE；D2 最终 `succeeded`，Attempt #1=`succeeded/200`，target snapshot 为 repaired URL。
- 最终实际 `GET /api/dead-letters?limit=50` 仍含 D1，不含 D2；实际 `GET /api/deliveries/D2` 显示 `replay_of_delivery_id=D1` 且 `status=succeeded`。

## Risks

- DLQ 是 failed Delivery 读模型，不是独立 Broker queue，也没有 acknowledge/archive/retention。
- 查询依赖最终 Attempt 元数据的一致性；发现不可能的 failed Delivery/Attempt 组合时会 fail closed，而不是伪造结果。
- EventRelay 仍为 at-least-once；接收方仍应按稳定 Delivery ID 处理自动重复投递。

## NOT RUN

- GitHub PR CI：外部事实，待本分支首次单次推送、创建 Draft PR 后在 PR Body 记录。
- Independent Review。
