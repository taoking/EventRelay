# Phase 0 执行计划

## 目标

在 `feature/phase-0-harness` 分支完成 EventRelay 的 Laravel 13 工程基线与 Agentic Engineering Harness；不实现任何产品业务能力。

## 执行项

- [x] 初始化本地 Git 仓库并创建 Phase 0 分支。
- [x] 初始化 Laravel 13，锁定 Composer 平台 PHP 8.5，并配置 MySQL、Redis 与队列基线。
- [x] 配置 Pint、PHPStan/Larastan、Deptrac、统一质量命令和测试。
- [x] 配置 Docker Compose、GitHub Actions、Issue/PR 模板和开发文档。
- [x] 执行机械与运行时验证，更新验收记录。
- [x] 自审、提交、推送并创建 Draft PR。

## 已知风险

- 本地 PHP 为 8.4，项目依赖解析与 CI 将锁定 PHP 8.5；本地运行验证使用兼容版本。
- GitHub 仓库、推送、PR、Docker 服务取决于当前登录、网络和本机 Docker 状态，需以实际命令证据为准。

## PR #1 独立审查整改

- [x] 在 GitHub 为 `main` 建立有效 ruleset：必须通过 Pull Request，并要求 `quality` check。
- [x] 添加不进入生产源码的 Deptrac 违规 fixture 与可重复负向验证，并纳入统一质量 Gate。
- [x] 同步 PR #1 和验收记录至当前 HEAD 的 CI 证据，完成复验。

## Issue #2：Endpoint CRUD

- [x] 从 `origin/main@7d3940e` 创建 `feature/endpoint-crud`，阅读 Issue 与现有 Harness。
- [x] 建立 Endpoint Domain、Application 用例/仓储契约与 Infrastructure 持久化实现。
- [x] 建立 5 个 Endpoint CRUD API，并明确软删除行为。
- [x] 添加 Unit / Feature 测试，维持架构 Gate 与负向验证。
- [x] 执行机械 Gate、Docker CRUD Runtime Validation、自审、提交、推送与 Draft PR。

## Issue #4：Event 接收 API 与持久化

- [x] 确认 `origin/main@9121a8a` post-merge CI PASS，阅读 Issue、规则与现有 Endpoint 实现。
- [x] 实现不可变 Event Domain、Application Repository 边界与 Infrastructure JSON 持久化。
- [x] 实现 Event 创建、列表、详情 API 与请求校验。
- [x] 添加 Unit / Feature 测试，并维持架构与负向验证。
- [x] 执行质量 Gate、fresh Docker Runtime Validation、自审、提交、推送和 Draft PR。

## PR #5：payload shape 高优先级整改

- [x] 阅读独立审查，确认 `{}` 被转换为 `[]` 的 High finding。
- [x] 在 Domain、Application DTO、Resource 与 API 输出中保留 JSON object shape。
- [x] 添加空 object 与嵌套空 object 的 POST/detail/list 回归测试，并执行双环境质量与 HTTP 验证。
- [ ] 提交、推送并同步 PR Evidence；等待独立复审。

## Issue #6：Endpoint Event Type Subscriptions

- [x] 确认 `main@d1c16d65` 的 post-merge CI run `33132430142` 为 PASS，并完整阅读 Issue、必读文档与现有 Endpoint/Event 实现。
- [x] 提取共享的纯领域 `EventType`，使 Event 与订阅使用同一业务不变量。
- [x] 实现 Endpoint 订阅的领域集合、应用用例/仓储契约、MySQL 持久化与 GET/PUT API。
- [x] 添加 Unit / Feature / Architecture 测试并执行本地、Docker PHP 8.5 与真实 HTTP Runtime Gate。
- [x] 审核差异、提交并推送 `83e3993`，创建关联 #6 的 Draft PR #7；CI run `33133000300` 为 PASS。
- [ ] 等待 Independent Review；在其完成前最终状态保持 `INCOMPLETE`。

## PR #7：TOCTOU / 软删除竞态 Medium 整改

- [x] 阅读 Independent Review，确认只整改 persistence 二次查询在 soft delete 后泄漏 Infrastructure `ModelNotFoundException` 的 Medium finding。
- [x] 将 persistence 端不可见 Endpoint 转换为既有 Application `EndpointNotFound`，并添加 stale reference 回归测试。
- [x] 执行本地、Docker PHP 8.5 质量 Gate 与相关 HTTP Runtime 验证。
- [ ] 推送、等待 CI 并更新 Draft PR 证据。
- [ ] 等待 Independent Review 复核；最终状态保持 `INCOMPLETE`。

## Issue #8：Delivery Domain 与持久化

