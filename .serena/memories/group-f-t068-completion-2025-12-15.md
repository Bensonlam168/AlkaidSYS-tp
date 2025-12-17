# 组 F / T-068 完成记录（2025-12-15）

## 概要
- 任务：**[T-068] (P1) 原生 SQL 多租户安全检查与 CI 静态扫描**（对应 RAWSQL-01）
- 完成日期：2025-12-15
- 状态：**已完成**，TaskList 中 `Implement T-068` 已标记完成，Backlog 中 [T-068] 已更新为 ✅ 并附完成时间与实现说明。

## 需求与审计对齐

来自 `docs/todo/development-backlog-2025-11-25.md`：

- **问题 ID**：RAWSQL-01（Info，安全高影响）
- **标题**：建立原生 SQL 多租户安全 Checklist 与 CI 静态扫描
- **描述要点**：
  - 梳理全仓库 `Db::table()/Db::name()/Db::query()` 等原生 SQL 使用点；
  - 建立包含 `tenant_id/site_id` 过滤与审计要求的开发 Checklist；
  - 在 CI 中集成“最小可用”的静态扫描（例如脚本列出所有原生 SQL 调用，由人工或规则确认多租户安全），优先覆盖报表、批处理、运维脚本等高风险路径。

结合 `multi-tenant-implementation-audit-2025-12-14.md` 与 `lowcode-implementation-audit-2025-12-14.md` 中 RAWSQL-01 的描述，本次实现围绕：

1. 文档层面：提供明确的 Raw SQL 多租户安全 Checklist；
2. 工具层面：提供脚本扫描原生 SQL 入口，用于人工/规则审查；
3. 测试层面：通过单元测试验证扫描逻辑的正确性，为后续 CI 集成打基础。

## 实施内容一：Raw SQL 多租户安全检查清单文档

- 新增文档文件：`docs/technical-specs/multi-tenant/rawsql-safety-checklist.md`
- 主要内容：
  - 明确原生 SQL 使用前的判断（是否确实需要 Raw SQL，优先考虑 Repository/BaseModel/FormDataManager 等封装），避免“能不用就不用”；
  - 规定原生 SQL 在多租户环境中的硬性要求：
    - 租户级业务表必须显式包含 `tenant_id = :tenantId` 过滤；
    - 视需要同时过滤 `site_id`（`tenant_id + site_id`），对跨站点聚合需在设计/注释中明确说明；
    - 禁止在无租户过滤条件前提下对业务表执行 `UPDATE`/`DELETE`/`TRUNCATE` 等破坏性操作；
  - 参数绑定与注入防护：
    - 所有原生 SQL 必须使用参数绑定，禁止字符串拼接用户输入；
    - 给出 ✅/❌ 示例；
  - 审计与可观测性：
    - 对报表/批处理/运维脚本等高风险路径要求记录 `trace_id`、`tenant_id`/`site_id`、操作者信息、影响范围等；
  - CI 静态扫描与人工审查流程：
    - 推荐在 `alkaid-backend` 容器中执行：
      - `docker exec alkaid-backend php scripts/check-rawsql-safety.php`
    - 脚本扫描 `app/`、`domain/`、`infrastructure/`、`tests/`、`scripts/` 目录；
    - 匹配 `Db::table(`/`Db::name(`/`Db::query(`/`Db::execute(` 等入口，输出清单供人工审查；
    - 提出后续可选的严格模式 `--strict` 用于 CI Gate；
  - 总结典型高风险场景（报表全表扫描、批处理无租户过滤、运维脚本导出共享表等），强调必须显式通过清单逐项评审。

## 实施内容二：Raw SQL 静态扫描脚本

- 新增脚本：`scripts/check-rawsql-safety.php`
- 主要功能：
  - 递归遍历指定目录（默认：`app/`、`domain/`、`infrastructure/`、`tests/`、`scripts/`）。
  - 在所有 `.php` 文件中按行搜索以下原生 SQL 入口模式：
    - `Db::table(`
    - `Db::name(`
    - `Db::query(`
    - `Db::execute(`
  - 对每个命中位置记录：
    - 文件相对路径 `file`；
    - 行号 `line`；
    - 命中模式 `pattern`；
    - 简要代码片段（该行去掉前后空白后的内容 `code`）。

- 核心函数：

  ```php:scripts/check-rawsql-safety.php
  function scanRawSqlUsage(string $rootDir, array $dirsToScan, array $patterns): array
  {
      $results = [];
      foreach ($dirsToScan as $dir) {
          $baseDir = $rootDir . DIRECTORY_SEPARATOR . $dir;
          if (!is_dir($baseDir)) {
              continue;
          }
          $iterator = new RecursiveIteratorIterator(
              new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
          );
          foreach ($iterator as $file) {
              if ($file->getExtension() !== 'php') {
                  continue;
              }
              $filePath = $file->getPathname();
              $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);
              $contents = @file_get_contents($filePath);
              if ($contents === false) {
                  fwrite(STDERR, colorize("[WARN] Failed to read file: {$relativePath}\n", 'yellow'));
                  continue;
              }
              $lines = explode("\n", $contents);
              foreach ($lines as $index => $line) {
                  foreach ($patterns as $pattern) {
                      if (strpos($line, $pattern) !== false) {
                          $results[] = [
                              'file'    => $relativePath,
                              'line'    => $index + 1,
                              'pattern' => $pattern,
                              'code'    => trim($line),
                          ];
                      }
                  }
              }
          }
      }
      return $results;
  }
  ```

