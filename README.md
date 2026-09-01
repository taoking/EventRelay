# EventRelay

EventRelay 是一个使用 PHP/Laravel 构建的事件通知与 Webhook 可靠投递平台。工程治理基线（Phase 0）已完成；当前实现包含 Endpoint CRUD、Event 接收 API、Endpoint Event Type Subscription、Delivery 历史记录与 pending Delivery 的 Redis Queue 调度骨架，不发送 Webhook。

## 技术基线

- Laravel 13（当前锁定 `13.29.0`）
- Composer 解析平台与 CI：PHP 8.5
- MySQL 8.4、Redis 7、Laravel Redis Queue
- Pint、PHPStan/Larastan、Deptrac、PHPUnit

本机 PHP 8.4+ 可用于兼容性开发与验证；依赖解析的目标版本由 `composer.json` 的 `config.platform.php` 锁定为 8.5，CI 也运行 PHP 8.5。

## 本地启动

前置条件：PHP 8.4+、Composer 2、Docker Compose。

```sh
composer install
cp .env.example .env
php artisan key:generate
docker compose up --build -d
docker compose exec app php artisan migrate --force
```

应用位于 `http://localhost:8000`。Docker Compose 中的 `eventrelay` / `root` 密码仅用于本地开发容器，不能用于任何生产环境。

停止本地容器：

```sh
docker compose down
```

## 验证

统一机械质量 Gate：

```sh
composer quality
```

该命令依次执行 Pint、PHPStan/Larastan、Deptrac、Deptrac 负向验证和 PHPUnit 测试。负向验证只读取 `tests/Architecture` 中的专用 fixture，断言 `Domain -> Framework` 违规会被 Deptrac 拒绝；它不属于生产源码。也可在容器内执行：

```sh
docker compose exec app composer quality
```

最小运行时验证：

```sh
docker compose exec app php artisan about
docker compose exec app php artisan migrate --force
docker compose exec app php artisan tinker --execute="dump(DB::select('select 1 as ok')); dump(Redis::connection()->ping());"
curl --fail http://localhost:8000/up
```

## Internal operations endpoints

内部 operations endpoints 默认关闭。只有在部署网络已限制其访问范围时，才可以同时配置
`OPERATIONS_ENDPOINTS_ENABLED=true` 与非空的 `OPERATIONS_BEARER_TOKEN`，并使用 Bearer token
访问 `/internal/health/live`、`/internal/health/ready` 和 `/internal/metrics`。启用但未配置 token
属于启动时配置错误；不要将 token 写入日志、监控标签或仓库文件。

## 核心列表 Cursor Pagination

`GET /api/events`、`GET /api/deliveries` 和 `GET /api/endpoints` 均使用同一受限的 cursor pagination
契约。省略参数时最多返回 50 条；`limit` 只能是 `1..100` 的十进制整数。响应固定为：

```json
{
  "data": [],
  "meta": {
    "limit": 50,
    "next_cursor": null
  }
}
```

`next_cursor` 是仅供下一页使用的加密、认证且 resource-bound 的不透明值；不得解析、构造、记录或将
Events cursor 用于 Deliveries/Endpoints。无效 `limit` 返回 `422 invalid_pagination_limit`，无效、
篡改或跨资源 cursor 返回 `422 invalid_pagination_cursor`。核心列表不提供总数、页码、offset 或上一页
cursor。

三个列表继续按既有创建/接收顺序升序输出。第一页固定当时的创建上界，后续页只遍历该上界以内的记录，
因此第一页之后创建的 Event、Delivery 或 Endpoint 不会进入原 traversal。Event 和 Delivery 的历史
遍历在该快照窗口内不会重复或遗漏；Endpoint 仍遵守实时 soft-delete 可见性：后续页不会暴露页间已删除
的 Endpoint，因此不承诺跨独立 HTTP 请求的 serializable visibility snapshot。

## Endpoint API

Endpoint 使用稳定的公开 UUID，内部数据库自增键不会出现在 API 响应中。支持的操作为：

