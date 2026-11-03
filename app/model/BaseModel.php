<?php

declare(strict_types=1);

namespace app\model;

use app\exception\TenantContextMissingException;
use think\Container;
use think\db\BaseQuery as Query;
use think\facade\Log;
use think\Model;
use Throwable;

/**
 * Base Model | 基础模型
 *
 * Provides multi-tenant and multi-site functionality for all models.
 * 为所有模型提供多租户和多站点功能。
 *
 * Features:
 * - Automatic tenant_id and site_id filtering via global scopes
 * - Console detection via `App::runningInConsole()` (Swoole-aware, see below)
 * - Column existence caching for performance
 * - Automatic timestamp fields
 *
 * 特性：
 * - 通过全局作用域自动 tenant_id 和 site_id 过滤
 * - 用 `App::runningInConsole()` 判定运行环境（**Swoole 感知**，见下）
 * - 字段存在性缓存以提升性能
 * - 自动时间戳字段
 *
 * Usage: Subclasses should set $globalScope = ['tenant', 'site'] to enable scopes.
 * 使用方法：子类应设置 $globalScope = ['tenant', 'site'] 以启用作用域。
 *
 * ## 三个必须同时成立的契约（改动前请先读完）
 *
 * ### 1. 环境判据用 `App::runningInConsole()`，**不能用** `php_sapi_name()`
 *
 * 本仓库后端跑的是 Swoole（`php think swoole`），**worker 的 SAPI 恒为 `cli`**。
 * 原实现用 `php_sapi_name() === 'cli' || defined('STDIN')` 判定 CLI，
 * 于是「在任何真实 HTTP 请求里都是 CLI」→ `areScopesEnabled()` 恒为 false
 * → 租户作用域在生产路径上**从未生效过**（历史实测：worker 内 `php_sapi_name()="cli"`、
 * `defined('STDIN')=true`；声明了全局作用域后查 `roles` 仍返回全部 11 行，SQL 里没有 `tenant_id`）。
 *
 * 正确判据是 `App::runningInConsole()`：think-swoole 覆写了它
 * （`think\swoole\App::runningInConsole()` 返回可写的 `$inConsole` 属性），
 * 并在**每个请求的沙箱内**执行 `$app->setInConsole(false)`
 * （`think\swoole\concerns\InteractsWithHttp::onRequest()`）。
 * 因此：Swoole 请求内 `runningInConsole()=false`，真 CLI（`php think xxx`）内 `=true`。
 *
 * ### 2. `scopeTenant()` 是 **fail-closed** 的
 *
 * 启用作用域时：表**有** `tenant_id` 列 → 必须有有效租户上下文，否则**抛异常**；
 * 表**没有** `tenant_id` 列（全局表）→ 不加过滤、不抛异常（这是正确行为）。
 *
 * ### 3. 「作用域是否启用」的推断结果**不缓存**
 *
 * 原实现把推断结果写回 `$scopesEnabled` 当 memo。在 Swoole 常驻 worker 下这是致命的：
 * 只要 worker 启动期（CLI 语境）碰过一次 `areScopesEnabled()`，结论就被永久钉成 `false`，
 * 之后**即使 `setInConsole(false)` 生效也再不会重算** —— 这是缺陷 1 在 Swoole 下
 * 无法自愈的第二个原因。现在 `$scopesEnabled` **只**存放显式覆盖值（`setScopesEnabled()`），
 * 环境变量派生的开关单独缓存（进程内稳定），而「控制台与否」每次现算。
 *
 * @package app\model
 */
abstract class BaseModel extends Model
{
    /**
     * Auto timestamp | 自动时间戳
     * @var bool
     */
    protected $autoWriteTimestamp = true;

    /**
     * Create timestamp field | 创建时间字段
     * @var string
     */
    protected $createTime = 'created_at';

    /**
     * Update timestamp field | 更新时间字段
     * @var string
     */
    protected $updateTime = 'updated_at';

    /**
     * Column existence cache | 字段存在性缓存
     * Format: ['ModelClass:column_name' => bool]
     *
     * 只存放**确定**的结论（`true` / `false`）。「探测失败」一律不入缓存 —— 理由见 probeColumn()。
     *
     * @var array<string, bool>
     */
    protected static array $columnCache = [];

