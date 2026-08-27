# EventRelay Phase 0：Agentic Engineering Harness

## 目标

Phase 0 不实现 Webhook 业务功能。目标是先建立一个可被 Coding Agent 安全开发、可被 CI 机械约束、可被 Review/GC Agent 持续治理的 Laravel 仓库。

## 技术基线

- PHP 8.5（开发环境可接受 8.4+，CI 最终以项目锁定版本为准）
- Laravel 13
- MySQL
- Redis / Laravel Queue
- Docker Compose（可使用 Laravel Sail，只要开发命令稳定且文档明确）
- Pest 或 PHPUnit（二选一，建议沿用 Laravel 默认测试体系）
- Laravel Pint
- PHPStan（Laravel 项目建议配 Larastan）
- Deptrac
- GitHub Actions

## Phase 0 交付物

1. 可启动的 Laravel 空项目。
2. MySQL、Redis 开发环境可启动。
3. Pint、PHPStan/Larastan、Deptrac、测试全部可执行。
4. GitHub Actions 在 Pull Request 上自动执行所有机械检查。
5. AGENTS.md 作为 Agent 导航入口。
6. 中文规则文档：产品、架构、代码质量、测试、垃圾回收、Definition of Done。
7. Issue 与 PR 模板。
8. Review Agent、Weekly GC Agent、Monthly Architecture Agent 的角色配置文档。
9. README 包含本地启动、测试、质量检查命令。
10. 创建公开 GitHub 仓库 `taoking/EventRelay`，使用 feature 分支完成 Phase 0，并通过 Draft PR 验证流程。

## 不做

- Endpoint / Event / Delivery 等业务代码
- RabbitMQ
- 多租户
- Dashboard
- 微服务
- Kubernetes
- 自动 Merge
- GC Agent 自动修改代码

## Phase 0 执行顺序

### 0.1 仓库初始化
- 创建公开仓库 `taoking/EventRelay`
- 默认分支 `main`
- 创建 `feature/phase-0-harness`
- Laravel 初始化
- `.env.example` 不包含真实密钥

### 0.2 开发环境
- MySQL
- Redis
- App
- 明确启动、停止、测试命令

### 0.3 机械质量 Gate
- Pint：格式检查
- PHPStan/Larastan：静态分析
- Deptrac：架构依赖约束
- Unit/Feature Tests
- Laravel boot / smoke test

### 0.4 Agent Context
- `AGENTS.md`
- `docs/架构原则.md`
- `docs/代码质量原则.md`
- `docs/测试原则.md`
- `docs/垃圾回收规则.md`
- `docs/Definition-of-Done.md`

### 0.5 GitHub Workflow
- Issue 模板
- PR 模板
- CI workflow
- Draft PR

### 0.6 验证
必须记录以下证据：
- composer install 成功
- Laravel 应用可启动
- 数据库连接成功
- Redis 连接成功
- Pint PASS
- PHPStan/Larastan PASS
- Deptrac PASS
- Tests PASS
- PR CI PASS

## Phase 0 完成判定

只有以下全部满足才能标记 DONE：

- [ ] 仓库创建并推送
- [ ] feature 分支存在
- [ ] Laravel 可启动
- [ ] MySQL / Redis 可用
- [ ] Pint PASS
- [ ] PHPStan/Larastan PASS
- [ ] Deptrac PASS
- [ ] Tests PASS
- [ ] GitHub Actions PASS
- [ ] 所有规则文档存在
- [ ] PR 模板与 Issue 模板存在
- [ ] Draft PR 已创建
- [ ] PR 中附有 Runtime Evidence
- [ ] 未加入 EventRelay 业务实现

任何未实际执行的验证必须标记 `NOT RUN`；因环境/权限无法执行必须标记 `BLOCKED`，不得写成 PASS。
