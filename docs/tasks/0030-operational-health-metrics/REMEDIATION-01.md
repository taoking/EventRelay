# Issue #30 — Remediation #1

> 本文件是 Independent Review #1 后的整改执行合同。
>
> Issue: #30
>
> Draft PR: #31
>
> Independent Review #1: `5075157949`
>
> 整改前 current HEAD: `21ee5df8bf2e826624c32adab039da179fe52222`
>
> 不要 Merge。
>
> 不要 Ready for review。
>
> Independent Review #2 前最终状态只能是 `INCOMPLETE`。

## 1. Blocking Finding

### M-01 — readiness 只证明 MySQL 可读，未证明 durable write capability

Issue #30 已锁定 readiness 定义：

```text
API 是否能安全 commit
Event + Delivery + Outbox
durable transaction
```

当前实现只做 `SELECT 1`，因此只能证明 MySQL reachable + 当前连接可读，不能证明 MySQL writable、application connection 有必要写权限、transaction write/commit path 可用。

真实错误场景：

```text
MySQL 在线
SELECT 1 成功
但 server 进入 read-only / application credential 失去写权限
→ /internal/health/ready = 200   ← 错误
→ POST /api/events 的 Event + Delivery + Outbox durable transaction FAIL
```

此问题阻断 AC-03。

## 2. One-Sentence Goal

让 `/internal/health/ready` 真正验证 **MySQL durable write capability**，并以真实 MySQL 场景证明“数据库可连接可读但不可写”时 readiness 必须返回 503。

## 3. Locked Decisions

### R1. Readiness 仍然只依赖 MySQL

必须继续保持：

```text
MySQL durable write unavailable → ready 503
Redis unavailable               → ready 200
RabbitMQ unavailable            → ready 200
```

禁止增加 Redis/RabbitMQ readiness probe。

### R2. Liveness 完全不变

`/internal/health/live` 不访问 MySQL/Redis/RabbitMQ/business filesystem。数据库不可写甚至完全 down 时仍 `/live=200`。

### R3. 必须验证“写能力”

以下不能单独视为修复：

```sql
SELECT 1
SELECT @@read_only
SELECT @@super_read_only
SHOW VARIABLES ...
SELECT FROM information_schema ...
```

它们可用于诊断或测试辅助，但不能替代 application connection 的真实 write/transaction capability 证明。

### R4. Probe 不得修改 EventRelay 业务事实

禁止为 readiness 新建/修改 Event、Delivery、Attempt、Outbox、Endpoint、Replay、Idempotency、DLQ，也不得 enqueue/publish/recover。

Probe 必须与业务数据隔离。

### R5. 不允许留下持续增长的 readiness 数据

允许增加专用 operational readiness storage/migration，但必须满足：

```text
bounded / zero-net-state
+
concurrency safe
+
无业务语义
```

不要使用每次 probe 永久增长的 auto-increment 事实。

### R6. 必须覆盖 transaction/write failure

实现需要证明 `begin/write/commit capability`，或用仓库事实证明等价且更可靠的 MySQL write-capability probe。

如果选择“写后回滚”，必须解释为何足以覆盖 durable transaction 定义；否则优先采用能验证 commit path 且最终 zero-net business state 的方案。

### R7. Fail closed 且不泄漏

明确的 MySQL readiness failure（PDO/Query/write denied/read-only/transaction/commit failure）→ 503 generic body：

```json
{"status":"not_ready","checks":{"mysql":"down"}}
```

禁止泄漏 SQLSTATE、host、port、DB、user、DSN、exception class/message、SQL text。

未知编程错误不要被 `catch (\Throwable)` 过宽吞掉。

## 4. Acceptance Criteria

| ID | Requirement |
|---|---|
| M-AC-01 | MySQL 正常可写时 `/ready=200` |
| M-AC-02 | MySQL 物理 down 时 `/live=200`、`/ready=503` |
| M-AC-03 | MySQL 在线、SELECT 可成功、但 application connection 不可写时 `/ready=503` |
| M-AC-04 | 恢复 MySQL 写能力后无需重启应用，`/ready=200` |
| M-AC-05 | 恢复后真实 `POST /api/events=201`，Event + matching Delivery + Outbox durable commit |
| M-AC-06 | RabbitMQ outage 仍 `/ready=200` |
| M-AC-07 | Redis outage 仍 `/ready=200` |
| M-AC-08 | readiness probe 不创建/claim/recover/publish/enqueue 任何业务状态 |
| M-AC-09 | readiness probe 不产生无界持久 probe 数据 |
| M-AC-10 | 并发 readiness 请求不会产生 false-negative、唯一键冲突或明显串行锁 |
| M-AC-11 | DB 错误响应不泄漏 SQLSTATE/host/user/DSN/exception/SQL |
| M-AC-12 | Issue #30 原 AC-01..AC-15 与历史回归继续 PASS |

## 5. Automated Tests

### T-01 writable success

真实 MySQL：

```text
application DB writable
→ readiness repository = available
→ /ready = 200
```

### T-02 readable-but-not-writable

必须使用真实 MySQL 行为，不是 mock。

构造：

```text
MySQL 仍在线
SELECT 1 可成功
application write 被拒绝
```

