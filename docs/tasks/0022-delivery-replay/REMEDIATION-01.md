# EventRelay PR #23 — Independent Review #1 Remediation

> **执行合同**
>
> 本文件是 Issue #22 / Draft PR #23 的 Independent Review #1 整改合同。
> Codex 必须以本文件为唯一整改任务源执行，不要依赖聊天历史补全要求。
>
> 目标仓库：`taoking/EventRelay`  
> PR：`#23` — https://github.com/taoking/EventRelay/pull/23  
> Issue：`#22`  
> 分支：`feature/delivery-replay`  
> 整改前 HEAD：`4e24e2b8f7a35be835d288d1070f87c2947a3685`  
> 整改前 CI：`33354524360 — PASS`  
> Independent Review #1：`1 个 Medium blocking finding`
>
> PR 必须继续保持 **Draft**。  
> **不要 Merge，不要点 Ready for review。**

---

## 1. 唯一目标

修复 Replay API 的 **Idempotency-Key 稳定返回语义**：

> 当 `source Delivery + Idempotency-Key` 已经成功创建过 Replay Delivery 后，后续相同请求必须稳定返回第一次创建的同一个 Replay Delivery，不能因为 Endpoint 后来被 disabled、soft-deleted、修改 URL 或 rotation signing key 而改变结果。

本轮不是重新设计 Replay。

---

## 2. Review Finding — M-01

当前 `EloquentDeliveryRepository::createReplay()` 的关键顺序是：

```text
lock source Delivery
→ source 必须 failed
→ lock current Endpoint
→ Endpoint 必须 active / not deleted
→ 查询同 source + Idempotency-Key 对应的 existing Replay
```

这会破坏最重要的幂等场景：

```text
POST D1/replay key=A
→ 服务端成功 COMMIT D2 + Outbox
→ 客户端在收到 201 前超时

随后 Endpoint 被 disabled / soft-deleted / 修改

客户端重试：
POST D1/replay key=A
```

当前实现可能返回：

```text
409 replay_endpoint_unavailable
```

正确结果必须是：

```text
200
same D2
created=false
```

原因：Endpoint eligibility 是“**创建新的 Replay**”的前置条件，不是“**读取已经提交的幂等结果**”的前置条件。

---

## 3. Locked Decisions — 已通过，不得重做

以下设计已经通过 Independent Review，本轮不得重新设计：

- Replay 永远创建 **新 Delivery**，绝不复活 source Delivery。
- source Delivery / source Attempts 历史不可变。
- Replay 使用同一个 immutable Event。
- `replay_of_delivery_id` lineage 保留。
- primary Delivery 始终使用 `creation_key=primary`。
- Replay creation identity 为 source-scoped SHA-256 digest。
- raw `Idempotency-Key` 不进入 DB / log / API / Outbox / Queue。
- 新 Replay 使用 Endpoint **当前** URL + signing key 的同一锁窗口 snapshot。
- Replay 自己创建后冻结 target/signing snapshot。
- Replay Delivery + `attempt:1` Outbox intent 同一 MySQL transaction。
- Redis 不参与 Replay API transaction。
- 同 key MySQL 双进程依赖 DB unique constraint + locking current read。
- Retry max=3、10s/60s backoff、stale recovery 不变。
- HMAC v1 canonical protocol 不变。
- SSRF / DNS pinning / proxy disabled / redirect disabled / TLS verify 不变。
- Transactional Outbox / broker-loss recovery 不变。
- migration schema 不变；不要修改 `2026_09_03_000000_add_delivery_replay_identity.php`。

---

## 4. Acceptance Matrix

| ID | 必须满足 | 自动化证据 | Runtime |
|---|---|---|---|
| **AC-01** | 已存在 `D1 + key=A → D2` 后，Endpoint disabled，再次 `key=A` 返回 **same D2 / 200 / created=false** | API regression | R-01 |
| **AC-02** | 已存在 D2 后，Endpoint soft-deleted，再次 `key=A` 返回 **same D2** | API regression | 可与 R-01 合并 |
| **AC-03** | Endpoint unavailable 时，**新 key=B** 仍返回 `409 replay_endpoint_unavailable`，不得创建 D3 | API regression | R-01 |
| **AC-04** | Endpoint URL/key 后续变化后，同 key=A 返回原 D2 且不刷新 snapshot；新 key=B 才使用当前配置创建新 Replay | regression | 非必须人工 Runtime |
| **AC-05** | 同 source + 同 key 双 MySQL 进程仍只产生 **1 Replay + 1 Outbox**，并返回同一 UUID | 现有 concurrency test | — |
| **AC-06** | raw Idempotency-Key 继续不泄漏到 DB/log/API/Outbox/Queue/docs | security regression /现有断言 | — |
| **AC-07** | Replay、Outbox、Retry、Stale、HMAC、SSRF 等既有 Gate 全部不回归 | full quality gates | — |

任何 AC 无法满足：最终状态必须是 `BLOCKED`，不要弱化要求。

---

## 5. 实现约束

### 5.1 正确查询/锁顺序

保持一个 MySQL transaction，推荐：

