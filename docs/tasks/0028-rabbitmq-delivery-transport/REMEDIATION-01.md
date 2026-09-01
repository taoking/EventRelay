# EventRelay PR #29 — REMEDIATION-01

> 本轮唯一整改执行合同  
> Repo：`taoking/EventRelay`  
> Issue：`#28`  
> Draft PR：`#29`  
> Branch：`feature/rabbitmq-delivery-transport`
>
> Independent Review #1：`5067567804`
>
> Pre-remediation HEAD：
> `fe09ddc0dfc69ea44d6f0d951c9ab8f7577eebbc`
>
> Pre-remediation CI：
> `33398647965 — PASS`
>
> PR 必须继续保持 Draft。  
> 不要 Merge。不要 Ready for review。

## 1. Goal

本轮只修两个 finding：

- **M-01**：continuous RabbitMQ consumer 在空队列 idle timeout 后错误退出。
- **L-01**：`outbox:work` 在 idle sleep 收到 SIGTERM/SIGINT 后可能再启动一个 publication batch。

不要重新设计 Issue #28。

整改后重新证明 AC-06、AC-08、AC-14，并确认原 AC-01～AC-16 无回归。

## 2. Locked Decisions

以下设计已经通过 Independent Review #1，本轮禁止重做：

- MySQL Outbox `available_at` 是唯一业务 scheduling truth。
- `DeliveryTransport` 只负责立即发布 Delivery UUID。
- Redis 保持默认 transport。
- RabbitMQ 使用 durable direct exchange + quorum queue。
- Rabbit message 仍为 persistent UUID-only envelope。
- publisher confirm + mandatory/unroutable detection 不变。
- Consumer manual ACK 不变。
- malformed envelope 继续 `reject(false)`。
- `ProcessPendingDelivery` unknown exception 不得 ACK 为成功。
- Retry 10s / 60s、`MAX_ATTEMPTS=3` 不变。
- Consumer crash → redelivery → stale recovery 不变。
- confirmed publish → missing mark → duplicate 仍属于 at-least-once，由 Delivery atomic claim 吸收。
- transport switch 禁止 dual-write。
- Replay / Ingress Idempotency / DLQ / HMAC / SSRF 不变。

禁止本轮引入：

- Rabbit client 替换；
- topology 重构；
- delayed-message plugin；
- Broker business RetryPolicy；
- Broker DLQ；
- reconnect framework；
- Supervisor/K8s 部署；
- Delivery 状态机重构；
- unrelated refactor。

## 3. Remediation Acceptance Matrix

| ID | Requirement |
|---|---|
| R-AC-01 | continuous consumer 空队列超过多个 wait timeout 后仍存活 |
| R-AC-02 | idle 后 publish，新消息由**同一个** continuous consumer 成功消费 |
| R-AC-03 | idle consumer 收到 SIGTERM 后 graceful exit |
| R-AC-04 | 只把 idle `AMQPTimeoutException` 当正常 tick，其它 AMQP / processor unknown exception 仍传播 |
| R-AC-05 | malformed envelope 仍 reject/no-requeue |
| R-AC-06 | `outbox:work` stop 后不开始下一 publication cycle |
| R-AC-07 | signal 在当前 batch 内时允许当前 batch 完成，但无下一 batch |
| R-AC-08 | `--once`、limit/sleep validation、unknown exception 不回归 |
| R-AC-09 | Issue #28 AC-01～AC-16 全量回归 PASS |
| R-AC-10 | REMEDIATION/EVIDENCE/PR/CI traceability 正确 |

## 4. M-01 Required Fix

目标：

`app/Infrastructure/RabbitMq/PhpAmqpLibRabbitMqDeliveryConsumer.php`

当前 continuous loop 的 timed `wait()` idle timeout 不能导致 daemon 退出。

推荐语义：

```php
while ($channel->is_consuming()) {
    if ($shouldStop()) {
        break;
    }

    try {
        $channel->wait(null, false, 1);
    } catch (AMQPTimeoutException) {
        continue;
    }
}
```

允许等价实现。

关键约束：

> 只允许在 continuous idle `wait()` 边界将 `AMQPTimeoutException` 解释为正常 poll tick。

禁止：

```php
catch (\Throwable) {
    continue;
}
```

也禁止吞掉所有 AMQP exception。

以下错误仍必须传播并导致 consumer 失败/连接关闭：

- connection closed；
- channel closed；
- socket/network failure；
- protocol error；
- `ProcessPendingDelivery` unknown exception；
- programming error。

### Stop semantics

每次 wait 前检查 `shouldStop()`。

timeout tick 后回到循环重新检查。

如果 SIGTERM/SIGINT 在业务处理中到达：

```text
当前 ProcessPendingDelivery 可以正常完成
→ ACK 当前消息
→ 不领取下一条消息
→ close channel/connection
→ success exit
```

如果 signal 在 idle 时到达：

```text
最迟在 bounded wait tick 后退出
```

不要改成无限阻塞 wait。

## 5. M-01 Required Tests

### T-M01-01 — Real idle consumer survives

必须使用真实 RabbitMQ。

流程：

