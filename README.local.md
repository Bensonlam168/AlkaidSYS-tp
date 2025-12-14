# AlkaidSYS 本地 Docker 开发环境说明

> 本文档描述在本地使用 Docker 进行 AlkaidSYS 后端开发与测试时的推荐命令与规范。
> 详细的强制规范见 `.augment/rules/always-alkaidsys-project-rules.md` 第 8 节。

## 1. 容器启动与停止

### 1.1 启动本地开发环境

```bash
# 在项目根目录执行
docker compose up -d
```

### 1.2 停止本地开发环境

```bash
docker compose down
```

### 1.3 查看容器状态

```bash
docker compose ps
# 或仅查看后端容器
docker ps --filter "name=alkaid-backend"
```

## 2. 后端容器约定

- 后端 PHP 应用容器名称：`alkaid-backend`
- 其他相关容器：
  - MySQL：`alkaid-mysql`
  - Redis：`alkaid-redis`
  - RabbitMQ：`alkaid-rabbitmq`
  - Nginx：`alkaid-nginx`

> 重要：**所有 `php think` 相关命令在开发环境必须通过 `docker exec alkaid-backend ...` 执行，禁止在宿主机直接运行 `php think ...`。**

## 3. 常用测试命令

在项目根目录执行以下命令（均在 `alkaid-backend` 容器内运行）：

```bash
# 运行全部测试
docker exec -it alkaid-backend php think test

# 运行特定测试文件
docker exec -it alkaid-backend php think test tests/Feature/AuthTest.php

# 运行特性测试套件
docker exec -it alkaid-backend php think test --testsuite=Feature

# 过滤运行单个测试方法
docker exec -it alkaid-backend php think test tests/Feature/AuthTest.php --filter=test_login

# 生成覆盖率报告（示例：HTML）
docker exec -it alkaid-backend php think test --coverage-html
```

在 CI 或非交互环境中，可以去掉 `-it`：

```bash
docker exec alkaid-backend php think test
```

在某些场景下（例如与现有 CI 配置保持一致），也可以直接使用 PHPUnit 作为测试入口，命令形式等价：

```bash
# 运行全部测试（等价于 php think test）
docker exec -it alkaid-backend ./vendor/bin/phpunit

# 运行特定测试文件（示例：认证权限集成测试）
docker exec -it alkaid-backend ./vendor/bin/phpunit tests/Feature/AuthPermissionIntegrationTest.php
```

## 4. 代码格式化

在提交 PHP 代码之前，建议使用 PHP-CS-Fixer 按项目统一规范检查和格式化代码。

### 4.1 配置文件与规则集

- 配置文件位置：项目根目录下的 `.php-cs-fixer.php`
- 主要规则集：
  - `@PSR12`（PSR-12 编码规范）
  - `array_syntax`（短数组语法）
  - `no_unused_imports`（移除未使用的 use 导入）
  - `single_quote`（字符串优先使用单引号）

### 4.2 常用命令

所有命令均需在 `alkaid-backend` 容器内执行：

```bash
# 只读检查格式问题（不会修改文件，适合本地或 CI 预检查）
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff

# 自动修复全仓库格式问题（请注意可能产生较多变更）
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix

# 仅检查/修复特定目录或文件（示例：控制器目录，只读检查）
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix app/controller --dry-run --diff
```

> 建议：在提交 PHP 代码前，至少执行一次只读检查命令，确保新改动符合 PSR-12 及项目统一风格要求。

## 5. 常用迁移命令

```bash
# 运行所有待执行的迁移
docker exec -it alkaid-backend php think migrate:run

# 回滚最近 n 步迁移（示例：2 步）
docker exec -it alkaid-backend php think migrate:rollback --step=2

# 查看迁移状态
docker exec -it alkaid-backend php think migrate:status

# 运行数据填充（seed）
docker exec -it alkaid-backend php think seed:run
```

## 6. 其他开发常用命令

```bash
# 进入后端容器 Shell
docker exec -it alkaid-backend bash

# 查看后端容器日志
docker logs -f alkaid-backend

# 在容器内安装 Composer 依赖
docker exec -it alkaid-backend composer install

# 在容器内执行一次性维护脚本（示例）
docker exec -it alkaid-backend php tools/one-off-script.php
```

## 7. 注意事项

- 请勿在宿主机直接执行如下命令：
  - `php think test`
  - `php think migrate:run`
  - 以及其他任何 `php think ...` 命令
- 如需修改 Docker 命令规范，请同时更新：
  - `.augment/rules/always-alkaidsys-project-rules.md` 第 8 节
  - `.augment/skills/run-tests.yaml`
  - `.augment/skills/run-migration.yaml`

