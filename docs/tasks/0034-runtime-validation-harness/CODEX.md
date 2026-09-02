# EventRelay Issue #34 — CODEX Execution Contract

> 本文件是本轮唯一执行合同。
>
> Issue: #34
> https://github.com/taoking/EventRelay/issues/34
>
> Branch: `feature/runtime-validation-harness`
>
> Draft PR 必须 `Closes #34`。
>
> 不要 Merge。
> 不要 Ready for review。
> Independent Review 前 Final status = `INCOMPLETE`。

## 0. Baseline Gate

开始前确认：

```text
main@fce39ce07f42b159dda61119c0387752b73477fd
post-merge CI 33539260903 = PASS
Issue #32 = closed
Issue #34 = open
```

如果 main 已前进，只允许从新的 exact green main 开始并记录新 baseline SHA/CI；无法证明绿色 baseline → `BLOCKED`。

## 1. One-Sentence Goal

建立一个 **隔离、可重复、cleanup-safe、可诊断的 Runtime Validation Harness**，把 EventRelay 已反复使用的 Docker outage / long-running process / HTTP lifecycle orchestration 抽成仓库级能力，并用 bounded sentinel scenarios 证明它可靠。

## 2. Explore First

完整阅读：

```text
AGENTS.md
mandatory docs
Issue #34
plan.md
docs/开发记录.md
composer.json
composer.lock
docker-compose.yml
docker/php/Dockerfile
.github/workflows/ci.yml
.env.example
```

重点阅读：

```text
tests/Feature/RabbitMq/RabbitMqDeliveryConsumerLifecycleTest.php
tests/Feature/Console/WorkDeliveryOutboxCommandTest.php
tests/Feature/Operations/MySqlWritableReadinessTest.php
tests/Feature/CoreList/CoreListPaginationConcurrencyTest.php
```

搜索：

```text
pcntl_fork
posix_kill
stream_socket_pair
sleep(
usleep
SIGTERM
waitFor
docker
Process
```

继续检查：

```text
ConsumeRabbitMqDeliveriesCommand
WorkDeliveryOutboxCommand
Outbox publication path
ProcessPendingDelivery
Rabbit topology/config
Operations auth/readiness
Delivery/Outbox schema
```

先输出中文 Explore：

```text
现有 process-control patterns
现有 eventual/barrier patterns
Docker assumptions / fixed-port risks
cleanup/orphan risks
Symfony Process/pcntl availability
runtime topology
runner CLI
minimal support primitives
R-01..R-05 mapping
AC-01..AC-15 mapping
```

未完成 Explore 不要实现。

## 3. Scope / Architecture Boundary

这是 test/tooling infrastructure。

允许新增/修改：

```text
scripts/**
tests/Runtime/**
tests/Unit/Runtime/** 或等价 harness self-tests
docker-compose.runtime.yml 或等价独立 runtime topology
composer.json runtime script
.github/workflows/ci.yml 独立 runtime-harness job
README / developer docs必要部分
docs/tasks/0034-runtime-validation-harness/**
docs/开发记录.md
plan.md
```

默认禁止修改 production business behavior：

```text
app/Domain/**
app/Application/** business semantics
app/Infrastructure/** business semantics
routes/api.php product APIs
business migrations/schema
```

如果 Harness 暴露 Rabbit/Outbox/Operations 等 production defect：

```text
STOP
→ 记录真实 reproduction
→ Final status = BLOCKED
```

不要顺手修产品代码。只有没有更小 tooling 方案且属于纯 testability change 时才可提出并明确记录，默认不要做。

## 4. Dedicated Runtime Compose

禁止 destructive 使用默认：

```text
docker-compose.yml
project = eventrelay
fixed ports = 8000/3306/6379/5672/15672
```

新增独立 runtime topology，推荐：

```text
docker-compose.runtime.yml
```

包含：

```text
app
mysql:8.4
redis:7
rabbitmq:4.3
```

复用现有 Dockerfile和技术版本，不创建第二套产品环境。

### 4.1 Unique project/run ID

每次自动生成安全 run id。

Compose project：

```text
eventrelay-runtime-<run-id>
```

必须：

```text
safe lowercase chars
bounded length
nonempty
not "eventrelay"
not arbitrary unvalidated user input
```

CI允许传：

```text
EVENTRELAY_RUNTIME_RUN_ID=ci-<github-run-id>-<attempt>
```

仍要 validate。

### 4.2 Ownership

Runtime resources添加可机械验证 label，例如：

