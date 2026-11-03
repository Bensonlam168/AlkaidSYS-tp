#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration Data Consistency Check | 迁移数据一致性检查（R51）
 *
 * ## 为什么需要本门禁
 *
 * 开发库出现过**两次**「迁移在 `migrations` 表里记为已应用，但它插入的数据一行都不存在」：
 *   - `20251218000001_add_tenant_and_role_management_permissions` → 缺 12 条
 *     `tenant.*` / `role.*` / `permission.*` / `user.*` 权限（已由 `20251225000001` 修复）；
 *   - `20251219000002_add_dept_and_menu_permissions` → 缺 6 条 `dept.*` / `menu.*` 权限
 *     （已由 `20251226000001` 修复）。
 *
 * 这两次的共同点：`migrations` 表说「跑过了」，`permissions` 表说「没有」。
 * Phinx 是在 `up()` **成功之后**才写账本，因此干净的 `migrate:run` 不可能留下这种状态
 * ——数据是被执行环境之外的改动弄丢的。**结构性问题是没有门禁比对「迁移意图 ↔ 实际行」**，
 * 于是丢了会永久潜伏，只以 403 / 2002 的形式在业务侧暴露。
 *
 * ## 设计：从迁移源码提取「意图」，再与库现状比对
 *
 * 刻意**不手写一份期望清单**：那种清单必然与迁移文件脱钩（新增迁移忘了加 → 门禁永远绿），
 * 正是要防的那类腐化。这里改为：
 *
 *   1. 扫描 `database/migrations/*.php`，用 `token_get_all()` 解析源码
 *      （与 `scripts/check-migrations.php` 同一手法，正则会在注释/字符串上误判）；
 *   2. 从**数组字面量**里提取「数据意图」：
 *      - 权限：键为 `slug`、值为 `resource.action` 形态（两段、小写字母数字下划线）；
 *      - 菜单：同时含 `tenant_id` / `name` / `type` / `path` 四个键的载荷（`menus` 表专属形态），
 *        取其中 `name` 的字面量值；
 *   3. 只比对**账本里记为已应用**的迁移：未应用的迁移「还没跑」，不是不一致
 *      （否则每写完一条新迁移、还没 `migrate:run` 时门禁就红，属于误报）；
 *   4. 缺失即报错，并指回「哪个迁移文件 / 第几行 / 哪个 slug」，便于定位；
 *   5. 新增迁移自动被覆盖：新迁移只要用同样的数组写法插入权限/菜单，无需改本脚本。
 *
 * ## up() / down() 的区分（可靠性说明）
 *
 * `down()` 里的 slug 是**删除**意图，不能当成期望存在的意图。本脚本的做法是：
 * 在 token 流上定位 `function down(...) { ... }`，按花括号深度把**整个方法体剔除**后再提取。
 * 也就是说参与提取的是「类体 − down() 方法体」：既有 `up()`，也有 `up()` 依赖的
 * **类属性数组**与**私有辅助方法**（本项目两种写法都存在：
 * `20251218000001` 的 `$permissions` 是类属性、`EnsureSystemPermissions` 也是类属性）。
 *
 * 逐 token 处理而不是按行切分，好处是：
 *   - 注释 / 文档注释里的 `'slug' => 'x.y'` **不会**被误提取（注释是单个 token）；
 *   - 字符串里的同样片段也不会（`T_CONSTANT_ENCAPSED_STRING` 是单个 token）。
 *
 * ## 提取策略的可靠性边界（哪些写法会漏检 / 误检）
 *
 * 会**漏检**（不报，但也不误报 —— 宁可漏，不可乱）：
 *   - 权限 / 菜单用纯 SQL 字符串插入（`$this->execute("INSERT INTO permissions ...")`），
 *     静态上不存在「键 => 字面量」结构，本脚本不解析 SQL 文本；
 *   - slug / name 来自变量、`sprintf`、`array_column()`、配置常量等非字面量来源；
 *   - 用 `change()` 隐式插入（本项目无此写法）。
 * 会**误检**（报了但实际不算缺失）：
 *   - 同名字段被别的表复用，且同时凑齐四个菜单键（当前代码库里没有这种载荷）；
 *   - 迁移**退役**了某条 slug/菜单（改名、下线）而旧迁移仍留在链上 ——
 *     历史迁移的插入意图不会消失，此时用下方 `RETIRED_DATA_EXEMPTIONS` 登记（必须带到期日）。
 *
 * 会**漏检**的已知盲点（如实说明，不粉饰）：
 *   - 菜单只按 `name` 比对，**不区分租户**：载荷里的 `tenant_id` 是变量（`$tenantId`）而不是字面量，
 *     静态上取不到值。因此「菜单建在了别的租户下」这种情况本脚本认为「存在」。
 *     `menus` 的唯一键是 `(tenant_id, name)`，而本项目迁移只给租户 1 建菜单，实际影响可忽略。
 *   - 只校验「行存在」，不校验「字段值对不对」（name/resource/action/description 的内容漂移不报）。
 *     值漂移属于另一类问题（例如 seeder 与迁移对同一 slug 给出不同显示名），不在本门禁范围。
 *
 * ## 只做权限与菜单的原因（其它表为什么不做）
 *
 * - `tenants`：唯一的插入是 `20251219000003` 里的**内联 SQL**（`INSERT INTO tenants ... FROM DUAL`），
 *   且租户行本就是运行期实体（开发库 207 行），期望值无法从源码稳定推导 → 不做；
 * - `roles` / `users`：没有任何迁移插入它们（由 `database/seeds/CorePlatformSeed.php` 负责），
 *   迁移侧无意图可比 → 不做；
 * - `workflow_definitions` / `workflow_triggers` / `workflow_event_logs`：`20251216000003` 插入的是
 *   **示例数据**，且被 `definitionExists()` / `tenantExists()` 等守卫包住（租户不存在就整体跳过），
 *   「应当存在」并不成立 → 不做；
 * - `role_permissions`：授权意图写在纯 SQL 里（`INSERT ... SELECT ... WHERE p.resource IN (...)`），
 *   同样不可静态提取 → 不做；
 * - `menus`：两个菜单迁移都是「数组字面量 → `$this->table('menus')->insert()`」，
 *   且载荷同时含 `tenant_id`/`name`/`type`/`path`（工作流的 `buildTriggerRow()` 只有
 *   `tenant_id`/`name`/`type`，没有 `path`，天然区分）→ **做**。
 *
 * ## 用法
 *
 *   php scripts/check-migration-data.php                  # 连库检查，列出问题，退出码 0
 *   php scripts/check-migration-data.php --strict          # 连库检查，有缺失即退出码 1（本地门禁）
 *   php scripts/check-migration-data.php --strict --no-db  # 只跑源码侧提取（CI 静态步骤）
 *
 * ## 关于 `--no-db`（与 scripts/check-migrations.php 语义完全一致）
 *
 * 它的语义是「**本环境没有 applied 状态**」，**不是**「允许跳过检查」：
 * 传了它会显式打印「跳过（--no-db）」，日志能一眼看出这次只验了源码侧提取，
 * 不会被伪装成「全量通过」。CI 里必须用它（GitHub Actions 的全新库没有 applied 基准）——
 * 但 CI 另有一个带 MySQL service 的 job 会真正 `migrate:run` 后跑不带 `--no-db` 的全量检查，
 * 那才是能抓住本类问题的地方。
 *
 * ## fail-closed（比 `--strict` 更强）
 *
 * 1. 「有缺失」→ 仅 `--strict` 下 exit 1（本地自查模式允许只看报告）；
 * 2. 「连不上库 / 查询失败 / 表缺失」→ **一律 exit 1**，不受 `--strict` 影响：
 *    这时门禁**根本没跑**，退出 0 等于把「没检查」报成「通过」；
 *    `--no-db` 是唯一允许跳过的方式，且必须显式传。
 * 3. `enforceFailClosedOnError()`：`think\App::initialize()` 会注册全局异常处理器，
 *    未捕获异常被它渲染之后 PHP 以 **exit 0** 结束（`set_exception_handler()` 的返回值不影响退出码），
 *    于是脚本崩在半途时日志里既有报错又显示通过。这里在 `initialize()` 之后把处理器换回
 *    「打印到 STDERR + exit 1」。与 `scripts/check-migrations.php` 同一套兜底。
 *
 * ## 退出码
 *
 *   0 - 通过；或**未传** `--strict` 时「已列出问题但只作报告」（本地自查）。
 *       `--no-db` 下 0 只代表「源码侧提取通过」，数据比对已显式跳过。
 *   1 - `--strict` 下存在缺失 / 豁免条目异常；或**连库失败**（不受 `--strict` 影响，
 *       因为门禁根本没跑，报 0 等于把「没检查」伪装成「通过」）；或脚本异常中断。
 *
 * @package scripts
 */

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/** 仓库根目录 */
const REPO_ROOT = __DIR__ . '/..';

