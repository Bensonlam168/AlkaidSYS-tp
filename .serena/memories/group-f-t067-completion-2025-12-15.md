# 组 F / T-067 完成记录（2025-12-15）

## 概要
- 任务：**[T-067] (P1) Legacy Collection 栈技术护栏与清理协同**（对应 LEGACY-01 / LC-LEGACY-01）
- 完成日期：2025-12-15
- 状态：**已完成**，TaskList 中 `Implement T-067` 已标记完成。

## 设计对齐与现状
- 根据 `docs/report/lowcode-implementation-audit-2025-12-14.md`：
  - **LC-LEGACY-01（Major）**：Legacy `Domain\Model\Collection` / `Infrastructure\Collection\CollectionManager` 等旧实现仍在仓库中存在，并在少量历史路径中被使用，缺少技术护栏（如 deprecation wrappers、静态检查）阻止新代码引用。
- 根据 `docs/report/design-compliance-audit-2025-12-14.md`：
  - **LEGACY-01（Minor）**：Legacy 低代码体系仍在代码库中，缺少“防新增依赖”的技术护栏（静态检查 / 自动审查规则）。

- 代码实态审计结果：
  - `Domain\Model\Collection`：
    - 已存在 `@deprecated` PHPDoc 标注，提示迁移至 `Domain\Lowcode\Collection\Model\Collection`。
    - 构造函数中已通过 `request()` + `think\facade\Log::warning` 记录告警日志，包含 `trace_id` / `tenant_id` / `collection_name` / `backtrace` 等字段。
  - `Infrastructure\Collection\CollectionManager`：
    - 已存在 `@deprecated` 标注，指向新版 `Infrastructure\Lowcode\Collection\Service\CollectionManager`。
    - 构造函数中同样使用 `Log::warning` 记录告警日志，包含 `trace_id` / `tenant_id` / `class` / `backtrace`。
  - `Infrastructure\Field\FieldTypeRegistry`：
    - 已存在 `@deprecated` 标注，并在 `create()` 调用时记录 `trace_id` / `tenant_id` / `field_type` / `field_name` / `backtrace`。
    - 未显式出现在 LC-LEGACY-01 文本中，但被视为 Legacy Collection 栈的一部分，一并纳入技术护栏范围。
  - Legacy 栈引用情况：
    - 通过全局搜索 `Domain\\Model\\Collection` / `Infrastructure\\Collection\\CollectionManager` / `Infrastructure\\Field\\FieldTypeRegistry`：
      - 除 `infrastructure/Collection/CollectionManager.php` 文件顶部 `use Domain\Model\Collection;` 外，代码库中 **不存在其他引用点**；
      - 未发现任何业务代码或新低代码实现对 Legacy 栈的直接依赖。

结论：
- **@deprecated 标注与运行时告警日志已经基本完备**，与审计报告中“deprecation wrappers + runtime logging”的期望一致；
- 真正缺失的是“静态检查/CI 规则，禁止未来新增依赖”。

## 实施内容：静态检查 / CI 技术护栏

### 1. 新增 Legacy 栈静态防御测试

- 新增文件：`tests/Legacy/LegacyCollectionUsageGuardTest.php`
- 作用：
  - 作为一个“Guard Test”，在测试阶段对代码库进行静态扫描，防止出现新的 Legacy Collection 栈引用；
  - 只允许在极小的白名单文件中出现 Legacy 类全限定名，其余任意 PHP 文件一旦出现引用即视为失败。

- 关键实现要点：
  - 采用 `PHPUnit\Framework\TestCase`，命名空间 `Tests\Legacy`；
  - 通过 `dirname(__DIR__, 2)` 计算项目根目录；
  - 扫描目录集：`app/`、`domain/`、`infrastructure/`、`tests/`（不包含 `vendor/` 等第三方代码）；
  - 仅扫描 `.php` 文件，减少误报并保证性能；
  - 使用以下 Legacy 栈标识字符串作为匹配目标：
    - `Domain\\Model\\Collection`
    - `Infrastructure\\Collection\\CollectionManager`
    - `Infrastructure\\Field\\FieldTypeRegistry`
  - 白名单（允许包含上述字符串的文件，相对路径）：
    - `infrastructure/Collection/CollectionManager.php`（其内部需要 `use Domain\Model\Collection;`）
    - `domain/Model/Collection.php`
    - `infrastructure/Field/FieldTypeRegistry.php`
    - `tests/Legacy/LegacyCollectionUsageGuardTest.php`（测试自身）
  - 对每个扫描到的 PHP 文件：
    - 计算相对路径；如在白名单中则跳过；
    - 否则读取文件内容，逐个匹配 `legacyClasses` 数组中的字符串；
    - 若命中，则将 `(relativePath, class)` 记录为 violation。
  - 测试断言：
    - 若 `violations` 非空，则 `fail()` 并输出所有违规文件及类名，例如：
      - `Domain\Model\Collection referenced in app/Some/NewService.php`
    - 若为空，则显式 `assertTrue(true)`，便于阅读测试报告。