```text
BEGIN

1. lock source Delivery by public UUID
   - missing → delivery_not_found
   - source != failed → delivery_not_replayable

2. 使用 source.event_id + source.endpoint_id + creation_key
   做 locking/current read 查询 existing Replay

3. 如果 existing Replay 存在：
   return ReplayDeliveryCreation(existing, false)
   不读取、不锁定、不重新校验 Endpoint current config

4. 只有 existing 不存在时：
   lock current Endpoint
   - active
   - not soft-deleted
   - current URL
   - current signing secret

5. create NEW Replay D2
6. create D2 attempt:1 Outbox intent

COMMIT
```

关键 invariant：

```text
existing idempotent result lookup
必须发生在
current Endpoint eligibility check
之前
```

### 5.2 不允许的修法

禁止：

```text
catch ReplayEndpointUnavailable
→ 再查 existing Replay
```

禁止：

```text
Endpoint unavailable
→ 允许所有 key 返回/创建
```

只有 **existing same-key result** 可以绕过当前 Endpoint eligibility。

禁止：

```text
same key
→ 更新 existing Replay 的 target_url / signing_secret_id
```

existing Replay 必须保持创建时 snapshot。

### 5.3 Source eligibility 不变

本轮继续要求：

```text
source.status = failed
```

不要扩大到：
- succeeded
- pending
- processing
- retry_scheduled

---

## 6. 必须补的 Regression

### T-01 — same key after disable

```text
D1 failed
Endpoint active
key=A → 201 D2
Endpoint disabled
key=A → 200 same D2
```

断言：

```text
Replay count = 1
D2 Outbox attempt:1 count = 1
response UUID = original D2 UUID
```

### T-02 — different key after disable

在 T-01 的 Endpoint disabled 状态：

```text
key=B
→ 409 replay_endpoint_unavailable
```

断言：

```text
Replay count remains 1
no D3
```

### T-03 — same key after soft delete

```text
D1 failed
Endpoint active
key=A → D2
Endpoint soft delete
key=A → 200 same D2
key=B → 409 replay_endpoint_unavailable
```

### T-04 — same key after config change

建议补轻量 regression：

```text
key=A → D2 snapshot U1/K1
Endpoint → U2/K2
key=A → same D2, still U1/K1
key=B → NEW D3, snapshot U2/K2
```

如果已有测试能通过少量断言覆盖，不要新增重复测试体系。

### T-05 — concurrency regression

现有真实 MySQL：

`DeliveryReplayConcurrencyTest`

必须继续证明：

```text
same source
same key
two processes
→ same Replay UUID
→ Replay rows = 1
→ Outbox rows = 1
```

winner recovery 必须继续使用 locking/current read，不能退回普通 consistent read。

### T-06 — Endpoint snapshot concurrency regression

现有：

`DeliveryReplayEndpointSnapshotConcurrencyTest`

必须继续 PASS。

本轮只改变 **existing replay lookup** 的顺序，不得削弱真正创建新 Replay 时的 Endpoint snapshot linearization。

---

## 7. Runtime Gate

只需要新增一个针对本 Finding 的 Docker/MySQL Runtime，不必重复完整 Runtime A/B/C/D。

### R-01 — commit 后 Endpoint 状态变化

流程：

```text
D1 failed
Endpoint active
key=A → create D2
Endpoint disabled 或 soft-deleted
key=A → 200 same D2
key=B → 409 replay_endpoint_unavailable
```

记录：
- source Delivery UUID
- Replay D2 UUID
- same-key second response status
- different-key response status
- Replay row count
- D2 Outbox row count

真实 Runtime Idempotency-Key 只记录：

```text
REDACTED
```

不要把 raw key 写进 evidence / logs / PR。

---

## 8. Mechanical Gate

完成代码后执行：

```bash
composer quality
```

以及：

```bash
docker compose exec -T app composer quality
```

至少确认以下测试真实执行并 PASS：

```text
DeliveryReplayApiTest
DeliveryReplayConcurrencyTest
DeliveryReplayEndpointSnapshotConcurrencyTest
DeliveryConcurrencyTest

Outbox tests
Retry tests
Stale recovery tests
HMAC tests
SSRF / DNS pinning tests
```

不得删除、skip、弱化测试来获得绿色结果。

未知异常继续传播；不要新增 `catch (Throwable)` 掩盖问题。

---

## 9. 执行阶段

Codex 按以下顺序连续执行，不需要中途等待人工确认：

### Phase A — Explore
只读确认：
- 当前 PR HEAD / branch；
- `createReplay()` 当前实现；
- Replay API tests；
- concurrency tests；
- 当前 `docs/tasks/` 是否存在。

### Phase B — Plan
建立：

```text
AC → production change → test → evidence
```

映射。

如果发现本合同与当前仓库事实存在实质冲突，报告：

```text
BLOCKED
```

否则直接进入 Phase C。

### Phase C — Implement
只做 M-01 所需最小生产改动和 regression。

### Phase D — Validate
运行 targeted tests、local quality、Docker quality、R-01。

### Phase E — Self Review
逐项输出：

