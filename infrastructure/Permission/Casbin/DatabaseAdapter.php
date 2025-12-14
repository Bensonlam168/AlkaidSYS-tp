<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Casbin;

use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;
use think\facade\Request;

/**
 * Casbin 数据库适配器
 * Casbin Database Adapter
 *
 * 从现有 RBAC 数据库表加载策略到 Casbin，支持多租户隔离。
 * Load policies from existing RBAC database tables to Casbin with multi-tenancy support.
 *
 * 策略格式 | Policy Format:
 * - 角色分配 | Role Assignment: g, user:{userId}, role:{roleId}, tenant:{tenantId}
 * - 权限分配 | Permission Assignment: p, role:{roleId}, tenant:{tenantId}, {resource}, {action}
 *
 * 数据源 | Data Source:
 * - user_roles: 用户角色关联表
 * - role_permissions: 角色权限关联表
 * - users: 用户表（获取 tenant_id）
 * - roles: 角色表（获取 tenant_id）
 * - permissions: 权限表（获取 resource, action）
 *
 * 模式 | Mode:
 * - 只读模式：只支持 loadPolicy()，不支持 savePolicy() 等写操作
 * - Read-only mode: Only supports loadPolicy(), does not support savePolicy() and other write operations
 *
 * @see https://casbin.org/docs/adapters
 * @see https://github.com/php-casbin/php-casbin
 */
class DatabaseAdapter implements Adapter
{
    /**
     * 加载所有策略规则
     * Load all policy rules from the storage
     *
     * 从 RBAC 数据库表加载策略到 Casbin Model：
     * 1. 加载角色分配（g 策略）：从 user_roles 表
     * 2. 加载权限分配（p 策略）：从 role_permissions 表
     *
     * Load policies from RBAC database tables to Casbin Model:
     * 1. Load role assignments (g policies): from user_roles table
     * 2. Load permission assignments (p policies): from role_permissions table
     *
     * 性能优化 | Performance Optimization:
     * - 添加性能监控日志
     * - 记录查询时间和策略数量
     * - 支持策略缓存（可选）
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @return void
     */
    public function loadPolicy(Model $model): void
    {
        // 记录开始时间
        // Record start time
        $startTime = microtime(true);

        // 获取 trace_id
        // Get trace_id
        $traceId = Request::header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            // 检查是否启用策略缓存
            // Check if policy cache is enabled
            $cacheEnabled = Config::get('casbin.policy_cache_enabled', false);

            if ($cacheEnabled) {
                // 尝试从缓存加载策略
                // Try to load policy from cache
                $cachedPolicies = Cache::get('casbin:policy:full');

                if ($cachedPolicies !== null && is_array($cachedPolicies)) {
                    // 从缓存恢复策略
                    // Restore policies from cache
                    $this->restorePoliciesFromCache($model, $cachedPolicies);

                    // 计算执行时间
                    // Calculate execution time
                    $executionTime = (microtime(true) - $startTime) * 1000; // ms

                    // 记录缓存命中
                    // Log cache hit
                    Log::info('Casbin policy loaded from cache', [
                        'trace_id' => $traceId,
                        'execution_time_ms' => round($executionTime, 2),
                        'role_assignments' => count($cachedPolicies['g'] ?? []),
                        'permission_assignments' => count($cachedPolicies['p'] ?? []),
                        'cache' => 'hit',
                    ]);

                    return;
                }
            }

            // 缓存未命中或禁用，从数据库加载
            // Cache miss or disabled, load from database
            $dbStartTime = microtime(true);

            // 加载角色分配（g 策略）
            // Load role assignments (g policies)
            $roleCount = $this->loadRoleAssignments($model);

            // 加载权限分配（p 策略）
            // Load permission assignments (p policies)
            $permissionCount = $this->loadPermissionAssignments($model);

            // 计算数据库查询时间
            // Calculate database query time
            $dbTime = (microtime(true) - $dbStartTime) * 1000; // ms

            // 如果启用缓存，保存策略到缓存
            // If cache is enabled, save policies to cache
            if ($cacheEnabled) {
                $this->savePolicesToCache($model);
            }

            // 计算总执行时间
            // Calculate total execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录策略加载结果
            // Log policy loading result
            Log::info('Casbin policy loaded from database', [
                'trace_id' => $traceId,
                'execution_time_ms' => round($executionTime, 2),
                'db_query_time_ms' => round($dbTime, 2),
                'role_assignments' => $roleCount,
                'permission_assignments' => $permissionCount,
                'total_policies' => $roleCount + $permissionCount,
                'cache' => $cacheEnabled ? 'miss' : 'disabled',
            ]);
        } catch (\Exception $e) {
            // 计算执行时间
            // Calculate execution time
            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            // 记录错误
            // Log error
            Log::error('Casbin policy loading failed', [
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
    }

    /**
     * 保存所有策略规则
     * Save all policy rules to the storage
     *
     * 注意：当前为只读模式，不支持保存策略。
     * 策略管理通过原有的 RBAC API 进行。
     *
     * Note: Currently in read-only mode, does not support saving policies.
     * Policy management is done through the original RBAC API.
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @return void
     * @throws \RuntimeException 只读模式不支持保存 | Read-only mode does not support saving
     */
    public function savePolicy(Model $model): void
    {
        throw new \RuntimeException(
            'DatabaseAdapter is in read-only mode. ' .
            'Use RBAC API to manage policies instead.'
        );
    }

    /**
     * 添加策略规则
     * Add a policy rule to the storage
     *
     * 注意：当前为只读模式，不支持添加策略。
     * Note: Currently in read-only mode, does not support adding policies.
     *
     * @param string $sec 策略类型（p 或 g）| Policy type (p or g)
     * @param string $ptype 策略子类型 | Policy subtype
     * @param array $rule 策略规则 | Policy rule
     * @return void
     * @throws \RuntimeException 只读模式不支持添加 | Read-only mode does not support adding
     */
    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        throw new \RuntimeException(
            'DatabaseAdapter is in read-only mode. ' .
            'Use RBAC API to add policies instead.'
        );
    }

    /**
     * 删除策略规则
     * Remove a policy rule from the storage
     *
     * 注意：当前为只读模式，不支持删除策略。
     * Note: Currently in read-only mode, does not support removing policies.
     *
     * @param string $sec 策略类型（p 或 g）| Policy type (p or g)
     * @param string $ptype 策略子类型 | Policy subtype
     * @param array $rule 策略规则 | Policy rule
     * @return void
     * @throws \RuntimeException 只读模式不支持删除 | Read-only mode does not support removing
     */
    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        throw new \RuntimeException(
            'DatabaseAdapter is in read-only mode. ' .
            'Use RBAC API to remove policies instead.'
        );
    }

