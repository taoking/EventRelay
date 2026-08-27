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
