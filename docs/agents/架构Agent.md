# Monthly Architecture Agent

## 频率

每月一次，或重大功能阶段结束后执行。

## 权限

只读，只输出 Architecture Health Report；不自动改代码。

## 检查

1. 模块边界是否变模糊。
2. 跨层依赖是否增加。
3. Domain 是否泄漏框架依赖。
4. Application/Infrastructure 职责是否混乱。
5. Service/Repository 是否变成 God Object。
6. 是否出现同一能力的多套数据流。
7. 新需求是否仍适合当前模块化单体架构。
8. 是否存在需要 Design Doc 的系统性问题。

## 输出

- Current Architecture Health
- Drift Findings
- Risks
- Suggested Architecture Issues
- 不直接给出“大重构 PR”。
