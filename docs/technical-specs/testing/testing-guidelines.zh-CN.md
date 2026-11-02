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

### 4.1 测试库初始化：只跑 `migrate:run` 不够（R50）

冷启动是**两步**。`migrate:run` 只建表结构与 19 条系统权限
（`tenant.*`、`role.*`、`user.*`、`dept.*`、`menu.*`、`casbin.manage`），
**刻意不建**任何角色、用户与业务权限。而测试硬编码了 `userId: 1, tenantId: 1, siteId: 0`
（`tests/Helpers/AuthHelper::generateTestToken`），于是「只有表结构」的库会让所有
「admin 访问受保护接口」的用例以**缺种子数据**的形式失败，看起来像业务代码坏了。

```bash
# 1) 建表（同时创建 alkaid_sys_test）
docker exec -it alkaid-backend sh -lc 'PHP_ENV_NAME=test php think migrate:run'

# 2) 种子：角色 1/2、33 条业务权限（显式 id 1001..1033，
#    含 route/workflow.php 依赖的 10 条 workflow_*）、角色授权、
#    默认站点，以及 id=1 的系统用户
docker exec -it alkaid-backend sh -lc 'PHP_ENV_NAME=test php think seed:run -s TestPlatformSeed'
```

职责划分（不要把数据塞回迁移链）：**表结构归迁移、种子数据归 seeder**。
`TestPlatformSeed` 是测试专用 seeder，继承 `CorePlatformSeed`，唯一差别是
`id = 1` 的系统用户使用**随机且不可用**的口令哈希 —— 测试通过 JWT 认证，不需要任何口令，
因此它**不需要** `ADMIN_INIT_PASSWORD`（`CorePlatformSeed` 对真实环境的 fail-closed 口令策略保持不变）。

两步都是幂等的：重复执行不产生新行、不覆盖已有数据。

**历史遗留的本地测试库**：显式权限 id 是有意义的。若 `permissions` 里已存在由旧 seeder
创建的业务权限（id 落在 99xxxx，继承自 AUTO_INCREMENT），若干单元测试会按自己的清理口径
（`DELETE FROM permissions WHERE id >= 7000` / `>= 8000` / `>= 9000`）在**同一轮**运行中
把这些行删掉。请先重建一次测试库，让种子落在保留 id 段：

```bash
docker exec alkaid-mysql mysql -uroot -proot -e 'DROP DATABASE IF EXISTS alkaid_sys_test; CREATE DATABASE alkaid_sys_test CHARACTER SET utf8mb4;'
# 然后重新执行上面的 1) 与 2)
```

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

### 7.1 性能预算断言不进功能套件（`performance` 组）

**约定**：断言「这台机器此刻多快」的用例，**不允许**留在功能回归套件里。

- **墙钟阈值类断言**（耗时 / 吞吐 / QPS / 命中率 / 超时）**必须**打 `#[Group('performance')]`，
  且阈值**必须**可由环境变量覆盖、默认值给足余量。
- **功能断言**（响应字段是否存在、`code`/`message`、是否放行或拒绝、类型契约）**必须**留在普通用例里，
  一条都不许挪走或删掉。字段**存在性**属于功能契约（留在功能用例），字段的**具体耗时**不是。
- **禁止**为了让抖动消失而删除功能断言、收紧其它阈值或跳过用例。

**为什么**：功能回归的结论必须是「行为对不对」，不能是「当时机器有多快」。实测随机顺序全量跑时，
`CasbinControllerTest::testReloadPolicySuccess` 里的 `assertLessThan(1000, execution_time_ms)`
在近 3000 条断言的压力下出现过 1029ms / 1114ms，于是功能完全正常的用例变红、整轮结论作废。
同类问题还有令牌桶用例：`rate = 1.0` 时「耗尽即拒绝」实际等价于「5 次 Redis 调用必须在 1 秒内跑完」。

**默认套件如何排除**：`phpunit.xml` 中声明了组排除，因此 `php think test` 与 CI **永远不会**执行该组：

```xml
<groups>
    <exclude>
        <group>performance</group>
    </exclude>
</groups>
```

`tests/Benchmark/`（`PermissionServiceBenchmarkTest` 等基准）**不在任何默认套件里**，
不要把它加进 `phpunit.xml` 的 `testsuites` —— 基准只在性能配置下运行。

**怎么跑这一组**（均在 `alkaid-backend` 容器内，注意容器内的工作目录是 `/var/www/html`）：

```bash
# 只跑 performance 组
docker exec alkaid-backend ./vendor/bin/phpunit --group performance

# 跑完整性能套件 = tests/Benchmark + tests/Performance + 功能文件里的 performance 用例
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.performance.xml

# 只想跑基准目录
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.performance.xml --testsuite=Benchmark
```

`phpunit.performance.xml` 用 `<groups><include><group>performance</group></include></groups>`
挑选用例：打了 `#[Group('performance')]` 的用例自动入选；整份目录都属于性能套件的，用
`<directory groups="performance">` 打标签。这份配置的 `bootstrap` **必须**是 `vendor/autoload.php`
（历史版本写的是 `vendor/topthink/framework/src/helper.php`，缺 autoloader 会让所有用例 Class not found）。