    /**
     * 显式覆盖的作用域开关 | Explicit scopes override
     *
     * **只**由 `setScopesEnabled()` 写入、`resetScopesEnabled()` 清空。
     * 「按运行环境推断」的结果绝不写回这里 —— 在 Swoole 常驻 worker 下，
     * 把推断结果 memo 到这里会让 worker 启动期的 CLI 结论永久生效（见类注释第 3 条）。
     *
     * @var bool|null
     */
    protected static ?bool $scopesEnabled = null;

    /**
     * 由环境变量派生的开关（进程内稳定，可安全缓存）| Env-derived scope flags
     *
     * 只包含 `DISABLE_TENANT_SCOPES` / `ENABLE_TENANT_SCOPES_IN_CLI` 两个逃生口，
     * 它们的取值在进程生命周期内不会变，因此缓存是安全的；由 `resetScopesEnabled()` 失效。
     *
     * @var array{disabled: bool, enabledInCli: bool}|null
     */
    protected static ?array $envScopeFlags = null;

    /**
     * Tenant scope | 租户作用域
     *
     * Automatically filters queries by tenant_id.
     * Called when 'tenant' is in the $globalScope array.
     * 按 tenant_id 自动过滤查询。
     * 当 'tenant' 在 $globalScope 数组中时调用。
     *
     * ## 决策表（fail-closed）
     *
     * | 情况                                             | 行为                     |
     * | ------------------------------------------------ | ------------------------ |
     * | 作用域禁用                                       | 不加过滤、不抛异常       |
     * | 有 `tenant_id` 列 + 有效租户（> 0）              | `where('tenant_id', $id)`|
     * | 有 `tenant_id` 列 + 上下文缺失（null / <= 0）    | **抛异常**（fail-closed）|
     * | **没有** `tenant_id` 列（全局表）                | 不加过滤、不抛异常       |
     *
     * 原实现是 fail-open 的：`if ($tenantId !== null && $tenantId > 0 && hasColumn(...))`，
     * 其他所有分支都**静默返回全表**。一旦上下文因任何原因缺失（中间件没跑、header 漏传、
     * 上游异常被吞），查询不会报错，而是安静地读出**所有租户**的数据 ——
     * 这类缺陷不会出现在监控里，只会出现在事故报告里。
     *
     * @param Query $query
     * @return Query
     * @throws TenantContextMissingException 表有 tenant_id 列但拿不到有效租户上下文时
     */
    public function scopeTenant(Query $query): Query
    {
        // Skip if scopes are disabled | 如果作用域禁用则跳过
        if (!static::areScopesEnabled()) {
            return $query;
        }

        // ── 第 1 步：先判「这张表有没有 tenant_id」──
        //
        // 顺序不能反：必须**早于**「取租户上下文」和「抛异常」。
        // 全局表（permissions / tenants / role_permissions / user_roles）天生没有租户维度，
        // 若先要求上下文再判列，它们会在「请求没带 X-Tenant-ID」时被误抛异常，
        // 直接打挂登录与鉴权 —— 那等于用「修 fail-open」换来一个更大的可用性缺陷。
        $hasTenantColumn = $this->probeColumn('tenant_id');

        if ($hasTenantColumn !== true) {
            // false：**确定**没有该列 → 全局表，不加过滤、不抛异常（正确行为）。
            // null ：**无法判定**（表结构查询失败：没连上库 / 表不存在 / 权限不足）。
            //        此时同样不加过滤也不抛：这次查询本身马上就会因为同样的原因失败，
            //        不存在「静默读到别人租户数据」的窗口。详见 probeColumn() 的说明。
            return $query;
        }

        // ── 第 2 步：表有 tenant_id ⇒ 必须有有效租户上下文（fail-closed）──
        $tenantId = static::getTenantContext();

        if ($tenantId === null || $tenantId <= 0) {
            throw TenantContextMissingException::forOrmScope(
                static::class,
                $this->describeQueryTable($query)
            );
        }

        $query->where('tenant_id', $tenantId);

        return $query;
    }

