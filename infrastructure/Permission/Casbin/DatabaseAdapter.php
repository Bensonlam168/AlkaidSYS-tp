<?php

declare(strict_types=1);

namespace Infrastructure\Permission\Casbin;

use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;

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
 * ## 缓存降级边界（本次修复）| Cache degradation boundaries
 *
 * 策略缓存（`casbin.policy_cache_enabled`）是**旁路**，它的三种故障都必须降级为
 * 「当作 miss，去 DB 加载」，而**不能**升级成「鉴权 500」：
 *
 * | 故障 | 位置 | 处理 |
 * | --- | --- | --- |
 * | 读失败（Redis 不可用 / 连接池释放） | `readPoliciesFromCache()` | warning + 返回 null（miss） |
 * | 内容损坏（结构非法 / 缺段 / 字段错位） | `readPoliciesFromCache()` + `isValidPolicyCachePayload()` | warning + 返回 null（miss） |
 * | 恢复失败（违反模型约束） | `loadPolicy()` 内层 try | warning + `clearPolicy()` + 回落 DB |
 * | 写失败（回写 Redis 失败） | `savePolicesToCache()` | warning（策略已从 DB 装好，结果不变） |
 *
 * 唯一仍然**原样上抛**的是「DB 装载失败」—— 那才是真的装不出策略，
 * 继续鉴权等于静默全拒绝。见 `loadPolicy()` 外层 catch 的注释。
 *
 * @see https://casbin.org/docs/adapters
 * @see https://github.com/php-casbin/php-casbin
 */
class DatabaseAdapter implements Adapter
{
    /**
     * 全量策略缓存键 | Full policy cache key
     *
     * 读（`readPoliciesFromCache`）与写（`savePolicesToCache`）必须用**同一个**常量，
     * 否则一旦有人改了其中一处的字面量，就会出现「写得进、读不到」的永久 miss，
     * 且不会有任何报错 —— 只会表现为「缓存开关打开了但一直不命中」。
     */
    protected const POLICY_CACHE_KEY = 'casbin:policy:full';

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
        $traceId = request()->header('X-Trace-Id') ?: uniqid('casbin_', true);

