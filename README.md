# EventRelay

EventRelay 是一个计划使用 PHP/Laravel 构建的事件通知与 Webhook 可靠投递平台。当前为 **Phase 0 — Agentic Engineering Harness**：只建立工程与治理基线，不实现 Endpoint、Event、Delivery 或 Webhook 业务能力。

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

## 工程治理

- [Phase 0 计划](PHASE0_PLAN.md)
- [Phase 0 验收记录](PHASE0_ACCEPTANCE.md)
- [Agent 导航](AGENTS.md)
- [中文规则文档](docs)

GitHub Actions 对 Pull Request 和 `main` push 执行与本地相同的 `composer quality` Gate。`main` 受 GitHub ruleset 保护：必须经 Pull Request，且 `quality` 必须通过。Issue 与 PR 模板要求使用 `PASS`、`FAIL`、`BLOCKED`、`NOT RUN`，并记录 Runtime Evidence。