- [x] 确认 `main@ab9ea7a3fd80a5ac2b2a5aa5d4f323d32d0dd629` 的 post-merge CI run `33256015412` 为 PASS，阅读 Issue、必读文档、开发记录和现有实现。
- [x] 实现 Delivery Domain、Application 用例/仓储契约、并发安全的幂等持久化和只读 API。
- [x] 添加 Domain、Feature/Integration 与 Out-of-Scope 回归测试，并完成双环境质量与 Docker Runtime Gate。
- [x] 审核差异、提交推送 `17d9c6c` 并创建关联 #8 的 Draft PR #9；CI run `33257033332` 为 PASS。
- [ ] 等待 Independent Review；在其完成前最终状态保持 `INCOMPLETE`。

## PR #9：Delivery 并发幂等 High 整改

- [x] 阅读最新 Independent Review、Issue #8、必读文档、开发记录与 Delivery 实现/测试，确认在 `feature/delivery-domain@f41eb5952d509b277b068d7ac2958c620f008515` 上整改。
- [x] 将复合唯一键冲突后的恢复查询改为 MySQL current/locking read，并仅识别当前 Delivery 复合唯一约束。
- [x] 添加两个独立 MySQL 连接、受控 REPEATABLE READ 快照时序的并发回归测试。
- [x] 更新中文开发记录，审核并提交修复。
- [x] 执行本地与 Docker PHP 8.5 质量 Gate，以及 Docker MySQL 实际并发 Runtime Validation。
- [ ] 推送、等待新 CI，并同步 Draft PR #9 的真实证据；Independent Review 复验前状态保持 `INCOMPLETE`。

## Issue #10：Event Subscription Match 与 Delivery 自动生成

- [x] 确认 `main@25d1f420e39d3d0b11255a8be26c05929e812d8d` 的 post-merge CI run `33260165081` 为 PASS，阅读必读文档、Issue 与现有实现，并创建 `feature/event-delivery-matching`。
- [x] 实现原子 Event → exact Subscription match → Delivery 流程与锁定一致性边界。
- [x] 添加 Feature、事务原子性及 MySQL 两连接并发回归测试。
- [x] 执行本机、Docker PHP 8.5 质量门和完整 Docker Runtime 场景。
- [ ] 提交、推送、创建关联 #10 的 Draft PR 并同步真实 CI 证据；Independent Review 前保持 `INCOMPLETE`。

## PR #11：Independent Review 并发证据与开发记录整改

- [x] 确认现有 Draft PR #11、`feature/event-delivery-matching@1b53896` 与 CI run `33261196549` 为 PASS，并复核 Issue、规范、实现和现有并发测试。
- [x] 让 MySQL 回归测试在真实 `CreateEvent` 外层事务中经过真实 matcher 锁、并发 Endpoint 变更、CreateDelivery 与提交窗口。
- [x] 补录 Issue #10、最初 Prompt 与本次整改 Prompt 的完整原文，并固化“完整原文”规则。
- [ ] 已执行本机/Docker 质量门与 MySQL 专项验证，整改功能提交 CI 已 PASS；待推送开发记录证据、最终 CI 与 Draft PR #11 证据同步。

## Issue #12：Laravel Queue / Redis Delivery 调度骨架

- [x] 确认 `main@1d9e6f737cb7937c3cc53df4eb9d66979032bedf` 及 post-merge CI run `33264733096` 为 PASS，创建 `feature/delivery-redis-queue` 并完成规范、Issue 与现有实现勘查。
- [x] 建立 Application queue/worker/recovery contracts，并让 CreateEvent 在 MySQL commit 后调度 Delivery。
- [x] 实现 Redis `deliveries` Job、pending finder 与 Console recovery command，保持 Outbox、HTTP、Attempt 和状态机均不在范围内。
- [x] 增加 Unit、Feature、MySQL/Redis integration 与 Worker 回归，补录完整来源，执行质量门和 Docker Runtime。
- [x] 审核差异、提交推送、创建 Draft PR #13、等待 GitHub Actions run `33272462771` PASS 并同步真实证据；Independent Review 前保持 `INCOMPLETE`。

## PR #13：Redis publication 与 unique dispatch 整改

- [x] 阅读 PR #13 Independent Review、Issue #12、必读规范、开发记录、现有 Queue 实现及 Laravel 13 PendingDispatch / Dispatcher / UniqueLock 本地源码。
- [x] 精确转换 Predis 服务端 publication failure，并锁住 commit 后 HTTP 201 语义。
- [x] 使用真实 PendingDispatch unique-lock 路径，并在 publication failure 后释放该 Job 的 lock。
- [x] 添加真实 Redis duplicate enqueue 与 immediate recovery 回归测试，完成双环境质量门和 Docker Runtime。
- [x] 审核、提交、推送，整改功能 commit `421b72e` 的 CI run `33289678696` PASS；待推送证据同步并等待最终 CI，Independent Review 前保持 `INCOMPLETE`。

