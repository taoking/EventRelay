# Issue #32 — Core List Cursor Pagination：验证证据

## 基线

- `main`：`b4d89fd835671496b1b884aa1a77a0303af699fe`
- post-merge CI：`33519988281 — PASS`
- Issue #30：`PASS`（已关闭）
- Issue #32：`PASS`（开始时为 open）

## 精确实现 SHA

- `validated_implementation_head=a810f82eee6b56e48faeb0514282a701a18219f5`
- 此 SHA 将加密游标的内部单调键完全限制在 Infrastructure：Application 只传递不透明的 cursor 字符串与 page DTO，不持有内部键。

## 自动化与质量门

| 验证 | 状态 | 结果 |
|---|---|---|
| 本机列表专项 | PASS | `php artisan test tests/Feature/Api/CoreListPaginationApiTest.php tests/Feature/CoreList/CoreListPaginationConcurrencyTest.php`：7 passed、4 skipped（本机非 MySQL）、139 assertions |
| Docker 列表/并发专项 | PASS | 同命令：11 passed、156 assertions；C-01 至 C-04 在 MySQL/独立进程下实际执行 |
| `composer quality` | PASS | Pint、PHPStan Level 8、Deptrac、negative validation、全套测试通过；217 passed、47 skipped、1439 assertions |
| `docker compose exec -T app composer quality` | PASS | Pint、PHPStan Level 8、Deptrac、negative validation、Docker MySQL/Redis/RabbitMQ 全套回归通过 |

## 并发 Gate

| Gate | 状态 | 证据 |
|---|---|---|
| C-01 Event snapshot | PASS | `CoreListPaginationConcurrencyTest`：第一页后由独立 MySQL process 插入 Event；旧窗口无重复、无遗漏且排除新行。 |
| C-02 Delivery snapshot | PASS | 同一真实 MySQL barrier 模式；新 Delivery 排除，既有 Delivery 状态变更不改变成员资格。 |
| C-03 同秒 tie | PASS | 内部单调创建键提供稳定总序；跨页无 skip、无重复。 |
| C-04 Endpoint mutation | PASS | 页间新建 Endpoint 被上界排除，尚未读取的软删除 Endpoint 在 SQL 可见性谓词中隐藏。 |
| C-05 cursor misuse | PASS | Docker HTTP R-04 对篡改、跨资源与垃圾 cursor 均返回 422。 |

## Docker Runtime

所有 Runtime 使用独立 MySQL 数据库与 Docker 内实际 HTTP server；未改动默认开发数据库。

| Gate | 状态 | 结果 |
|---|---|---|
| R-01 Events | PASS | 125 Events；无参第一页 50；`limit=40` 分页为 `40/40/40/5`，125 个公开 UUID 恰好一次，末页 `next_cursor=null`。 |
| R-02 Deliveries | PASS | 105 个旧 Delivery；第一页后新建一个 Delivery 且把后页既有 Delivery 改为 `succeeded`；旧窗口仍为 105 个唯一 Delivery，新行排除，状态实时反映为 `succeeded`。 |
| R-03 Endpoints | PASS | 105 个旧 Endpoint；第一页后软删除尚未读取的 Endpoint 并插入一个新 Endpoint；旧 traversal 返回 105 个唯一可见 Endpoint，已删项隐藏、新项排除。 |
| R-04 Security | PASS | 篡改、跨资源、垃圾 cursor 分别返回 422；响应不含 `after`、`upper`、APP_KEY、MAC/cipher 或解密异常信息；请求前后应用日志增长为 0。 |
| R-05 Large dataset | PASS | 5,231 Events、5,106 Deliveries、5,106 个可见 Endpoint；Events 与 Deliveries 均实际 HTTP 连续读取 50 页、各 5,000 个唯一公开 UUID，深页固定 100 项且仍有下一页。 |

## Query / EXPLAIN Gate

