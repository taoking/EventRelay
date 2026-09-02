# Issue #34 — test: add reusable isolated runtime validation harness

> 来源：https://github.com/taoking/EventRelay/issues/34

## Context

EventRelay 的可靠性链路已经多次依赖真实 Runtime Gate：MySQL/RabbitMQ/Redis outage、continuous consumer、Outbox worker SIGTERM、真实 HTTP、独立进程/barrier 与清理。当前这些 orchestration 模式分散在 Feature tests 与各 Issue 的 EVIDENCE 中，仓库还没有独立、可重复、cleanup-safe 的 Runtime Validation Harness。

Baseline：

- `main@fce39ce07f42b159dda61119c0387752b73477fd`
- post-merge CI `33539260903 — PASS`

## Goal

建立第一版仓库级 Runtime Validation Harness，把已经验证成熟的 lifecycle/outage orchestration 抽成可复用能力，并用少量 sentinel scenarios 证明：

- 隔离环境可重复启动/销毁；
- destructive service operations 绝不影响默认开发栈；
- 长运行 Artisan process 可观测、可发 signal、可超时回收且无 orphan；
- HTTP/MySQL/service readiness 使用 bounded polling/deadline，而不是随机 sleep；
- PASS/FAIL/SIGINT/SIGTERM 后均可靠 cleanup，并输出可诊断且脱敏的 evidence。

## Locked boundaries

- Runtime Harness 是独立 Gate；现有 `composer quality` 的 Pint/PHPStan/Deptrac/tests 语义保持不变。
- 不迁移全部历史 Runtime；第一版只做最小高价值 sentinel suite。
- Harness 必须使用 run-scoped、唯一且可证明 ownership 的 Docker Compose project；stop/restart/down 只能作用于 harness-owned resources。
- 不得直接 destructive 操作默认 `eventrelay` Compose project。
- Harness Compose 不依赖固定 host ports，避免与本地 8000/3306/6379/5672 冲突。
- readiness/等待必须使用 bounded eventual polling、barrier 或显式 process/service state；随机 sleep 不得作为 correctness synchronization。
- cleanup 必须幂等，覆盖 success、scenario failure、SIGINT、SIGTERM；超时 child process 必须 TERM → bounded wait → KILL fallback。
- failure diagnostics 可以包含 bounded stdout/stderr/docker log tail/service state，但不得泄漏 token/password/secret/DSN。
- 不改变 EventRelay production business behavior。
- 不修改 branch protection/ruleset。

## Canonical entrypoint

提供仓库内稳定入口（最终命名按现有 Composer/script 风格确定），至少支持：

- list scenarios；
- run one named scenario；
- run sentinel suite；
- stable non-zero exit on failure。

`composer quality` 不隐式执行 Runtime Harness。

## Sentinel scenarios v1

### R-01 Harness isolation / smoke

- 创建唯一 runtime project；
- clean schema/migration；
- 启动实际 HTTP；
- `/internal/health/live` 与 `/ready` healthy；
- 证明默认开发 Compose project未被创建/停止/修改；
- teardown 后无 owned containers/network/volumes/orphan process。

### R-02 MySQL outage / recovery

在 harness-owned stack：

- live=200 / ready=200；
- physical stop owned MySQL；
- live=200 / ready=503；
- restart + bounded wait；
- ready=200，无需重启 app。

### R-03 Rabbit consumer lifecycle

- Rabbit transport；
- 启动真实 continuous `deliveries:consume-rabbitmq`；
- consumer 经历 idle timeout 后仍存活；
- 后续消息可以成功消费；
- SIGTERM 后 exit 0；
- 无 orphan consumer。

### R-04 Outbox worker lifecycle

- 启动真实 continuous `outbox:work`；
- worker 进入 idle/sleep；
- SIGTERM 后正常退出；
- 不开始额外 publication cycle；
- 无 orphan worker。

### R-05 Repeatability / cleanup

sentinel suite 连续执行两次：

- 两次都 PASS；
- project/run IDs 不冲突；
- 每次结束后无 harness-owned container/network/volume/child process 残留。

## Acceptance Criteria

- AC-01 canonical runner 可 list / run one / run sentinel suite，并有稳定 exit code。
- AC-02 run-scoped isolated Compose ownership guard；destructive operations 不能触及默认开发栈。
- AC-03 无固定 host-port collision；同机已有开发 stack 时仍可运行。
- AC-04 service/process readiness 使用 bounded deadline/eventual/barrier，无随机 sleep correctness gate。
- AC-05 long-running process 支持 stdout/stderr capture、TERM、deadline、KILL fallback、exit status 与 no-orphan。
- AC-06 cleanup 在 PASS/FAIL/SIGINT/SIGTERM 下均幂等且完整。
- AC-07 failure diagnostics 有 bounded tails/state 且敏感信息脱敏。
- AC-08 R-01 PASS。
- AC-09 R-02 PASS。
- AC-10 R-03 PASS。
- AC-11 R-04 PASS。
- AC-12 R-05 PASS，并有 harness failure-path self-test 证明 non-zero + cleanup。
- AC-13 existing `composer quality` 语义不变且继续 PASS；Runtime 作为独立 CI job/gate，不改 ruleset。
- AC-14 不改变 production business behavior；现有 Rabbit/Outbox/Operations/Redis/MySQL/concurrency 回归 PASS。
- AC-15 repository-native traceability、exact validated implementation SHA、single-push/Draft-PR/CI evidence 完整。

## CI

新增独立 `runtime-harness` CI job，运行 bounded sentinel suite。不要把 destructive Runtime 塞进 `composer quality`。Harness job 自己创建并销毁隔离 Docker resources；失败也必须执行 cleanup/diagnostics。

## Out of Scope

- 迁移所有历史 Runtime scenario；
- Kubernetes/deployment；
- Toxiproxy/Chaos framework；
- Redis outage scenario（可后续追加）；
- load/performance benchmark；
- retention/data lifecycle；
- 新业务功能；
- generic testing framework/package；
- branch protection/ruleset 调整；
- external Prometheus/Grafana/Alertmanager。

## Delivery

建议分支：`feature/runtime-validation-harness`

Repository-native task files：

```text
docs/tasks/<issue>-runtime-validation-harness/
├── ISSUE.md
├── CODEX.md
└── EVIDENCE.md
```

流程：Explore → Plan → Implement → targeted → first functional commit（不 push）→ exact-SHA local/Docker Runtime validation → self-review → EVIDENCE/docs commit → single push → Draft PR → CI。

Independent Review 前最终状态必须为 `INCOMPLETE`。不要 Merge，不要 Ready for review。