## Issue #14：Webhook HTTP Delivery 与 DeliveryAttempt

- [x] 确认 `main@391acdb87b5b2968be724865b0d940b8060ecafc` 及 post-merge CI run `33292301949` 为 PASS，阅读必读规范、Issue、开发记录与现有 Delivery/Queue 实现，并创建 `feature/webhook-http-delivery-attempt`。
- [x] 实现 target URL snapshot、Delivery 状态机、DeliveryAttempt 持久化与只读 API。
- [x] 实现原子 claim、事务 A/B、Webhook transport、SSRF/DNS pinning 与稳定失败分类。
- [x] 添加 Unit/Feature/MySQL 并发/HTTP/SSRF 回归，保存完整来源并执行双环境质量门和 Docker Runtime。
- [x] 审核、提交、推送、创建 Draft PR、等待 CI 并同步证据；Independent Review 前保持 `INCOMPLETE`。

## PR #15：SSRF / DNS pinning / 完整投递并发整改

- [x] 复核 Draft PR #15、`feature/webhook-http-delivery-attempt@03c6f88` 与 CI run `33303380542` 为 PASS，阅读 Independent Review、Issue、必读规范、开发记录和相关实现/测试。
- [x] 收紧为 fail-closed global-unicast IP policy，并补齐 IPv4/IPv6 special-use、mixed DNS 与 IP literal 回归。
- [x] 修复 IPv6 `CURLOPT_RESOLVE` 格式，抽出安全选项安装边界并保证配置失败不执行网络请求。
- [x] 新增完整 `ProcessPendingDelivery` 两进程并发回归，并修正 Worker 完成日志。
- [x] 保存整改任务追溯，执行本机/Docker 质量门与 Docker Security/并发 Runtime；提交、推送、等待 CI 并同步 Draft PR 证据；Independent Review #2 前保持 `INCOMPLETE`。

## Issue #16：Retry / Backoff / stale-processing recovery

- [x] 确认 `main@b9eeaa5cd5993cccf144721c9fc91eec6adf6d10` 与 post-merge CI run `33308179391` 为 PASS，创建 `feature/delivery-retry-recovery` 并阅读 Issue、必读规范和现有实现。
- [x] 建立 Clock、RetryPolicy、状态机、Attempt abandoned 语义与持久化演进。
- [x] 实现 due retry/stale finder、原子 claim/finalize/recovery、delayed Redis queue 与两个 recovery command。
- [x] 添加 Unit、Feature、MySQL/Redis 并发与 HTTP retry 回归，并保存 Issue/Prompt 完整原文。
- [x] 执行双环境质量门与 Docker Runtime，审核、提交、推送、创建 Draft PR #17，并确认实现/测试 HEAD `abb3dd4` 的 GitHub Actions run `33315933453` 为 PASS；Independent Review 前保持 `INCOMPLETE`。

## PR #17：Independent Review #1 整改

- [x] 阅读 Independent Review #1、Issue #16、必读规范、开发记录及当前 Delivery retry/stale 实现；确认仅处理真实 stale-late-finalize 并发证据与两项 Docker Runtime 缺口。
- [x] 新增 MySQL 双进程、socket barrier、真实 `ProcessPendingDelivery → transport → finalize` 对真实 stale recovery 的竞争回归。
- [x] 物理停止 Docker Redis，完成 delayed publication-gap 的 durable state 与 due-retry recovery Runtime；执行本机/Docker 质量门，待推送、最终 CI 与 Draft PR 证据同步。

## Issue #18：Webhook HMAC Signing 与 Endpoint Secret Rotation

- [x] 确认 `main@11ee0d0547607ae79455e8ecd4f85bd3a07ed08c`、CI run `33319316183` 为 PASS，且 Issue #16 已关闭；创建 `feature/webhook-hmac-signing` 并阅读必读规范、Issue 与现有实现。
- [x] 建立版本化、加密落库的端点签名密钥，以及一次性 reveal / rotation API。
- [x] 将 Delivery target URL 与 signing key ID 置于同一端点锁定快照窗口，并让 Worker 对实际 HTTP body 生成 v1 HMAC。
- [x] 完成签名 retry、泄漏、MySQL rotation、CreateDelivery/rotation barrier 并发与 Docker 独立验签验证。
- [x] 保存完整任务来源，审核、提交、推送、创建 Draft PR #19，并确认实现 HEAD 的 CI run `33321379921` 为 PASS；Independent Review 前保持 `INCOMPLETE`。