    /**
     * Site scope | 站点作用域
     *
     * Automatically filters queries by site_id.
     * Called when 'site' is in the $globalScope array.
     * 按 site_id 自动过滤查询。
     * 当 'site' 在 $globalScope 数组中时调用。
     *
     * @param Query $query
     * @return Query
     */
    public function scopeSite(Query $query): Query
    {
        // Skip if scopes are disabled | 如果作用域禁用则跳过
        if (!static::areScopesEnabled()) {
            return $query;
        }

        $siteId = static::getSiteContext();

        // Only apply if model has site_id column and siteId is valid
        // 仅当模型有 site_id 列且 siteId 有效时应用
        if ($siteId !== null && $siteId >= 0 && $this->hasColumnCached('site_id')) {
            $query->where('site_id', $siteId);
        }

        return $query;
    }

    /**
     * Check if global scopes are enabled | 检查全局作用域是否启用
     *
     * 判定顺序（越靠前优先级越高）：
     *   1. 显式覆盖 `setScopesEnabled()`；
     *   2. 逃生口 `DISABLE_TENANT_SCOPES=true` → 关闭；
     *   3. 运行环境：控制台下默认关闭，逃生口 `ENABLE_TENANT_SCOPES_IN_CLI=true` 可开启；
     *      非控制台（Swoole 请求 / FPM 请求）→ 打开。
     *
     * 注意：本方法**不缓存最终结论**。原因见类注释第 3 条 —— 在 Swoole 常驻 worker 里，
     * 结论依赖 `App::$inConsole`，而它是**每个请求**在沙箱内被重置的；
     * 把结论 memo 下来等于让第一个请求（或 worker 启动期）的判断永久生效。
     * 这里每次现算，成本是「一次静态属性读 + 一次方法调用」，可忽略。
     *
     * @return bool
     */
    public static function areScopesEnabled(): bool
    {
        // 1) 显式覆盖优先（测试 / 特殊场景）| Explicit override wins
        if (static::$scopesEnabled !== null) {
            return static::$scopesEnabled;
        }

        // 2) 环境变量逃生口（进程内稳定，可缓存）| Escape hatches from env
        $flags = static::resolveEnvScopeFlags();

        if ($flags['disabled']) {
            return false;
        }

        // 3) 运行环境判据 | Runtime judgement (Swoole-aware)
        if (static::runningInConsole()) {
            // Allow enabling in CLI via ENABLE_TENANT_SCOPES_IN_CLI=true
            // 允许通过 ENABLE_TENANT_SCOPES_IN_CLI=true 在 CLI 中启用
            return $flags['enabledInCli'];
        }

        return true;
    }

    /**
     * 是否运行在控制台环境 | Is the current execution a console one?
     *
     * **这是作用域开关的唯一环境判据**，替代原先的
     * `php_sapi_name() === 'cli' || defined('STDIN')`。
     *
     * 为什么必须换：后端跑 Swoole（`php think swoole`），worker 的 SAPI 恒为 `cli`，
     * `STDIN` 也一直存在 —— 用 SAPI 判定等于「永远认为自己在 CLI」，
     * 于是租户作用域在任何真实请求里都不会应用。
     *
     * 为什么 `runningInConsole()` 才对：`think\swoole\App` 覆写了它
     * （返回可写的 `$inConsole` 属性），并且在**每个请求的沙箱内**执行
     * `$app->setInConsole(false)`（`InteractsWithHttp::onRequest()`）；
     * 真 CLI（`php think migrate:run` 等）下它退回 `php_sapi_name() === 'cli'`，返回 true。
     * 沙箱还会把 `Container::setInstance(fn () => $sandboxApp)`，因此下面的
     * `Container::getInstance()` 在请求内拿到的正是那个 `think\swoole\App`。
     *
     * 安全回退：若容器不可用（未 boot 的纯脚本 / 单测直接 new 模型 / 容器被换掉），
     * 退回 SAPI 判定。回退语义是「仍是原实现的保守行为」——宁可在未知环境下关掉作用域
     * （与原行为一致），也不要在没有框架上下文时凭空打开过滤、把查询打成异常。
     *
     * 实现上刻意**不用** `app()` 助手：`Container::getInstance()` 在无实例时只会建一个
     * 空 `think\Container`，而 `app()` 会反射构造一个 `think\App` 并 `Container::setInstance()`
     * 抢占全局容器 —— 那是「探测一下运行环境」不该有的副作用。
     *
     * 声明为 `protected static` 是为了给测试留一个干净的接缝：
     * 子类覆写它即可在不换容器、不改 SAPI 的前提下验证「结论由 runningInConsole 驱动」。
     *
     * @return bool
     */
    protected static function runningInConsole(): bool
    {
        try {
            $container = Container::getInstance();

            if ($container instanceof \think\App) {
                return (bool) $container->runningInConsole();
            }
        } catch (Throwable) {
            // 容器不可用 → 走下面的 SAPI 回退
        }

        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg' || defined('STDIN');
    }

