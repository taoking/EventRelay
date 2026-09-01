# EventRelay Issue #32 — Remediation #1

## 0. Task Metadata

- Repository: `taoking/EventRelay`
- Issue: `#32 feat: add stable cursor pagination to core list APIs`
- Draft PR: `#33`
- Branch: `feature/core-list-cursor-pagination`
- Review round: `Independent Review #1`
- GitHub Review ID: `5080278324`
- Review semantic result: `REQUEST CHANGES`

Expected starting state:

```text
PR #33: Draft
HEAD: 44729ae35bcdd064b779663046fef8661e452d33
CI: 33527801474 — PASS
validated_implementation_head:
a810f82eee6b56e48faeb0514282a701a18219f5
```

Before doing anything, verify the remote PR HEAD is still exactly the expected HEAD above.

If the branch moved unexpectedly, or the latest exact-head CI is no longer green:

```text
BLOCKED
```

Do not guess, rebase, reset, or overwrite another change.

---

# 1. One-Sentence Goal

只修复 Independent Review #1 指出的 **R-03 Endpoint Runtime 证据自相矛盾**，并准确描述 cursor high-watermark 的一致性边界；**不要修改分页生产代码、SQL、索引、API contract 或 cursor implementation。**

---

# 2. Read First

按顺序读取：

```text
docs/tasks/0032-core-list-cursor-pagination/ISSUE.md
docs/tasks/0032-core-list-cursor-pagination/CODEX.md
docs/tasks/0032-core-list-cursor-pagination/EVIDENCE.md
docs/开发记录.md
```

同时读取 PR #33 的 Independent Review #1：

```text
Review ID: 5080278324
```

重点理解：

1. Review 没有发现 production-code blocker。
2. 唯一 blocking finding 是 R-03 evidence count contradiction。
3. AUTO_INCREMENT `id` high-watermark 的 MVCC 边界是 non-blocking note，不允许据此扩大成分页架构重构。

---

# 3. Locked Scope

## 3.1 允许修改

只允许：

```text
docs/tasks/0032-core-list-cursor-pagination/REMEDIATION-01.md
docs/tasks/0032-core-list-cursor-pagination/EVIDENCE.md
docs/开发记录.md
```

如 PR Body 中存在已经被新证据证明不准确的描述，可更新 PR Body；PR Body 更新不需要 repo commit。

## 3.2 默认禁止修改

除非重新执行 R-03 后得到可重复的 FAIL 并证明 production code 确实错误，否则禁止修改：

```text
app/**
routes/**
database/migrations/**
tests/**
composer.json
composer.lock
docker-compose.yml
.github/**
README*
```

尤其禁止：

- 重写 cursor schema；
- 改 `after < key <= upper`；
- 换 public UUID/created_at 排序；
- 引入 MVCC snapshot token；
- 加 transaction snapshot/session holding；
- 新增 pagination framework；
- 删除或修改 `endpoints_visible_cursor_index`；
- 顺手修 Issue #32 以外问题。

若 R-03 真实失败：

```text
STOP
→ 保留现场
→ 报告 FAIL
→ 不静默修 production code
```

---

# 4. Blocking Finding L-01

当前 `EVIDENCE.md` 的 R-03 描述存在数学矛盾。

它当前表达的逻辑等价于：

```text
initial old Endpoints = 105
page1 后 soft-delete 1 个尚未读取的 old Endpoint
page1 后 insert 1 个 new Endpoint
new Endpoint 被 upper boundary 排除
deleted old Endpoint 被 visibility predicate 隐藏
final traversal = 105 unique visible old Endpoints
```

如果 initial eligible old rows 确实为 105，则以上条件下：

```text
expected visible membership
= 105
- 1 deleted old row
+ 0 post-page1 new row
= 104
```

所以以下两件事至少有一件不准确：

1. EVIDENCE 中 seed/count 的描述；
2. Runtime 实际结果记录。

不能仅凭推断把 `105` 改成 `104`。

**必须重新执行真实 R-03 并记录实际测量值。**

---

# 5. R-03 Remediation Runtime Gate

## R-03R — Endpoint pagination evidence correction

使用 Issue #32 原先相同等级的真实环境：

```text
Docker
+ MySQL 8.4
+ actual HTTP server
+ real GET /api/endpoints
```

不要只调用 Repository、Application use case 或 PHPUnit mock 来替代 Runtime。

### 5.1 Fixture

创建 `N > 100` 个最初可见 Endpoint。

记录精确：

```text
initial_visible_count = N
```

不要在文档中写“大约”“105 左右”。

### 5.2 Page 1

请求：

```http
GET /api/endpoints?limit=<bounded limit>
```

记录：

```text
page1_count
next_cursor_present = YES
page1 UUID set
```

### 5.3 Between-page mutations

在 page1 返回后：

1. 从 **尚未出现在 page1** 的旧 Endpoint 中选择一个：
   ```text
   deleted_old_endpoint_id
   ```
   对其执行真实 soft delete。

2. 创建一个新 Endpoint：
   ```text
   inserted_after_page1_endpoint_id
   ```