```text
com.eventrelay.runtime=true
com.eventrelay.runtime.run-id=<run-id>
```

同时验证 Docker Compose标准 project/service labels。

任何 destructive operation前必须：

1. project name符合 runtime prefix；
2. resource属于当前 project；
3. runtime ownership label匹配当前 run；
4. service在 allowlist。

`project=eventrelay` 必须 fail closed。

禁止：

```text
docker stop <arbitrary user container>
docker rm <unverified id>
docker system prune
```

### 4.3 No fixed ports

Runtime topology不得固定发布：

```text
8000
3306
6379
5672
15672
```

App HTTP使用动态 host port并由 runner查询实际 mapping。

DB/Redis/Rabbit默认走 internal Compose network，不 publish host ports，除非 Explore证明必须；若必须也只能 dynamic。

Harness必须能在默认开发 stack已经运行时执行，不要求用户停止开发环境。

## 5. Canonical Runner

推荐稳定命令：

```text
composer runtime -- list
composer runtime -- run isolation-smoke
composer runtime -- run mysql-outage
composer runtime -- run rabbit-consumer
composer runtime -- run outbox-worker
composer runtime -- suite
```

准确命名可按仓库风格微调，但必须支持：

```text
list scenarios
run one named scenario
run sentinel suite
stable zero/nonzero exit
```

Unknown scenario/invalid args → nonzero + concise usage。

不要建立 generic plugin/framework；fixed scenario registry足够。

`composer quality` 不调用 runtime。

## 6. Process Abstraction

优先使用可靠 process abstraction。当前依赖图已有 Symfony Process；如果 Harness源码直接依赖它且项目治理要求 direct dependency，可显式加入 `require-dev symfony/process`，不要引入其它 process/chaos framework。

Managed process至少支持：

```text
argv array
working directory
environment allowlist
start
isRunning
bounded stdout tail
bounded stderr tail
signal
graceful deadline
SIGKILL fallback
exit code
wait/reap
```

禁止把 run id/token等直接拼进 shell command。

如果需要 shell，严格验证/escape，优先 argv。

目标真实进程包括：

```text
docker compose exec -T app ... php artisan deliveries:consume-rabbitmq
docker compose exec -T app ... php artisan outbox:work
```

具体 wrapper必须经过真实 signal propagation验证。

## 7. Signal / Reaping

Harness自身处理：

```text
SIGINT
SIGTERM
```

收到后：

```text
stop starting new scenario
terminate managed children
bounded wait
KILL fallback
reap
cleanup owned Docker resources
return nonzero/cancelled semantic
```

Managed child：

```text
TERM
→ bounded deadline
→ KILL
→ wait/reap
```

不得留下 zombie/orphan。

添加 automated controlled-process self-test验证 TERM→KILL fallback。

## 8. Eventually / Deadline

实现一个统一 bounded wait primitive：

```text
monotonic clock
deadline
bounded polling interval
condition callback
last observation
timeout exception/diagnostic
```

禁止：

```text
while(true)
unbounded polling
random sleep作为同步
大段 blind sleep
```

Rabbit scenario“跨 idle timeout window”可以有语义所需等待，但：

```text
consumer ready
message ack
DB state
service health
```

仍必须 eventually/state-based验证。

不要为迁移而重写所有旧 Feature tests。

## 9. HTTP Probe

支持：

```text
GET/POST/PUT/DELETE
status
JSON
headers
Bearer
timeout
```

通过 dynamic app port访问真实 HTTP server。

Runtime operations：

```text
OPERATIONS_ENDPOINTS_ENABLED=true
OPERATIONS_BEARER_TOKEN=<run-scoped token>
```

Token永不进入：

```text
stdout/stderr
diagnostic
EVIDENCE
PR Body
```

Evidence只写 `REDACTED`。

不要 dump完整 environment。

## 10. Docker Lifecycle API

Service allowlist：

```text
app
mysql
redis
rabbitmq
```

Destructive操作必须 ownership-check。

例：

```text
assert project safe
resolve current project mysql
assert compose project label
assert runtime run-id label
stop via owned compose project
```

Start/restart后使用 health/state eventual。

Cleanup使用：

```text
docker compose -p <owned> -f <runtime> down -v --remove-orphans
```

或等价 project-scoped方式。

## 11. Diagnostics / Redaction

场景失败至少输出 bounded：

```text
scenario/phase
last observation
owned compose ps
service health
last N docker log lines
managed stdout tail
managed stderr tail
last HTTP status + bounded redacted body
```