    /**
     * 解析并缓存「环境变量逃生口」| Resolve env escape hatches (cached)
     *
     * @return array{disabled: bool, enabledInCli: bool}
     */
    protected static function resolveEnvScopeFlags(): array
    {
        if (static::$envScopeFlags !== null) {
            return static::$envScopeFlags;
        }

        static::$envScopeFlags = [
            'disabled' => static::isTruthyEnvValue(static::getEnvValue('DISABLE_TENANT_SCOPES')),
            'enabledInCli' => static::isTruthyEnvValue(static::getEnvValue('ENABLE_TENANT_SCOPES_IN_CLI')),
        ];

        return static::$envScopeFlags;
    }

    /**
     * 判定环境变量是否表达「打开」| Is an env value truthy?
     *
     * 保留原实现接受的三种字面量（`true` / `'true'` / `'1'`），语义不变。
     *
     * @param mixed $value
     */
    protected static function isTruthyEnvValue(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === '1';
    }

    /**
     * Get environment variable value | 获取环境变量值
     *
     * Uses ThinkPHP env() if available, falls back to getenv().
     * 如果可用则使用 ThinkPHP env()，否则回退到 getenv()。
     *
     * @param string $name Environment variable name
     * @param mixed $default Default value if not set
     * @return mixed
     */
    protected static function getEnvValue(string $name, mixed $default = false): mixed
    {
        // Try ThinkPHP env() function first | 首先尝试 ThinkPHP env() 函数
        if (function_exists('env')) {
            return env($name, $default);
        }

        // Fallback to PHP getenv() | 回退到 PHP getenv()
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return $value;
    }