必须确保新 Endpoint 的插入发生在 page1 已经建立 cursor upper boundary 之后。

### 5.4 Traverse old cursor to end

持续使用 page1 返回的 cursor 遍历直到：

```text
next_cursor = null
```

必须记录：

```text
all_returned_uuid_count
unique_returned_uuid_count
duplicate_count
deleted_old_present
new_endpoint_present
terminal_next_cursor
```

### 5.5 Required invariant

如果仅发生：

```text
1 old row soft-deleted
1 post-page1 new row inserted
```

且没有其他 visibility mutation，则预期：

```text
expected_total_returned
= initial_visible_count - 1
```

因为：

```text
post-page1 new row → excluded by high-watermark
soft-deleted old row → hidden by live visibility
```

必须证明：

```text
all_returned_uuid_count == expected_total_returned
unique_returned_uuid_count == expected_total_returned
duplicate_count == 0
deleted_old_present == false
new_endpoint_present == false
terminal_next_cursor == null
```

如果任何一项不成立：

```text
R-03R = FAIL
```

立即停止 remediation，报告真实 production finding。

---

# 6. Evidence Update Requirements

R-03R PASS 后，更新：

```text
docs/tasks/0032-core-list-cursor-pagination/EVIDENCE.md
```

## 6.1 R-03 必须写成可审计数字

推荐格式：

```text
R-03 Endpoints — PASS

initial visible old Endpoints: <N>
page1 returned: <P>
soft-deleted unread old Endpoint: 1
inserted after page1: 1
expected old traversal membership: <N-1>
actual returned UUIDs: <N-1>
unique returned UUIDs: <N-1>
duplicate UUIDs: 0
deleted old Endpoint present: NO
post-page1 new Endpoint present: NO
terminal next_cursor: null
```

使用实际数字替换占位符。

## 6.2 AC-13

只有在 R-03R 真实 PASS 后，才能保持：

```text
AC-13 = PASS
```

否则：

```text
AC-13 = FAIL
Final status = INCOMPLETE
```

## 6.3 AC-15 / Independent Review

准确记录：

```text
Independent Review #1:
REQUEST CHANGES
Review ID: 5080278324

Blocking finding:
L-01 R-03 evidence count contradiction

Remediation #1:
<current status>
```

Review #2 尚未发生前：

```text
Independent Review final = PENDING REVIEW #2
Final status = INCOMPLETE
```

---

# 7. Snapshot / High-Watermark Wording

当前实现内部使用单调 AUTO_INCREMENT persistence key：

```sql
id > after
AND id <= upper
ORDER BY id ASC
```

应准确描述为：

```text
creation-key / allocation-key high-watermark traversal
```

它保证本 Issue 锁定的：

```text
page1 完成
→ 之后新建的新 row
→ 不进入旧 cursor traversal
```

但不要把它写成：

```text
cross-request MVCC snapshot
point-in-time transaction snapshot
Serializable snapshot
commit-order watermark
```

在 `EVIDENCE.md` 风险/一致性说明中补一句即可：

```text
该 cursor 固定的是 persistence creation-key high-watermark，用于保证
page1 之后新创建的 rows 不向旧 traversal 扩张；它不声明跨 HTTP 请求
MVCC/Serializable point-in-time snapshot。Endpoint 仍使用实时 soft-delete
visibility。
```

不要因此修改 implementation。

---

# 8. REMEDIATION-01.md

在仓库创建：

```text
docs/tasks/0032-core-list-cursor-pagination/REMEDIATION-01.md
```

内容至少记录：

```text
Review ID
reviewed head
finding L-01
root cause
R-03R procedure
actual measured counts
files changed
production code changed: NO
validated_implementation_head unchanged: YES
new remediation commit SHA
CI run
final remediation status
```

如果 R-03R PASS 且没有代码变化：

```text
validated_implementation_head
仍必须是：
a810f82eee6b56e48faeb0514282a701a18219f5
```

不要为了 docs commit 把它改成新的 HEAD。

---

# 9. 开发记录

`docs/开发记录.md` 只追加简洁索引，不复制整个 remediation prompt。

记录：

```text
Issue #32
Independent Review #1
Review ID 5080278324
L-01 evidence contradiction
R-03R result
REMEDIATION-01.md path
new remediation commit
CI run
等待 Independent Review #2
```

---

# 10. Validation

## 10.1 Mandatory

必须：

```text
R-03R real Docker HTTP runtime
git diff --check
git status
```

确认：

```text
production code diff = NONE
test diff = NONE
migration diff = NONE
```

## 10.2 Existing regression truth

不要伪造重新运行记录。

区分：

```text
previous exact implementation validation:
validated_implementation_head=a810f82...

previous PR CI:
33527801474 — PASS

this remediation:
R-03R — PASS/FAIL
```

Push 后的新 PR CI 作为 current-head regression truth。

---

# 11. Git / CI Rule

这是 Independent Review 明确要求修正的 repository evidence，因此允许产生：