集中 Redactor必须去除：

```text
Authorization Bearer
operations token
DB password/DSN
Rabbit password
signing secret
raw key
```

Automated test必须把 marker secret放入原始诊断，再证明 rendered output不存在。

限制日志 lines/bytes，避免 CI 爆量。

## 12. Cleanup Correctness

建立 cleanup stack/resource registry或等价机制。

以下全部进入 cleanup：

```text
success
assertion failure
unexpected exception
partial compose startup
migration failure
HTTP failure
SIGINT
SIGTERM
```

Cleanup必须：

```text
idempotent
bounded
continue-on-cleanup-error where safe
partial-start safe
service-already-stopped safe
```

最终 audit：

```text
owned containers = 0
owned networks = 0
owned volumes = 0
managed children = 0
```

若 cleanup失败：

```text
final exit nonzero
report exact owned residue
```

绝不能扩大清理到 foreign resources。

## 13. R-01 Isolation / Smoke

真实 Docker：

1. snapshot default `eventrelay` Compose resources；
2. create unique run/project；
3. up app/MySQL/Redis/Rabbit；
4. wait health；
5. fresh migrate；
6. enable operations runtime token；
7. discover dynamic HTTP port；
8. real HTTP：
   ```text
   live 200
   ready 200
   ```
9. verify ownership labels；
10. cleanup；
11. verify runtime residue zero；
12. verify default project unchanged。

如果默认 dev stack正在运行，Harness必须共存而非要求停止它。

## 14. R-02 Physical MySQL Outage / Recovery

真实 owned stack：

```text
live=200
ready=200
record app container identity

ownership(mysql)=PASS
physical stop mysql
wait stopped

live=200
ready=503

start mysql
wait healthy
ready=200

app identity unchanged
```

不得用：

```text
mock
SET SESSION TRANSACTION READ ONLY
```

替代 physical service outage。

## 15. R-03 Rabbit Continuous Consumer

真实 command：

```text
php artisan deliveries:consume-rabbitmq
```

通过 managed process启动。

Gate：

1. runtime transport=rabbitmq；
2. deterministic queue state；
3. start continuous consumer；
4. mechanically detect consumer ready；
5. 跨过至少一个 consumer idle wait/timeout window；
6. consumer仍alive；
7. 创建并发布 **valid delivery envelope**；
8. normal consumer调用 `ProcessPendingDelivery`；
9. 不依赖公网；
10. message ACK / queue drained；
11. chosen deterministic business state可验证；
12. SIGTERM；
13. exit 0；
14. no orphan。

### No-public-internet requirement

禁止公网 receiver。

可选 deterministic fixture：

- valid pending Delivery，其 target在安全策略下 deterministic fail并由业务正常 finalize/return，然后 consumer ACK；
- 或其它无需外网、不会 throw unknown exception 的 valid Delivery状态。

“成功消费”指 valid envelope进入正常 consumer/business path并 ACK，不要求 Delivery最终必须 succeeded。

禁止使用：

```text
malformed envelope
unknown version
reject/no-requeue
```

冒充 R-03。

## 16. R-04 Real Outbox Worker

真实：

```text
php artisan outbox:work --limit=1 --sleep=<bounded>
```

不要 mock `OutboxWorkerSleeper`。

推荐 fixture：

```text
Redis transport
2 due Outbox intents
```

步骤：

1. start worker；
2. eventually first row becomes published / first cycle完成；
3. second row仍 due/pending；
4. establish worker in idle window；
5. SIGTERM；
6. exit 0；
7. second row仍 pending；
8. publication attempts证明没有第二 cycle；
9. no orphan。

如果无法可靠机械判断 idle window，优先用：

```text
first cycle state transition
+ production configured sleep duration
+ process state
```

设计 bounded gate；不要随意修改 production command增加测试 hook。

若无更小方案，STOP并报告。

## 17. R-05 Repeatability

同一 host连续执行：

```text
suite A
cleanup A
suite B
cleanup B
```

必须：

```text
A/B different projects
A PASS
B PASS
A residue zero
B residue zero
default project unchanged
no port collision
```

本地 exact-SHA validation必须做两次。

CI可以：

- 直接 repeat 2；或
- 若稳定时长明显过大，CI执行单 suite，而 exact-SHA evidence记录 repeat 2。

不能把 CI single run误写成 R-05。

## 18. Harness Failure-path Self Tests

普通 automated suite至少：

### F-01 Scenario exception

```text
scenario fails
runner nonzero
cleanup invoked
```