    /**
     * Get tenant context safely | 安全获取租户上下文
     *
     * Returns tenant ID from request, with proper error handling.
     * 从请求中返回租户 ID，带有适当的错误处理。
     *
     * ## 契约（刻意保持不变）
     *
     * 返回值 `null` 表示「本次执行**确实没有**租户上下文」，它只是一个**探针**，
     * 不做决策。决策在调用方 `scopeTenant()` 里，且那里已经是 fail-closed ——
     * 表有 `tenant_id` 列时拿不到上下文会抛 `TenantContextMissingException`。
     *
     * 为什么不在这里直接抛：
     *   全局作用域是**横切**的 —— 它跑在 ORM 层，可能出现在 CLI / 队列 / seeder / 迁移 /
     *   未走中间件的场景里。若这里用严格版 `Request::tenantId()`，「拿不到租户」会抛
     *   `TenantContextMissingException`，而 `getTenantContext()` 的调用方（含测试替身）
     *   全都按「可空」写 —— 等于用异常做控制流，并把「该不该有租户」的判断塞进一个
     *   名字叫「取上下文」的方法里。判断应由使用方（作用域）做，它才知道这张表是不是租户级数据。
     *
     * ## 错误处理（本次改动的重点）
     *
     * 原实现是 `catch (\Throwable) { return null; }` —— **静默**吞掉一切。
     * 那意味着「权限校验抛错」「连接池耗尽」「代码 bug 触发 TypeError」全都会
     * 变成「没有租户」这一个看起来完全正常的结果，再配合 fail-open 的作用域，
     * 就成了一条「出错了反而读到全表」的暗道。
     *
     * 现在的处理：
     *   - **保留** `catch (\Throwable)`（不改成「只捕获某一种、其余上抛」），理由见下；
     *   - 但捕获后**一定记录**（`Log::debug`，带异常类型 / 消息 / 模型类），不再静默；
     *   - 记录后再返回 `null`，契约不变。
     *
     * 为什么仍然捕获 `\Throwable` 而不是「只捕获无请求上下文、其余上抛」：
     *   1. 本方法在**每一次带全局作用域的查询**上执行。若把未知异常上抛，
     *      一个与租户无关的偶发错误（如日志/缓存抖动）会被渲染成 500，
     *      而正确的可观测面是「上下文缺失 → 由 `scopeTenant()` 给出精确的 fail-closed 异常」；
     *   2. 上抛会把「没有上下文」和「取上下文时崩了」混成同一个失败面，
     *      前端/值班看到的是随机的底层异常，而不是可操作的 1001；
     *   3. 这样做**不再有安全损失**：`scopeTenant()` 已经 fail-closed，
     *      「被吞掉的错误 → null → 抛 `TenantContextMissingException`」的最终结果是**拒绝查询**，
     *      而不是原实现那样「拒绝查询 → 退化成读全表」。信息损失只剩「原始异常没进响应」，
     *      而它会进日志（本方法）与日志的 debug 通道。
     *
     * 为什么用 `debug` 而不是 `error`：这是**正常存在**的分支（CLI / 队列 / seeder 全走它），
     * 用 error 级别刷屏只会训练值班忽略日志。真正需要告警的是
     * `scopeTenant()` 抛出的 `TenantContextMissingException`（`ExceptionHandle` 已按
     * REASON_REQUEST 记 warning/error）。
     *
     * @return int|null 解析出的租户ID；无上下文时返回 null
     */
    public static function getTenantContext(): ?int
    {
        try {
            // 框架 helper 未加载（纯单元测试 / 独立脚本）→ 视为「无上下文」，不算异常
            if (!function_exists('request')) {
                return null;
            }

            $request = request();

            if (method_exists($request, 'tenantIdOrNull')) {
                $tenantId = $request->tenantIdOrNull();

                return $tenantId === null ? null : (int) $tenantId;
            }

            if (method_exists($request, 'tenantId')) {
                return (int) $request->tenantId();
            }

            // Fallback to header only in web context | 仅在 web 上下文中使用 header 后备方案
            if (method_exists($request, 'header')) {
                $headerValue = $request->header('X-Tenant-ID');
                if ($headerValue !== null && $headerValue !== '') {
                    return (int) $headerValue;
                }
            }

            return null;
        } catch (Throwable $e) {
            // 不再静默：记录后按「无上下文」返回（契约不变），由 scopeTenant() fail-closed 兜底
            static::logContextProbeFailure('tenant', $e);

            return null;
        }
    }

    /**
     * Get site context safely | 安全获取站点上下文
     *
     * 语义与 `getTenantContext()` 对齐：`null` 表示「本次执行没有站点维度」，
     * `scopeSite()` 据此决定是否加 `site_id` 过滤（站点是**可选**维度，`null` 合法，
     * 与租户不同，这里不做 fail-closed）。
     *
     * 错误处理同样不再静默：捕获后记 `debug` 日志再返回 null。
     * 保留 `catch (\Throwable)` 的理由见 `getTenantContext()`。
     *
     * @return int|null
     */
    public static function getSiteContext(): ?int
    {
        try {
            $request = request();

            if (method_exists($request, 'siteId')) {
                return $request->siteId();
            }

            // Fallback to header only in web context | 仅在 web 上下文中使用 header 后备方案
            if (method_exists($request, 'header')) {
                $headerValue = $request->header('X-Site-ID');
                if ($headerValue !== null && $headerValue !== '') {
                    return (int) $headerValue;
                }
            }

            return null;
        } catch (Throwable $e) {
            // 不再静默：记录后按「无站点维度」返回（站点是可选维度，不需要 fail-closed）
            static::logContextProbeFailure('site', $e);

            return null;
        }
    }