        try {
            // 检查是否启用策略缓存
            // Check if policy cache is enabled
            $cacheEnabled = Config::get('casbin.policy_cache_enabled', false);

            if ($cacheEnabled) {
                // 尝试从缓存加载策略
                // Try to load policy from cache
                //
                // 【R-健康｜缓存读独立兜底】这里的缓存读**必须自己接住异常**，不能交给外层 catch：
                // 外层 catch 的语义是「装不出策略 → 记 error + 原样上抛」（DB 真挂了就该 500），
                // 而「Redis 读不出来」只是旁路失效 —— 缓存里本来就有 DB 的副本，
                // 丢掉它去读 DB 即可。历史实现把两者混在同一个 try 里，于是
                // 「开启 policy_cache_enabled 后 Redis 抖一下 → loadPolicy 抛 → 鉴权 500」，
                // 恰好抵消了 shouldReload() 的兜底（它返回 true → 触发 reload → 到这里炸）。
                $cachedPolicies = $this->readPoliciesFromCache($traceId);

                if ($cachedPolicies !== null) {
                    try {
                        // 从缓存恢复策略
                        // Restore policies from cache
                        $this->restorePoliciesFromCache($model, $cachedPolicies);
                    } catch (\Throwable $e) {
                        // 【缓存内容损坏也算 miss】能过结构校验、但违反 Casbin 模型约束的条目
                        // （例如规则字段数不够、含嵌套数组导致 implode 失败）同样是旁路故障：
                        // 正确动作是丢掉这份缓存、回落到 DB，而不是让整次鉴权 500。
                        //
                        // 必须先 clearPolicy()：restore 可能已写入一部分规则，若直接接着从 DB
                        // 加载，模型里会留下「半份缓存 + 全量 DB」的混合策略集 —— 既脏又极难排查
                        // （表现为某些用户莫名多出/少了权限，重启后又消失）。
                        Log::warning('Casbin policy cache restore failed, falling back to database', [
                            'reason' => 'policy_cache_restore_failed',
                            'trace_id' => $traceId,
                            'error_type' => get_class($e),
                            'error_message' => $e->getMessage(),
                        ]);

                        $model->clearPolicy();
                        $cachedPolicies = null;
                    }
                }

                if ($cachedPolicies !== null) {
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
        } catch (\Throwable $e) {
            // 【为什么捕 \Throwable】（R4）本 catch 只做「记日志 + 原样上抛」，不吞异常：
            // `loadPolicy()` 是 Casbin 策略装配的入口，装不出策略就继续鉴权 = 静默全拒绝，
            // 因此这里**必须**上抛。扩到 \Throwable 只是让 \TypeError / \Error 这类
            // 「代码写错了」的错误也留下带 trace_id 的结构化日志（旧写法下它们直接穿到框架层）。
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
        $roleAssignmentsQuery = Db::table('user_roles')
            ->alias('ur')
            ->join('users u', 'ur.user_id = u.id')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('u.tenant_id', 'exp', Db::raw('= r.tenant_id'))
            ->field([
                'ur.user_id',
                'ur.role_id',
                'u.tenant_id',
            ]);

        // 可选租户过滤（避免多租户混查）
        $tenantFilter = (int) Config::get('casbin.tenant_id', 0);
        if ($tenantFilter > 0) {
            $roleAssignmentsQuery->where('u.tenant_id', $tenantFilter);
        }

        // 【确定性加载顺序（R50）】必须显式 ORDER BY，不能依赖「数据库碰巧按索引顺序返回」。
        // 本查询没有 ORDER BY 时，返回顺序由优化器选的执行计划决定 —— 表里的数据量一变
        // （例如种子数据补齐后 role_permissions 从 0 行变成 45 行），计划就可能从
        // 「按 user_roles 主键扫描」换成「按 users 索引驱动」，同一批策略的先后顺序随之改变。
        // 实测：`DatabaseAdapterTest::testLoadPermissionAssignments` 的
        // `$testPolicies[0]` 断言就是这样从绿翻红的 —— 断言本身依赖顺序，但顺序从未被保证。
        // 本仓的 casbin-model.conf 没有优先级（policy_effect 只用 allow-override），
        // 顺序对 enforce 结果无语义影响，因此显式排序是纯粹的稳定性改进。
        $roleAssignmentsQuery->order(['ur.user_id' => 'asc', 'ur.role_id' => 'asc']);

        $roleAssignments = $roleAssignmentsQuery
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
        $permissionAssignmentsQuery = Db::table('role_permissions')
            ->alias('rp')
            ->join('roles r', 'rp.role_id = r.id')
            ->join('permissions p', 'rp.permission_id = p.id')
            ->field([
                'rp.role_id',
                'r.tenant_id',
                'p.resource',
                'p.action',
            ]);

        // 可选租户过滤（避免多租户混查）
        $tenantFilter = (int) Config::get('casbin.tenant_id', 0);
        if ($tenantFilter > 0) {
            $permissionAssignmentsQuery->where('r.tenant_id', $tenantFilter);
        }

        // 【确定性加载顺序（R50）】理由同 loadRoleAssignments()：
        // 无 ORDER BY 时行序随执行计划漂移。这里按 (role_id, permission_id) 升序固定下来，
        // 使「同一角色的权限」始终按权限 id 排列 —— 与 role_permissions 复合主键
        // (role_id, permission_id) 的自然顺序一致，也便于日志比对。
        $permissionAssignmentsQuery->order(['rp.role_id' => 'asc', 'rp.permission_id' => 'asc']);

        $permissionAssignments = $permissionAssignmentsQuery
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
     * 从缓存读取全量策略（**独立兜底**，绝不向调用方抛异常）
     * Read the full policy set from cache (never throws to the caller)
     *
     * 返回 `null` 的三种情形，对调用方的语义完全一致 —— **当作 cache miss，去 DB 加载**：
     *   1. 正常未命中（首次启动、TTL 过期、被清除）；
     *   2. **读失败**（Redis 不可用 / 连接池已释放 / 驱动抛 `\TypeError` 等）；
     *   3. **内容损坏**（不是数组、缺少 `g`/`p` 段、规则不是字符串列表……）。
     *
     * 为什么必须捕 `\Throwable` 而不是 `\Exception`：`\Error`（含 `\TypeError`）不继承
     * `\Exception`，Redis 驱动/连接池在故障期抛的正是这一类；只捕 `\Exception` 等于
     * 把「缓存坏了」重新变成「鉴权 500」，也就是本次要修的缺陷本身。
     *
     * 为什么记 **warning** 而不是 error：这是可降级的旁路故障（本次请求仍能用 DB 正常装出策略）。
     * 记成 error 会让告警级别失真 —— 真正的 error 应该只留给「装不出策略」。
     *
     * @param string $traceId 当前请求 trace_id（用于把缓存故障与同一次鉴权对齐）
     * @return array{g: array<int, list<string>>, p: array<int, list<string>>}|null
     *         合法缓存返回数组；未命中 / 读失败 / 内容损坏返回 null
     */
    protected function readPoliciesFromCache(string $traceId): ?array
    {
        try {
            $cachedPolicies = Cache::get(self::POLICY_CACHE_KEY);
        } catch (\Throwable $e) {
            Log::warning('Casbin policy cache read failed, falling back to database', [
                'reason' => 'policy_cache_read_failed',
                'trace_id' => $traceId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);

            return null;
        }

        // 未命中：Cache::get() 的默认返回值就是 null，这是完全正常的路径，不需要日志
        if ($cachedPolicies === null) {
            return null;
        }

        if (!$this->isValidPolicyCachePayload($cachedPolicies)) {
            // 损坏的缓存**不能**继续往下走：例如只缓存了 `g` 段而丢了 `p` 段，
            // 恢复后会得到「有角色、零权限」的模型 —— 请求全部 403，且没有任何异常可循。
            // 宁可多查一次 DB，也不能把一份不完整的策略当成真的。
            Log::warning('Casbin policy cache payload is malformed, falling back to database', [
                'reason' => 'policy_cache_malformed',
                'trace_id' => $traceId,
                'payload_type' => get_debug_type($cachedPolicies),
            ]);

            return null;
        }

        return $cachedPolicies;
    }

    /**
     * 校验缓存载荷结构是否合法（防止「坏缓存」变成「静默的错误策略集」）
     * Validate the cached payload shape
     *
     * 判定标准（全部满足才算合法）：
     *   - 顶层是数组，且 `g`、`p` **两段都存在**、都是数组（写路径这两个键一定都会写，
     *     少一个就说明载荷被截断/被别的写入者覆盖过）；
     *   - 每条规则是非空数组，且每个字段都是字符串；
     *   - 每条规则是 **list**（键为 0..n-1 的连续整数）。
     *
     * 最后一条最容易被忽略却最危险：`Casbin` 的 `Policy::addPolicy()` 用
     * `implode(separator, $rule)` 生成 policyMap 的键，**依赖数组的插入顺序**。
     * 关联数组/乱序键会让字段整体错位（`tenant:1` 落到 obj 位），
     * 结果不是报错，而是「某条策略莫名其妙地不生效或过度放行」。
     *
     * @param mixed $payload 缓存里读出来的原始值
     * @return bool 合法返回 true
     */
    protected function isValidPolicyCachePayload(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach (['g', 'p'] as $section) {
            if (!array_key_exists($section, $payload) || !is_array($payload[$section])) {
                return false;
            }

            foreach ($payload[$section] as $rule) {
                if (!is_array($rule) || $rule === []) {
                    return false;
                }

                if (!array_is_list($rule)) {
                    return false;
                }

                foreach ($rule as $field) {
                    if (!is_string($field)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * 从缓存恢复策略
     * Restore policies from cache
     *
     * ⚠️ 调用前提：`isValidPolicyCachePayload()` 已通过。即便如此，`addPolicy()` 仍可能
     * 因为模型约束（字段数、优先级索引等）抛异常，因此**调用方必须 try/catch**，
     * 并在失败时 `clearPolicy()` 后回落到 DB（见 `loadPolicy()`）。
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
     * 【写路径同样是旁路，失败不得影响本次请求】
     * 历史实现让 `Cache::set()` 直接暴露在 `loadPolicy()` 的外层 try 里，
     * 于是「策略已经从 DB 成功读出来了，只是没写进 Redis」会被外层 catch 接住
     * → 记 error → **原样上抛** → 鉴权 500。这跟读路径是同一类缺陷：
     * 缓存写失败的正确后果是「下次继续 miss（多查一次 DB）」，不是「本次请求失败」。
     * 因此这里自己接住 `\Throwable`（含 `\TypeError`），只记 warning。
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

        try {
            // 保存到缓存
            // Save to cache
            Cache::set(self::POLICY_CACHE_KEY, $policies, $cacheTtl);
        } catch (\Throwable $e) {
            // 【为什么必须捕 \Throwable】\Error（含 \TypeError）不继承 \Exception，
            // Redis 驱动/连接池故障期抛的正是这一类；漏接就会把降级变成 500。
            Log::warning('Casbin policy cache write failed (policies are still loaded from database)', [
                'reason' => 'policy_cache_write_failed',
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'role_assignments' => count($policies['g']),
                'permission_assignments' => count($policies['p']),
                'cache_ttl' => $cacheTtl,
            ]);
        }
    }
}