```text
purge Rabbit queue
start continuous consumer child process
queue empty
idle > 2 × wait timeout
assert child process still alive
```

不能只 mock `AMQPChannel::wait()`。

### T-M01-02 — Idle → later consume

继续使用 **T-M01-01 同一个 child consumer**：

```text
consumer 已经历多个 idle timeout
→ parent publish valid UUID envelope
→ same consumer consumes
```

验证：

```text
Delivery expected state
Attempt #1 exactly once
message ACKed
queue empty
```

禁止改成另外启动 `--once` consumer。

### T-M01-03 — SIGTERM while idle

```text
continuous consumer
queue empty
confirmed running
→ SIGTERM
```

最终：

```text
bounded graceful exit
exit success
no Attempt
queue unchanged
```

不要使用 SIGKILL。

### T-M01-04 — Unknown processor exception

valid envelope 下让 processor 抛 unknown exception。

必须证明：

```text
message 没有 ACK 成成功
exception 继续传播
```

真实 Broker 场景下 message 应可 redeliver。

原 consumer crash → redelivery → stale recovery regression 必须继续 PASS。

## 6. L-01 Required Fix

目标：

`app/Infrastructure/Console/Commands/WorkDeliveryOutboxCommand.php`

锁定 invariant：

> stop 已请求后，绝不能开始新的 `publisher->handle()`。

推荐结构：

```php
while (true) {
    if ($stop) {
        return self::SUCCESS;
    }

    $result = $publisher->handle($limit);

    // output...

    if ($this->option('once') === true || $stop) {
        return self::SUCCESS;
    }

    sleep($sleep);
}
```

必须同时保留 batch 后 stop check。

正确语义：

```text
signal before batch
→ batch 不开始

signal during batch
→ current batch 完成
→ no next batch

signal during idle sleep
→ no next batch
```

## 7. L-01 Required Tests

### T-L01-01 — Signal during idle sleep

使用 child process + socket/barrier 做 deterministic test：

```text
child 完成 cycle #1
→ 通知 parent
→ child 进入较长 idle sleep，例如 30s
→ parent SIGTERM
→ child graceful exit
```

最终：

```text
publication cycle count = exactly 1
```

不得出现第二次 `publisher->handle()`。

不要仅依赖随机 sleep/timing guess。

### T-L01-02 — Signal during current batch

barrier 卡在：

```text
batch #1 entered
```

然后 parent：

```text
SIGTERM
→ 允许 batch #1 return
```

最终：

```text
cycle count = 1
no batch #2
exit success
```

### T-L01-03 — Existing contract

继续验证：

```text
--once = exactly one cycle
invalid --limit = failure
invalid --sleep = failure
unknown publisher exception = propagate / non-zero
```

如果没有 unknown exception regression，补一个。

## 8. Runtime R-06 — Continuous Rabbit Consumer Lifecycle

真实 Docker RabbitMQ + MySQL：

```text
1. purge Rabbit queue
2. create pending Delivery + committed Outbox
3. start continuous deliveries:consume-rabbitmq
   注意：不是 --once
4. 暂时不 publish
5. idle > 2 × wait timeout
6. confirm consumer process still alive
7. outbox:publish
8. same consumer consumes Rabbit message
9. verify Delivery / Attempt / queue
10. send SIGTERM
11. consumer graceful exit
```

记录：

- idle duration；
- consumer process alive state；
- Delivery UUID；
- Attempt count/status；
- queue count；
- exit status。

Evidence 禁止记录：

- Rabbit password；
- Event payload；
- target URL；
- secret；
- Idempotency-Key。

## 9. Runtime R-07 — Outbox Worker Graceful Stop

运行：

```text
outbox:work --sleep=<较长时间>
```

证明：

```text
first cycle complete
→ idle
→ SIGTERM
→ no second claim/publication
→ success exit
```

为了精确观察 cycle 可以使用 test runtime binding，但不得改变 production contract。

## 10. Mechanical / Regression Gates

整改后执行：

```bash
composer quality
docker compose exec -T app composer quality
```

Docker 中 RabbitMQ / MySQL / Redis integration 不得 skip。

至少真实执行：

- `RabbitMqDeliveryTransportTest`
- `RabbitMqDeliveryTransportContractTest`
- `WorkDeliveryOutboxCommandTest`
- `PublishDeliveryOutboxCommandTest`
- Outbox tests
- Redis Queue integration
- Retry / Stale
- Replay
- Ingress Idempotency
- DLQ
- HMAC
- SSRF
- 新 continuous lifecycle tests
- 新 outbox worker signal tests

原 Runtime：

```text
R-01 Redis compatibility
R-02 Rabbit E2E
R-03 Rabbit outage/recovery
R-04 Retry timing
R-05 Duplicate confirm window
```

必须继续保持有效，不得回归。

## 11. Traceability

将本文件保存为：

```text
docs/tasks/0028-rabbitmq-delivery-transport/REMEDIATION-01.md
```

目录：

```text
docs/tasks/0028-rabbitmq-delivery-transport/
├── ISSUE.md
├── CODEX.md
├── REMEDIATION-01.md
└── EVIDENCE.md
```