```text
AC-01 PASS/FAIL
...
AC-07 PASS/FAIL
```

任何一项 FAIL：不得声称完成。

### Phase F — Git / PR
提交、push、更新 Draft PR Body，等待最新 CI。

不要 Merge。  
不要 Ready for review。

---

## 10. Repository-native Traceability（本轮开始采用）

本文件目标路径：

```text
docs/tasks/0022-delivery-replay/REMEDIATION-01.md
```

### 10.1 不再把本文件全文复制进 `docs/开发记录.md`

从本轮开始，`docs/开发记录.md` 只增加简短索引，例如：

```text
PR #23 Independent Review #1 remediation:
docs/tasks/0022-delivery-replay/REMEDIATION-01.md
```

并记录：
- finding 摘要；
- implementation commit；
- validated implementation head；
- evidence 文件；
- 状态。

**不要再次复制本任务文件全文。**

本文件本身就是稳定、逐字、Git 可追溯的任务源。

### 10.2 首个整改 commit

第一份整改代码 commit 必须同时包含：

```text
production fix
+
regression tests
+
docs/tasks/0022-delivery-replay/REMEDIATION-01.md
+
docs/开发记录.md 的索引条目
```

如果本文件在执行前是 untracked，必须在这个 commit 中加入 Git。

推荐 commit：

```text
fix: preserve replay idempotency after endpoint changes (#22)
```

### 10.3 Evidence 文件

创建或更新：

```text
docs/tasks/0022-delivery-replay/EVIDENCE.md
```

只记录本轮真正验证的信息：

```text
Review: Independent Review #1 remediation
validated_implementation_head: <执行代码验证时的实现 SHA>

AC-01: PASS ...
...
AC-07: PASS ...

Targeted tests: ...
composer quality: PASS
Docker composer quality: PASS
Runtime R-01: PASS

Risks:
...
NOT RUN:
Independent Review #2
```

---

## 11. HEAD / CI 证据规则 — 禁止自引用循环

不要为了“让 Git 文件永远写当前 HEAD”反复制造 docs commit。

统一规则：

### `EVIDENCE.md`
记录：

```text
validated_implementation_head
```

即真正被 targeted tests / Docker / Runtime 验证的实现 HEAD。

### PR Body
记录：

```text
current PR HEAD
latest CI run
latest CI result
```

这属于 GitHub 外部事实，不要求再次回写仓库文件。

### 最终 Codex 报告
记录真正的：

```text
current final HEAD
latest CI
```

因此不允许出现：

```text
docs 写 HEAD A
→ commit B
→ 为更新 HEAD 再 commit C
→ 无限循环
```

---

## 12. PR Body 更新要求

PR #23 继续保持 Draft。

更新 Body，增加 Independent Review #1 整改段，至少包含：

```text
Finding M-01
root cause
existing-result-before-endpoint-check fix

AC-01 PASS
AC-02 PASS
AC-03 PASS
AC-04 PASS
AC-05 PASS
AC-06 PASS
AC-07 PASS

Runtime R-01 PASS

current PR HEAD
latest CI URL
Independent Review #2 = NOT RUN
```

不要删除原有 Replay/Runtime 证据，只补充整改结果。

---

## 13. Scope Guard

本轮禁止实现：

- generic ingress Idempotency-Key
- force replay
- replay succeeded Delivery
- bulk replay
- event-level replay
- DLQ
- Outbox daemon
- RabbitMQ / Kafka
- KMS/HSM
- auth / RBAC
- multi-tenancy
- Dashboard
- exactly-once
- Runtime harness 基础设施重构

也不要顺手重构：
- HMAC
- RetryPolicy
- stale recovery
- Outbox publisher
- Endpoint signing
- SSRF resolver

只修 M-01。

---

## 14. Definition of Done

必须同时满足：

```text
AC-01..AC-07 全部 PASS

targeted tests PASS
composer quality PASS
Docker composer quality PASS
Runtime R-01 PASS

整改代码 + tests + 本 REMEDIATION-01.md
已进入首个整改 commit

EVIDENCE.md 已记录 validated_implementation_head
docs/开发记录.md 只保存索引/摘要

PR #23 已 push
PR 仍 Draft
最新 CI PASS

Independent Review #2 = NOT RUN
```

最终状态：

```text
INCOMPLETE
```

直到 Independent Review #2。

不要自行 Merge。  
不要自行 Ready for review。

---

## 15. 最终中文报告格式

最终只需按下面结构返回，不要重新复述整份合同：

```text
PR #23 remediation

Commit(s):
...

Current HEAD:
...

Latest CI:
...

Finding M-01:
根因：
修复：

Acceptance Matrix:
AC-01 PASS — evidence
AC-02 PASS — evidence
AC-03 PASS — evidence
AC-04 PASS — evidence
AC-05 PASS — evidence
AC-06 PASS — evidence
AC-07 PASS — evidence

Runtime:
R-01 PASS — summary

Traceability:
REMEDIATION-01.md committed: YES
EVIDENCE.md: ...
validated_implementation_head: ...

Risks:
...

Independent Review #2:
NOT RUN

Final status:
INCOMPLETE
```