- 状态：PASS。
- 第一页固定为上界查询加 `LIMIT limit+1` page query；后续页为单条 `after < key <= upper` keyset query；无 `COUNT(*)`、OFFSET、`skip`、`forPage`、`paginate` 或全量 materialization。
- Event：Docker MySQL 8.4 首页与深页均为 `PRIMARY`、`range`、`Using where`；深页 `EXPLAIN ANALYZE` 为 `PRIMARY` index range scan，101 行被 limit 截断，无 filesort。
- Delivery：首/深页驱动表均为 `deliveries.PRIMARY` 的 `range`；Event、Endpoint 与 replay source 为 `PRIMARY` 的 `eq_ref` 单行 lookup；无 N+1、无 filesort。
- Endpoint：首/深页为 `PRIMARY` 的 `range`、SQL `deleted_at IS NULL` 谓词；新增 `endpoints_visible_cursor_index (deleted_at, id)` 后，上界 `MAX(id) WHERE deleted_at IS NULL` 显示 `Select tables optimized away`。深页 `EXPLAIN ANALYZE` 为主键 range scan，101 行被 limit 截断，无 filesort。
- Delivery list 的 query-count 回归为固定两条（上界 + join page），任一资源后续页为一条 bounded keyset query。

## Cursor / `all()` 审计

- 状态：PASS。
- Cursor 为 Laravel Encrypter 加密且认证的 v1 payload，绑定 `events`、`deliveries` 或 `endpoints`；解码只窄化处理 decrypt/JSON/argument 错误并统一映射为 `invalid_pagination_cursor`。
- `after` 始终取实际返回页的最后一项，不取 lookahead 项；`limit` 不绑定 cursor，允许下一请求改变 limit。
- 三个核心列表 API 路径均不再调用 `all()`；搜索结果中的其余 `all()` 仅属于既有 outbox intent、metrics 聚合、订阅读取或测试用途，不参与三个列表 HTTP endpoint。
- 公开 DTO、HTTP 响应、日志与本证据均未记录内部 cursor key、明文 payload 或密文。

## Acceptance Criteria

| AC | 状态 | 覆盖 |
|---|---|---|
| AC-01 | PASS | 默认 50、`1..100` 与非法 limit API 回归。 |
| AC-02 | PASS | `data`、`meta.limit`、`meta.next_cursor`；无 total/page/offset。 |
| AC-03 | PASS | 原有 item resource shape 与升序创建/接收顺序。 |
| AC-04 | PASS | Bounded keyset SQL；审计无 OFFSET/全量 materialization。 |
| AC-05 | PASS | 加密、认证、versioned、resource-bound cursor。 |
| AC-06 | PASS | C-01/C-02/C-04、R-02/R-03。 |
| AC-07 | PASS | C-01、R-01。 |
| AC-08 | PASS | C-02、R-02。 |
| AC-09 | PASS | C-04、R-03；Endpoint 不宣称 Serializable visibility snapshot。 |
| AC-10 | PASS | C-03。 |
| AC-11 | PASS | API cursor/limit 安全回归与 R-04。 |
| AC-12 | PASS | query-count 回归与 Docker MySQL EXPLAIN/ANALYZE。 |
| AC-13 | PASS | R-01 至 R-05。 |
| AC-14 | PASS | 两套 `composer quality` 覆盖 DLQ、Replay、Ingress、Outbox、Retry/Stale、Redis/Rabbit、Operations、HMAC 与 SSRF。 |
| AC-15 | NOT RUN | Issue/CODEX 与本 EVIDENCE 已存在；单次 push、Draft PR 与 CI 将在本证据提交后执行。 |

## 风险 / NOT RUN

- `INCOMPLETE`：Independent Review 仍为 `NOT RUN`。
- Endpoint 的旧 cursor 只固定插入成员上界；页间软删除的旧 Endpoint 会隐藏，这是刻意的实时可见性语义，不是跨请求 Serializable snapshot。
- 不提供 total、previous cursor、offset、筛选或排序 DSL；均属于本 Issue 范围外。