不要把整改合同全文复制进：

```text
docs/开发记录.md
```

这里只增加：

```text
Independent Review #1 = 5067567804
M-01 summary
L-01 summary
remediation implementation commit
validated remediation head
Independent Review #2 = NOT RUN
```

## 12. Commit Boundary

第一份 remediation code commit 必须同时包含：

```text
M-01 production fix
M-01 regression tests
L-01 production fix
L-01 regression tests
REMEDIATION-01.md
docs/开发记录.md concise index
```

推荐：

```text
fix: harden rabbitmq and outbox worker lifecycles (#28)
```

在 commit 前完成 Pint/format，尽量不要再产生独立 formatting commit。

**此时不要 push。**

## 13. Validation / Evidence

记录：

```text
validated_remediation_head=<remediation code commit SHA>
```

必须在这个 exact code commit 上运行：

```text
targeted tests
continuous lifecycle tests
signal tests
composer quality
Docker targeted
Docker composer quality
R-06
R-07
self review
```

全部 PASS 后再创建 evidence/docs commit，更新：

- `EVIDENCE.md`
- `plan.md`
- `docs/开发记录.md`

Evidence 同时保留原：

```text
validated_implementation_head=e38e980dc10f5152f9d9d54416e03e27736550a2
```

并新增：

```text
validated_remediation_head=<new code SHA>
```

禁止使用后续 docs commit SHA 替代被验证的代码 SHA。

## 14. Evidence Update

在 `EVIDENCE.md` 新增：

```text
## Independent Review #1 Remediation

Review ID:
5067567804

M-01:
PASS/FAIL

L-01:
PASS/FAIL

validated_remediation_head:
<sha>

Continuous Rabbit lifecycle:
...

Outbox graceful-stop:
...

R-06:
PASS — ...

R-07:
PASS — ...

composer quality:
PASS

Docker quality:
PASS

Independent Review #2:
NOT RUN
```

原 R-01～R-05 不删除。

## 15. Issue #28 AC Re-evaluation

最终必须重新报告：

```text
AC-01 PASS
AC-02 PASS
AC-03 PASS
AC-04 PASS
AC-05 PASS
AC-06 PASS — continuous idle/consume/SIGTERM evidence
AC-07 PASS
AC-08 PASS — graceful stop / no-next-batch evidence
AC-09 PASS
AC-10 PASS
AC-11 PASS
AC-12 PASS
AC-13 PASS
AC-14 PASS — real Rabbit lifecycle + CI
AC-15 PASS
AC-16 PASS
```

不能只写“两个 finding 已修复”。

## 16. Optimized Git / CI Flow

```text
Explore
→ Implement M-01/L-01
→ targeted
→ remediation code commit
→ 不 push
→ exact code commit full validation
→ R-06 / R-07
→ Evidence/plan/docs commit
→ ONE remediation push
→ PR CI
```

CI PASS 后：

```text
只更新 PR Body
```

不要为了记录 final HEAD / latest CI 再 commit docs。

## 17. PR Body

PR #29 继续 Draft。

补充：

```text
Independent Review #1:
5067567804

M-01:
...

L-01:
...

validated_implementation_head:
e38e980...

validated_remediation_head:
...

R-06:
PASS

R-07:
PASS

AC-01..AC-16:
PASS

Current PR HEAD:
...

Latest CI:
...

Independent Review #2:
NOT RUN
```

保留原 R-01～R-05。

## 18. Definition of Done

必须全部满足：

```text
R-AC-01..R-AC-10 PASS
M-01 closed
L-01 closed

continuous Rabbit consumer:
idle > timeout → alive
later message → same consumer consumes
SIGTERM → graceful exit

outbox worker:
signal during idle → no next batch
signal during current batch → current completes, no next

composer quality PASS
Docker composer quality PASS
R-06 PASS
R-07 PASS

REMEDIATION-01.md committed
EVIDENCE.md updated
validated_remediation_head correct
Draft PR pushed
latest CI PASS

Independent Review #2 = NOT RUN
```

最终状态仍然：

```text
INCOMPLETE
```

不要 Merge。  
不要 Ready for review。

## 19. Codex Final Report

只返回：

```text
PR #29 Remediation #1

Pre-remediation HEAD:
fe09ddc0dfc69ea44d6f0d951c9ab8f7577eebbc

Review:
5067567804

Commits:
...

Current HEAD:
...

Latest CI:
...

M-01:
root cause:
fix:
tests:
PASS

L-01:
root cause:
fix:
tests:
PASS

Remediation Acceptance:
R-AC-01 PASS — ...
...
R-AC-10 PASS — ...

Issue #28:
AC-01 PASS
...
AC-16 PASS

Runtime:
R-06 PASS — ...
R-07 PASS — ...

Automated:
targeted ...
composer quality PASS
Docker quality PASS

Traceability:
REMEDIATION-01.md committed: YES
validated_implementation_head: e38e980...
validated_remediation_head: ...

CI optimization:
remediation push count:
new CI runs:
extra docs-only CI: NO

Independent Review #2:
NOT RUN

Final status:
INCOMPLETE
```