    /**
     * Check if model has specific column (cached) | 检查模型是否有指定列（带缓存）
     *
     * 保留原有的 `bool` 公开契约：**无法判定**时按「没有该列」返回 `false`。
     * 需要区分「确定没有」与「无法判定」的调用方（例如 `scopeTenant()`）请直接用 `probeColumn()`。
     *
     * @param string $column Column name | 列名
     * @return bool
     */
    public function hasColumnCached(string $column): bool
    {
        return $this->probeColumn($column) ?? false;
    }

    /**
     * 探测模型对应的数据表是否有指定列（三态）| Probe column existence (tri-state)
     *
     * 返回：
     *   - `true`  ：确定有该列；
     *   - `false` ：确定**没有**该列；
     *   - `null`  ：**无法判定** —— 表结构查询失败（未连上库 / 表不存在 / 权限不足 / 驱动异常）。
     *
     * ## 为什么是「三态」而不是原来的「抛异常就当没有」
     *
     * 原实现是 `hasColumn()` 捕获 `\Exception` 后返回 `false`，而 `hasColumnCached()` 会把这个
     * `false` **写进缓存**。在 Swoole 常驻 worker 下，这等于：
     * 「一次瞬时抖动（比如建连超时）→ 该模型在整个进程生命周期内都判定为『没有 tenant_id』
     * → 静默跳过租户过滤（fail-open）」。
     * 一条瞬时错误被放大成永久的安全缺陷，这是这套缓存最危险的地方。
     *
     * 现在的处理：
     *   - **只缓存确定的结论**（`true` / `false`）；`null`（探测失败）**一律不入缓存**，
     *     下次查询会重新探测，抖动不会留下后遗症；
     *   - 捕获范围放宽到 `\Throwable`（原实现只捕 `\Exception`）：表结构探测可能抛出
     *     `\PDOException`、`think\db\exception\DbException`，也可能因为环境缺扩展 /
     *     配置空指针抛出 `\Error`。后者若不捕，会在**每一次**查询上炸掉 —— 而这条路径
     *     的定位是「守卫」，不该成为新的故障点；
     *   - 捕获时记 `debug` 日志（带列名 + 模型类 + 原始异常），不静默。
     *
     * ## 「无连接」场景的实际后果（重要，属于有意接受的设计）
     *
     * 纯单元测试 / 未配置数据库的运行环境里，这里必然返回 `null`，于是 `scopeTenant()`
     * 会走「不加过滤、不抛异常」分支。这**不是** fail-open 回潮：
     * 触发作用域的那条 SQL 本身马上就会因为完全相同的原因（没有连接）失败，
     * 不存在「静默读到别的租户数据」的时间窗口 —— 读不到数据，是因为根本读不到库。
     * 反过来，如果这里选择抛异常，会把「没有数据库」这件事伪装成「缺少租户上下文」，
     * 让排查方向从「连接配置」偏到「多租户中间件」，得不偿失。
     *
     * @param string $column Column name | 列名
     * @return bool|null
     */
    public function probeColumn(string $column): ?bool
    {
        $cacheKey = static::class . ':' . $column;

        // 注意用 array_key_exists 而不是 isset：值可能是 false（「确定没有该列」也是有效结论）
        if (array_key_exists($cacheKey, static::$columnCache)) {
            return static::$columnCache[$cacheKey];
        }

        try {
            $schema = $this->getTableFields();
        } catch (Throwable $e) {
            // 关键：**绝不把探测失败写进缓存**（见方法注释）
            static::logContextProbeFailure('column:' . $column, $e);

            return null;
        }

        $exists = in_array($column, $schema);

        static::$columnCache[$cacheKey] = $exists;

        return $exists;
    }

    /**
     * Check if model has specific column | 检查模型是否有指定列
     *
     * 与 `hasColumnCached()` 行为一致（同样带缓存、同样在无法判定时返回 `false`）。
     * 保留本方法是为了兼容既有调用点；新代码请直接用 `probeColumn()`。
     *
     * @param string $column Column name | 列名
     * @return bool
     */
    public function hasColumn(string $column): bool
    {
        return $this->probeColumn($column) ?? false;
    }

