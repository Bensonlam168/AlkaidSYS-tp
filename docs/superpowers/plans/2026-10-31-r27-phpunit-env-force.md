# R27：phpunit.xml 关键 env 加 force

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development

**Goal:** 让测试语义相关的 env 不被 shell 导出变量抢占；同时**不得**影响连接类 env（它们本就该跟着环境走）。

**Architecture:** PHPUnit 11 的 `PhpHandler::handleEnvVariables()` 逻辑是 `if ($force || !isset($_ENV[$name]))`。不加 force 时已存在的 `$_ENV` 值直接胜出。故只能对「测试语义固定」的变量加 force，对「环境相关」的连接参数保持默认语义。

**Tech Stack:** PHPUnit 11.5.44 / ThinkPHP 8

**Spec:** 上一轮修复清单的 R27

## Global Constraints

- 中文注释与提交信息；**不得推送**；提交用 `git -c core.hooksPath=/dev/null commit`
- 只碰白名单文件
- 全量失败集去重必须 **≤ 41**（当前 41，用例数 520）
- 三处门禁 exit=0
- **worker 不得并行跑测试**

## Review Focus

- **绝不可给 `DB_*` 加 force**：容器内 `$_ENV['DB_HOST']` 已是 `mysql`，而 phpunit.xml 写的是 `127.0.0.1`；一旦 force，测试会连不上库。（实测：当前 `DB_HOST` 解析结果为 `mysql`，证明 phpunit 的值本来就没生效。）
- **必须 force 的 4 类**：`APP_ENV`（否则 shell 导出 `production` 会让 C-5 的 JWT 强密钥校验拒掉 dev 占位密钥）、`CACHE_FILE_PREFIX`（否则 R20 的 `Cache::clear()` 作用域失效）、`ALLOW_RUNTIME_DDL`、`RATELIMIT_*`。
- **force 之后行为必须可验证**：导出冲突变量后跑测试，测试仍用 phpunit 的值。

---

## Task 1：选择性 force + 验证

**Files:**
- Modify: `phpunit.xml`

**已核实事实**
1. PHPUnit 11.5.44 `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:104-121`：
   ```php
   if ($force || getenv($name) === false) { putenv("{$name}={$value}"); }
   $value = getenv($name);
   if ($force || !isset($_ENV[$name])) { $_ENV[$name] = $value; }
   ```
2. 容器实测（非 phpunit 环境）：
   | 变量 | `$_ENV` | `getenv()` |
   |---|---|---|
   | `APP_ENV` | `dev` | `dev` |
   | `DB_HOST` | **`mysql`** | `mysql` |
   | `DB_NAME` / `CACHE_FILE_PREFIX` / `RATELIMIT_ENABLED` / `ALLOW_RUNTIME_DDL` | 未设 | 未设 |
3. phpunit 运行中实测：`DB_HOST` 解析为 **`mysql`**（不是 phpunit.xml 里的 `127.0.0.1`）→ **证明无 force 时 phpunit 的 DB 值被忽略**。若给它加 force，测试会改连 `127.0.0.1` 并在容器内失败。

**要做**
- 对下列 env 加 `force="true"`（共 6 个，按现状逐一判断，认为不该加的请给理由）：
  `APP_ENV`、`ALLOW_RUNTIME_DDL`、`CACHE_FILE_PREFIX`、`RATELIMIT_ENABLED`、
  `RATELIMIT_SCOPE_IP_LIMIT/PERIOD`、`RATELIMIT_SCOPE_USER_LIMIT/PERIOD`、`RATELIMIT_SCOPE_TENANT_LIMIT/PERIOD`
- 对 `DB_*` **不加 force**，并在 phpunit.xml 里加一段注释说明理由（连接参数应跟随运行环境；
  phpunit.xml 里的值只是「环境未提供时的默认值」）
- 每处 force 加一行中文注释说明「为什么测试必须固定这个值」

**验证（必须给原始输出）**
1. **反证（改前）**：导出冲突变量跑测试，证明 phpunit 的值被抢占
   - `CACHE_FILE_PREFIX=evil` 导出 → 跑 `tests/Unit/Lowcode/Schema/SchemaParserTest.php` →
     观察 `runtime/cache/test/` **未**生成（作用域失效，R20 回归路径）
   - `APP_ENV=production` 导出 → 跑任一鉴权相关测试 → 观察是否被 JWT 强密钥校验打挂
2. **正证（改后）**：同样导出冲突变量，测试仍用 phpunit 的值
   - `CACHE_FILE_PREFIX=evil` → `runtime/cache/test/` **生成**、根层哨兵幸存
   - `APP_ENV=production` → 测试仍按 dev 跑、不被 JWT 校验打挂
3. **DB 未被 force 的证据**：打印 phpunit 运行中 `env('DB_HOST')`，仍应是 `mysql`
4. **DB_ 加 force 会破坏的反证**（只做一次，做完立即还原）：临时给 `DB_HOST` 加 force，
   跑一个连库测试 → 应失败（连不上 127.0.0.1）；**务必还原**并 `git diff` 确认
5. 全量跑一次：失败集去重 ≤ 41，与基线 diff 一致
6. 三处门禁 exit=0

## 收尾验收（主审串行执行）

- [ ] 全量失败集去重 ≤ 41
- [ ] 三处门禁 exit=0
- [ ] `git status --porcelain` 空
