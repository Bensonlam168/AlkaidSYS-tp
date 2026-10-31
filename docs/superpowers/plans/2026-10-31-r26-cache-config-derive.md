# R26：测试基类的 cache 配置改为派生自 config/cache.php

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development

**Goal:** 消除 `tests/ThinkPHPTestCase.php` 里那份手抄的 `cache` 配置，改为从 `config/cache.php` 派生，只保留测试必需的、有明确理由的少量覆盖。

**Architecture:** `config/cache.php` 是纯 PHP 数组、可直接 `require`。测试基类不再复制整份结构，只覆盖真正需要偏离的键，并对每处偏离写明理由。

**Tech Stack:** ThinkPHP 8 / PHPUnit 11

**Spec:** 上一轮修复清单的 R26

## Global Constraints

- 中文注释与提交信息；**不得推送**；提交用 `git -c core.hooksPath=/dev/null commit`
- 只碰白名单文件
- 全量失败集去重必须 **≤ 41**（当前 41，用例数 520）
- `check-migrations --strict` / `check-docs --strict` / `lang:check` 均 exit=0
- **worker 不得并行跑测试**；同一时刻只允许一个 phpunit 进程

## Review Focus

- **派生后行为必须与改前等价**：`tests/Integration/Permission/`、`tests/Unit/Auth/`、`tests/Unit/Lowcode/` 全绿，全量失败集不变。
- **`Cache::clear()` 的作用域不能被改回整目录**：改后再放一次根层哨兵验证它仍只清 `runtime/cache/test/`。
- **Redis store 必须仍在**：`RateLimitService` 与 Casbin 依赖 `cache.stores.redis`；派生时不得丢失该 store。
- **测试不得意外依赖 `.env` 的 `CACHE_DRIVER`**：若某人本地 `.env` 设了 `CACHE_DRIVER=redis`，测试行为不应被悄悄改变。
- **默认驱动仍应是 `file`**：测试不应因 Redis 不可用而失败。

---

## Task 1：cache 配置改为派生

**Files:**
- Modify: `tests/ThinkPHPTestCase.php`

**已核实事实**
1. `config/cache.php` 是纯 PHP 数组（无 `return` 之外的副作用），顶部用 `env('APP_ENV')` / `env('CACHE_DRIVER')` / `env('CACHE_FILE_PREFIX')` 计算 `default`。
2. 测试基类 `setUpBeforeClass()`（约 `:83-125`）手抄了一份完整 cache 数组，与 config 版的**实际差异只有 3 处**：
   | 键 | config/cache.php | 测试基类 |
   |---|---|---|
   | `default` | 生产→redis，否则 `CACHE_DRIVER`（默认 file） | 硬编码 `file` |
   | `stores.file.path` | `''`（由驱动回落为 `runtime_path()/cache`） | 绝对路径 `$rootPath . '/runtime/cache/'` |
   | `stores.redis.prefix` | `alkaid:` | `alkaid:test:` |
3. `phpunit.xml` 设 `APP_ENV=dev`、未设 `CACHE_DRIVER` → 派生后 `default` 一般也是 `file`。
4. 该配置必须在 `setUpBeforeClass()` 里**早于 `App::initialize()`** 注入：多数测试在 initialize 之前就调用 `Cache::clear()`，而 File 驱动实例一旦创建就被容器缓存、之后不再重建。

**要求**
- 改为 `$cacheConfig = require $rootPath . '/config/cache.php';`
- 只保留**必要的**覆盖，每处必须带一句「为什么测试需要偏离」的注释。判断标准：
  - `default` 是否必须钉死为 `file`？—— 测试不应依赖 Redis 可用性，也不应被开发者本地 `.env` 的 `CACHE_DRIVER` 影响。若认同，保留 `$cacheConfig['default'] = 'file';` 并注明理由。
  - `stores.file.path` 是否必须改成绝对路径？—— 先查明 File 驱动对空 `path` 的回落值是否就等于 `runtime_path()/cache`；**若等价则删掉该覆盖**，若不等价则保留并注明。
  - `stores.redis.prefix` 是否必须改？—— 测试用 `alkaid:test:` 与生产键区分是合理的，保留并注明。
- 不得丢失 `stores.redis`（RateLimit/Casbin 依赖）
- 原有的「runtime 目录不存在则 mkdir」逻辑保留
- 不许把 `config/cache.php` 整个绕过（例如继续手写数组）

- [ ] 先记录改前基线：`Cache::clear()` 作用域证据（根层哨兵幸存）、三个目录测试全绿
- [ ] 改为派生 + 最小覆盖
- [ ] 验证等价性：
  - 打印派生后的 `config('cache')`（或注入前的数组）与改前逐键对比，列出**完全相同**与**有意不同**的键
  - 三个目录测试全绿
  - 根层哨兵仍幸存（`Cache::clear()` 仍只清 `test/`）
  - 全量跑一次：失败集去重 ≤ 41，且 `runtime/cache` 目录跑后仍存在
- [ ] `php -l` 通过

## 收尾验收（主审串行执行）

- [ ] 全量失败集去重 ≤ 41
- [ ] 三处门禁 exit=0
- [ ] `runtime/cache` 存在且 `test/` 作用域仍生效
- [ ] `git status --porcelain` 空