- 代码摘要：

```php:tests/Legacy/LegacyCollectionUsageGuardTest.php
class LegacyCollectionUsageGuardTest extends TestCase
{
    public function testNoNewReferencesToLegacyCollectionStack(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        $legacyClasses = [
            'Domain\\Model\\Collection',
            'Infrastructure\\Collection\\CollectionManager',
            'Infrastructure\\Field\\FieldTypeRegistry',
        ];

        $allowList = [
            'infrastructure/Collection/CollectionManager.php',
            'domain/Model/Collection.php',
            'infrastructure/Field/FieldTypeRegistry.php',
            'tests/Legacy/LegacyCollectionUsageGuardTest.php',
        ];

        $dirsToScan = ['app', 'domain', 'infrastructure', 'tests'];
        $violations = [];

        // 遍历并扫描 PHP 文件，收集所有违规引用...

        if (!empty($violations)) {
            $messages = array_map(
                static fn (array $v): string => sprintf('%s referenced in %s', $v[1], $v[0]),
                $violations
            );

            $this->fail(
                "Legacy Collection stack must not be referenced by new code. Found:\n" .
                implode("\n", $messages)
            );
        }

        $this->assertTrue(true);
    }
}
```

### 2. 测试执行情况

- 在 `alkaid-backend` 容器内执行：

```bash
docker exec alkaid-backend ./vendor/bin/phpunit \
  --configuration phpunit.xml \
  tests/Legacy/LegacyCollectionUsageGuardTest.php
```

- 结果：
  - 测试通过：Tests: 1, Assertions: 1；
  - 存在 PHPUnit 自身的 Deprecation 提示（与本任务无关）。
- 同时，因为当前代码库中除白名单外 **没有任何 Legacy 栈引用**，故该 Guard Test 在现状下是“绿色”的。

### 3. 对 CI / 开发流程的意义

- 任意新提交若在非白名单 PHP 文件中增加以下任一引用：
  - `use Domain\Model\Collection;`
  - `use Infrastructure\Collection\CollectionManager;`
  - `use Infrastructure\Field\FieldTypeRegistry;`
  - 或其它形式包含上述 FQCN 字符串的代码片段（包括字符串常量、注释等），
- 一旦执行 PHPUnit / `php think test`，`LegacyCollectionUsageGuardTest` 将立即失败，提示违规文件路径和类名：
  - 有效实现了审计报告中要求的“静态检查/CI 规则，防止新增依赖”。

## 对审计问题 LEGACY-01 / LC-LEGACY-01 的响应

- **@deprecated 标注**：
  - Legacy 栈三个核心类（`Domain\Model\Collection`、`Infrastructure\Collection\CollectionManager`、`Infrastructure\Field\FieldTypeRegistry`）均已具备清晰的 `@deprecated` 注释，并指向 Lowcode 新栈实现；
- **运行时告警日志**：
  - 在构造函数或工厂方法中已经加入告警日志（通过 `think\facade\Log::warning`），并补充了 `trace_id` / `tenant_id` / 调用栈等关键信息，满足审计报告对可观测性与迁移规划的需求；
- **静态检查 / CI 规则**：
  - 通过新增 `tests/Legacy/LegacyCollectionUsageGuardTest.php`，将 Legacy 栈引用检查纳入自动化测试矩阵，实现“新代码一旦误用 Legacy 栈即快速失败”的技术护栏；
  - 白名单粒度严格，仅允许 Legacy 实现自身及此 Guard Test 本身持有 FQCN 字符串，有利于后续彻底清理 Legacy 栈时的收口与退出。

综合以上，T-067 目标（为 Legacy Collection 栈增加技术护栏，阻断新增依赖，并与后续清理批次协同）已经实现。
