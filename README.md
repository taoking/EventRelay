# EventRelay

EventRelay 是一个使用 PHP/Laravel 构建的事件通知与 Webhook 可靠投递平台。工程治理基线（Phase 0）已完成；当前实现包含 Endpoint CRUD、Event 接收 API、Endpoint Event Type Subscription 与 Delivery 历史记录，不包含自动 Delivery 创建或 Webhook 投递能力。

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

`payload` 必填且必须为 JSON object（允许 `{}`），嵌套 object 和 array 会完整保存并返回。Event 不提供修改或删除 API；不进行订阅匹配、排队或 Webhook 投递。

## Endpoint Subscription API

Endpoint 可订阅零个或多个 Event type，但订阅配置本身不会触发 Event/Endpoint 匹配或任何投递行为。支持的操作为：

- `GET /api/endpoints/{id}/subscriptions`：返回该 Endpoint 的订阅；新 Endpoint 返回空 `types`。
- `PUT /api/endpoints/{id}/subscriptions`：以 `{ "types": ["order.paid"] }` 完整替换订阅；`[]` 清空订阅。重复 type 会自动去重，响应按字典序稳定排序。

Event type 使用与 Event 接收 API 相同的领域规则：小写字母、数字、`.`、`_`、`-`，长度最多 120 字符，并且首尾必须为字母或数字。Endpoint 不存在或已软删除时，两个订阅 API 都返回 `404`；无效请求返回 `422`。所有成功响应使用 `{ "data": { "endpoint_id": "...", "types": [...] } }`。

## Delivery API

Delivery 是 `Event + Endpoint` 的唯一、不可变历史记录；新建记录的状态固定为 `pending`。Delivery 创建目前仅是内部 Application 能力，既不根据 Subscription 自动匹配，也不会入队或发送 Webhook。

- `GET /api/deliveries`：按创建顺序稳定返回 Delivery 列表。
- `GET /api/deliveries/{id}`：返回单条历史 Delivery。

响应使用 `{ "data": ... }`，返回公开 UUID、`event_id`、`endpoint_id`、`status`、`created_at` 和 `updated_at`。不提供 `POST`、`PATCH` 或 `DELETE` Delivery API。重复的内部创建调用通过数据库 `(event_id, endpoint_id)` 唯一约束保证返回同一条 Delivery；Endpoint 软删除后，历史 Delivery 仍可读取，但不可再创建新的 Delivery。

## 工程治理

- [Phase 0 计划](PHASE0_PLAN.md)
- [Phase 0 验收记录](PHASE0_ACCEPTANCE.md)
- [Agent 导航](AGENTS.md)
- [中文规则文档](docs)

GitHub Actions 对 Pull Request 和 `main` push 执行与本地相同的 `composer quality` Gate。`main` 受 GitHub ruleset 保护：必须经 Pull Request，且 `quality` 必须通过。Issue 与 PR 模板要求使用 `PASS`、`FAIL`、`BLOCKED`、`NOT RUN`，并记录 Runtime Evidence。
