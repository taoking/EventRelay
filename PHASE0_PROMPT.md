# Codex 执行 Prompt：EventRelay Phase 0

你正在创建一个新的公开 GitHub 项目：`taoking/EventRelay`。

目标不是实现 EventRelay 业务，而是建立 Phase 0 Agentic Engineering Harness。

## 开始前

1. 阅读本目录中的 `PHASE0_PLAN.md`。
2. 阅读 `AGENTS.md` 及其引用的全部规则文档。
3. 先输出一个简短执行计划、拟创建/修改文件、风险点。
4. 然后直接执行，不要停留在计划阶段。

## 仓库与 Git

- 仓库名：`EventRelay`
- 可见性：public
- 默认分支：`main`
- Phase 0 分支：`feature/phase-0-harness`
- 所有实现都在 Phase 0 分支完成。
- 不直接向 main 开发。
- 完成后创建 Draft PR。
- PR 标题建议：`chore: bootstrap EventRelay agentic engineering harness`

如果 GitHub 登录、权限或网络阻止创建仓库/推送：
- 不要伪造成功；
- 保留本地完整成果；
- 将 GitHub 步骤标记为 `BLOCKED`；
- 给出明确失败命令与错误；
- 其余本地工作继续完成。

## 技术要求

建立：

- PHP 8.5 优先；若当前环境只提供兼容版本，可完成本地准备，但 composer/platform 和 CI 必须明确锁定项目目标版本。
- Laravel 13
- MySQL
- Redis / Laravel Queue
- Docker Compose 或 Laravel Sail
- Laravel Pint
- PHPStan + Larastan
- Deptrac
- Laravel 默认测试体系（PHPUnit/Pest 均可，但不要无意义引入两套）
- GitHub Actions

## 架构目标

Phase 0 只建立边界，不实现业务。

未来代码层次：

HTTP -> Application -> Domain
Infrastructure -> Domain/Application contracts

禁止未来出现：

- Controller 直接操作 Eloquent Model 作为业务实现
- Controller 直接访问 Redis / Queue / HTTP Client
- Domain 依赖 Laravel / Eloquent / Redis / HTTP Client / Queue

使用 Deptrac 建立可执行的基础规则。Phase 0 没有业务层文件时，可以通过占位规则/测试证明配置可运行，但不要创建虚假的业务实现来迎合 Deptrac。

## 机械质量 Gate

本地统一提供一个命令（例如 `composer quality` 或脚本）执行：

1. Pint --test
2. PHPStan/Larastan
3. Deptrac
4. Tests

GitHub Actions 对 Pull Request 与 main push 至少执行同等检查。

不要通过以下方式让检查变绿：

- 降低 PHPStan 等级而不说明原因
- 大量 baseline 屏蔽问题
- 删除测试
- 用 `@ignore` / `@phpstan-ignore` 大面积压制
- 修改规则以适配错误代码

## 规则文档

把本包提供的中文规则文件复制/整合到仓库，并确保 `AGENTS.md` 是短导航，不要膨胀成超长 Prompt。

必须包含：

- `docs/产品需求.md`
- `docs/架构原则.md`
- `docs/代码质量原则.md`
- `docs/测试原则.md`
- `docs/垃圾回收规则.md`
- `docs/Definition-of-Done.md`
- `docs/agents/代码审查Agent.md`
- `docs/agents/GC-Agent.md`
- `docs/agents/架构Agent.md`

## GitHub 模板

建立：

- Feature Issue
- Bug Issue
- Tech Debt Issue
- Pull Request Template

PR 模板必须明确区分：

- PASS
- FAIL
- BLOCKED
- NOT RUN

并要求 Runtime Evidence。

## Phase 0 不得做

禁止实现：

- Endpoint
- Event
- Delivery
- Webhook 投递
- Retry
- RabbitMQ
- Dashboard

Phase 0 的产品能力最多只保留 Laravel 默认欢迎页/健康检查。

## 完成前自审

完成实现后：

1. `git diff` 自审。
2. 检查是否包含 API Key、Token、证书、个人绝对路径。
3. 确认没有无关业务代码。
4. 执行全部质量命令。
5. 执行应用启动与 MySQL/Redis Runtime Validation。
6. 创建清晰 commit。
7. Push feature branch。
8. 创建 Draft PR。

## 最终报告格式

### Git
- Repository:
- Branch:
- Commit:
- Draft PR:

### Mechanical Gate
- Pint: PASS / FAIL / BLOCKED / NOT RUN
- PHPStan/Larastan: ...
- Deptrac: ...
- Tests: ...
- GitHub Actions: ...

### Runtime Gate
- Laravel boot: ...
- MySQL: ...
- Redis: ...

### Files
列出核心 Harness 文件。

### Findings / Risks
只报告真实问题。

### Final status
只能是：
- DONE
- INCOMPLETE
- BLOCKED

没有证据不得写 DONE。
