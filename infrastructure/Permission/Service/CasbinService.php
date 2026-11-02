<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Service;

use Casbin\Enforcer;
use Infrastructure\Permission\Cache\PermissionCachePurger;
use Infrastructure\Permission\Casbin\DatabaseAdapter;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;

/**
 * Casbin 授权服务
 * Casbin Authorization Service
 *
 * 封装 Casbin Enforcer，提供权限检查和策略管理功能。
 * Encapsulates Casbin Enforcer to provide permission checking and policy management.
 *
 * 功能 | Features:
 * - 权限检查：检查用户是否有指定权限
 * - 用户权限获取：获取用户的所有权限
 * - 策略刷新：重新加载策略
 * - 多租户支持：基于 Casbin Domains
 * - 缓存支持：提高性能
 *
 * - Permission checking: Check if user has specified permission
 * - User permissions retrieval: Get all permissions of a user
 * - Policy reload: Reload policies from database
 * - Multi-tenancy support: Based on Casbin Domains
 * - Cache support: Improve performance
 *
 * @see https://casbin.org/
 * @see https://github.com/php-casbin/php-casbin
 */
class CasbinService
{
    /**
     * Casbin Enforcer 实例
     * Casbin Enforcer instance
     */
    protected Enforcer $enforcer;

    /**
     * 缓存命中次数
     * Cache hit count
     */
    protected int $cacheHits = 0;

    /**
     * 缓存未命中次数
     * Cache miss count
     */
    protected int $cacheMisses = 0;

    /**
     * 缓存降级次数
     * Cache degradation count
     */
    protected int $cacheDegradationCount = 0;

    /**
     * 最后一次缓存降级时间
     * Last cache degradation time
     */
    protected ?int $lastCacheDegradationTime = null;

    /**
     * 最后一次缓存降级原因
     * Last cache degradation reason
     */
    protected ?string $lastCacheDegradationReason = null;

    /**
     * 权限缓存前缀清除器（懒加载）
     * Permission cache prefix purger (lazy)
     */
    protected ?PermissionCachePurger $purger = null;

    /**
     * 构造函数
     * Constructor
     *
     * 初始化 Casbin Enforcer：
     * 1. 加载 Casbin 模型
     * 2. 创建 DatabaseAdapter
     * 3. 初始化 Enforcer
     * 4. 启用缓存（如果配置启用）
     *
     * Initialize Casbin Enforcer:
     * 1. Load Casbin model
     * 2. Create DatabaseAdapter
     * 3. Initialize Enforcer
     * 4. Enable cache (if configured)
     */
    public function __construct()
    {
        // 获取模型文件路径
        // Get model file path
        $modelPath = Config::get('casbin.model_path') ?: __DIR__ . '/../../../config/casbin-model.conf';

        // 创建 DatabaseAdapter
        // Create DatabaseAdapter
        $adapter = new DatabaseAdapter();

        // 初始化 Enforcer
        // Initialize Enforcer
        $this->enforcer = new Enforcer($modelPath, $adapter);

        // 注意：Casbin PHP 版本没有内置缓存功能
        // 缓存通过应用层的 shouldReload() 机制实现
        // Note: Casbin PHP version does not have built-in cache
        // Caching is implemented through application-level shouldReload() mechanism
    }

