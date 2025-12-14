# 测试环境搭建与使用说明文档

> 适用对象：本项目后端（ThinkPHP 8 + MySQL）开发者与测试人员

---

## 1. 整体架构概览

本项目的测试环境依托 **Docker + PHPUnit + MySQL**：

- 后端服务容器：`backend`（PHP + ThinkPHP 框架）
- 数据库容器：`mysql`（MySQL 实例）
- 测试框架：PHPUnit 11.x
- 代码根目录：`/Users/Benson/Code/AlkaidSYS-tp`

关键特点：

- 测试环境和开发环境共用同一个数据库名：`alkaid_sys`
- 测试跑在 **Docker 容器内**，通过 `docker compose exec backend ...` 调用
- 数据库结构通过 ThinkPHP migration 在容器内初始化

---

## 2. 一次性初始化步骤

> 新人第一次在本机跑测试，建议完整执行以下步骤。

### 2.1 安装依赖

在项目根目录执行（如尚未安装依赖）：

```bash
composer install
```

### 2.2 准备环境配置

1. 复制默认环境配置：

   ```bash
   cp .env.example .env
   ```

2. 确认 `.env` 中数据库相关配置（已按当前推荐值整理）：

   ```ini
   DB_TYPE = mysql
   DB_HOST = mysql
   DB_NAME = alkaid_sys
   DB_USER = root
   DB_PASS = root
   DB_PORT = 3306
   ```

   说明：
   - `DB_HOST = mysql`：指向 Docker Compose 中定义的 `mysql` 服务（容器网络名），不要写成 `localhost`。
   - `DB_NAME = alkaid_sys`：当前项目测试与开发共用此库，请务必确保这是“安全可清空”的环境。

3. 确认 `phpunit.xml` 中的环境变量配置与 `.env` 一致，尤其是：

   ```xml
   <env name="DB_NAME" value="alkaid_sys"/>
   ```

### 2.3 启动 Docker 服务

在项目根目录执行：

```bash
docker compose up -d
```

确认容器状态（可选）：

```bash
docker compose ps
```

确保至少有：

- `backend` 容器 Running
- `mysql` 容器 Running

### 2.4 在容器内执行数据库迁移

> 注意：**必须在 backend 容器内执行迁移**，否则容器内 MySQL 不会有正确的表结构。

在宿主机项目根目录执行：

```bash
docker compose exec backend php think migrate:run
```

若迁移成功，将看到类似输出：

- 已执行的 migration 列表
- 无报错信息

如果迁移失败，请重点检查：

- `.env` 中 DB 配置是否正确
- `mysql` 容器是否正常运行
- 是否已成功连接到 `mysql` 容器（可用 `docker compose exec mysql mysql -uroot -proot` 手动检查）

---

## 3. 日常测试运行方式

### 3.1 启动服务

日常开发/测试前：

```bash
cd /Users/Benson/Code/AlkaidSYS-tp
docker compose up -d
```

> 一般只需在早上或需要时启动一次，之后可以多次在容器中跑测试。

### 3.2 全量测试

在项目根目录执行：

```bash
docker compose exec backend php vendor/bin/phpunit
```

正常情况下，你会看到类似输出：

- `Tests: 63, Assertions: 174`
- `Errors: 0, Failures: 0, Risky: 0`
- 可能会有少量 `Incomplete` 和 `PHPUnit Deprecations`（目前为已知、可接受的状态）。

### 3.3 按模块/按文件运行测试

推荐在开发阶段按“最小范围”运行测试，加快反馈：

1. **低代码 Form 数据管理单测**：

   ```bash
   docker compose exec backend php vendor/bin/phpunit \
       tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php
   ```

2. **低代码 Form Schema 管理单测**：

   ```bash
   docker compose exec backend php vendor/bin/phpunit \
       tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php
   ```

3. **Schema 构建相关单测**：

   ```bash
   docker compose exec backend php vendor/bin/phpunit \
       tests/Unit/Schema/SchemaBuilderTest.php
   ```

4. **低代码 API 端到端测试（推荐在改动接口逻辑后运行）**：

   ```bash
   docker compose exec backend php vendor/bin/phpunit \
       tests/Feature/Lowcode/FormApiTest.php
   ```

---

## 4. 常见问题与排查思路

### 4.1 `SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'`

出现此错误通常有以下几类原因：

1. Docker 容器未启动或 MySQL 容器异常：
   - 先运行 `docker compose up -d`
   - 再用 `docker compose ps` 检查 `mysql` 状态

2. `.env` 与容器配置不一致：
   - 确认 `DB_HOST = mysql`，而不是 `localhost`
   - 确认 `DB_NAME = alkaid_sys`

3. 未在容器内执行迁移：
   - 执行：`docker compose exec backend php think migrate:run`

### 4.2 测试中出现大量 SQL 表不存在 / 结构不匹配

检查顺序：

1. 确认迁移是否完整执行（参考 2.4）
2. 确认是否使用了正确的数据库名（`.env` 和 `phpunit.xml` 一致）
3. 对于低代码相关测试（特别是 `FormApiTest`）：
   - 测试中会自动 TRUNCATE/DELETE 相关表数据，并通过 `SchemaBuilder` 动态建表
   - 若数据库结构异常，请先把全量测试跑一遍，观察最早报错的用例

### 4.3 PHPUnit 报告 Risky tests（自定义 error/exception handlers）

当前代码中已对 `tests/Feature/Lowcode/FormApiTest.php` 进行了统一处理：

- ThinkPHP 在初始化时会通过 `\think\initializer\Error` 注册自己的 error/exception handler；
- 测试中在第一次 HTTP 调用后，会恢复回 PHPUnit 原有的 handler；
- 这部分逻辑已封装在测试基类的请求封装内部，正常使用 **不用额外处理**。

如果未来你在新的测试中手动调用 `set_error_handler` 或 `set_exception_handler`：

- 请在 `tearDown()` 或测试逻辑结束前调用 `restore_error_handler()` / `restore_exception_handler()`；
- 否则 PHPUnit 会把该测试标记为 Risky。

---

## 5. 编写新测试的建议

1. **尽量使用已有的测试基类**：
   - 继承 `Tests\ThinkPHPTestCase`，这样可以复用现有的 App 初始化与 DB 配置逻辑。

2. **优先选择“最小范围”测试**：
   - 业务逻辑尽可能用 Unit Test 覆盖；
   - 端到端 HTTP 测试留给关键接口或跨层流程。

3. **涉及数据库的测试**：
   - 统一使用迁移建表，不要在测试中手工 `CREATE DATABASE`；
   - 需要清理数据时，优先使用 TRUNCATE/DELETE 指定表，而不是 DROP DATABASE。

4. **涉及异常/错误处理的测试**：
   - 避免在测试中全局替换 error/exception handler；
   - 如确有需要，一定要在测试结束前显式恢复，以避免 Risky。

---

## 6. 总结

- 测试环境的核心要点是：**Docker 内执行、配置统一、迁移先行**。
- 日常使用时，只要记住三个命令：
  1. `docker compose up -d`（启动服务）
  2. `docker compose exec backend php think migrate:run`（如有新迁移）
  3. `docker compose exec backend php vendor/bin/phpunit`（运行测试）

如在使用过程中遇到新的问题，建议：

1. 先记录错误信息与运行命令；
2. 尝试对照本说明文档中的排查步骤；
3. 若仍无法解决，可在 `docs/todo/` 目录新增条目，方便后续统一分析与修复。