### F-02 Partial startup

```text
partial resource registration
cleanup idempotent
```

### F-03 Ownership rejection

```text
project=eventrelay → reject
wrong run label → reject
foreign resource → reject
```

### F-04 Forced child kill

controlled process ignores graceful window：

```text
TERM
timeout
KILL
reap
```

### F-05 Redaction

raw diagnostics包含 marker secret：

```text
rendered output must not contain secret
```

这些 self-tests进入 `composer quality`。

## 19. Preserve Existing Correctness Tests

不要因为新 Harness删除：

```text
RabbitMqDeliveryConsumerLifecycleTest
WorkDeliveryOutboxCommandTest
MySqlWritableReadinessTest
CoreListPaginationConcurrencyTest
```

以及其它现有 reliability tests。

Harness补充真实 system lifecycle；focused Feature tests继续提供快速机械 correctness gate。

小范围抽 `tests/Support` helper只有在不改变 skip/CI semantics且明显减少重复时才做，默认不迁移。

## 20. Composer

新增独立 runtime script，例如：

```json
"runtime": "@php scripts/runtime.php"
```

必须保证现有：

```text
composer quality
```

的命令列表/语义不加入 runtime。

README/dev docs明确：

```text
composer quality = mechanical gate
composer runtime = isolated system runtime gate
```

## 21. CI

现有 `.github/workflows/ci.yml` 的 `quality` job保留职责，不改 ruleset。

新增 sibling：

```text
runtime-harness
```

建议：

```text
runs-on ubuntu-latest
bounded timeout
checkout
PHP 8.5
composer install
unique EVENTRELAY_RUNTIME_RUN_ID
composer runtime -- suite
always cleanup
```

Runtime job不使用 GitHub Actions `services:` 作为其 destructive fault targets；自己通过 runtime Compose创建 MySQL/Redis/Rabbit。

CI cleanup safety net必须使用明确 run id/project，不允许全局 prune。

例如：

```text
EVENTRELAY_RUNTIME_RUN_ID=ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}
```

最终 `if: always()`：

```text
composer runtime -- cleanup-current
```

或等价明确 owned cleanup命令。

如果 runner正常 cleanup已完成，final cleanup重复执行必须安全。

## 22. Ruleset

不要修改 GitHub branch protection / ruleset。

当前 required `quality` 保持。

是否未来把 `runtime-harness`设为 required check属于单独决策。

## 23. Security

Runtime test-only credentials可以固定或 run-scoped，但不得混用真实 secrets。

禁止输出：

```text
operations bearer token
DB password
Rabbit password
Authorization header
signing secrets
```

不要上传包含 secrets 的 artifacts。

## 24. Scope Guard

禁止本 Issue：

```text
Rabbit production redesign
Outbox production redesign
Retry/Stale changes
Redis outage scenario migration
Retention
DLQ scaling
Toxiproxy
Chaos Mesh
Kubernetes
load/perf
new product APIs
generic test framework/package
external monitoring
ruleset changes
```

Runtime发现 production defect：

```text
record exact reproduction
BLOCKED
stop scope expansion
```

## 25. Validation Commands

至少：

```text
targeted Harness self tests
composer quality
composer runtime -- list
composer runtime -- run isolation-smoke
composer runtime -- run mysql-outage
composer runtime -- run rabbit-consumer
composer runtime -- run outbox-worker
composer runtime -- suite
composer runtime -- suite   # R-05 second run
```

所有 Runtime必须离线可完成，不依赖 public Internet。

## 26. Runtime Evidence

每个 scenario记录：

```text
run/project id
ownership verification
phase observations
child exit
cleanup
residue
default project safety
```

R-02：

```text
app identity before/after
mysql physical stop
live200
ready503
mysql healthy
ready200
```

R-03：

```text
consumer ready
idle window crossed
still alive
valid envelope published
normal ProcessPendingDelivery path
ACK / queue empty
SIGTERM
exit0
no orphan
```

R-04：

```text
two due intents
first cycle exactly one
second pending before SIGTERM
exit0
second still pending
no next cycle
no orphan
```

R-05：

```text
A != B
both PASS
both zero residue
```

敏感内容只写：

```text
REDACTED
```

## 27. First Functional Commit

首个 functional commit必须包括：

```text
runner
runtime support primitives
runtime compose topology
sentinel scenarios
failure-path tests
composer runtime entrypoint
runtime-harness CI job
ISSUE.md
CODEX.md
README/dev docs
docs/开发记录.md concise index
```

