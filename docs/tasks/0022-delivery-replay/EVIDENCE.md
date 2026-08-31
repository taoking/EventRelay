# EventRelay PR #23 — Independent Review #1 整改证据

Review：Independent Review #1 remediation（M-01）
validated_implementation_head：`f7d1c572d5c1dc21a0012b65d2cc6cd7b8331d2d`

## Acceptance Matrix

- AC-01：PASS。`DeliveryReplayApiTest` 与 Docker/MySQL R-01 均确认：Endpoint disabled 后，相同 source + key 返回原 Replay（HTTP 200，`created=false`）。
- AC-02：PASS。`DeliveryReplayApiTest` 确认 Endpoint soft delete 后相同 source + key 返回原 Replay。
- AC-03：PASS。API 回归与 R-01 均确认 unavailable Endpoint 下的新 key 返回 `409 replay_endpoint_unavailable`，不创建新的 Replay。
- AC-04：PASS。API 回归确认 URL / signing key 后续变化后，同 key 返回原 Delivery 并保留原 URL / key snapshot；新 key 使用当前 URL / key 创建新 Delivery。
- AC-05：PASS。Docker MySQL `DeliveryReplayConcurrencyTest` 使用两个独立 process、独立连接和 socket barrier，确认同 source + 同 key 仅产生 1 Replay、1 Outbox，两个调用返回同一 UUID。
- AC-06：PASS。既有 source-scoped digest 与 DB/Outbox 泄漏断言继续通过；本整改没有新增 raw Idempotency-Key 持久化、日志、API 或 Queue 路径。Runtime key 在本文件中已 REDACTED。
- AC-07：PASS。本机与 Docker 完整质量门以及 Replay、Outbox、Retry、Stale、HMAC、SSRF / DNS pinning 专项均通过。

## Targeted Tests

- `php artisan test tests/Feature/Api/DeliveryReplayApiTest.php`：PASS，11 tests / 132 assertions。
- `docker compose exec -T app php artisan test tests/Feature/Api/DeliveryReplayApiTest.php tests/Feature/Delivery/DeliveryReplayConcurrencyTest.php tests/Feature/Endpoint/DeliveryReplayEndpointSnapshotConcurrencyTest.php tests/Feature/Api/DeliveryConcurrencyTest.php tests/Feature/Outbox/DeliveryOutboxTest.php tests/Feature/Delivery/DeliveryRetryTest.php tests/Feature/Delivery/StaleDeliveryRecoveryTest.php tests/Feature/Endpoint/EndpointSigningSecretTest.php tests/Unit/Application/Delivery/HmacWebhookSignerTest.php tests/Unit/Infrastructure/Webhook/PhpWebhookTargetResolverTest.php tests/Unit/Infrastructure/Webhook/CurlWebhookTransportTest.php`：PASS，71 tests / 379 assertions。
- `composer quality`：PASS，187 tests，165 passed，951 assertions，22 skipped（本机 SQLite 环境的 MySQL/Redis/pcntl 专项）。
- `docker compose exec -T app composer quality`：PASS，187 tests / 1201 assertions；Pint、PHPStan、Deptrac 与 Deptrac negative validation 均通过。

## Runtime R-01

Docker MySQL Runtime：PASS。

- Event：`fd431106-89bf-40d8-8f67-489aa25370cb`。
- source Delivery D1：`d6f118d1-ca34-4398-821b-30df7d885251`，受控 fixture 置为 `failed`。
- 首次 Replay：HTTP 201，D2：`3934cb14-ae1b-48eb-9c68-156763154162`。
- Endpoint disabled 后，同 key（`REDACTED`）重试：HTTP 200，返回同一 D2。
- Endpoint disabled 后，新 key：HTTP 409，`replay_endpoint_unavailable`。
- 最终 source 的 Replay rows=1；D2 的 initial Outbox rows=1。

## Risks

- EventRelay 仍为 at-least-once；本整改只修复已提交 Replay 的 endpoint-scoped idempotency result retrieval，不改变自动 broker duplicate 或 receiver 去重语义。
- Endpoint 当前可用性仍是创建新 Replay 的必要条件；只有已存在的同 source + 同 key 结果可以绕过该检查。

## NOT RUN

- Independent Review #2：NOT RUN。