## PR #19：Independent Review #1 签名快照契约整改

- [x] 复核 Draft PR #19、`feature/webhook-hmac-signing@6dd47e15`、CI run `33321629838` 与两项审查 blocker。
- [x] 将 Delivery URL/签名 key 原子快照设为 `CreateDelivery` 的强制 Application contract，删除可选 unsigned fallback。
- [x] 修复 `DeliveryRepository::createOrGet()` 的签名 key 无损持久化与 Endpoint 归属校验，并补充回归测试。
- [x] 保存完整整改 Prompt，执行双环境质量门、轻量 Docker signed smoke、push 与 GitHub Actions run `33330538162` PASS；待最终 PR 证据同步，Independent Review #2 前保持 `INCOMPLETE`。

## Issue #20：Transactional Outbox 与 Durable Delivery Publication

- [x] 确认 `main@f9a4a40d51e9bfddc94623f27bcfb184efb6354c`、post-merge CI run `33331233576`、PR #19 已合并、Issue #18 已关闭且 Issue #20 仍开放；创建 `feature/transactional-outbox`。
- [x] 阅读 Issue、必读规范、开发记录、现有 Queue / Retry / Stale / HMAC 实现，并确定采用“Outbox 可提前发布延迟 Redis Job”的 `available_at` 模型：MySQL `next_attempt_at` 仍为业务时间事实源。
- [x] 建立最小 Outbox schema、执行 intent 去重与 Application 持久化契约；将初始 Delivery、Retry 与 stale recovery 的 intent 写入同一业务事务。
- [x] 实现有界、稳定排序、claim/lease 的 Outbox Publisher 与 `outbox:publish` 命令，并将旧 pending/due recovery 命令迁移为确保 durable intent 的路径。
- [x] 添加 MySQL/Redis 原子性、双 Publisher 并发、lease crash recovery、已知 Redis 故障与既有安全回归测试；保存 Issue / Prompt 完整原文。
- [x] 执行本机与 Docker 质量门、Docker Runtime A/B/C。
- [x] 提交最终运行证据、推送、创建 Draft PR #21，并确认 GitHub Actions run `33344487442` 为 PASS。
- [ ] Independent Review：NOT RUN；在其完成前保持 `INCOMPLETE`，不得自行 Merge。

## PR #21：Independent Review #1 — broker-loss recovery 与 publication acknowledgement 整改

- [x] 复核 Draft PR #21、审查结论、Outbox/Queue/Recovery 实现与现有 Redis/MySQL 回归，确认只处理 broker-loss re-arm、真实 publication acknowledgement 与 lease 批次边界。
- [x] 将人工 pending/due recovery 分离为业务状态校验后的 Outbox recovery contract；仅对尚未开始的当前 intent 重开已 published message。
- [x] 移除 Queue 层 `ShouldBeUniqueUntilProcessing` 静默抑制，使 Outbox publisher 的成功标记对应实际 Redis publication；保留 Delivery 原子 claim 作为业务正确性防线。
- [x] 新增 broker-loss initial/retry、orphan unique-lock、re-arm 并发与 lease expiry at-least-once 回归。
- [x] 补齐完整整改 Prompt，执行本机/Docker 质量门与 Docker Runtime D/E/F，提交整改功能与运行证据。
- [x] 推送并确认整改 HEAD 的 GitHub Actions run `33346107528` 为 PASS，PR Body 使用正确链接同步证据。
- [ ] 推送最终 CI traceability 文档并确认其 GitHub Actions；Independent Review #2 前保持 `INCOMPLETE`。
- [ ] Independent Review #2：NOT RUN；在其完成前保持 `INCOMPLETE`，不得自行 Merge。

## Issue #22：Failed Delivery Manual Replay

- [x] 确认 `main@c83c8d19b02c81732e4638d63c923883f8f0a68c`、post-merge CI run `33346704661` 为 PASS，且 Issue #20 已关闭、Issue #22 保持开放；创建 `feature/delivery-replay`。
- [x] 建立 Replay 的新 Delivery 语义、`replay_of` 谱系、主投递/Replay creation key 与 Endpoint 当前 URL/签名 key 原子快照骨架。
- [x] 建立强制 Idempotency-Key 校验与 source-scoped SHA-256 creation identity，并让 Replay Delivery 与 attempt #1 Outbox 在同一 MySQL 事务内创建。
- [x] 补齐 MySQL 并发、端点配置快照、Outbox/Redis、HMAC 与历史不可变性的完整回归。
- [x] 保存 Issue/Prompt 完整原文，执行双环境质量门和 Docker Runtime A/B/C/D。
- [x] 审核、提交、推送、创建 Draft PR #23，并确认验证 HEAD `a6562f3` 的 GitHub Actions run `33354360566` 为 PASS；Independent Review 前状态保持 `INCOMPLETE`。