推荐：

```text
test: add isolated runtime validation harness (#34)
```

targeted green后 commit。

然后：

```text
DO NOT PUSH
```

## 28. Exact SHA Validation

记录：

```text
validated_implementation_head=<functional SHA>
```

这个 exact code SHA上完成：

```text
F-01..F-05
targeted
composer quality
runtime list
R-01
R-02
R-03
R-04
R-05 repeat
cleanup residue audit
default dev project safety audit
```

如果 Harness代码改变：

```text
new code SHA
→ relevant validation rerun
```

最终 docs-only commit不能替代 validated implementation head。

## 29. EVIDENCE.md

创建：

```text
docs/tasks/0034-runtime-validation-harness/EVIDENCE.md
```

至少记录：

```text
baseline SHA + postmerge CI
validated_implementation_head
F-01..F-05
R-01..R-05
AC-01..AC-15
composer quality
runtime commands
owned cleanup residue
default project safety
```

状态只允许：

```text
PASS
FAIL
BLOCKED
NOT RUN
```

首次 push前：

```text
PR CI = NOT RUN
Independent Review = NOT RUN
Final = INCOMPLETE
```

## 30. CI Optimization

执行：

```text
Explore
→ Plan
→ Implement
→ targeted
→ functional code commit
→ DO NOT PUSH
→ exact SHA full local/runtime validation
→ self review
→ EVIDENCE/plan/dev-record docs commit
→ first/single push
→ Draft PR
→ one CI workflow run
```

该 workflow run可以有：

```text
quality job
runtime-harness job
```

这不是“两次 CI”；不要额外 push。

只有 CI发现真实 Harness/code问题才允许 remediation push。

禁止为了 CI run number制造 docs-only commit。

## 31. Draft PR

Title：

```text
test: add reusable isolated runtime validation harness (#34)
```

Body至少：

```text
Closes #34
Baseline
Runner
Runtime Compose/isolation
Ownership guard
Dynamic ports
Process lifecycle
Eventually/deadline
Cleanup
Diagnostics/redaction
F-01..F-05
R-01..R-05
AC-01..AC-15
validated_implementation_head
current PR HEAD
quality job
runtime-harness job
Independent Review = NOT RUN
```

保持 Draft。

## 32. Self Review

逐项回答：

```text
1. 所有 destructive operation是否先验证 owned project/resource？
2. project=eventrelay是否机械拒绝？
3. 是否无固定 runtime host ports？
4. 默认开发 stack正在运行时是否可共存？
5. correctness同步是否没有 random sleep？
6. 所有 waits是否 bounded？
7. child TERM失败是否 KILL+reap？
8. PASS/FAIL/SIGINT/SIGTERM cleanup是否都有路径？
9. cleanup是否可能误删 foreign resource？
10. diagnostics是否 bounded + redacted？
11. Rabbit gate是否 valid envelope，不是 malformed reject？
12. Rabbit gate是否完全不依赖公网？
13. Outbox gate是否真实 continuous process，不是 mock sleeper？
14. SIGTERM后是否真实证明 no next cycle？
15. suite两次是否 zero residue？
16. composer quality是否没有隐式 runtime？
17. quality job职责是否没变？
18. ruleset是否没改？
19. production business behavior是否没变？
20. validated SHA是否真跑完 R-01..R-05？
```

任何一项不能证明：

```text
INCOMPLETE
```

## 33. Final Chinese Report

最终返回：

```text
## Issue #34

Baseline:
...

Branch:
...

Commits:
...

Draft PR:
...

Current HEAD:
...

Latest CI:
- workflow:
- quality:
- runtime-harness:

Harness:
- runner:
- runtime compose:
- project ownership:
- dynamic port:
- process manager:
- eventually:
- cleanup:
- diagnostics/redaction:

Self Tests:
F-01 ...
F-02 ...
F-03 ...
F-04 ...
F-05 ...

Runtime:
R-01 ...
R-02 ...
R-03 ...
R-04 ...
R-05 ...

Acceptance:
AC-01 PASS
...
AC-15 PASS

Existing regression:
composer quality ...

Traceability:
ISSUE.md YES
CODEX.md YES
EVIDENCE.md YES
validated_implementation_head ...

CI optimization:
push count:
workflow runs:
extra docs-only CI: NO

Production business code changed:
NO / explain exception

Risks / NOT RUN:
Independent Review: NOT RUN

Final status:
INCOMPLETE
```

不要 Merge。
不要 Ready for review。
