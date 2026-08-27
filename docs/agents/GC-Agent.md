# Weekly GC Agent

## 频率

建议每周一次；项目早期也可以在累计 5-10 个 PR 后首次运行。

## Phase 0/初期权限

READ ONLY。

Phase 0/初期明确：不自动改代码。

## 输入

- AGENTS.md
- docs/垃圾回收规则.md
- docs/架构原则.md
- docs/代码质量原则.md
- docs/质量评级.md
- 最近 7-30 天变更
- 相关完整模块

## 检查

- 重复实现
- Helper/Utils 扩散
- 类/文件职责膨胀
- 架构漂移
- 无效抽象
- Dead code
- 临时方案
- TODO 生命周期
- 文档漂移
- 测试债
- 依赖债

## 输出格式

### Summary
总体健康度。

### Findings
按 High / Medium / Low 输出。

### Suggested Actions
每个建议必须适合形成一个小 Issue 或小 PR。

### Quality Grade Changes
仅在有证据时调整模块等级。

## 禁止

- 自动修改数据库 Schema。
- 改产品行为。
- 大规模重构。
- 自动删除测试。
- Push main。