根据 Docker/MySQL 8.4 实际环境选择稳定方式：

- read-only / super-read-only；或
- 专门只读 application credential；或
- 其它客观证明 SELECT 成功但 write transaction 失败的 MySQL 配置。

验证：

```text
SELECT probe = success
/readiness = 503
```

### T-03 recovery

恢复写能力后：

```text
/ready = 200
```

并真实创建 matching Endpoint/subscription 后执行：

```text
POST /api/events = 201
Event persisted
Delivery persisted
Outbox persisted
```

### T-04 probe state invariant

多次 readiness 调用前后，业务表状态不得因 readiness 变化。

如增加专用 probe table：

```text
N 次调用前后 logical persistent row count 不增长
```

### T-05 concurrency

至少两个独立 DB connections/processes 并发执行 readiness probe。

必须证明无 unique collision、deadlock、false-negative、明显单行热点锁。

禁止随机 sleep 冒充并发同步；沿用 socket barrier / 现有 concurrency harness。

### T-06 exception boundary

- known DB/write failure → false / 503
- unknown programming exception → 不得静默吞掉

## 6. Docker Runtime Gates

### R-06 — Readable but not writable

真实 Docker MySQL 8.4：

```text
MySQL service = running
application SELECT = success
application write = denied
```

验证：

```text
/live  = 200
/ready = 503
```

`/metrics` 仍是 durable read model：如果 read-only 状态下 aggregate 仍可读，则 `/metrics=200` 合理；不要为了 readiness 人为改成 503。

核心区分：

```text
ready   = ingress durable-write capability
metrics = durable read availability
```

### R-07 — Restore writable ingress

恢复写能力，无需重启 app：

```text
/ready = 200
POST /api/events = 201
Event + Delivery + Outbox committed
```

### R-08 — Broker regressions

至少再次验证：

```text
RabbitMQ down → ready 200
Redis down    → ready 200
```

## 7. Mechanical Gates

```text
targeted tests
composer quality
docker compose exec -T app composer quality
```

全部 PASS。

如新增 migration/table：

- migration forward PASS
- clean DB migration PASS
- 不依赖 destructive rollback
- schema/naming 符合仓库规则

## 8. Evidence

更新：

```text
docs/tasks/0030-operational-health-metrics/EVIDENCE.md
```

新增：

```text
## Remediation #1
```

记录：

```text
Independent Review #1: 5075157949
finding: M-01
validated_remediation_head: <真正执行 targeted/Docker/Runtime 的代码 HEAD>
```

逐项记录 M-AC-01..12、T-01..06、R-06..08、composer quality、Docker composer quality。

必须明确写出：

```text
SELECT succeeded while write was denied
```

以及恢复后：

```text
POST /api/events = 201
Event + Delivery + Outbox committed
```

不要把最终 docs-only commit 当 `validated_remediation_head`。

## 9. Repository Traceability

新增：

```text
docs/tasks/0030-operational-health-metrics/REMEDIATION-01.md
```

本文件必须进入 **首个整改代码提交**。

首个整改代码 commit 同时包含：

```text
readiness remediation code
+
regression tests
+
REMEDIATION-01.md
+
必要的 concise docs/开发记录.md index
```

如有 migration 也放该提交。

最终 evidence/docs commit 可单独存在。

## 10. Git / PR Rules

继续使用：

```text
feature/operational-health-metrics
PR #31
```

不要新开 PR。

流程：

```text
Explore
→ Plan
→ Implement M-01 only
→ targeted tests
→ first remediation code commit
→ validate exact remediation code SHA
→ Docker Runtime R-06/R-07/R-08
→ composer quality
→ Docker composer quality
→ Self Review
→ EVIDENCE/docs commit
→ single remediation push
→ PR CI
```

要求：

- remediation 尽量只 push 1 次
- 不要为了 current HEAD 再制造 docs-only push
- EVIDENCE 记录 validated remediation code HEAD
- PR Body 记录 final current HEAD + latest CI
- 不要 Ready for review
- 不要 Merge

## 11. Self Review Checklist

```text
1. readiness 是否真实执行 write-capability probe？
2. 是否仍可能 SELECT 1 success 但不可写时 ready=200？
3. probe 是否修改业务数据？
4. probe 是否留下无限增长数据？
5. probe 是否有并发冲突/热点锁问题？
6. read-only/write-denied 是否真实 MySQL 验证？
7. 恢复后是否真实 Event+Delivery+Outbox commit？
8. Rabbit/Redis 是否仍不参与 readiness？
9. 是否只 catch 明确数据库异常？
10. 是否有 SQLSTATE/DSN/user/token 泄漏？
```

任何一项不确定：`INCOMPLETE`。

## 12. Final Report

Codex 最终报告必须包含：

```text
PR
Review #1 ID
整改前 HEAD
当前 HEAD
validated_remediation_head
提交列表
M-01 修复说明
具体 write-capability probe 设计
M-AC-01..12
targeted
composer quality
Docker composer quality
R-06
R-07
R-08
latest CI run + exact head
风险 / NOT RUN
```

Independent Review #2 前：

```text
最终状态：INCOMPLETE
```