```text
一个 remediation commit
一个 push
一个新的 PR CI
```

一次性完成：

```text
REMEDIATION-01.md
+ corrected EVIDENCE.md
+ concise docs/开发记录.md
```

然后：

```text
single remediation commit
→ single push
→ one new PR CI
```

推荐 commit message：

```text
docs: correct endpoint pagination runtime evidence (#32)
```

如果意外发现需要 production code remediation：

```text
STOP
```

不要继续 docs-only flow。

---

# 12. PR Body

Push 后更新 PR #33 Body，准确记录：

```text
current PR HEAD: <actual current head>
latest CI: <new exact-head CI id/status>

Independent Review #1:
REQUEST CHANGES
Review ID: 5080278324

Remediation #1:
R-03 evidence corrected from real Docker HTTP rerun
production code changed: NO
validated_implementation_head:
a810f82eee6b56e48faeb0514282a701a18219f5

Independent Review #2:
PENDING
```

不要写：

```text
READY TO MERGE
DONE
Independent Review PASS
```

---

# 13. Final State

如果：

```text
R-03R PASS
docs corrected
single remediation commit pushed
new exact-head CI PASS
```

最终仍然是：

```text
INCOMPLETE
```

原因：

```text
Independent Review #2 = NOT RUN
```

不要：

- Merge
- Ready for review
- squash
- close Issue #32

---

# 14. Final Report Format

最终返回中文报告：

```text
## Issue #32 Remediation #1

Starting HEAD:
...

Review:
- ID: 5080278324
- semantic result: REQUEST CHANGES

Finding L-01:
...

R-03R:
- initial_visible_count:
- page1_count:
- deleted_old:
- inserted_after_page1:
- expected_total:
- actual_total:
- unique_total:
- duplicate_count:
- deleted_present:
- new_present:
- terminal_cursor:
- result: PASS/FAIL

Production code changed:
NO

Files changed:
...

validated_implementation_head:
a810f82eee6b56e48faeb0514282a701a18219f5

Remediation commit:
...

Current HEAD:
...

Push count:
1

Latest CI:
...

Independent Review #2:
NOT RUN

Final status:
INCOMPLETE
```

如果 R-03R FAIL，则不要继续 commit/push 文档来掩盖问题；报告：

```text
FAIL / BLOCKED
```

并给出真实复现结果。

---

## Remediation #1 执行记录

- Review ID：`5080278324`
- reviewed head：`44729ae35bcdd064b779663046fef8661e452d33`
- Review semantic result：`REQUEST CHANGES`
- Finding L-01：原 R-03 证据将「105 个初始可见 Endpoint、删除 1 个未读旧项、页后新增项排除」与「旧 traversal 返回 105 项」同时记录，数学上自相矛盾。
- 根因：原 Runtime 证据记录错误；本次不改变分页生产行为，而是在相同等级的 Docker + MySQL 8.4 + 实际 HTTP 环境重新测量。

### R-03R procedure

1. 在独立 Docker MySQL 8.4 数据库创建 105 个初始可见 Endpoint。
2. 通过实际 `GET /api/endpoints?limit=50` 取得第一页和 cursor。
3. 从不在第一页 UUID 集合中的旧 Endpoint 通过实际 `DELETE /api/endpoints/{id}` 执行 soft delete。
4. 通过实际 `POST /api/endpoints` 创建一个新 Endpoint。
5. 使用第一页原 cursor 继续真实 HTTP traversal 直到 `next_cursor=null`。

### Actual measured counts

```text
initial_visible_count: 105
page1_count: 50
next_cursor_present: YES
deleted_old_endpoint_id: e7f9a0c3-8758-44a7-89c2-2ef431df346c
inserted_after_page1_endpoint_id: ac2335a2-a9eb-4022-9224-357d7630b9aa
soft_deleted_unread_old_endpoint: 1
inserted_after_page1_endpoint: 1
expected_total_returned: 104
all_returned_uuid_count: 104
unique_returned_uuid_count: 104
duplicate_count: 0
deleted_old_present: NO
new_endpoint_present: NO
terminal_next_cursor: null
result: PASS
```

### Precision boundary

cursor 固定的是 persistence creation-key / allocation-key high-watermark，用于保证第一页完成后新创建的 rows 不会向旧 traversal 扩张；它不声明跨 HTTP 请求的 MVCC、Serializable 或 point-in-time transaction snapshot。Endpoint 继续使用实时 soft-delete visibility。

### Change record

- Files changed：本文件、`EVIDENCE.md`、`docs/开发记录.md`。
- Production code changed：NO。
- Test changed：NO。
- Migration changed：NO。
- `validated_implementation_head` unchanged：YES，仍为 `a810f82eee6b56e48faeb0514282a701a18219f5`。
- Remediation commit：本提交（准确 SHA 在 Git history 与 PR current HEAD 中记录）。
- Latest CI：NOT RUN（本提交单次 push 后产生新的 exact-head CI）。
- Final remediation status：R-03R 为 PASS；Independent Review #2 前整体状态为 `INCOMPLETE`。