- `POST /api/endpoints`：创建；`name`、`url` 必填，`url` 仅允许 HTTP/HTTPS，`status` 默认为 `active`。
- `GET /api/endpoints` 与 `GET /api/endpoints/{id}`：列表与详情。
- `PATCH /api/endpoints/{id}`：局部更新 `name`、`url` 或 `status`（`active` / `disabled`）。
- `DELETE /api/endpoints/{id}`：软删除，返回 `204`；之后详情返回 `404`，列表不再包含该记录。

除 `DELETE` 的 `204` 无响应体外，其余成功读取/写入响应均使用 `{ "data": ... }` JSON 结构；请求校验失败返回 `422`。

## Event API

Event 是创建后不可变的业务事实，使用稳定公开 UUID，内部数据库自增键不会出现在 API 响应中。支持的操作为：

- `POST /api/events`：接收并持久化 Event；`type` 必填，使用小写字母、数字、`.`、`_`、`-`，且长度最多 120 字符。
- `GET /api/events` 与 `GET /api/events/{id}`：按接收顺序稳定列出与查询详情。

`payload` 必填且必须为 JSON object（允许 `{}`），嵌套 object 和 array 会完整保存并返回。Event 不提供修改或删除 API。创建 Event 时会按 exact Event type 为 active、未软删除且已订阅的 Endpoint 自动创建 pending Delivery；不进行排队或 Webhook 投递。

## Endpoint Subscription API

Endpoint 可订阅零个或多个 Event type，但订阅配置本身不会触发 Event/Endpoint 匹配或任何投递行为。支持的操作为：

- `GET /api/endpoints/{id}/subscriptions`：返回该 Endpoint 的订阅；新 Endpoint 返回空 `types`。
- `PUT /api/endpoints/{id}/subscriptions`：以 `{ "types": ["order.paid"] }` 完整替换订阅；`[]` 清空订阅。重复 type 会自动去重，响应按字典序稳定排序。

Event type 使用与 Event 接收 API 相同的领域规则：小写字母、数字、`.`、`_`、`-`，长度最多 120 字符，并且首尾必须为字母或数字。Endpoint 不存在或已软删除时，两个订阅 API 都返回 `404`；无效请求返回 `422`。所有成功响应使用 `{ "data": { "endpoint_id": "...", "types": [...] } }`。

## Delivery API

Delivery 是 `Event + Endpoint` 的唯一、不可变历史记录；新建记录的状态固定为 `pending`。Delivery 创建仍仅是内部 Application 能力：Event 创建会根据符合条件的 Subscription 自动调用它。MySQL Delivery 是业务事实；transaction commit 后，系统请求固定 `redis` connection 的 `deliveries` queue 调度仅携带 Delivery UUID 的 Worker Job。Worker 当前只重新读取并确认 pending Delivery，既不发送 Webhook，也不改变状态。

- `GET /api/deliveries`：按创建顺序稳定返回 Delivery 列表。
- `GET /api/deliveries/{id}`：返回单条历史 Delivery。

响应使用 `{ "data": ... }`，返回公开 UUID、`event_id`、`endpoint_id`、`status`、`created_at` 和 `updated_at`。不提供 `POST`、`PATCH` 或 `DELETE` Delivery API。重复的内部创建调用通过数据库 `(event_id, endpoint_id)` 唯一约束保证返回同一条 Delivery；Endpoint 软删除后，历史 Delivery 仍可读取，但不可再创建新的 Delivery。

若 Redis publication 暂时失败，已提交的 Event/Delivery 仍按 `201` 语义保留为 `pending`；可人工执行以下命令以按稳定顺序、有限批量重新调度：

```sh
php artisan deliveries:enqueue-pending --limit=100
```

这是 at-least-once recovery 工具，不是 Transactional Outbox 或 exactly-once 方案。

## 工程治理

- [Phase 0 计划](PHASE0_PLAN.md)
- [Phase 0 验收记录](PHASE0_ACCEPTANCE.md)
- [Agent 导航](AGENTS.md)
- [中文规则文档](docs)

GitHub Actions 对 Pull Request 和 `main` push 执行与本地相同的 `composer quality` Gate。`main` 受 GitHub ruleset 保护：必须经 Pull Request，且 `quality` 必须通过。Issue 与 PR 模板要求使用 `PASS`、`FAIL`、`BLOCKED`、`NOT RUN`，并记录 Runtime Evidence。
