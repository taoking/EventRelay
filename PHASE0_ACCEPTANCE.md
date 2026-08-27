# Phase 0 验收记录

由执行 Agent 填写真实证据，不得预填 PASS。

## Git

- Repository: `https://github.com/taoking/EventRelay`
- Branch: `feature/phase-0-harness`
- Commit: 初始 Harness 提交 `4f6e33c`；最终证据见 `feature/phase-0-harness` HEAD
- Draft PR: `https://github.com/taoking/EventRelay/pull/1`

## Mechanical Gate

| Check | Status | Evidence |
|---|---|---|
| Pint | PASS | `composer quality` 在宿主与 PHP 8.5 容器内均通过 |
| PHPStan/Larastan | PASS | PHPStan level 8；宿主与 PHP 8.5 容器均为 `No errors` |
| Deptrac | PASS | 生产规则在宿主与容器均为 0 violations；负向 fixture 被报告为预期的 1 violation |
| Tests | PASS | 宿主与 PHP 8.5 容器均为 2 passed / 2 assertions |
| GitHub Actions | NOT RUN | 等待当前变更推送后的 PR #1 `quality` check |

## Runtime Gate

| Check | Status | Evidence |
|---|---|---|
| Laravel boot | PASS | `docker compose exec -T app php artisan about`：Laravel 13.29.0 / PHP 8.5.9 |
| MySQL | PASS | 容器 healthcheck healthy；`migrate --force` 成功；`DB::select('select 1 as ok')` 返回 1 |
| Redis | PASS | 容器 healthcheck healthy；`Redis::connection()->ping()` 返回 `PONG` |

## Independent Review Remediation

| Finding | Status | Evidence |
|---|---|---|
| High：`main` 允许绕过 Mechanical Gate | PASS | active [ruleset #21665762](https://github.com/taoking/EventRelay/rules/21665762) 应用于默认分支；要求 Pull Request 与严格 `quality` check；无 bypass actor，当前用户 `current_user_can_bypass=never` |
| High：Deptrac 缺少可证明的负向验证 | PASS | `composer architecture:negative` 在测试 fixture 中构造 `Domain -> Framework`；Deptrac 报告 1 violation，验证脚本返回 0；fixture 不在 `app/` |
| Medium：PR / 验收 CI 证据漂移 | NOT RUN | 等待当前 HEAD 的 `quality` check 后同步 PR #1 与本记录 |

## Scope Check

- [x] 没有 Event / Endpoint / Delivery 业务实现
- [x] 没有 Secret
- [x] 没有个人绝对路径
- [x] 没有为了通过 CI 弱化规则

## Final Status

`INCOMPLETE`（等待当前 HEAD CI 与 PR 证据同步）