    /**
     * 记录「上下文 / 表结构探测失败」| Log a degraded probe
     *
     * 这些失败都是「正常可预期」的分支（CLI / 队列 / 未配置数据库），
     * 因此用 `debug` 级别；真正需要告警的是失败之后的 fail-closed 异常。
     *
     * 日志本身失败一律不再上抛：这条路径已经在「降级」里，不能因为写日志把查询打挂。
     *
     * @param string    $what 探测对象（`tenant` / `column:tenant_id` 等）
     * @param Throwable $e    原始异常
     */
    protected static function logContextProbeFailure(string $what, Throwable $e): void
    {
        try {
            Log::debug(sprintf(
                '[TenantScope] %s 探测失败，按「无法判定」降级处理：%s: %s (model=%s)',
                $what,
                get_class($e),
                $e->getMessage(),
                static::class
            ));
        } catch (Throwable) {
            // 日志通道不可用时忽略：不能让「记录降级」本身变成新的故障点
        }
    }

    /**
     * 取查询对象对应的表名（仅用于异常信息）| Best-effort table name for diagnostics
     *
     * 刻意用 `$query->getTable()` 而不是 `$this->getTable()`：后者会通过 `Model::__call`
     * 转发到 `$this->db()`，在异常路径上再触发一次建连，属于雪上加霜。
     *
     * @param Query $query
     * @return string 取不到时返回空串
     */
    protected function describeQueryTable(Query $query): string
    {
        try {
            $table = $query->getTable();

            return is_string($table) ? $table : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Clear column cache | 清除字段缓存
     *
     * Useful for testing or after schema changes.
     * 用于测试或架构变更后。
     *
     * @param string|null $modelClass Clear cache for specific model, or all if null
     * @return void
     */
    public static function clearColumnCache(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            static::$columnCache = [];
            return;
        }

        foreach (array_keys(static::$columnCache) as $key) {
            if (str_starts_with($key, $modelClass . ':')) {
                unset(static::$columnCache[$key]);
            }
        }
    }

    /**
     * Reset scopes enabled flag | 重置作用域启用标志
     *
     * Useful for testing to re-evaluate environment.
     * 用于测试以重新评估环境。
     *
     * 清空两部分：
     *   1. 显式覆盖值（`setScopesEnabled()` 写入的）；
     *   2. 环境变量派生的开关缓存（`DISABLE_TENANT_SCOPES` / `ENABLE_TENANT_SCOPES_IN_CLI`）。
     *
     * 注意：**不需要**重置「是否控制台」—— 那是每次 `areScopesEnabled()` 现算的（见类注释第 3 条）。
     *
     * @return void
     */
    public static function resetScopesEnabled(): void
    {
        static::$scopesEnabled = null;
        static::$envScopeFlags = null;
    }

    /**
     * Force enable/disable scopes | 强制启用/禁用作用域
     *
     * Useful for testing or special scenarios.
     * 用于测试或特殊场景。
     *
     * @param bool $enabled
     * @return void
     */
    public static function setScopesEnabled(bool $enabled): void
    {
        static::$scopesEnabled = $enabled;
    }

    /**
     * Set tenant ID | 设置租户ID
     *
     * @param int $tenantId
     * @return self
     */
    public function setTenantId(int $tenantId): self
    {
        $this->setAttr('tenant_id', $tenantId);
        return $this;
    }

    /**
     * Get tenant ID | 获取租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int
    {
        return $this->getAttr('tenant_id');
    }

    /**
     * Set site ID | 设置站点ID
     *
     * @param int $siteId
     * @return self
     */
    public function setSiteId(int $siteId): self
    {
        $this->setAttr('site_id', $siteId);
        return $this;
    }

    /**
     * Get site ID | 获取站点ID
     *
     * @return int|null
     */
    public function getSiteId(): ?int
    {
        return $this->getAttr('site_id');
    }

    /**
     * Disable global scopes | 禁用全局作用域
     *
     * Use this when you need to query across all tenants/sites.
     * 当需要跨租户/站点查询时使用此方法。
     *
     * Example: Model::withoutGlobalScope(['tenant', 'site'])->select();
     *
     * @param array $scopes Scope names to disable | 要禁用的作用域名称
     * @return \think\db\Query
     */
    public static function withoutTenantScope(array $scopes = ['tenant', 'site'])
    {
        return static::withoutGlobalScope($scopes);
    }
}
