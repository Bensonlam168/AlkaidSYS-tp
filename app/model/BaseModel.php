<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Base Model | 基础模型
 * 
 * Provides multi-tenant and multi-site functionality for all models.
 * 为所有模型提供多租户和多站点功能。
 * 
 * Features:
 * - Automatic tenant_id and site_id filtering
 * - Global scope for data isolation
 * - Automatic timestamp fields
 * 
 * 特性：
 * - 自动tenant_id和site_id过滤
 * - 全局作用域实现数据隔离
 * - 自动时间戳字段
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
     * Model initialization | 模型初始化
     * 
     * Registers global scopes for tenant and site filtering.
     * 注册租户和站点过滤的全局作用域。
     * 
     * @return void
     */
    protected static function init(): void
    {
        parent::init();

        // Register tenant scope | 注册租户作用域
        static::globalScope('tenant', function ($query) {
            $request = request();
            
            // Get tenant ID from request | 从请求获取租户ID
            $tenantId = null;
            if (method_exists($request, 'tenantId')) {
                $tenantId = $request->tenantId();
            } else {
                // Fallback to header | 后备方案：从header获取
                $tenantId = (int)$request->header('X-Tenant-ID', 1);
            }
            
            // Only apply if model has tenant_id column | 仅当模型有tenant_id列时应用
            if ($tenantId && static::hasColumn('tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }
        });

        // Register site scope | 注册站点作用域
        static::globalScope('site', function ($query) {
            $request = request();
            
            // Get site ID from request | 从请求获取站点ID
            $siteId = null;
            if (method_exists($request, 'siteId')) {
                $siteId = $request->siteId();
            } else {
                // Fallback to header | 后备方案：从header获取
                $siteId = (int)$request->header('X-Site-ID', 0);
            }
            
            // Only apply if model has site_id column | 仅当模型有site_id列时应用
            if ($siteId !== null && static::hasColumn('site_id')) {
                $query->where('site_id', $siteId);
            }
        });
    }

    /**
     * Check if model has specific column | 检查模型是否有指定列
     * 
     * @param string $column Column name | 列名
     * @return bool
     */
    protected static function hasColumn(string $column): bool
    {
        try {
            $model = new static();
            $schema = $model->getTableFields();
            return in_array($column, $schema);
        } catch (\Exception $e) {
            return false;
        }
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