**怎么调阈值**：阈值一律从环境变量读、代码里只写默认值；变量为空或非法时**回落默认值**
（不是「关掉预算」）。现有阈值：

| 用例 | 环境变量 | 默认值 | 含义 |
| --- | --- | --- | --- |
| `CasbinControllerTest::testReloadPolicyExecutionTimeWithinBudget` | `CASBIN_RELOAD_BUDGET_MS` | 3000 | 手动刷新 Casbin 策略的耗时预算（毫秒） |

```bash
# 在慢机器上放宽 / 在发布验证时收紧
docker exec alkaid-backend env CASBIN_RELOAD_BUDGET_MS=1500 ./vendor/bin/phpunit --group performance
```

**新写测试时，什么情况该打 `performance` 组**（按下表顺序判断，能确定性化就不要进组）：

| 情况 | 处置 |
| --- | --- |
| 判据是墙钟时间 / 吞吐，且无法改写成确定性断言（P95 延迟、QPS、命中率、大循环总耗时） | 打 `#[Group('performance')]`，阈值走环境变量 + 宽松默认值 |
| 想把默认值定成「刚好卡住现状」（实测 900ms 就写 1000ms） | **不允许**：预算要留数倍余量，否则等于把负载波动当回归 |
| 判据是行为（是否放行、字段是否存在、`code` 是否为 0），只是实现里涉及时间 | **不许**打组，改成确定性写法：缩小 `rate` 让「补 1 个令牌」远超用例耗时，或把「等待」换成**有界轮询** |
| 判断不了 | 先按确定性写；确实做不到才进组，并在用例注释里写清「为什么它只能是概率性验证」 |

参考实现：`CasbinControllerTest` 把预算断言拆成独立用例（阈值可配），
`RateLimitServiceTest` 把 4 个时间敏感用例改成「`DENY_RATE = 0.01` 的确定性拒绝」
+ 「有界轮询的确定性补充」，因此它们**仍然留在 Unit 套件**里，不需要打组。

### 7.2 任务变体配置（`phpunit.t048.xml` / `phpunit.t050.xml`）与根级安全属性

T-048 / T-050 的「覆盖率 ≥80%」是**按取样目录**验收的，因此根层有两份任务变体配置：

| 配置 | 取样范围 | 用途 |
| --- | --- | --- |
| `phpunit.t048.xml` | `tests/Unit/Domain/Lowcode`、`tests/Unit/Schema`、`tests/Unit/Infrastructure/Lowcode`、`tests/Integration/Lowcode` | ORM / 低代码覆盖率 |
| `phpunit.t050.xml` | `tests/Unit/Lowcode/FormDesigner` | 表单验证器覆盖率 |

改这两份配置前先读下面三条约定：

1. **变体是「任务子集」，不是「基线的裁剪版」**。它们只声明 T-048 / T-050 相关目录，
   **不含** `Legacy` 套件：两个守卫要递归扫全仓数千个 PHP 文件（约 8 秒/个），对覆盖率数字零贡献；
   而且它们在基线 `phpunit.xml` 的 `Legacy` 套件里已经跑到了（CI 单独一步 + 本地 `php think test`）。
   把 `Legacy` 加进变体属于纯重复，**不要**当成「漏了」去补全。
2. **根级安全属性必须与基线逐项一致**：`failOnRisky` / `failOnWarning` /
   `failOnEmptyTestSuite` / `beStrictAboutOutputDuringTests`。其中缺 `failOnEmptyTestSuite`
   的后果最隐蔽：取样目录被挪走、改名或用例被删空时，PHPUnit 只打印 `No tests executed!`
   就以 0 退出，覆盖率报告照常输出，而样本其实是 0 个用例。
3. 上面两条由 **`php scripts/check-phpunit-config.php --strict` 的「检查 G」**守着
   （对应 CI 的 `PHPUnit Config Consistency`）：
   - 三件套（`failOnRisky` / `failOnWarning` / `failOnEmptyTestSuite`）在基线里必须为 `true`，
     变体的每个安全属性必须与基线一致（非法字面量如 `"1"` / `"yes"` 单独报错，PHPUnit 会静默回落默认值）；
   - 基线必须声明 `Legacy` 套件，其取样路径必须在盘上、目录里至少有一个 `*Test.php`；
   - 变体声明的每个 `directory` / `file` 必须存在且还能收集到用例 —— 这是
     `failOnEmptyTestSuite` 的**静态**版（运行期判定只在「真的跑了一次」时才生效）。
   确需例外时走脚本里的 `EXEMPT_CONFIGS`（必须写 `reason` 与 `review_by` 到期日），
   **不要**为了让检查变绿去改基线或删断言。

```bash
# 变体配置自检（均在 alkaid-backend 容器内）
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.t048.xml
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.t050.xml
docker exec alkaid-backend php scripts/check-phpunit-config.php --strict
```

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

