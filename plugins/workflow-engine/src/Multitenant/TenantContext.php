<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use Swoole\Coroutine;
use RuntimeException;

/**
 * Tenant Context | 租户上下文
 *
 * Manages tenant context in a coroutine-safe manner. Stores tenant information
 * per coroutine to ensure proper isolation in concurrent environments.
 * 以协程安全的方式管理租户上下文。按协程存储租户信息以确保并发环境中的正确隔离。
 *
 * ## 核心不变量：协程内的上下文只来自本协程，不继承进程级默认值
 *
 * 本类有两类槽位：
 * - 协程槽位 `self::$contexts[$cid]`（`$cid > 0`）：该协程**自己**写入的上下文；
 * - 进程级槽位 `self::$defaultTenantId` / `self::$contexts[0]`（`$cid <= 0`）：
 *   只服务于非协程代码（`php think` 命令、队列 worker 的非协程段、定时任务）。
 *
 * 不变量有两条，缺一不可：
 *
 * 1. **读侧不得跨槽位回落**。`getTenantId()` / `getUserId()` / `get()` 在协程内
 *    只查本协程槽位，查不到就是 `null`；**绝不**回落到 `self::$defaultTenantId`
 *    或 `self::$contexts[0]`。
 *    为什么这是硬要求：`ResolvesWorkflowTenant::tenantId()` 靠
 *    `getTenantId() === null` 判定「没有租户上下文」并抛 `TenantIsolationException`。
 *    一旦协程内会回落到进程级默认值，只要有任意一段非协程代码（CLI 命令 / 队列 worker）
 *    调过一次 `setTenantId()`，本进程**所有没自己设租户的协程**都会继承那个租户，
 *    fail-closed 静默失效，直接变成跨租户读写 —— 而且日志里看不出任何异常。
 *    这个回落从无用处：非协程设、非协程读本来就读 `$defaultTenantId`，够用了；
 *    它唯一的「效果」就是把别人的租户塞给没设租户的协程。
 *
 * 2. **`null` 不等于「没设过」**。协程内 `initialize($tenantId, null)` 写入的
 *    `user_id = null` 表示「本协程没有登录用户」；读侧必须用 `array_key_exists()`
 *    而不是 `isset()` 判断键是否存在，否则显式的 `null` 会被当成「没设过」，
 *    再去借一个身份回来（未登录请求继承上一个人的用户身份）。
 *
 * 结论：协程内的上下文**只能**由本协程的 `initialize()` / `setTenantId()` /
 * `setUserId()` / `set()` 写入，也只能被本协程的 `clear()` 清掉。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class TenantContext
{
    /**
     * Coroutine-local tenant storage
     * 协程本地租户存储
     *
     * 键是协程 ID；键 `0` 是「非协程」的进程级槽位（由 `$cid <= 0` 写入）。
     *
     * @var array<int, array>
     */
    private static array $contexts = [];

    /**
     * Default tenant ID for non-coroutine contexts
     * 非协程上下文的默认租户ID
     *
     * ⚠️ 只服务于 `$cid <= 0` 的读写；协程内**不得**读它（见类注释的不变量 1）。
     *
     * @var int|null
     */
    private static ?int $defaultTenantId = null;

    /**
     * Global tenant configuration
     * 全局租户配置
     *
     * @var array<int, array>
     */
    private static array $tenantConfigs = [];

    /**
     * Whether tenant isolation is enforced
     * 是否强制租户隔离
     *
     * @var bool
     */
    private static bool $isolationEnforced = true;

    /**
     * Set current tenant ID | 设置当前租户ID
     *
     * 只写「本执行单元」的槽位：协程内写本协程槽位，非协程写进程级默认值。
     *
     * @param int $tenantId Tenant ID
     * @return void
     */
    public static function setTenantId(int $tenantId): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            if (!isset(self::$contexts[$cid])) {
                self::$contexts[$cid] = [];
            }
            self::$contexts[$cid]['tenant_id'] = $tenantId;
        } else {
            self::$defaultTenantId = $tenantId;
        }
    }

    /**
     * Get current tenant ID | 获取当前租户ID
     *
     * @param bool $required Whether tenant ID is required
     * @return int|null
     * @throws RuntimeException If required but not set
     */
    public static function getTenantId(bool $required = false): ?int
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            // 协程内：只认本协程槽位。没设就是 null —— 绝不回落到 $defaultTenantId，
            // 否则「本协程没有租户」会被静默解释成「按别人设过的租户读写」。
            $slot = self::$contexts[$cid] ?? [];
            $tenantId = array_key_exists('tenant_id', $slot) ? $slot['tenant_id'] : null;
        } else {
            // 非协程：进程级默认值（与 setTenantId()/initialize() 的写入口径一一对应）
            $tenantId = self::$defaultTenantId;
        }

        if ($required && $tenantId === null) {
            throw new RuntimeException('Tenant ID is required but not set in context');
        }

        return $tenantId;
    }

    /**
     * Check if tenant ID is set | 检查租户ID是否已设置
     *
     * @return bool
     */
    public static function hasTenantId(): bool
    {
        return self::getTenantId() !== null;
    }

    /**
     * Set current user ID | 设置当前用户ID
     *
     * @param int $userId User ID
     * @return void
     */
    public static function setUserId(int $userId): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            if (!isset(self::$contexts[$cid])) {
                self::$contexts[$cid] = [];
            }
            self::$contexts[$cid]['user_id'] = $userId;
        } else {
            if (!isset(self::$contexts[0])) {
                self::$contexts[0] = [];
            }
            self::$contexts[0]['user_id'] = $userId;
        }
    }

    /**
     * Get current user ID | 获取当前用户ID
     *
     * @return int|null
     */
    public static function getUserId(): ?int
    {
        $slot = self::ownSlot();

        // array_key_exists 而不是 isset：显式写入的 null（未登录）算「本协程已表态」，
        // 它的语义是「本协程没有登录用户」，而不是「本协程没说过，去别处借一个身份」。
        return array_key_exists('user_id', $slot) ? $slot['user_id'] : null;
    }

    /**
     * Set context value | 设置上下文值
     *
     * @param string $key Key
     * @param mixed $value Value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $cid = self::getCoroutineId();
        $contextId = $cid > 0 ? $cid : 0;

        if (!isset(self::$contexts[$contextId])) {
            self::$contexts[$contextId] = [];
        }
        self::$contexts[$contextId][$key] = $value;
    }

    /**
     * Get context value | 获取上下文值
     *
     * @param string $key Key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $slot = self::ownSlot();

        // 与 getUserId() 同口径：协程内只查本协程槽位，且「键存在但值为 null」
        // 与「键不存在」是两件事 —— 前者返回 null，后者才返回 $default。
        return array_key_exists($key, $slot) ? $slot[$key] : $default;
    }

    /**
     * Initialize context for current coroutine | 初始化当前协程的上下文
     *
     * 语义是**整槽替换**，不是逐键合并：`$userId === null` 必须把旧值覆盖成 null，
     * 而不是「这个键没提供」。逐键 `isset()` 判空会让 `initialize($t, null)`
     * 变成空操作，于是未登录请求继承上一个请求的用户身份。
     *
     * @param int $tenantId Tenant ID
     * @param int|null $userId User ID (optional)
     * @param array $extra Additional context data
     * @return void
     */
    public static function initialize(int $tenantId, ?int $userId = null, array $extra = []): void
    {
        $cid = self::getCoroutineId();
        $contextId = $cid > 0 ? $cid : 0;

        // 整槽替换：null 会被原样写进数组（array_merge 不会丢 null），
        // 因此「未登录」是一个明确的取值，而不是「没写」。
        self::$contexts[$contextId] = array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'initialized_at' => microtime(true),
        ], $extra);

        if ($cid <= 0) {
            self::$defaultTenantId = $tenantId;
        }
    }

    /**
     * Clear context for current coroutine | 清除当前协程的上下文
     *
     * 只清本执行单元的槽位：协程内清本协程，非协程清进程级槽位与默认值。
     *
     * @return void
     */
    public static function clear(): void
    {
        $cid = self::getCoroutineId();

        if ($cid > 0) {
            unset(self::$contexts[$cid]);
        } else {
            self::$defaultTenantId = null;
            unset(self::$contexts[0]);
        }
    }

    /**
     * Clear all contexts (for testing/cleanup) | 清除所有上下文
     *
     * @return void
     */
    public static function clearAll(): void
    {
        self::$contexts = [];
        self::$defaultTenantId = null;
    }

    /**
     * Run callback within tenant context | 在租户上下文中运行回调
     *
     * 进入时写入、退出时**精确还原**到调用前的状态：
     * 原来有值就写回原值，原来没有（或为 null）就删掉该键 ——
     * 不允许把本次作用域的租户/用户留在外面，也不允许写回进程级默认值。
     *
     * @param int $tenantId Tenant ID
     * @param callable $callback Callback to run
     * @param int|null $userId Optional user ID
     * @return mixed Callback result
     */
    public static function runWithTenant(int $tenantId, callable $callback, ?int $userId = null): mixed
    {
        // 快照「本执行单元」自己的上下文：必须区分「原本就有这个键」与「原本没有」，
        // 否则无法判断退出时该写回还是该删除（null 的语义见类注释不变量 2）。
        // 用 readOwnKey() 而不是 ownSlot()：非协程的租户事实在 $defaultTenantId 上。
        [$hadTenantId, $previousTenantId] = self::readOwnKey('tenant_id');
        [$hadUserId, $previousUserId] = self::readOwnKey('user_id');

        try {
            self::setTenantId($tenantId);
            if ($userId !== null) {
                self::setUserId($userId);
            }

            return $callback();
        } finally {
            self::restoreOwnKey('tenant_id', $hadTenantId, $previousTenantId);

            if ($userId !== null) {
                self::restoreOwnKey('user_id', $hadUserId, $previousUserId);
            }
        }
    }

    /**
     * Get all context data for current coroutine | 获取当前协程的所有上下文数据
     *
     * @return array
     */
    public static function all(): array
    {
        return self::ownSlot();
    }

    /**
     * Set tenant configuration | 设置租户配置
     *
     * @param int $tenantId Tenant ID
     * @param array $config Configuration
     * @return void
     */
    public static function setTenantConfig(int $tenantId, array $config): void
    {
        self::$tenantConfigs[$tenantId] = $config;
    }

    /**
     * Get tenant configuration | 获取租户配置
     *
     * @param int|null $tenantId Tenant ID (null for current)
     * @return array
     */
    public static function getTenantConfig(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? self::getTenantId();

        if ($tenantId === null) {
            return [];
        }

        return self::$tenantConfigs[$tenantId] ?? [];
    }

    /**
     * Get tenant config value | 获取租户配置值
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @param int|null $tenantId Tenant ID (null for current)
     * @return mixed
     */
    public static function getTenantConfigValue(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $config = self::getTenantConfig($tenantId);

        return $config[$key] ?? $default;
    }

    /**
     * Set whether tenant isolation is enforced | 设置是否强制租户隔离
     *
     * @param bool $enforced
     * @return void
     */
    public static function setIsolationEnforced(bool $enforced): void
    {
        self::$isolationEnforced = $enforced;
    }

    /**
     * Check if tenant isolation is enforced | 检查是否强制租户隔离
     *
     * @return bool
     */
    public static function isIsolationEnforced(): bool
    {
        return self::$isolationEnforced;
    }

    /**
     * Clone context to a child coroutine | 复制上下文到子协程
     *
     * 只复制「父协程自己的上下文」：父协程没有上下文就什么也不做，
     * 不回落到进程级默认槽位 —— 否则子协程会凭空继承一个它没声明过的租户。
     *
     * @param int $childCid Child coroutine ID
     * @return void
     */
    public static function cloneToChild(int $childCid): void
    {
        if ($childCid <= 0) {
            // 槽位 0 / 负数代表进程级（非协程）作用域：把某个协程的上下文写进去，
            // 等于把它变成全进程的默认值，与本类的不变量相反，直接拒绝。
            return;
        }

        $parentCid = self::getCoroutineId();
        $parentContext = $parentCid > 0
            ? (self::$contexts[$parentCid] ?? [])
            : (self::$contexts[0] ?? []);

        if (!empty($parentContext)) {
            self::$contexts[$childCid] = $parentContext;
        }
    }

    /**
     * Get debug info | 获取调试信息
     *
     * @return array
     */
    public static function getDebugInfo(): array
    {
        $cid = self::getCoroutineId();

        return [
            'coroutine_id' => $cid,
            'tenant_id' => self::getTenantId(),
            'user_id' => self::getUserId(),
            'context_count' => count(self::$contexts),
            'isolation_enforced' => self::$isolationEnforced,
            'current_context' => self::all(),
        ];
    }

    /**
     * 取「本执行单元」自己的上下文槽位（拷贝，不是引用）
     *
     * 协程内（`$cid > 0`）= 本协程的槽位；协程外（`$cid <= 0`）= 进程级槽位 `0`。
     * 协程内**不会**回落到槽位 0 —— 见类注释的不变量 1。
     *
     * @return array
     */
    private static function ownSlot(): array
    {
        $cid = self::getCoroutineId();

        return $cid > 0 ? (self::$contexts[$cid] ?? []) : (self::$contexts[0] ?? []);
    }

    /**
     * 读「本执行单元」槽位里的某个键（连同「是否存在」一起返回）
     *
     * 非协程的租户事实存放在 `$defaultTenantId`（`setTenantId()` 只写它），
     * 因此 `tenant_id` 这一项必须从那个属性读，否则 `runWithTenant()` 的还原
     * 判断会拿 `$contexts[0]` 当唯一真相，把原有租户误判成「原本没有」。
     *
     * @param string $key 键名
     * @return array{0: bool, 1: mixed} [是否存在（null 也算存在）, 值]
     */
    private static function readOwnKey(string $key): array
    {
        $cid = self::getCoroutineId();

        if ($cid <= 0 && $key === 'tenant_id') {
            return [self::$defaultTenantId !== null, self::$defaultTenantId];
        }

        $slot = self::ownSlot();

        return [array_key_exists($key, $slot), $slot[$key] ?? null];
    }

    /**
     * 把「本执行单元」槽位里的某个键还原到调用前的状态
     *
     * @param string $key      键名
     * @param bool   $hadValue 调用前该键是否存在（`null` 也算存在）
     * @param mixed  $value    调用前该键的值
     * @return void
     */
    private static function restoreOwnKey(string $key, bool $hadValue, mixed $value): void
    {
        if ($hadValue && $value !== null) {
            if ($key === 'tenant_id') {
                self::setTenantId((int) $value);
            } else {
                self::setUserId((int) $value);
            }

            return;
        }

        self::forgetOwnKey($key);
    }

    /**
     * 删除「本执行单元」槽位里的某个键
     *
     * @param string $key 键名
     * @return void
     */
    private static function forgetOwnKey(string $key): void
    {
        $cid = self::getCoroutineId();
        $contextId = $cid > 0 ? $cid : 0;

        unset(self::$contexts[$contextId][$key]);

        // 不留空数组：它与「没有槽位」在 get()/all() 上等价，但会让
        // getDebugInfo()['context_count'] 虚高，也不利于判断上下文是否真的清空了。
        if (isset(self::$contexts[$contextId]) && self::$contexts[$contextId] === []) {
            unset(self::$contexts[$contextId]);
        }

        if ($contextId === 0 && $key === 'tenant_id') {
            // 非协程：$defaultTenantId 是同一份租户事实的另一份副本，必须一起清，
            // 否则 getTenantId()（非协程读的就是它）会读到刚被删掉的值。
            self::$defaultTenantId = null;
        }
    }

    /**
     * Get current coroutine ID | 获取当前协程ID
     *
     * @return int Returns -1 if not in coroutine context
     */
    private static function getCoroutineId(): int
    {
        if (!class_exists(Coroutine::class)) {
            return -1;
        }

        return Coroutine::getCid();
    }
}