/** 迁移目录（相对仓库根） */
const MIGRATION_DIR = 'database/migrations';

/**
 * 权限 slug 的形态：`resource.action`，两段、小写字母数字下划线
 *
 * 只认这种形态，`roles.slug = 'admin'` / `tenants.slug = 'default'` 这类单段 slug 不会被误认。
 */
const PERMISSION_SLUG_PATTERN = '/^[a-z0-9_]+\.[a-z0-9_]+$/';

/**
 * 菜单 name 的形态（路由名，如 `UserManagement`）
 *
 * 用在识别出「菜单载荷」之后再做一次形态校验，避免把 `'name' => 'idx_slug'` 之类
 * 索引名当成菜单名（虽然那样也凑不齐四个键，属于双保险）。
 */
const MENU_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

/**
 * 菜单载荷的判别键（`menus` 表插入语句的字段签名）
 *
 * 四个键必须**同时出现**在同一个数组字面量里：
 *   - `tenant_id`：菜单表 NOT NULL 且有租户外键，插入必带；
 *   - `name` / `type` / `path`：`menus` 表的核心列；
 *   - `path` 是关键区分点：工作流的 `buildTriggerRow()` 同样有 `tenant_id`/`name`/`type`，
 *     但没有 `path`，不会与菜单混淆。
 */
const MENU_PAYLOAD_KEYS = ['tenant_id', 'name', 'type', 'path'];

