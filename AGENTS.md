# EventRelay Agent 导航

本文件只作为 Agent 的工作入口与导航，不承载全部项目知识。

## 开始任何任务前必须阅读

1. `docs/产品需求.md`
2. `docs/架构原则.md`
3. `docs/代码质量原则.md`
4. `docs/测试原则.md`
5. `docs/Definition-of-Done.md`

## 特定任务额外阅读

- 代码审查：`docs/agents/代码审查Agent.md`
- 垃圾回收/技术债治理：`docs/垃圾回收规则.md`、`docs/agents/GC-Agent.md`
- 架构健康检查：`docs/架构原则.md`、`docs/agents/架构Agent.md`

## 核心工作规则

- 一个任务只解决一个清晰目标。
- 默认从 Issue/验收标准开始工作。
- 不降低需求或测试标准来宣称完成。
- 不删除有效测试来让 CI 变绿。
- 不直接向 `main` 开发。
- 不提交密钥、Token、证书或个人绝对路径。
- 不做与当前 Issue 无关的大规模重构。
- 不确定业务行为时，保持现状并记录风险。
- 无 Runtime Evidence 的运行验证不能标记 PASS。

## 状态词

只使用以下状态：

- `PASS`：已执行且通过。
- `FAIL`：已执行且失败。
- `BLOCKED`：被权限、环境、依赖等阻塞。
- `NOT RUN`：尚未执行。

不得把 `NOT RUN` 或 `BLOCKED` 描述成完成。
