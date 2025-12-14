# 测试规范

## 1. 范围与目标

本规范用于约束 AlkaidSYS 项目的自动化测试实践，是单元测试、集成测试、特性/API 测试及端到端测试的**唯一权威规范**。

- 所有新增代码 **必须** 由适当的自动化测试覆盖。
- 重构 **不得** 在未经批准且未增加补偿性测试的前提下降低关键路径的有效覆盖率。

## 2. 测试类型

- **单元测试** **必须** 在不依赖外部资源（数据库、Redis、HTTP 等）的前提下验证单个类或函数行为。
- **集成测试** **应当** 覆盖数据库、队列、缓存和 HTTP 边界行为。
- **特性 / API 测试** **必须** 验证 REST 接口，包括认证、授权、错误处理与限流行为。
- **端到端测试** 可以 用于跨服务场景和关键用户路径。

## 3. 结构与命名

- 测试文件 **必须** 放在框架约定的测试目录（例如 `tests/`）中，并遵循项目约定的子目录结构。
- 测试类和方法名称 **应当** 描述行为而非实现细节，例如：`test_can_create_tenant_scoped_user`。
- 测试 **必须** 可重复且相互独立，不得依赖执行顺序。

## 4. 数据库与迁移

- 涉及数据库的集成/特性测试 **必须** 通过迁移创建表结构，禁止在测试中直接编写 DDL。
- 测试用例 **应当** 通过事务回滚或截断工具在用例之间重置数据库状态。
- 引入破坏性变更的迁移 **必须** 在测试中覆盖变更前后关键数据形态。

## 5. 安全与权限测试

- 所有受保护接口 **必须** 至少包含以下场景的测试：
  - 无认证访问（期望 `401` 及正确的错误码）；
  - 使用无效/过期 Token 访问（期望 `401` 或 `403`，且错误码来自安全错误码矩阵）；
  - 权限不足访问（期望 `403`，在适用时携带缺失的 `resource:action` 上下文）。
- 引入 Casbin 后（Phase 2），测试 **必须** 验证典型角色与边界场景下的策略行为。

## 6. 限流、错误码与 Trace ID

- 针对限流接口的测试 **必须** 验证：
  - 返回 HTTP `429` 状态码与业务 `code = 429`；
  - JSON 响应中的诊断字段及限流相关响应头符合 API 与安全规范描述。
- 错误处理测试 **必须** 断言所有 4xx/5xx JSON 响应中包含 `trace_id` 字段。

## 7. 统一测试入口 (T-056)

项目提供统一的测试入口命令，通过 ThinkPHP CLI 执行：

### 运行测试

```bash
# 运行所有测试（在 Docker 容器内）
docker exec -it alkaid-backend php think test

# 仅运行单元测试
docker exec -it alkaid-backend php think test --testsuite=Unit

# 仅运行功能测试
docker exec -it alkaid-backend php think test --testsuite=Feature

# 运行性能测试（需要 phpunit.performance.xml）
docker exec -it alkaid-backend php think test -c phpunit.performance.xml

# 按测试方法名过滤
docker exec -it alkaid-backend php think test --filter=testCanCreateUser

# 生成 HTML 覆盖率报告
docker exec -it alkaid-backend php think test --coverage-html=coverage

# 首次失败时停止
docker exec -it alkaid-backend php think test --stop-on-failure

# 详细输出
docker exec -it alkaid-backend php think test --phpunit-verbose

# 透传额外的 PHPUnit 参数
docker exec -it alkaid-backend php think test --passthru="--group=auth"
```

### 可用选项

| 选项 | 简写 | 描述 |
|------|------|------|
| `--testsuite` | `-s` | 测试套件名称 (Unit/Feature/Performance) |
| `--filter` | `-f` | 按方法/类名过滤测试 |
| `--coverage-html` | | 生成 HTML 覆盖率报告 |
| `--coverage-text` | | 输出文本覆盖率报告 |
| `--configuration` | `-c` | PHPUnit 配置文件 (默认: phpunit.xml) |
| `--stop-on-failure` | | 首次失败时停止 |
| `--stop-on-error` | | 首次错误时停止 |
| `--phpunit-verbose` | | PHPUnit 详细输出 |
| `--phpunit-debug` | | PHPUnit 调试模式 |
| `--list-suites` | | 列出可用的测试套件 |
| `--passthru` | `-p` | 额外的 PHPUnit 参数 |

### 重要说明

- 所有测试命令 **必须** 在 `alkaid-backend` Docker 容器内执行。
- 默认配置文件为项目根目录的 `phpunit.xml`。
- 性能测试使用 `phpunit.performance.xml` 配置。
- 退出码 0 表示所有测试通过；非零表示存在失败。

## 8. CI 集成与覆盖率 (T-059)

- CI 流水线 **必须** 在合入受保护分支前执行完整的自动化测试套件。
- 建议为关键模块（领域层、安全相关、API 层）设定最低覆盖率阈值。
- 失败测试 **必须** 被视为发布阻断因素，除非通过正式流程豁免并记录原因。

### CI 工作流

项目使用 GitHub Actions 进行 CI/CD，包含两个主要任务：

1. **PHP 代码风格检查 (PSR-12)**：以 dry-run 模式运行 PHP-CS-Fixer 检查代码格式。
2. **PHPUnit 测试**：基于 MySQL 和 Redis 服务运行测试套件。

CI 触发条件：
- 对任意分支的 Pull Request
- 推送到 `main`、`develop` 及 `releases/*` 分支

### CI 配置

完整的 CI 配置请参见 `.github/workflows/backend-php-cs-fixer.yml`。

## 9. 测试 Phase 模型

- **Phase 1（当前基线能力）**：
  - 现有测试可以暂时保持历史风格，但新增测试 **必须** 遵循本规范。
  - 关键领域（认证、授权、计费、限流）在上线生产前 **必须** 有自动化测试保护。
- **Phase 2（目标能力）**：
  - 测试套件 **应当** 覆盖所有 P0/P1 Backlog 任务及主要领域流程的端到端链路。
  - **应当** 为候选发布版本定义回归测试集合。 

