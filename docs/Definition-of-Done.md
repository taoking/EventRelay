# Definition of Done

任何 Coding Agent 只有在以下条件满足后才能声明 `DONE`：

- [ ] Issue 目标已实现。
- [ ] Acceptance Criteria 全部满足。
- [ ] Pint PASS。
- [ ] PHPStan/Larastan PASS。
- [ ] Deptrac PASS。
- [ ] Unit/Feature Tests PASS。
- [ ] 未删除/弱化有效测试来让 CI 变绿。
- [ ] 无无关大范围修改。
- [ ] 必要文档已更新。
- [ ] Runtime Validation 已执行并有证据。
- [ ] PR 已创建。
- [ ] 没有未处理的 Critical/High Review Finding。

若某项未执行：标记 `NOT RUN`。

若被环境/权限阻塞：标记 `BLOCKED`。

如果验收关键项存在 FAIL/BLOCKED/NOT RUN，最终状态只能是 `INCOMPLETE` 或 `BLOCKED`，不能是 `DONE`。
