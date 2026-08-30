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
