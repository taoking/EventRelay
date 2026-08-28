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
- [ ] 审核差异，提交、推送并创建关联 #6 的 Draft PR，记录真实证据；等待独立审查。