## PR #23：Independent Review #1 — Replay Idempotency-Key 稳定返回整改

- [x] 以 `docs/tasks/0022-delivery-replay/REMEDIATION-01.md` 作为唯一整改合同，确认 M-01 为既有结果读取发生在 Endpoint 可用性校验之后的顺序问题。
- [x] 调整同一 MySQL transaction 内的锁定 current read 顺序，补齐 disabled、soft-delete 与配置变更回归。
- [x] 执行专项、双环境质量门与 Docker/MySQL R-01，保存 repository-native evidence。
- [x] 提交、推送、同步 Draft PR #23 的 CI；Independent Review #2 前状态保持 `INCOMPLETE`。

## Issue #24：Event Ingress Idempotency

- [x] 确认 `main@78ace320b0dc49ebb600381b5802e5c5eaca5e41`、post-merge CI run `33359084169` 为 PASS，且 Issue #22 已关闭、Issue #24 保持开放；创建 `feature/event-ingress-idempotency`。
- [x] 阅读唯一执行合同、必读规范、Event/Delivery/Outbox/Replay 代码与现有并发测试，确定 Event graph、ingress binding 与 Outbox 的单一 MySQL 事务边界。
- [x] 实现可选 `Idempotency-Key`、摘要/指纹、binding 持久化与重复请求 winner 恢复。
- [x] 添加 API、原子性、MySQL 双进程并发、配置稳定性与泄漏回归。
- [x] 在 validated implementation head `1f1bab579af9713e3c470de772b307923fee611a` 完成本机/Docker 质量门和 R-01/R-02 Runtime。
- [x] 创建仅含证据与计划的提交；待单次推送、建立 Draft PR、等待 CI。Independent Review 前保持 `INCOMPLETE`。

## Issue #26：Operational Dead-Letter Queue

- [x] 确认 `main@09b3c27c56663eafb382fb00f1c4de0c8027c18f`、post-merge CI `33373781905` 为 PASS，且 Issue #24 已关闭、Issue #26 保持开放；创建 `feature/dead-letter-operations`。
- [x] 阅读唯一执行合同、必读规范、Delivery/Attempt/Replay/Outbox/Ingress 现状与相关 MySQL 并发测试，确定 DLQ 仅为 `Delivery.status=failed` 的读侧投影。
- [x] 建立失败 Delivery 的有界查询、filter/cursor/API 与 latest-attempt 一致性校验；不新增状态机或 DLQ 写模型。
- [x] 添加 API、安全、keyset、MySQL 并发、查询计划与 Replay 历史稳定性回归。
- [x] 在准确功能提交 `e0c53a57573a2785153890382539a9ffcd79e7e5` 上执行本机/Docker 质量门和 R-01/R-02 Runtime，并写入 repository-native Evidence。
- [ ] 单次 push、创建 Draft PR 并等待 CI；Independent Review 前保持 `INCOMPLETE`。

## Issue #28：RabbitMQ Delivery Transport

- [x] 确认 `main@f5a60a4df2842f6e63178178058e58b4791d718a`、post-merge CI `33382822797` 为 PASS，且 Issue #26 已关闭、Issue #28 保持开放；创建 `feature/rabbitmq-delivery-transport`。
- [x] 阅读唯一执行合同、必读规范、关闭的 Issue #20/相关开发记录，以及当前 Outbox、Queue、Retry/Stale、Docker/CI 与回归测试；确定 MySQL Outbox `available_at` 为唯一业务调度事实源。
- [x] 将 Outbox publisher 改为只 claim due intent；建立仅发布 Delivery UUID 的 broker-neutral Application transport，并保持 Redis 默认路径兼容。
- [x] 增加 RabbitMQ 4.3 topology、confirm publisher、严格 manual-ack consumer、`outbox:work` 与 config-select provider。
- [x] 补齐 Redis/RabbitMQ、MySQL/Rabbit 并发、故障/confirm/redelivery、切换与安全回归。
- [x] 在准确功能提交 `e38e980dc10f5152f9d9d54416e03e27736550a2` 上执行完整质量门、Docker R-01..R-05，并新增 repository-native `EVIDENCE.md`。
- [ ] 单次 push、创建 Draft PR 并等待 GitHub CI；Independent Review 前保持 `INCOMPLETE`。
