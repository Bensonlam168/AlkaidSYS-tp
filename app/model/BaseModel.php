<?php

declare(strict_types=1);

namespace app\model;

use think\db\BaseQuery as Query;
use think\Model;

/**
 * Base Model | 基础模型
 *
 * Provides multi-tenant and multi-site functionality for all models.
 * 为所有模型提供多租户和多站点功能。
 *
 * Features:
 * - Automatic tenant_id and site_id filtering via global scopes
 * - CLI environment detection (scopes disabled in CLI by default)
 * - Column existence caching for performance
 * - Automatic timestamp fields
 *
 * 特性：
 * - 通过全局作用域自动 tenant_id 和 site_id 过滤
 * - CLI 环境检测（CLI 下默认禁用作用域）
 * - 字段存在性缓存以提升性能
 * - 自动时间戳字段
 *
 * Usage: Subclasses should set $globalScope = ['tenant', 'site'] to enable scopes.
 * 使用方法：子类应设置 $globalScope = ['tenant', 'site'] 以启用作用域。
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
     * @var array<string, bool>
     */
    protected static array $columnCache = [];

    /**
     * Global scopes enabled flag | 全局作用域启用标志
     * Can be disabled via environment variable DISABLE_TENANT_SCOPES=true
     *
     * @var bool|null
     */
    protected static ?bool $scopesEnabled = null;

    /**
     * Tenant scope | 租户作用域
     *
     * Automatically filters queries by tenant_id.
     * Called when 'tenant' is in the $globalScope array.
     * 按 tenant_id 自动过滤查询。
     * 当 'tenant' 在 $globalScope 数组中时调用。
     *
     * @param Query $query
     * @return Query
     */
    public function scopeTenant(Query $query): Query
    {
        // Skip if scopes are disabled | 如果作用域禁用则跳过
        if (!static::areScopesEnabled()) {
            return $query;
        }

        $tenantId = static::getTenantContext();

        // Only apply if model has tenant_id column and tenantId is valid
        // 仅当模型有 tenant_id 列且 tenantId 有效时应用
        if ($tenantId !== null && $tenantId > 0 && $this->hasColumnCached('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

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
     * Scopes are disabled in CLI environment or when DISABLE_TENANT_SCOPES=true
     * CLI 环境或设置 DISABLE_TENANT_SCOPES=true 时禁用作用域
     *
     * @return bool
     */
    public static function areScopesEnabled(): bool
    {
        if (static::$scopesEnabled !== null) {
            return static::$scopesEnabled;
        }

        // Check environment variable | 检查环境变量
        $disabledByEnv = static::getEnvValue('DISABLE_TENANT_SCOPES');
        if ($disabledByEnv === true || $disabledByEnv === 'true' || $disabledByEnv === '1') {
            static::$scopesEnabled = false;
            return false;
        }

        // Disable in CLI environment | CLI 环境下禁用
        if (php_sapi_name() === 'cli' || defined('STDIN')) {
            // Allow enabling in CLI via ENABLE_TENANT_SCOPES_IN_CLI=true
            // 允许通过 ENABLE_TENANT_SCOPES_IN_CLI=true 在 CLI 中启用
            $enabledInCli = static::getEnvValue('ENABLE_TENANT_SCOPES_IN_CLI');
            if ($enabledInCli === true || $enabledInCli === 'true' || $enabledInCli === '1') {
                static::$scopesEnabled = true;
                return true;
            }
            static::$scopesEnabled = false;
            return false;
        }

        static::$scopesEnabled = true;
        return true;
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
     * @return int|null
     */
    public static function getTenantContext(): ?int
    {
        try {
            $request = request();

            if (method_exists($request, 'tenantId')) {
                return $request->tenantId();
            }

            // Fallback to header only in web context | 仅在 web 上下文中使用 header 后备方案
            if (method_exists($request, 'header')) {
                $headerValue = $request->header('X-Tenant-ID');
                if ($headerValue !== null && $headerValue !== '') {
                    return (int) $headerValue;
                }
            }

            return null;
        } catch (\Throwable) {
            // Silently return null on any error | 任何错误时静默返回 null
            return null;
        }
    }

    /**
     * Get site context safely | 安全获取站点上下文
     *
     * Returns site ID from request, with proper error handling.
     * 从请求中返回站点 ID，带有适当的错误处理。
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
        } catch (\Throwable) {
            // Silently return null on any error | 任何错误时静默返回 null
            return null;
        }
    }

    /**
     * Check if model has specific column (cached) | 检查模型是否有指定列（带缓存）
     *
     * Uses static cache to avoid repeated table schema queries.
     * 使用静态缓存避免重复查询表结构。
     *
     * @param string $column Column name | 列名
     * @return bool
     */
    public function hasColumnCached(string $column): bool
    {
        $cacheKey = static::class . ':' . $column;

        if (isset(static::$columnCache[$cacheKey])) {
            return static::$columnCache[$cacheKey];
        }

        $result = $this->hasColumn($column);
        static::$columnCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Check if model has specific column | 检查模型是否有指定列
     *
     * @param string $column Column name | 列名
     * @return bool
     */
    public function hasColumn(string $column): bool
    {
        try {
            $schema = $this->getTableFields();
            return in_array($column, $schema);
        } catch (\Exception) {
            return false;
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
     * @return void
     */
    public static function resetScopesEnabled(): void
    {
        static::$scopesEnabled = null;
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