- CLI 入口：
  - 通过 `if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)` 判断，仅在直接从命令行调用时执行主逻辑，避免被测试 `require_once` 时自动退出；
  - 默认设置：
    - `$dirsToScan = ['app', 'domain', 'infrastructure', 'tests', 'scripts'];`
    - `$patterns = ['Db::table(', 'Db::name(', 'Db::query(', 'Db::execute('];`
  - 输出格式：
    - 若无命中：打印绿色提示“未发现原生 SQL 入口”，退出码 0；
    - 若有命中：逐条打印：

      ```text
      [MATCH] path/to/file.php:123: pattern "Db::table(" => Db::table('foo')->...
      ```

    - 最后根据模式决定退出码：
      - 普通模式：始终退出码 0，用于“列清单 + 人工审查”；
      - 严格模式（`--strict`）：只要存在原生 SQL 调用，立即退出码 1，可用作 CI Gate：

        ```bash
        php scripts/check-rawsql-safety.php --strict
        ```

- 与 `scripts/check-permission-codes.php` 一致，脚本使用 `colorize()` 辅助输出彩色提示，遵循现有脚本风格。

## 实施内容三：扫描脚本单元测试

- 新增测试 fixture：`tests/Fixtures/RawSql/RawSqlSample.php`
  - 内容：一个简单的类 `Tests\Fixtures\RawSql\RawSqlSample`，内部包含一条有意识保留的 `Db::table('dummy_table')...` 调用，作为扫描器的固定命中样本，不影响业务逻辑：

  ```php:tests/Fixtures/RawSql/RawSqlSample.php
  final class RawSqlSample
  {
      public function demo(): void
      {
          // This call is intentionally left as raw SQL usage for testing.
          Db::table('dummy_table')->where('tenant_id', 1)->select();
      }
  }
  ```

- 新增单元测试：`tests/Unit/Scripts/RawSqlSafetyCheckTest.php`
  - 继承 `PHPUnit\Framework\TestCase`，不依赖 ThinkPHP 容器；
  - 通过 `require_once __DIR__ . '/../../../scripts/check-rawsql-safety.php';` 加载脚本定义的 `scanRawSqlUsage()` 函数；
  - 测试用例：

  ```php:tests/Unit/Scripts/RawSqlSafetyCheckTest.php
  public function testScannerFindsRawSqlInFixture(): void
  {
      $projectRoot = realpath(__DIR__ . '/../../..');
      $this->assertNotFalse($projectRoot);

      $dirsToScan = ['tests/Fixtures/RawSql'];
      $patterns = ['Db::table('];

      $results = scanRawSqlUsage($projectRoot, $dirsToScan, $patterns);

      $this->assertNotEmpty($results);
      $files = array_unique(array_column($results, 'file'));
      $this->assertContains('tests/Fixtures/RawSql/RawSqlSample.php', $files);
  }
  ```

- 在 `alkaid-backend` 容器中执行测试：

  ```bash
  docker exec alkaid-backend ./vendor/bin/phpunit \
    --configuration phpunit.xml \
    tests/Unit/Scripts/RawSqlSafetyCheckTest.php
  ```

  - 结果：Tests: 1, Assertions: 3，全部通过，仅有 PHPUnit 自身的 Deprecation 提示，与本任务无关。

## Backlog 更新

- `docs/todo/development-backlog-2025-11-25.md` 中 T-068 已更新为：

  ```markdown:docs/todo/development-backlog-2025-11-25.md
  ✅ **[T-068] (P1) 原生 SQL 多租户安全检查与 CI 静态扫描** `[已完成 2025-12-15]`
  - [x] 已完成（2025-12-15）：编写原生 SQL 多租户安全检查清单文档
    `docs/technical-specs/multi-tenant/rawsql-safety-checklist.md`，并新增脚本
    `scripts/check-rawsql-safety.php` 通过正则/遍历列出全仓库
    `Db::table()/Db::name()/Db::query()/Db::execute()` 等原生 SQL 调用点，
    配套单元测试 `tests/Unit/Scripts/RawSqlSafetyCheckTest.php` 验证扫描逻辑，
    可在 CI 中以普通模式输出清单供人工审查，或在严格模式（`--strict`）下作为 Gate 使用。
  ```

## 对 RAWSQL-01 的回应与后续建议

- 本次实现基本覆盖 RAWSQL-01 在“工具与流程”层面的核心诉求：
  - 有明确的、可执行的原生 SQL 多租户安全 Checklist；
  - 有可在本地/CI 中运行的静态扫描脚本列出所有原生 SQL 调用点；
  - 有单元测试保障扫描逻辑，不依赖人工记忆脚本细节。
- 后续可以在以下方向演进：
  - 在 CI 中集成脚本的普通模式输出，将扫描结果作为构建工件存档，便于安全/架构定期审查；
  - 在完成一次全仓库 Raw SQL 梳理并建立“已确认安全”的白名单后，逐步在 CI 中启用 `--strict` 模式，并基于路径/规则进一步细化 Gate 行为；
  - 结合 RAWSQL-01 与 TEST-01，将部分高风险原生 SQL 调用纳入自动化测试矩阵，以防止后续回归。