    /**
     * 删除过滤的策略规则
     * Remove policy rules that match the filter from the storage
     *
     * 注意：当前为只读模式，不支持删除策略。
     * Note: Currently in read-only mode, does not support removing policies.
     *
     * @param string $sec 策略类型（p 或 g）| Policy type (p or g)
     * @param string $ptype 策略子类型 | Policy subtype
     * @param int $fieldIndex 字段索引 | Field index
     * @param string ...$fieldValues 字段值 | Field values
     * @return void
     * @throws \RuntimeException 只读模式不支持删除 | Read-only mode does not support removing
     */
    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        throw new \RuntimeException(
            'DatabaseAdapter is in read-only mode. ' .
            'Use RBAC API to remove policies instead.'
        );
    }

    /**
     * 加载角色分配（g 策略）
     * Load role assignments (g policies)
     *
     * 从 user_roles 表加载用户角色关联，转换为 Casbin g 策略：
     * g, user:{userId}, role:{roleId}, tenant:{tenantId}
     *
     * Load user-role associations from user_roles table, convert to Casbin g policies:
     * g, user:{userId}, role:{roleId}, tenant:{tenantId}
     *
     * SQL 查询 | SQL Query:
     * SELECT ur.user_id, ur.role_id, u.tenant_id
     * FROM user_roles ur
     * JOIN users u ON ur.user_id = u.id
     * JOIN roles r ON ur.role_id = r.id
     * WHERE u.tenant_id = r.tenant_id
     *
     * 性能优化 | Performance Optimization:
     * - 记录查询时间和返回行数
     * - 使用索引优化查询（user_id, role_id, tenant_id）
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @return int 加载的角色分配数量 | Number of role assignments loaded
     */
    protected function loadRoleAssignments(Model $model): int
    {
        // 记录查询开始时间
        // Record query start time
        $queryStartTime = microtime(true);

        // 查询用户角色关联
        // Query user-role associations
        $roleAssignments = Db::table('user_roles')
            ->alias('ur')
            ->join('users u', 'ur.user_id = u.id')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('u.tenant_id', 'exp', Db::raw('= r.tenant_id'))
            ->field([
                'ur.user_id',
                'ur.role_id',
                'u.tenant_id',
            ])
            ->select()
            ->toArray();

        // 计算查询时间
        // Calculate query time
        $queryTime = (microtime(true) - $queryStartTime) * 1000; // ms

        // 记录查询性能
        // Log query performance
        Log::debug('Casbin role assignments query', [
            'query_time_ms' => round($queryTime, 2),
            'row_count' => count($roleAssignments),
        ]);

        // 转换为 Casbin g 策略
        // Convert to Casbin g policies
        foreach ($roleAssignments as $assignment) {
            $rule = [
                'user:' . $assignment['user_id'],      // sub: user:{userId}
                'role:' . $assignment['role_id'],      // role: role:{roleId}
                'tenant:' . $assignment['tenant_id'],  // domain: tenant:{tenantId}
            ];

            // 添加到 Model
            // Add to Model
            $model->addPolicy('g', 'g', $rule);
        }

        return count($roleAssignments);
    }

    /**
     * 加载权限分配（p 策略）
     * Load permission assignments (p policies)
     *
     * 从 role_permissions 表加载角色权限关联，转换为 Casbin p 策略：
     * p, role:{roleId}, tenant:{tenantId}, {resource}, {action}
     *
     * Load role-permission associations from role_permissions table, convert to Casbin p policies:
     * p, role:{roleId}, tenant:{tenantId}, {resource}, {action}
     *
     * SQL 查询 | SQL Query:
     * SELECT rp.role_id, r.tenant_id, p.resource, p.action
     * FROM role_permissions rp
     * JOIN roles r ON rp.role_id = r.id
     * JOIN permissions p ON rp.permission_id = p.id
     *
     * 性能优化 | Performance Optimization:
     * - 记录查询时间和返回行数
     * - 使用索引优化查询（role_id, permission_id, tenant_id）
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @return int 加载的权限分配数量 | Number of permission assignments loaded
     */
    protected function loadPermissionAssignments(Model $model): int
    {
        // 记录查询开始时间
        // Record query start time
        $queryStartTime = microtime(true);

        // 查询角色权限关联
        // Query role-permission associations
        $permissionAssignments = Db::table('role_permissions')
            ->alias('rp')
            ->join('roles r', 'rp.role_id = r.id')
            ->join('permissions p', 'rp.permission_id = p.id')
            ->field([
                'rp.role_id',
                'r.tenant_id',
                'p.resource',
                'p.action',
            ])
            ->select()
            ->toArray();

        // 计算查询时间
        // Calculate query time
        $queryTime = (microtime(true) - $queryStartTime) * 1000; // ms

        // 记录查询性能
        // Log query performance
        Log::debug('Casbin permission assignments query', [
            'query_time_ms' => round($queryTime, 2),
            'row_count' => count($permissionAssignments),
        ]);

        // 转换为 Casbin p 策略
        // Convert to Casbin p policies
        foreach ($permissionAssignments as $assignment) {
            $rule = [
                'role:' . $assignment['role_id'],      // sub: role:{roleId}
                'tenant:' . $assignment['tenant_id'],  // domain: tenant:{tenantId}
                $assignment['resource'],                // obj: resource
                $assignment['action'],                  // act: action
            ];

            // 添加到 Model
            // Add to Model
            $model->addPolicy('p', 'p', $rule);
        }

        return count($permissionAssignments);
    }

    /**
     * 从缓存恢复策略
     * Restore policies from cache
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @param array $cachedPolicies 缓存的策略 | Cached policies
     * @return void
     */
    protected function restorePoliciesFromCache(Model $model, array $cachedPolicies): void
    {
        // 恢复 g 策略（角色分配）
        // Restore g policies (role assignments)
        if (isset($cachedPolicies['g']) && is_array($cachedPolicies['g'])) {
            foreach ($cachedPolicies['g'] as $rule) {
                $model->addPolicy('g', 'g', $rule);
            }
        }

        // 恢复 p 策略（权限分配）
        // Restore p policies (permission assignments)
        if (isset($cachedPolicies['p']) && is_array($cachedPolicies['p'])) {
            foreach ($cachedPolicies['p'] as $rule) {
                $model->addPolicy('p', 'p', $rule);
            }
        }
    }

    /**
     * 保存策略到缓存
     * Save policies to cache
     *
     * @param Model $model Casbin 模型 | Casbin model
     * @return void
     */
    protected function savePolicesToCache(Model $model): void
    {
        // 获取所有策略
        // Get all policies
        $policies = [
            'g' => $model->getPolicy('g', 'g'),
            'p' => $model->getPolicy('p', 'p'),
        ];

        // 获取缓存 TTL
        // Get cache TTL
        $cacheTtl = Config::get('casbin.reload_ttl', 300);

        // 保存到缓存
        // Save to cache
        Cache::set('casbin:policy:full', $policies, $cacheTtl);
    }
}