/**
 * 已退役数据的豁免基线（**当前为空 = 健康状态**）
 *
 * 用途：某条 slug / 菜单被后续迁移**改名或下线**后，历史迁移文件里的插入意图仍在，
 * 本门禁会一直报「缺失」。此时把该键登记在此，而不是去改历史迁移或放宽比对。
 *
 * 硬要求（与 `scripts/check-migrations.php` 的 `ORPHAN_APPLIED_EXEMPTIONS` 同一套模式）：
 *   - 每条必须带 `review_by` 到期日，到期即退化为错误（`[EXEMPT-EXPIRED]`）；
 *   - 条目一旦不再缺失，立刻报 `[EXEMPT-STALE]` 要求删除，**不允许留僵尸豁免**。
 *
 * 新增条目等同于承认「这条数据被有意退役」，请同时写清 reason 与去向。
 * 格式：['permissions' => [slug => meta], 'menus' => [name => meta]]
 */
const RETIRED_DATA_EXEMPTIONS = [
    'permissions' => [],
    'menus'       => [],
];

// ---------------------------------------------------------------------------
// 输出工具
// ---------------------------------------------------------------------------

/**
 * 终端着色（NO_COLOR 环境变量存在时禁用）
 */
function colorize(string $text, string $color): string
{
    $codes = ['red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'gray' => '90'];
    $code = $codes[$color] ?? null;
    if ($code === null || getenv('NO_COLOR') !== false) {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

/**
 * 格式化检查结果
 */
function formatResult(int $errors, int $warnings = 0): string
{
    if ($errors === 0 && $warnings === 0) {
        return colorize('OK', 'green');
    }
    if ($errors === 0) {
        return colorize("{$warnings} 个告警", 'yellow');
    }

    return colorize("{$errors} 个缺失" . ($warnings > 0 ? " / {$warnings} 个告警" : ''), 'red');
}

/**
 * 兜底：确保「脚本自己崩了」不会被报成通过
 *
 * `think\App::initialize()` 会注册全局异常处理器，而未捕获异常被它渲染之后，
 * PHP 会以 **exit 0** 结束（`set_exception_handler()` 的返回值不影响退出码）。
 * 也就是说门禁脚本存在「明明抛错却绿灯」的隐患 ——
 * 实测：`$app->initialize()` 之后执行 `strlen([])`，输出 TypeError 后 `$?` 仍为 0。
 *
 * 因此在每次 `initialize()` 之后把异常处理器换回「打印到 STDERR + exit 1」。
 */
function enforceFailClosedOnError(): void
{
    set_exception_handler(static function (\Throwable $e): void {
        fwrite(STDERR, '[FATAL] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        fwrite(STDERR, '检查因脚本异常中断，按失败处理' . PHP_EOL);
        exit(1);
    });
}

// ---------------------------------------------------------------------------
// 源码侧：token 工具
// ---------------------------------------------------------------------------

/**
 * 取 token 的文本（数组 token 取 [1]，字符 token 原样返回）
 *
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function tokenText(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}

/**
 * 取 token 的 id（字符 token 返回 null）
 *
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function tokenId(array|string $token): ?int
{
    return is_array($token) ? $token[0] : null;
}

/**
 * 取 token 在源码中的起始行（字符 token 无行号，返回 0）
 *
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function tokenLine(array|string $token): int
{
    return is_array($token) ? $token[2] : 0;
}

/**
 * 是否为可忽略的 token（空白与注释）
 *
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function isIgnorableToken(array|string $token): bool
{
    return in_array(tokenId($token), [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
}

/**
 * 找下一个「有效」token 的下标（跳过空白与注释）
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function nextSignificantIndex(array $tokens, int $from): ?int
{
    $count = count($tokens);
    for ($i = $from + 1; $i < $count; $i++) {
        if (!isIgnorableToken($tokens[$i])) {
            return $i;
        }
    }

    return null;
}

/**
 * 找上一个「有效」token 的下标（跳过空白与注释）
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function previousSignificantIndex(array $tokens, int $from): ?int
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if (!isIgnorableToken($tokens[$i])) {
            return $i;
        }
    }

    return null;
}

/**
 * 解出字符串字面量的内容（仅处理 `T_CONSTANT_ENCAPSED_STRING`）
 *
 * 该 token 类型只覆盖**无插值**的字面量，因此不需要处理 `${...}`；
 * 只需还原 `\'` / `\\`（单引号）与常见转义（双引号）。
 */
function decodeStringLiteral(string $text): ?string
{
    $length = strlen($text);
    if ($length < 2) {
        return null;
    }
    $quote = $text[0];
    if (($quote !== "'" && $quote !== '"') || substr($text, -1) !== $quote) {
        return null;
    }
    $body = substr($text, 1, -1);

    if ($quote === "'") {
        // 单引号里只有 \\ 与 \' 是转义，其余反斜杠按字面保留
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    return stripcslashes($body);
}

/**
 * 判定「开括号」的 token 形态
 *
 * - `[` → 数组（短语法）；
 * - `(` 且前一个有效 token 是 `array` → 数组（长语法）；
 * - 其它 `(` → 普通括号（函数调用/参数表），仅作为透明容器维护栈平衡。
 *
 * 插值字符串里的 `{$x}` / `${x}` 也用花括号，但它们**不在**本函数的处理范围内：
 * 这里只处理 `[` / `(`，花括号只在剔除 `down()` 时参与深度计算。
 */
function arrayOpenerKind(array $tokens, int $index): ?string
{
    $text = tokenText($tokens[$index]);
    if ('[' === $text) {
        return 'bracket';
    }
    if ('(' === $text) {
        $prev = previousSignificantIndex($tokens, $index);
        if (null !== $prev && T_ARRAY === tokenId($tokens[$prev])) {
            return 'array';
        }

        return 'paren';
    }

    return null;
}

/**
 * 剔除 `down()` 方法体，返回「up 侧」token 流
 *
 * 只剔除 `down()`：`up()` 依赖的类属性数组与私有辅助方法都保留
 * （`20251218000001` / `EnsureSystemPermissions` 的 `$permissions` 就是类属性，
 * 只扫 `up()` 方法体会整条漏掉）。
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @return array<int, array{0:int, 1:string, 2:int}|string>
 */
function stripDownMethodTokens(array $tokens): array
{
    $result = [];
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (T_FUNCTION === tokenId($token)) {
            $nameIndex = nextSignificantIndex($tokens, $i);
            if (null !== $nameIndex && 'down' === strtolower(tokenText($tokens[$nameIndex]))) {
                // 跳到方法体结束：整个 down() 不参与提取
                $i = skipFunctionBody($tokens, $nameIndex);
                continue;
            }
        }

        $result[] = $token;
    }

    return $result;
}

/**
 * 从「方法名 token」往后跳到方法体闭合处，返回该闭合 token 的下标
 *
 * 花括号深度计算把 3 种「开括号」都算进去，保证插值字符串里的
 * `{$var}` / `${var}` 不会让深度失衡：
 *   `T_CURLY_OPEN`（`{$var}` 的前半）、`T_DOLLAR_OPEN_CURLY_BRACES`（`${var}` 的前半）、
 *   以及普通的 `{`。它们的闭合都是同一个 `}` 文本 token。
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function skipFunctionBody(array $tokens, int $nameIndex): int
{
    $count = count($tokens);

    // 1) 找方法体的左花括号（先经过参数表与返回类型）
    $openIndex = null;
    for ($i = $nameIndex + 1; $i < $count; $i++) {
        $text = tokenText($tokens[$i]);
        if ('{' === $text) {
            $openIndex = $i;
            break;
        }
        if (';' === $text) {
            // 抽象方法/接口声明：没有方法体
            return $i;
        }
    }

    if (null === $openIndex) {
        return $count - 1;
    }

    // 2) 按深度找闭合花括号
    $depth = 0;
    for ($i = $openIndex; $i < $count; $i++) {
        $token = $tokens[$i];
        $text  = tokenText($token);
        $id    = tokenId($token);

        $isOpener = ('{' === $text)
            || T_CURLY_OPEN === $id
            || T_DOLLAR_OPEN_CURLY_BRACES === $id;

        if ($isOpener) {
            $depth++;
            continue;
        }
        if ('}' === $text) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $count - 1;
}

// ---------------------------------------------------------------------------
// 源码侧：提取数据意图
// ---------------------------------------------------------------------------

/**
 * 收集 token 流里所有数组字面量的「一级键 => 字面量值」
 *
 * 只记录**同一层级**的键值对：嵌套数组会被压栈单独结算，
 * 因此 `'meta' => json_encode(['title' => ...])` 里的 `title` 不会污染外层载荷。
 *
 * 键只认 `T_CONSTANT_ENCAPSED_STRING . T_DOUBLE_ARROW` 这个组合，
 * 所以 `$permission['slug']`（取值）与 `->where('slug', 'x')`（选择条件）都不会被当成键
 * ——它们后面没有 `=>`。这一点是本提取策略的核心：**插入载荷才有 `=>`，查询/删除条件没有**。
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @return array<int, array<string, array{value:string|null, line:int}>>
 */
function collectArrayPayloads(array $tokens): array
{
    $payloads = [];
    /** @var array<int, array{pairs: array<string, array{value:string|null, line:int}>, pending: string|null}> $stack */
    $stack = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $text  = tokenText($token);

        // --- 1) 开括号：压栈 -------------------------------------------------
        $openerKind = arrayOpenerKind($tokens, $i);
        if (null !== $openerKind) {
            if ([] !== $stack) {
                // 上一个待定键的值不是字面量（是数组/调用），结算为 null
                settlePendingKey($stack);
            }
            $stack[] = ['pairs' => [], 'pending' => null];
            continue;
        }

        // --- 2) 闭括号：出栈并结算 -------------------------------------------
        if (']' === $text || ')' === $text) {
            if ([] === $stack) {
                continue;
            }
            settlePendingKey($stack);
            $context = array_pop($stack);
            // 只有真正的数组载荷才参与分类（普通参数表 `(...)` 无意义）
            if ([] !== $context['pairs']) {
                $payloads[] = $context['pairs'];
            }
            continue;
        }

        // --- 3) 层级内的键值对 ------------------------------------------------
        if ([] === $stack) {
            continue;
        }
        $top = count($stack) - 1;

        // `=>` 本身不参与任何判定
        if (T_DOUBLE_ARROW === tokenId($token)) {
            continue;
        }

        if (T_CONSTANT_ENCAPSED_STRING === tokenId($token)) {
            $next = nextSignificantIndex($tokens, $i);
            if (null !== $next && T_DOUBLE_ARROW === tokenId($tokens[$next])) {
                // 这是一个键：等它的值
                $stack[$top]['pending'] = decodeStringLiteral($text);
                continue;
            }

            if (null !== $stack[$top]['pending']) {
                $key                                       = $stack[$top]['pending'];
                $stack[$top]['pairs'][$key]                = [
                    'value' => decodeStringLiteral($text),
                    'line'  => tokenLine($token),
                ];
                $stack[$top]['pending'] = null;
            }
            continue;
        }

        // 其它 token（变量、数字、常量、闭包……）：待定键的值不是字面量
        if (!isIgnorableToken($token)) {
            settlePendingKey($stack);
        }
    }

    return $payloads;
}

/**
 * 把当前栈顶的「待定键」结算为 null 值（表示其值不是字面量）
 *
 * @param array<int, array{pairs: array<string, array{value:string|null, line:int}>, pending: string|null}> $stack
 */
function settlePendingKey(array &$stack): void
{
    $top = count($stack) - 1;
    if ($top < 0 || null === $stack[$top]['pending']) {
        return;
    }
    $key                        = $stack[$top]['pending'];
    $stack[$top]['pairs'][$key] = ['value' => null, 'line' => 0];
    $stack[$top]['pending']     = null;
}

/**
 * 从单个迁移文件的源码里提取数据意图
 *
 * @return array{
 *     permissions: array<string, int>,
 *     menus: array<string, int>,
 *     skipped: array<int, string>
 * } permissions/menus 的值为该字面量出现的行号
 */
function extractDataIntents(string $code): array
{
    $tokens   = stripDownMethodTokens(token_get_all($code));
    $payloads = collectArrayPayloads($tokens);

    $permissions = [];
    $menus       = [];
    $skipped     = [];

    foreach ($payloads as $payload) {
        // --- 权限载荷：'slug' => 'resource.action' -------------------------
        if (isset($payload['slug']) && is_string($payload['slug']['value'])) {
            $slug = $payload['slug']['value'];
            if (preg_match(PERMISSION_SLUG_PATTERN, $slug) === 1) {
                if (!isset($permissions[$slug])) {
                    $permissions[$slug] = $payload['slug']['line'];
                }
                continue;
            }
            // 形态不符（如 'admin' / 'default'）：不是权限意图，记录以便说明覆盖范围
            $skipped[] = "slug「{$slug}」不是 resource.action 形态，按非权限处理";
            continue;
        }

        // --- 菜单载荷：tenant_id + name + type + path ----------------------
        $isMenuPayload = true;
        foreach (MENU_PAYLOAD_KEYS as $key) {
            if (!isset($payload[$key])) {
                $isMenuPayload = false;
                break;
            }
        }
        if (!$isMenuPayload) {
            continue;
        }
        $name = $payload['name']['value'];
        if (!is_string($name) || preg_match(MENU_NAME_PATTERN, $name) !== 1) {
            $skipped[] = '菜单载荷的 name 不是字面量或形态不符，跳过';
            continue;
        }
        if (!isset($menus[$name])) {
            $menus[$name] = $payload['name']['line'];
        }
    }

    return ['permissions' => $permissions, 'menus' => $menus, 'skipped' => $skipped];
}

/**
 * 收集迁移文件（与 scripts/check-migrations.php 的口径一致）
 *
 * @return array<int, array{file:string, base:string, version:int}>
 */
function collectMigrationFiles(string $dir): array
{
    $result = [];
    if (!is_dir($dir)) {
        return $result;
    }

    $files = glob($dir . '/*.php');
    if (false === $files) {
        return $result;
    }
    sort($files);

    foreach ($files as $file) {
        $base = basename($file);
        // 迁移文件名格式：<时间戳前缀>_<类名>.php
        if (preg_match('/^(\d{8,14})_[A-Za-z0-9_]+\.php$/', $base, $matches) !== 1) {
            continue;
        }
        $result[] = [
            'file'    => $file,
            'base'    => $base,
            'version' => (int) $matches[1],
        ];
    }

    return $result;
}

// ---------------------------------------------------------------------------
// 数据库侧
// ---------------------------------------------------------------------------

/**
 * 读取比对所需的库现状
 *
 * 返回结构里 ok=false 表示「门禁根本没跑」（连不上库 / 表缺失），调用方必须 exit 1。
 * 这与「有缺失」是两种不同的失败：前者意味着**没有任何判定依据**。
 *
 * @return array{
 *     ok: bool,
 *     error: string,
 *     database: string,
 *     applied: int[],
 *     permissions: string[]|null,
 *     menus: string[]|null
 * }
 */
function loadDatabaseState(): array
{
    $state = [
        'ok'          => false,
        'error'       => '',
        'database'    => '',
        'applied'     => [],
        'permissions' => null,
        'menus'       => null,
    ];

    try {
        $autoload = REPO_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            $state['error'] = '未找到 vendor/autoload.php，请先在容器内执行 composer install';

            return $state;
        }
        require_once $autoload;

        $app = new \think\App();
        $app->initialize();
        // 框架会把未捕获异常吞成 exit 0，这里立刻换回 fail-closed 处理器
        enforceFailClosedOnError();

        $connection = $app->db->connect();
        if (!is_object($connection)) {
            // think\Db::connect() 在缺 DB_NAME 等配置时会返回 false，此时错误信息极其含糊，
            // 这里直接给出可操作的提示，而不是让后面 `->getPdo()` 抛「on bool」。
            $state['error'] = '数据库连接失败（App::db->connect() 返回非对象），'
                . '请检查 .env / 环境变量里的 DB_HOST、DB_NAME、DB_USER、DB_PASS';

            return $state;
        }
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            $state['error'] = '数据库连接未返回 PDO 句柄，请检查 DB_* 配置';

            return $state;
        }

        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $state['database'] = $database;

        /** @var string[] $tables */
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        // migrations 表是「已应用」的唯一基准；缺它就无法判定，直接判失败
        if (!in_array('migrations', $tables, true)) {
            $state['error'] = "数据库 {$database} 里没有 migrations 表，无法确定「哪些迁移已应用」";

            return $state;
        }

        /** @var int[] $applied */
        $applied = array_map(
            static fn ($version): int => (int) $version,
            $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN)
        );
        sort($applied);
        $state['applied'] = $applied;

        // 表缺失不当作「连接失败」：如实记为 null，由检查项逐条报「整表缺失」，
        // 这比含糊的「连不上」更能指向真正的问题（例如只跑了一半迁移）
        if (in_array('permissions', $tables, true)) {
            /** @var string[] $slugs */
            $slugs               = $pdo->query('SELECT slug FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
            $state['permissions'] = array_values(array_unique(array_map('strval', $slugs)));
        }
        if (in_array('menus', $tables, true)) {
            /** @var string[] $names */
            $names         = $pdo->query('SELECT name FROM menus')->fetchAll(PDO::FETCH_COLUMN);
            $state['menus'] = array_values(array_unique(array_map('strval', $names)));
        }

        $state['ok'] = true;
    } catch (\Throwable $e) {
        $state['ok']    = false;
        $state['error'] = get_class($e) . ': ' . $e->getMessage();
    }

    return $state;
}

// ---------------------------------------------------------------------------
// 比对
// ---------------------------------------------------------------------------

/**
 * 找出缺失项（期望集合 − 实际集合）
 *
 * @param array<int, array{version:int, base:string, file:string, line:int, key:string}> $expected
 * @param string[]                                                                       $actual
 * @return array<int, array{version:int, base:string, file:string, line:int, key:string}>
 */
function findMissingItems(array $expected, array $actual): array
{
    $actualSet = array_flip($actual);
    $missing   = [];
    foreach ($expected as $item) {
        if (isset($actualSet[$item['key']])) {
            continue;
        }
        $missing[] = $item;
    }

    return $missing;
}

/**
 * 审核退役豁免条目：过期 / 元数据缺失 / 僵尸条目都算错误
 *
 * @param string[]                                                   $missingKeys
 * @param array<string, array{reason:string, review_by:string}>       $exemptions
 * @return array{errors: string[], notices: string[], kept: string[]}
 */
function auditRetiredExemptions(array $missingKeys, array $exemptions): array
{
    $errors  = [];
    $notices = [];
    $kept    = [];
    $today   = date('Y-m-d');

    foreach ($exemptions as $key => $meta) {
        $key      = (string) $key;
        $reviewBy = (string) ($meta['review_by'] ?? '');
        $reason   = (string) ($meta['reason'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewBy) !== 1) {
            $errors[] = "[EXEMPT-META] 豁免条目 {$key} 缺少合法的 review_by（YYYY-MM-DD），请补齐或删除";
            continue;
        }
        if ($reviewBy < $today) {
            $errors[] = "[EXEMPT-EXPIRED] 豁免条目 {$key} 已于 {$reviewBy} 到期，请处理后删除本条";
            continue;
        }
        if (!in_array($key, $missingKeys, true)) {
            $errors[] = "[EXEMPT-STALE] 豁免条目 {$key} 已不再缺失，请删除本条（不允许留僵尸豁免）";
            continue;
        }

        $remaining = (int) ceil((strtotime($reviewBy) - strtotime($today)) / 86400);
        $notices[] = "豁免 {$key} 有效期至 {$reviewBy}，剩余 {$remaining} 天（理由："
            . ('' !== $reason ? $reason : '缺少 reason，请补齐') . '）';
        $kept[] = $key;
    }

    return ['errors' => $errors, 'notices' => $notices, 'kept' => $kept];
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);
// `--no-db` 的语义 = 「本环境没有 applied 状态」（CI 专用），不是「允许跳过检查」
$noDb = in_array('--no-db', $argv, true);

echo colorize('Migration Data Consistency Check | 迁移数据一致性检查', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

if ($noDb) {
    // 把「本次覆盖范围」写在最显眼的位置：避免 CI 日志被误读成「全量检查通过」
    echo colorize('模式: --no-db（无 applied 状态，仅做源码侧提取；本地请跑全量）', 'yellow') . PHP_EOL;
}

$migrations = collectMigrationFiles(REPO_ROOT . '/' . MIGRATION_DIR);
echo '扫描迁移文件数: ' . count($migrations) . PHP_EOL;

// --- 提取：每个迁移文件的数据意图 ---------------------------------------------
/** @var array<int, array{base:string, file:string, version:int, permissions:array<string,int>, menus:array<string,int>, skipped:string[]}> $intents */
$intents          = [];
$permissionCount  = 0;
$permissionSlugs  = [];
$menuCount        = 0;
$menuNames        = [];
$skippedNotes     = [];

foreach ($migrations as $migration) {
    $code = @file_get_contents($migration['file']);
    if (false === $code) {
        // 读不到源码 = 提取无法进行，按失败处理（不是「没有问题」）
        echo colorize('[FATAL]', 'red') . " 迁移文件不可读：{$migration['file']}" . PHP_EOL;
        exit(1);
    }

    $extracted = extractDataIntents($code);
    if ([] === $extracted['permissions'] && [] === $extracted['menus']) {
        continue;
    }

    $intents[] = [
        'base'        => $migration['base'],
        'file'        => $migration['file'],
        'version'     => $migration['version'],
        'permissions' => $extracted['permissions'],
        'menus'       => $extracted['menus'],
        'skipped'     => $extracted['skipped'],
    ];

    $permissionCount += count($extracted['permissions']);
    foreach (array_keys($extracted['permissions']) as $slug) {
        $permissionSlugs[$slug] = true;
    }
    $menuCount += count($extracted['menus']);
    foreach (array_keys($extracted['menus']) as $name) {
        $menuNames[$name] = true;
    }
    foreach ($extracted['skipped'] as $note) {
        $skippedNotes[$migration['base']][] = $note;
    }
}

printf(
    "数据型迁移: %d 个（权限 %d 个 / 菜单 %d 个）\n",
    count($intents),
    count(array_filter($intents, static fn (array $i): bool => [] !== $i['permissions'])),
    count(array_filter($intents, static fn (array $i): bool => [] !== $i['menus']))
);
printf(
    "预期项: 权限 %d 个 slug（%d 处声明）/ 菜单 %d 个 name（%d 处声明）\n\n",
    count($permissionSlugs),
    $permissionCount,
    count($menuNames),
    $menuCount
);

// --- --no-db：跳过比对 -------------------------------------------------------
if ($noDb) {
    printf("检查 A  权限数据（permissions.slug）  %s\n", colorize('跳过（--no-db）', 'gray'));
    echo '   ' . colorize('[SKIP]', 'gray')
        . ' --no-db：本环境没有 applied 状态，无法确定「哪些迁移已应用」，不做数据比对' . PHP_EOL;
    printf("检查 B  菜单数据（menus.name）        %s\n", colorize('跳过（--no-db）', 'gray'));
    echo '   ' . colorize('[SKIP]', 'gray')
        . ' --no-db：同上，菜单比对同样依赖 applied 集合' . PHP_EOL;
    printf("检查 C  比对基准（已应用迁移）        %s\n", colorize('跳过（--no-db）', 'gray'));
    echo '   ' . colorize('[SKIP]', 'gray')
        . ' --no-db：基准就是 migrations 表，本环境没有' . PHP_EOL;

    echo str_repeat('-', 64) . PHP_EOL;
    echo colorize(
        '提示：--no-db 只覆盖「源码侧提取」。数据比对需要 applied 状态，请在本地跑全量：'
        . 'php scripts/check-migration-data.php --strict',
        'gray'
    ) . PHP_EOL;
    echo colorize('✓ 源码侧提取通过；数据比对已跳过（--no-db）', 'green') . PHP_EOL;
    exit(0);
}

// --- 连库 --------------------------------------------------------------------
$state = loadDatabaseState();
if (!$state['ok']) {
    // fail-closed：门禁没跑成，一律 exit 1（不受 --strict 影响）
    echo colorize('[FATAL]', 'red') . ' 无法读取数据库现状：' . $state['error'] . PHP_EOL;
    echo colorize(
        '检查未执行 —— 连库失败不能报成通过。若本环境确实没有数据库，请显式传 --no-db。',
        'red'
    ) . PHP_EOL;
    exit(1);
}

echo '数据库: ' . $state['database'] . '（applied ' . count($state['applied']) . ' 条）' . PHP_EOL . PHP_EOL;

$appliedSet = array_flip($state['applied']);
$errorCount = 0;
$warnCount  = 0;

// --- 检查 C（先算基准，A/B 依赖它）-------------------------------------------
$appliedIntents    = [];
$notAppliedIntents = [];
foreach ($intents as $intent) {
    if (isset($appliedSet[$intent['version']])) {
        $appliedIntents[] = $intent;
    } else {
        $notAppliedIntents[] = $intent;
    }
}

// applied 里有、仓库里没有文件：由 check-migrations 的检查 F 负责，这里只做灰色提示
$fileVersions = [];
foreach ($migrations as $migration) {
    $fileVersions[$migration['version']] = true;
}
$orphanApplied = [];
foreach ($state['applied'] as $version) {
    if (!isset($fileVersions[$version])) {
        $orphanApplied[] = $version;
    }
}

$cWarnings = 0;
if ([] === $appliedIntents && [] !== $intents) {
    // 连到了库、但一条含数据意图的迁移都没记为已应用 —— 极可能是连错了库
    $cWarnings = 1;
}
printf("检查 C  比对基准（已应用迁移）        %s\n", formatResult(0, $cWarnings));
printf(
    "   参与比对的数据型迁移: %d / %d\n",
    count($appliedIntents),
    count($intents)
);
if ($cWarnings > 0) {
    echo '   ' . colorize('[WARN]', 'yellow')
        . ' 数据库中没有任何含数据意图的迁移被记为已应用，请确认连的是正确的库' . PHP_EOL;
    $warnCount += $cWarnings;
}
foreach ($notAppliedIntents as $intent) {
    echo '   ' . colorize('[INFO]', 'gray')
        . " {$intent['base']} 尚未 applied，跳过比对（未执行 ≠ 账本有数据无）" . PHP_EOL;
}
foreach ($orphanApplied as $version) {
    echo '   ' . colorize('[INFO]', 'gray')
        . " applied 记录 {$version} 无对应迁移文件（由 check-migrations 的检查 F 负责）" . PHP_EOL;
}
foreach ($skippedNotes as $base => $notes) {
    foreach ($notes as $note) {
        echo '   ' . colorize('[INFO]', 'gray') . " {$base}：{$note}" . PHP_EOL;
    }
}

// --- 检查 A：权限 ------------------------------------------------------------
/** @var array<int, array{version:int, base:string, file:string, line:int, key:string}> $expectedPermissions */
$expectedPermissions = [];
foreach ($appliedIntents as $intent) {
    foreach ($intent['permissions'] as $slug => $line) {
        $expectedPermissions[] = [
            'version' => $intent['version'],
            'base'    => $intent['base'],
            'file'    => $intent['file'],
            'line'    => $line,
            'key'     => $slug,
        ];
    }
}

$aErrors = 0;
if (null === $state['permissions']) {
    printf("检查 A  权限数据（permissions.slug）  %s\n", formatResult(1));
    echo '   ' . colorize('[TABLE]', 'red')
        . ' permissions 表不存在 —— 迁移链未执行完？整表缺失按缺失处理' . PHP_EOL;
    $aErrors = 1;
    $errorCount += $aErrors;
} else {
    $missingPermissions = findMissingItems($expectedPermissions, $state['permissions']);
    $audit              = auditRetiredExemptions(
        array_map(static fn (array $i): string => $i['key'], $missingPermissions),
        RETIRED_DATA_EXEMPTIONS['permissions']
    );
    $exempted  = $audit['kept'];
    $realMiss  = array_values(array_filter(
        $missingPermissions,
        static fn (array $i): bool => !in_array($i['key'], $exempted, true)
    ));

    printf("检查 A  权限数据（permissions.slug）  %s\n", formatResult(count($realMiss) + count($audit['errors'])));
    foreach ($realMiss as $item) {
        echo '   ' . colorize('[MISSING]', 'red')
            . " {$item['base']}（version {$item['version']}，第 {$item['line']} 行）"
            . "期望 slug「{$item['key']}」，但 permissions 表内不存在" . PHP_EOL;
    }
    foreach ($audit['errors'] as $message) {
        echo '   ' . colorize('[EXEMPT]', 'red') . " {$message}" . PHP_EOL;
    }
    foreach ($audit['notices'] as $message) {
        echo '   ' . colorize('[EXEMPT-REVIEW]', 'gray') . " {$message}" . PHP_EOL;
    }
    $aErrors = count($realMiss) + count($audit['errors']);
    $errorCount += $aErrors;
}

// --- 检查 B：菜单 ------------------------------------------------------------
/** @var array<int, array{version:int, base:string, file:string, line:int, key:string}> $expectedMenus */
$expectedMenus = [];
foreach ($appliedIntents as $intent) {
    foreach ($intent['menus'] as $name => $line) {
        $expectedMenus[] = [
            'version' => $intent['version'],
            'base'    => $intent['base'],
            'file'    => $intent['file'],
            'line'    => $line,
            'key'     => $name,
        ];
    }
}

if (null === $state['menus']) {
    printf("检查 B  菜单数据（menus.name）        %s\n", formatResult(1));
    echo '   ' . colorize('[TABLE]', 'red')
        . ' menus 表不存在 —— 迁移链未执行完？整表缺失按缺失处理' . PHP_EOL;
    $errorCount++;
} else {
    $missingMenus = findMissingItems($expectedMenus, $state['menus']);
    $menuAudit    = auditRetiredExemptions(
        array_map(static fn (array $i): string => $i['key'], $missingMenus),
        RETIRED_DATA_EXEMPTIONS['menus']
    );
    $menuExempted = $menuAudit['kept'];
    $realMenuMiss = array_values(array_filter(
        $missingMenus,
        static fn (array $i): bool => !in_array($i['key'], $menuExempted, true)
    ));

    printf(
        "检查 B  菜单数据（menus.name）        %s\n",
        formatResult(count($realMenuMiss) + count($menuAudit['errors']))
    );
    foreach ($realMenuMiss as $item) {
        echo '   ' . colorize('[MISSING]', 'red')
            . " {$item['base']}（version {$item['version']}，第 {$item['line']} 行）"
            . "期望菜单 name「{$item['key']}」，但 menus 表内不存在" . PHP_EOL;
    }
    foreach ($menuAudit['errors'] as $message) {
        echo '   ' . colorize('[EXEMPT]', 'red') . " {$message}" . PHP_EOL;
    }
    foreach ($menuAudit['notices'] as $message) {
        echo '   ' . colorize('[EXEMPT-REVIEW]', 'gray') . " {$message}" . PHP_EOL;
    }
    $errorCount += count($realMenuMiss) + count($menuAudit['errors']);
}

echo str_repeat('-', 64) . PHP_EOL;

if (0 === $errorCount && 0 === $warnCount) {
    echo colorize('✓ 全部检查通过', 'green') . PHP_EOL;
    exit(0);
}

echo sprintf(
    "%s（缺失/错误 %d 个，告警 %d 个）\n",
    colorize('检查未全部通过', 'red'),
    $errorCount,
    $warnCount
);

if ($strict) {
    // --strict：缺失即失败（本地门禁 / CI 用）
    echo colorize('--strict 模式下缺失/错误视为失败', 'red') . PHP_EOL;
    exit(1);
}

// 非 --strict：只输出报告，退出码 0 —— 供本地自查（与 scripts/check-docs.php 一致）。
// 注意与「连不上库」区分：那种情况在 loadDatabaseState() 之后已无条件 exit 1（fail-closed），
// 因为门禁根本没跑，报 0 等于把「没检查」伪装成「通过」。
echo colorize('提示：使用 --strict 可在门禁中生效（有缺失即返回非 0）', 'gray') . PHP_EOL;
exit(0);
