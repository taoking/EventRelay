# Issue #34 Runtime Validation Harness — Evidence

## 基线与精确实现

- baseline：`main@fce39ce07f42b159dda61119c0387752b73477fd`
- baseline CI：[33539260903](https://github.com/taoking/EventRelay/actions/runs/33539260903) = `PASS`
- Issue #32：`CLOSED`
- Issue #34：`OPEN`
- branch：`feature/runtime-validation-harness`
- validated_implementation_head：`f9a08906fbd75052632d4f980900afffae996fad`
- 首个功能提交：`f9a08906fbd75052632d4f980900afffae996fad`，尚未 push。

## Harness 设计事实

- canonical runner：`composer runtime -- list|run <scenario>|suite|cleanup-current`；未知场景返回非零。
- runtime Compose：`docker-compose.runtime.yml`，复用 PHP 8.5 Dockerfile、MySQL 8.4、Redis 7 与 RabbitMQ 4.3；没有固定 host port，只有 app HTTP 由 Docker 分配动态端口。
- ownership：project 仅允许 `eventrelay-runtime-*`；每一个 container、network、volume 带 `com.eventrelay.runtime=true` 与精确 run-id label。`eventrelay`、非 allowlist service 和错误 label 均 fail closed。
- 受管进程：argv、环境 allowlist、16 KiB stdout/stderr tail、TERM→有界等待→KILL→reap；长运行 Artisan 额外报告容器内 PID，TERM 精确发给已报告的 owned child。
- 等待：`hrtime()` monotonic deadline + 100ms bounded polling；没有随机 sleep correctness gate。
- cleanup：PASS、异常、partial startup、SIGINT/SIGTERM 均进入同一 owned cleanup phase；cleanup 期间仍执行有界 `down`、residue audit 与 default-project audit。
- diagnostics：包含 bounded managed-process tail、owned compose ps、每服务最多 40 行 log；token、DB/RabbitMQ credential 和 `whsec_` 被统一替换为 `REDACTED`。
- production business code changed：`NO`。

## Harness self-tests

命令：

```text
php artisan test tests/Unit/Runtime
```

结果：`PASS`，8 tests / 16 assertions。

- F-01 Scenario exception：`PASS`。失败结果非零且 cleanup 执行。
- F-02 Partial startup：`PASS`。cleanup LIFO、继续处理 cleanup error、第二次 cleanup 幂等。
- F-03 Ownership rejection：`PASS`。`eventrelay` project、错误 run label 与 foreign service 均拒绝。
- F-04 Forced child kill：`PASS`。受控 TERM-ignoring child 被 KILL 并 reap。
- F-05 Redaction：`PASS`。marker secret 与 Authorization 不出现在 rendered diagnostics。
- Cancellation：`PASS`。已请求的 cancellation 会中断 bounded eventual。
- CLI：`PASS`。unknown scenario 不启动 Docker 并返回稳定 exit=1。

## Runtime R-01 ～ R-05

所有运行均使用本机 Docker Desktop、isolated Compose project，且本机默认 `eventrelay` 四个开发容器在运行期间保持 `Up`；Harness 从未 stop、restart、down 或重建该默认项目。

### R-01 Isolation / Smoke

`local-evidence-a-isolation-smoke`：`PASS`

- project：`eventrelay-runtime-local-evidence-a-isolation-smoke`
- dynamic app port：`55048`
- ownership labels：`verified`
- `live=200`、`ready=200`
- cleanup：`zero-residue`

### R-02 Physical MySQL Outage / Recovery

`local-evidence-a-mysql-outage`：`PASS`

- project：`eventrelay-runtime-local-evidence-a-mysql-outage`
- dynamic app port：`55049`
- physical owned MySQL stop：`PASS`
- live=200、ready=503：`PASS`
- owned MySQL start + healthy + ready=200：`PASS`
- app container identity unchanged：`true`
- cleanup：`zero-residue`

### R-03 Rabbit Continuous Consumer

`local-evidence-a-rabbit-consumer`：`PASS`

- project：`eventrelay-runtime-local-evidence-a-rabbit-consumer`
- dynamic app port：`55050`
- consumer ready：`PASS`
- 已跨过一个 1.2s bounded idle wait window，consumer remained alive：`PASS`
- valid Delivery envelope：Delivery `9853c731-ef29-4e76-908d-cfc26273e366`
- normal `ProcessPendingDelivery` path：target 为安全策略确定性拒绝的 `127.0.0.1`，Delivery 正常进入已知失败终态，非 malformed reject。
- Rabbit ACK / queue empty：`PASS`
- owned Artisan child SIGTERM 后 exit=0：`PASS`
- no orphan + cleanup：`PASS`

### R-04 Real Outbox Worker Lifecycle

`local-evidence-a-outbox-worker`：`PASS`

- project：`eventrelay-runtime-local-evidence-a-outbox-worker`
- dynamic app port：`55051`
- two due intents：Delivery `2043b211-9d37-496c-8e7a-c4397b658765` 与 `fc5fde2a-db87-476e-81a0-e34005ff46af`
- real `outbox:work --limit=1 --sleep=10` first cycle：exactly one first Outbox row published。
- SIGTERM 前 second intent：`pending` 且 `publication_attempts=0`
- owned Artisan child SIGTERM 后 exit=0，stdout 仅一个 worker cycle：`PASS`
- second intent 仍 pending、没有 next cycle、no orphan + cleanup：`PASS`

### R-05 Repeatability / Cleanup

两次完整 suite：`PASS`

- A：base run id=`local-evidence-a`；R-01..R-04 均 `PASS`，所有 scenario cleanup=`zero-residue`。
- B：base run id=`local-evidence-b`；R-01..R-04 均 `PASS`，所有 project 与 A 不同，所有 scenario cleanup=`zero-residue`。
- B 的 Rabbit Delivery=`6d982e04-52aa-4995-a4ed-f099cf1ffe19`；Outbox 两个 Delivery=`4dc6b2af-7f21-4cac-9720-d7915728408f`、`be84fd28-41ba-4c61-af03-b9ad6bfb4cbf`。
- two-suite 后 owned container/network/volume/managed child：`0`。
- default `eventrelay` containers：仍为 `eventrelay-app-1`、`eventrelay-mysql-1`、`eventrelay-redis-1`、`eventrelay-rabbitmq-1`，状态为 `Up`。

### SIGTERM cleanup path

- `local-final4-sigterm`：对已通过 R-01 的 suite 主进程发 `SIGTERM`。
- Harness 停止启动下一个 scenario，返回非零 cancellation semantics：`PASS`。
- owned container/network/volume/managed child residue：`0`。
- default project safety：`PASS`。

## Mechanical Gate

```text
composer quality
```

结果：`PASS`

- Pint：`PASS`
- PHPStan / Larastan：`PASS`
- Deptrac：`PASS`
- Deptrac negative validation：`PASS`
- PHPUnit：`PASS`，272 tests，225 passed，1455 assertions，47 environment-specific skipped。
- `composer quality` 未调用 `composer runtime`：`PASS`。

## Acceptance Criteria

- AC-01：`PASS`
- AC-02：`PASS`
- AC-03：`PASS`
- AC-04：`PASS`
- AC-05：`PASS`
- AC-06：`PASS`
- AC-07：`PASS`
- AC-08：`PASS`
- AC-09：`PASS`
- AC-10：`PASS`
- AC-11：`PASS`
- AC-12：`PASS`
- AC-13：`PASS`
- AC-14：`PASS`（production business code 未修改；既有机械回归位于 `composer quality`，Rabbit/Outbox/MySQL 的真实 lifecycle 由 R-02～R-04 补充）。
- AC-15：`NOT RUN`（single push、Draft PR、GitHub CI 在本 evidence commit 后执行）。

## Risks / NOT RUN

- GitHub `quality` / `runtime-harness` CI：`NOT RUN`，等待本轮唯一 push 后执行。
- Draft PR：`NOT RUN`，将在唯一 push 后创建。
- Independent Review：`NOT RUN`。
- 系统仍为 at-least-once；本 Issue 仅建立 Runtime Harness，不改变 Delivery/Outbox/RabbitMQ 业务语义。

Final status：`INCOMPLETE`