    /**
     * 检查用户权限
     * Check user permission
     *
     * 检查用户在指定租户中是否有指定资源的指定操作权限。
     * Check if user has specified action permission on specified resource in specified tenant.
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @param string $resource 资源 | Resource
     * @param string $action 操作 | Action
     * @return bool 是否有权限 | Whether has permission
     */
    public function check(int $userId, int $tenantId, string $resource, string $action): bool
    {
        // 记录开始时间
        // Record start time
        $startTime = microtime(true);

        // 获取 trace_id
        // Get trace_id
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            // 检查缓存是否启用
            // Check if cache is enabled
            $cacheEnabled = Config::get('casbin.cache_enabled', true);

            if ($cacheEnabled) {
                try {
                    // 生成缓存键
                    // Generate cache key
                    $cacheKey = $this->getCacheKey($userId, $tenantId, $resource, $action);

                    // 尝试从缓存获取结果
                    // Try to get result from cache
                    $cachedResult = Cache::get($cacheKey);

                    if ($cachedResult !== null) {
                        // 缓存命中
                        // Cache hit
                        $this->cacheHits++;

                        // 计算执行时间
                        // Calculate execution time
                        $executionTime = (microtime(true) - $startTime) * 1000; // ms

                        // 记录缓存命中
                        // Log cache hit
                        Log::info('Casbin permission check: cache hit', [
                            'trace_id' => $traceId,
                            'user_id' => $userId,
                            'tenant_id' => $tenantId,
                            'resource' => $resource,
                            'action' => $action,
                            'result' => $cachedResult ? 'granted' : 'denied',
                            'cache' => 'hit',
                            'execution_time_ms' => round($executionTime, 2),
                        ]);

                        return (bool) $cachedResult;
                    }

                    // 缓存未命中
                    // Cache miss
                    $this->cacheMisses++;
                } catch (\Throwable $e) {
                    // 【为什么捕 \Throwable】（R4）缓存读失败 → 降级直接调 Enforcer，
                    // 属纯旁路：缓存坏了不该让「这次鉴权能不能做」跟着坏。
                    // \TypeError 等 \Error 不继承 \Exception，只捕 \Exception 会让缓存侧类型错误
                    // 穿透成 HTTP 500（R3 的实测放大路径）。
                    // Redis 故障，记录降级事件
                    // Redis failure, record degradation event
                    $this->recordCacheDegradation($e);

                    // 检查是否启用降级日志
                    // Check if degradation logging is enabled
                    $degradationLogEnabled = Config::get('casbin.cache_degradation_log_enabled', true);

                    if ($degradationLogEnabled) {
                        Log::warning('Casbin cache degradation: Redis failure', [
                            'trace_id' => $traceId,
                            'user_id' => $userId,
                            'tenant_id' => $tenantId,
                            'resource' => $resource,
                            'action' => $action,
                            'error' => $e->getMessage(),
                            'degradation_count' => $this->cacheDegradationCount,
                        ]);
                    }

                    // 降级到直接调用 Casbin 引擎（继续执行后续逻辑）
                    // Degrade to direct Casbin engine call (continue with subsequent logic)
                }
            }

            // 检查是否需要重新加载策略
            // Check if need to reload policy
            if ($this->shouldReload()) {
                $this->reloadPolicy();
            }

            // 调用 Enforcer::enforce() 检查权限
            // Call Enforcer::enforce() to check permission
            $result = $this->enforcer->enforce(
                "user:{$userId}",      // sub: user:{userId}
                "tenant:{$tenantId}",  // dom: tenant:{tenantId}
                $resource,             // obj: resource
                $action                // act: action
            );

            // 如果缓存启用，将结果写入缓存
            // If cache is enabled, write result to cache
            if ($cacheEnabled) {
                try {
                    $cacheTtl = Config::get('casbin.cache_ttl', 300);
                    Cache::set($cacheKey, $result, $cacheTtl);
                    // 登记键：不可枚举驱动（File 等）清理时依赖该索引
                    $this->purger()->trackKey($cacheKey);
                } catch (\Throwable $e) {
                    // 【为什么捕 \Throwable】（R4，本次缺陷的直接放大器）
                    //   本 try 块里除了 `Cache::set()` 还有 `$this->purger()->trackKey($cacheKey)`。
                    //   `trackKey` 一旦抛 \TypeError（参数类型/驱动返回值不符），在 PHP 里
                    //   \TypeError **不继承** \Exception，旧写法 `catch (\Exception)` 抓不到，
                    //   于是「缓存写失败本应只记 warning」被升级成 HTTP 500 —— 这正是 R3 实测到的
                    //   现象。缓存写入是纯旁路：结果已经由 Enforcer 算出来了，写不进缓存
                    //   最坏只是下一次仍走 Enforcer，绝不能影响本次返回值。
                    // 缓存写入失败，记录降级事件但不影响结果返回
                    // Cache write failed, record degradation but don't affect result
                    $this->recordCacheDegradation($e);

                    $degradationLogEnabled = Config::get('casbin.cache_degradation_log_enabled', true);
                    if ($degradationLogEnabled) {
                        Log::warning('Casbin cache degradation: Failed to write cache', [
                            'trace_id' => $traceId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录权限检查结果
            // Log permission check result
            if ($result) {
                Log::info('Casbin permission check: granted', [
                    'trace_id' => $traceId,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'resource' => $resource,
                    'action' => $action,
                    'result' => 'granted',
                    'cache' => $cacheEnabled ? 'miss' : 'disabled',
                    'execution_time_ms' => round($executionTime, 2),
                ]);
            } else {
                Log::warning('Casbin permission check: denied', [
                    'trace_id' => $traceId,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'resource' => $resource,
                    'action' => $action,
                    'result' => 'denied',
                    'cache' => $cacheEnabled ? 'miss' : 'disabled',
                    'execution_time_ms' => round($executionTime, 2),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            // 【为什么捕 \Throwable】（R4）本 catch **不吞异常**：记完日志就 `throw $e` 原样上抛，
            // 对调用方可见的结果完全不变（\Error 本来就穿透旧写法）。
            // 改成 \Throwable 只是让 \TypeError / \Error 也留下带 trace_id 的结构化日志，
            // 否则这类「代码写错了」的错误在日志里完全没有痕迹，只剩框架的 500。
            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录错误
            // Log error
            Log::error('Casbin permission check failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'resource' => $resource,
                'action' => $action,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time_ms' => round($executionTime, 2),
            ]);

            // 抛出异常
            // Throw exception
            throw $e;
        }
    }

    /**
     * 获取用户权限
     * Get user permissions
     *
     * 获取用户在指定租户中的所有权限，返回 resource:action 格式的权限码数组。
     * Get all permissions of user in specified tenant, return permission codes in resource:action format.
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @return array 权限码数组 | Permission codes array
     */
    public function getUserPermissions(int $userId, int $tenantId): array
    {
        // 记录开始时间
        // Record start time
        $startTime = microtime(true);

        // 获取 trace_id
        // Get trace_id
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            // 检查是否需要重新加载策略
            // Check if need to reload policy
            if ($this->shouldReload()) {
                $this->reloadPolicy();
            }

            // 获取用户的所有隐式权限（包括通过角色继承的权限）
            // Get all implicit permissions of user (including permissions inherited through roles)
            $permissions = $this->enforcer->getImplicitPermissionsForUser(
                "user:{$userId}",
                "tenant:{$tenantId}"
            );

            // 格式化权限为 resource:action 格式
            // Format permissions to resource:action format
            $formattedPermissions = $this->formatPermissions($permissions);

            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录权限获取结果
            // Log permissions retrieval result
            Log::info('Casbin get user permissions', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'permissions_count' => count($formattedPermissions),
                'execution_time_ms' => round($executionTime, 2),
            ]);

            return $formattedPermissions;
        } catch (\Throwable $e) {
            // 【为什么捕 \Throwable】（R4）同 check()：本 catch 只做「记日志 + 原样上抛」，
            // 不改变任何对外可见结果；扩到 \Throwable 是为了让 \Error 也进结构化日志。
            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录错误
            // Log error
            Log::error('Casbin get user permissions failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time_ms' => round($executionTime, 2),
            ]);

            // 抛出异常
            // Throw exception
            throw $e;
        }
    }

    /**
     * 重新加载策略
     * Reload policy
     *
     * 从数据库重新加载策略到 Enforcer。
     * Reload policies from database to Enforcer.
     *
     * @return void
     */
    public function reloadPolicy(): void
    {
        // 记录开始时间
        // Record start time
        $startTime = microtime(true);

        // 获取 trace_id
        // Get trace_id
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            // 重新加载策略
            // Reload policy
            //
            // 【try 里只放核心调用】（#2 同类收窄）
            //   收窄前这个 try 同时包住「核心 loadPolicy()」与「旁路 Cache::set()」，
            //   于是缓存写失败（Redis 抖动 / \TypeError）会被记成「Casbin policy reload failed」
            //   并**原样上抛** —— 而此刻策略其实已经装好了。方向与 #2 相反，根因相同：
            //   核心与旁路共用了一个 try，谁失败都会冒充另一个。
            //   现在核心失败 → 记日志 + 原样上抛（不变）；旁路失败 → 只记 warning（不留假故障）。
            $this->enforcer->loadPolicy();
        } catch (\Throwable $e) {
            // 【为什么捕 \Throwable】（R4）本 catch 只做「记日志 + 原样上抛」，不改变结果。
            // 注意：`loadPolicy()` 失败必须上抛 —— 策略没装好就继续鉴权会得到「全是拒绝」的
            // 静默错答。这里扩到 \Throwable 只为补齐 \Error 的日志，不是为了让失败变得可忽略。
            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录错误
            // Log error
            Log::error('Casbin policy reload failed', [
                'trace_id' => $traceId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time_ms' => round($executionTime, 2),
            ]);

            // 抛出异常
            // Throw exception
            throw $e;
        }

        try {
            // 更新缓存时间戳
            // Update cache timestamp
            //
            // 旁路：写不进时间戳最坏是「下一次检查再 reload 一遍」（多一次 loadPolicy），
            // 绝不能让「策略已成功重载」变成异常 —— 那会把一次缓存抖动升级成 HTTP 500，
            // 且此时 loadPolicy() 早已完成，上抛的异常与真实结果完全不符。
            Cache::set('casbin_last_reload', time());
        } catch (\Throwable $e) {
            Log::warning('Casbin reload timestamp write failed (policy already reloaded)', [
                'trace_id' => $traceId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
        }

        // 清除权限检查缓存（clearCache() 内部已自带 try/catch，失败只记 warning）
        // Clear permission check cache (clearCache() swallows its own failures)
        $this->clearCache();

        // 计算执行时间
        // Calculate execution time
        $executionTime = (microtime(true) - $startTime) * 1000; // ms

        // 记录策略刷新结果
        // Log policy reload result
        Log::info('Casbin policy reloaded', [
            'trace_id' => $traceId,
            'execution_time_ms' => round($executionTime, 2),
            'timestamp' => time(),
        ]);
    }

    /**
     * 检查用户是否有指定权限码
     * Check if user has specified permission code
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @param string $permissionCode 权限码（resource:action 格式）| Permission code (resource:action format)
     * @return bool 是否有权限 | Whether has permission
     */
    public function hasPermission(int $userId, int $tenantId, string $permissionCode): bool
    {
        // 解析权限码：resource:action
        // Parse permission code: resource:action
        $parts = explode(':', $permissionCode);
        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;

        // 调用 check() 方法
        // Call check() method
        return $this->check($userId, $tenantId, $resource, $action);
    }

    /**
     * 检查用户是否有任一权限
     * Check if user has any of the specified permissions
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @param array $permissionCodes 权限码数组 | Permission codes array
     * @return bool 是否有任一权限 | Whether has any permission
     */
    public function hasAnyPermission(int $userId, int $tenantId, array $permissionCodes): bool
    {
        foreach ($permissionCodes as $permissionCode) {
            if ($this->hasPermission($userId, $tenantId, $permissionCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查用户是否有所有权限
     * Check if user has all of the specified permissions
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @param array $permissionCodes 权限码数组 | Permission codes array
     * @return bool 是否有所有权限 | Whether has all permissions
     */
    public function hasAllPermissions(int $userId, int $tenantId, array $permissionCodes): bool
    {
        foreach ($permissionCodes as $permissionCode) {
            if (!$this->hasPermission($userId, $tenantId, $permissionCode)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查是否需要重新加载策略
     * Check if need to reload policy
     *
     * 根据配置的刷新间隔时间判断是否需要重新加载策略。
     * Determine if need to reload policy based on configured reload interval.
     *
     * @return bool 是否需要重新加载 | Whether need to reload
     */
    protected function shouldReload(): bool
    {
        // 获取上次加载时间
        // Get last reload time
        $lastReload = Cache::get('casbin_last_reload', 0);

        // 获取刷新间隔时间（秒）
        // Get reload interval (seconds)
        $reloadTtl = Config::get('casbin.reload_ttl', 300);

        // 如果设置为 0，表示不自动刷新
        // If set to 0, means no auto reload
        if ($reloadTtl === 0) {
            return false;
        }

        // 判断是否超过刷新间隔
        // Check if exceeded reload interval
        return (time() - $lastReload) > $reloadTtl;
    }

    /**
     * 生成缓存键
     * Generate cache key
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @param string $resource 资源 | Resource
     * @param string $action 操作 | Action
     * @return string 缓存键 | Cache key
     */
    protected function getCacheKey(int $userId, int $tenantId, string $resource, string $action): string
    {
        return "casbin:check:{$userId}:{$tenantId}:{$resource}:{$action}";
    }

    /**
     * 清除所有权限检查缓存
     * Clear all permission check cache
     *
     * 清除所有以 `casbin:check:` 开头的缓存（以及 `casbin:policy:` 全量策略缓存）。
     * Clear all cache with prefix `casbin:check:` (plus `casbin:policy:` full policy cache).
     *
     * **R1 修复**：此前这里是 `Cache::clear()` —— Redis 驱动下等于 `FLUSHDB`，
     * 会连 `session:*` 会话与限流计数一起清空。现在按白名单前缀清除：
     * Redis 用 `SCAN` + 批量 `DEL`，其它驱动用键索引精确删除，绝不退化为清全库。
     * 同时不再需要「先备份 `casbin_last_reload` 再恢复」——那个键压根不在清除范围内。
     *
     * @return void
     */
    public function clearCache(): void
    {
        // 获取 trace_id
        // Get trace_id
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            $report = $this->purger()->purge([
                PermissionCachePurger::PREFIX_CASBIN_CHECK,
                PermissionCachePurger::PREFIX_CASBIN_POLICY,
            ]);

            // 重置缓存统计
            // Reset cache statistics
            $this->cacheHits = 0;
            $this->cacheMisses = 0;

            Log::info('Casbin cache cleared (prefix-scoped)', [
                'trace_id' => $traceId,
                'driver' => $report['driver'] ?? null,
                'strategy' => $report['strategy'] ?? null,
                'deleted' => $report['deleted'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            // 缓存清除失败不应让「策略已成功重载」这件事变成异常：
            // 只记 warning，让 reloadPolicy() 正常返回（最坏情况是缓存多活一个 TTL）
            Log::warning('Casbin cache clear failed', [
                'trace_id' => $traceId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 清除特定用户的权限检查缓存
     * Clear permission check cache for specific user
     *
     * 只清 `casbin:check:{userId}:{tenantId}:` 前缀下的键，其它用户/其它租户的缓存不受影响。
     * Only purge keys under `casbin:check:{userId}:{tenantId}:`.
     *
     * @param int $userId 用户 ID | User ID
     * @param int $tenantId 租户 ID | Tenant ID
     * @return void
     */
    public function clearUserCache(int $userId, int $tenantId): void
    {
        // 获取 trace_id
        // Get trace_id
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            $report = $this->purger()->purge([
                sprintf('%s%d:%d:', PermissionCachePurger::PREFIX_CASBIN_CHECK, $userId, $tenantId),
            ]);

            // 重置缓存统计
            // Reset cache statistics
            $this->cacheHits = 0;
            $this->cacheMisses = 0;

            Log::info('Casbin user cache cleared (prefix-scoped)', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'strategy' => $report['strategy'] ?? null,
                'deleted' => $report['deleted'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Casbin user cache clear failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 取权限缓存前缀清除器（懒加载）
     *
     * @return PermissionCachePurger
     */
    protected function purger(): PermissionCachePurger
    {
        if ($this->purger === null) {
            $this->purger = new PermissionCachePurger();
        }

        return $this->purger;
    }

    /**
     * 获取缓存统计信息
     * Get cache statistics
     *
     * @return array 缓存统计信息 | Cache statistics
     */
    public function getCacheStats(): array
    {
        $total = $this->cacheHits + $this->cacheMisses;
        $hitRate = $total > 0 ? round(($this->cacheHits / $total) * 100, 2) : 0;

        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'total' => $total,
            'hit_rate' => $hitRate,
        ];
    }

    /**
     * 记录缓存降级事件
     * Record cache degradation event
     *
     * 【参数类型必须是 \Throwable，不能是 \Exception】（R4）
     *   调用点 {@see self::check()} 的两个 catch 已放宽到 `\Throwable`（否则 \TypeError 会穿透成 500）。
     *   若这里仍声明 `\Exception`，把 \TypeError 传进来时 PHP 会在**实参检查**阶段再抛一个
     *   \TypeError —— 而那一行位于 catch 块内部、try 之外，没有任何 catch 能接住它，
     *   于是「降级」又会变成 500，等于白改。参数类型跟着放宽是这次修复的必要组成部分。
     *
     * 本方法只用到 getMessage()，\Throwable 与 \Exception 的可用面完全一致。
     *
     * @param \Throwable $e 异常或错误对象 | Throwable object (Exception or Error)
     * @return void
     */
    protected function recordCacheDegradation(\Throwable $e): void
    {
        $this->cacheDegradationCount++;
        $this->lastCacheDegradationTime = time();
        $this->lastCacheDegradationReason = $e->getMessage();
    }

    /**
     * 获取缓存降级统计信息
     * Get cache degradation statistics
     *
     * @return array 缓存降级统计信息 | Cache degradation statistics
     */
    public function getCacheDegradationStats(): array
    {
        return [
            'degradation_count' => $this->cacheDegradationCount,
            'last_degradation_time' => $this->lastCacheDegradationTime,
            'last_degradation_reason' => $this->lastCacheDegradationReason,
        ];
    }

    /**
     * 格式化权限为 resource:action 格式
     * Format permissions to resource:action format
     *
     * 将 Casbin 返回的权限数组转换为 resource:action 格式的权限码数组。
     * Convert Casbin returned permissions array to resource:action format permission codes array.
     *
     * Casbin 返回格式 | Casbin return format:
     * [
     *   ['role:1', 'tenant:1', 'forms', 'view'],
     *   ['role:1', 'tenant:1', 'forms', 'create'],
     * ]
     *
     * 转换后格式 | Converted format:
     * ['forms:view', 'forms:create']
     *
     * @param array $permissions Casbin 权限数组 | Casbin permissions array
     * @return array 权限码数组 | Permission codes array
     */
    protected function formatPermissions(array $permissions): array
    {
        $formattedPermissions = [];

        foreach ($permissions as $permission) {
            // Casbin 权限格式：[sub, dom, obj, act]
            // Casbin permission format: [sub, dom, obj, act]
            if (count($permission) >= 4) {
                $resource = $permission[2]; // obj
                $action = $permission[3];   // act

                // 转换为 resource:action 格式
                // Convert to resource:action format
                $formattedPermissions[] = "{$resource}:{$action}";
            }
        }

        // 去重
        // Remove duplicates
        return array_unique($formattedPermissions);
    }
}
