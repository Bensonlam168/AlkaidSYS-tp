<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\Integration\Interfaces\LowcodeAdapterInterface;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\TenantIsolationGuard;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use think\facade\Db;
use think\facade\Event;

/**
 * 低代码系统集成适配器
 *
 * 提供工作流引擎与低代码系统的完整集成支持，包括：
 * - Collection 数据操作（CRUD、批量、聚合）
 * - 表单数据自动填充
 * - 权限系统继承
 * - 多租户数据隔离
 *
 * @package Alkaid\Workflow\Engine\Integration
 */
class LowcodeAdapter implements LowcodeAdapterInterface
{
    /**
     * @var CollectionManager|null Collection 管理器
     */
    private ?CollectionManager $collectionManager = null;

    /**
     * @var FormDataManager|null 表单数据管理器
     */
    private ?FormDataManager $formDataManager = null;

    /**
     * @var FormSchemaManager|null 表单 Schema 管理器
     */
    private ?FormSchemaManager $formSchemaManager = null;

    /**
     * @var array 操作日志
     */
    private array $operationLog = [];

    /**
     * @var int 最大日志条目数
     */
    private int $maxLogEntries = 100;

    /**
     * @var bool 是否启用操作日志
     */
    private bool $loggingEnabled = true;

    /**
     * 条件运算符映射
     */
    private const CONDITION_OPERATORS = [
        'eq' => '=',
        'neq' => '<>',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'like' => 'like',
        'in' => 'in',
        'nin' => 'not in',
        'between' => 'between',
        'null' => 'null',
        'notnull' => 'not null',
    ];

    /**
     * 构造函数
     *
     * @param CollectionManager|null $collectionManager
     * @param FormDataManager|null $formDataManager
     * @param FormSchemaManager|null $formSchemaManager
     */
    public function __construct(
        ?CollectionManager $collectionManager = null,
        ?FormDataManager $formDataManager = null,
        ?FormSchemaManager $formSchemaManager = null
    ) {
        $this->collectionManager = $collectionManager;
        $this->formDataManager = $formDataManager;
        $this->formSchemaManager = $formSchemaManager;
    }

    /**
     * 惰性加载 CollectionManager
     *
     * @return CollectionManager
     */
    public function getCollectionManager(): CollectionManager
    {
        if ($this->collectionManager === null) {
            $this->collectionManager = app()->make(CollectionManager::class);
        }
        return $this->collectionManager;
    }

    /**
     * 惰性加载 FormDataManager
     *
     * @return FormDataManager
     */
    public function getFormDataManager(): FormDataManager
    {
        if ($this->formDataManager === null) {
            $this->formDataManager = app()->make(FormDataManager::class);
        }
        return $this->formDataManager;
    }

    /**
     * 惰性加载 FormSchemaManager
     *
     * @return FormSchemaManager
     */
    public function getFormSchemaManager(): FormSchemaManager
    {
        if ($this->formSchemaManager === null) {
            $this->formSchemaManager = app()->make(FormSchemaManager::class);
        }
        return $this->formSchemaManager;
    }

    // ========== Collection 数据操作 ==========

    /**
     * 创建数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $data 数据
     * @param array $options 选项
     * @return int|string 新记录 ID
     * @throws TenantIsolationException
     */
    public function create(string $collectionName, array $data, array $options = []): int|string
    {
        $tenantId = $this->requireTenantContext('create');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        // 自动注入租户 ID
        $data['tenant_id'] = $tenantId;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        // 创建记录
        $id = Db::table($tableName)->insertGetId($data);

        $this->logOperation('create', $collectionName, [
            'id' => $id,
            'data' => $this->sanitizeDataForLog($data),
        ]);

        // 触发事件
        Event::trigger('workflow.lowcode.created', [
            'collection' => $collectionName,
            'id' => $id,
            'data' => $data,
            'tenant_id' => $tenantId,
        ]);

        return $id;
    }

    /**
     * 读取数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return array 查询结果
     * @throws TenantIsolationException
     */
    public function read(string $collectionName, array $conditions = [], array $options = []): array
    {
        $tenantId = $this->requireTenantContext('read');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName);

        // 自动添加租户过滤
        $query->where('tenant_id', $tenantId);

        // 应用查询条件
        $this->applyConditions($query, $conditions);

        // 应用字段选择
        if (!empty($options['fields'])) {
            $query->field($options['fields']);
        }

        // 应用排序
        if (!empty($options['order'])) {
            foreach ($options['order'] as $field => $direction) {
                $query->order($field, $direction);
            }
        }

        // 应用分页
        if (isset($options['limit'])) {
            $query->limit((int) $options['limit']);
        }
        if (isset($options['offset'])) {
            $query->offset((int) $options['offset']);
        }

        // 执行查询
        $result = $query->select()->toArray();

        $this->logOperation('read', $collectionName, [
            'conditions' => $conditions,
            'count' => count($result),
        ]);

        return $result;
    }

    /**
     * 读取单条记录
     *
     * @param string $collectionName Collection 名称
     * @param int|string $id 记录 ID
     * @param array $options 选项
     * @return array|null 记录数据
     * @throws TenantIsolationException
     */
    public function find(string $collectionName, int|string $id, array $options = []): ?array
    {
        $tenantId = $this->requireTenantContext('find');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName)
            ->where('id', $id)
            ->where('tenant_id', $tenantId);

        if (!empty($options['fields'])) {
            $query->field($options['fields']);
        }

        $result = $query->find();

        $this->logOperation('find', $collectionName, [
            'id' => $id,
            'found' => $result !== null,
        ]);

        return $result;
    }

    /**
     * 更新数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 更新条件
     * @param array $data 更新数据
     * @param array $options 选项
     * @return int 影响行数
     * @throws TenantIsolationException
     */
    public function update(string $collectionName, array $conditions, array $data, array $options = []): int
    {
        $tenantId = $this->requireTenantContext('update');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName);

        // 自动添加租户过滤
        $query->where('tenant_id', $tenantId);

        // 应用更新条件
        $this->applyConditions($query, $conditions);

        // 自动更新时间戳
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        // 防止更新 tenant_id
        unset($data['tenant_id']);

        $affected = $query->update($data);

        $this->logOperation('update', $collectionName, [
            'conditions' => $conditions,
            'data' => $this->sanitizeDataForLog($data),
            'affected' => $affected,
        ]);

        // 触发事件
        Event::trigger('workflow.lowcode.updated', [
            'collection' => $collectionName,
            'conditions' => $conditions,
            'data' => $data,
            'affected' => $affected,
            'tenant_id' => $tenantId,
        ]);

        return $affected;
    }

    /**
     * 删除数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 删除条件
     * @param array $options 选项
     * @return int 影响行数
     * @throws TenantIsolationException
     */
    public function delete(string $collectionName, array $conditions, array $options = []): int
    {
        $tenantId = $this->requireTenantContext('delete');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName);

        // 自动添加租户过滤
        $query->where('tenant_id', $tenantId);

        // 应用删除条件
        $this->applyConditions($query, $conditions);

        // 软删除 vs 硬删除
        if ($options['soft_delete'] ?? true) {
            $affected = $query->update([
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $affected = $query->delete();
        }

        $this->logOperation('delete', $collectionName, [
            'conditions' => $conditions,
            'affected' => $affected,
            'soft_delete' => $options['soft_delete'] ?? true,
        ]);

        // 触发事件
        Event::trigger('workflow.lowcode.deleted', [
            'collection' => $collectionName,
            'conditions' => $conditions,
            'affected' => $affected,
            'tenant_id' => $tenantId,
        ]);

        return $affected;
    }

    // ========== 批量操作 ==========

    /**
     * 批量创建记录
     *
     * @param string $collectionName Collection 名称
     * @param array $records 记录数组
     * @param array $options 选项
     * @return int 创建数量
     * @throws TenantIsolationException
     */
    public function batchCreate(string $collectionName, array $records, array $options = []): int
    {
        $tenantId = $this->requireTenantContext('batchCreate');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $now = date('Y-m-d H:i:s');

        // 为每条记录注入租户 ID 和时间戳
        foreach ($records as &$record) {
            $record['tenant_id'] = $tenantId;
            $record['created_at'] = $record['created_at'] ?? $now;
            $record['updated_at'] = $record['updated_at'] ?? $now;
        }
        unset($record);

        // 批量插入
        $count = Db::table($tableName)->insertAll($records);

        $this->logOperation('batchCreate', $collectionName, [
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * 批量更新记录
     *
     * @param string $collectionName Collection 名称
     * @param array $updates 更新数组 [['conditions' => [...], 'data' => [...]], ...]
     * @param array $options 选项
     * @return int 影响行数
     * @throws TenantIsolationException
     */
    public function batchUpdate(string $collectionName, array $updates, array $options = []): int
    {
        $tenantId = $this->requireTenantContext('batchUpdate');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $totalAffected = 0;
        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            foreach ($updates as $update) {
                $conditions = $update['conditions'] ?? [];
                $data = $update['data'] ?? [];

                if (empty($conditions) || empty($data)) {
                    continue;
                }

                $query = Db::table($tableName)->where('tenant_id', $tenantId);
                $this->applyConditions($query, $conditions);

                $data['updated_at'] = $data['updated_at'] ?? $now;
                unset($data['tenant_id']);

                $totalAffected += $query->update($data);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $this->logOperation('batchUpdate', $collectionName, [
            'updates_count' => count($updates),
            'affected' => $totalAffected,
        ]);

        return $totalAffected;
    }

    /**
     * 批量删除记录
     *
     * @param string $collectionName Collection 名称
     * @param array $ids ID 数组
     * @param array $options 选项
     * @return int 影响行数
     * @throws TenantIsolationException
     */
    public function batchDelete(string $collectionName, array $ids, array $options = []): int
    {
        $tenantId = $this->requireTenantContext('batchDelete');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids);

        if ($options['soft_delete'] ?? true) {
            $affected = $query->update([
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $affected = $query->delete();
        }

        $this->logOperation('batchDelete', $collectionName, [
            'ids_count' => count($ids),
            'affected' => $affected,
        ]);

        return $affected;
    }

    // ========== 聚合操作 ==========

    /**
     * 执行聚合查询
     *
     * @param string $collectionName Collection 名称
     * @param string $function 聚合函数 (count, sum, avg, min, max)
     * @param string|null $field 聚合字段
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return mixed 聚合结果
     * @throws TenantIsolationException
     */
    public function aggregate(
        string $collectionName,
        string $function,
        ?string $field = null,
        array $conditions = [],
        array $options = []
    ): mixed {
        $tenantId = $this->requireTenantContext('aggregate');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName)->where('tenant_id', $tenantId);
        $this->applyConditions($query, $conditions);

        $result = match ($function) {
            'count' => $query->count($field ?? '*'),
            'sum' => $query->sum($field ?? 'id'),
            'avg' => $query->avg($field ?? 'id'),
            'min' => $query->min($field ?? 'id'),
            'max' => $query->max($field ?? 'id'),
            default => throw new \InvalidArgumentException("Unsupported aggregate function: {$function}"),
        };

        $this->logOperation('aggregate', $collectionName, [
            'function' => $function,
            'field' => $field,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * 分组聚合查询
     *
     * @param string $collectionName Collection 名称
     * @param array $groupBy 分组字段
     * @param array $aggregates 聚合配置 [['function' => 'sum', 'field' => 'amount', 'alias' => 'total'], ...]
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return array 聚合结果
     * @throws TenantIsolationException
     */
    public function groupAggregate(
        string $collectionName,
        array $groupBy,
        array $aggregates,
        array $conditions = [],
        array $options = []
    ): array {
        $tenantId = $this->requireTenantContext('groupAggregate');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName)->where('tenant_id', $tenantId);
        $this->applyConditions($query, $conditions);

        // 构建聚合字段
        $fields = $groupBy;
        foreach ($aggregates as $agg) {
            $func = strtoupper($agg['function']);
            $field = $agg['field'] ?? '*';
            $alias = $agg['alias'] ?? "{$func}_{$field}";
            $fields[] = Db::raw("{$func}({$field}) AS {$alias}");
        }

        $query->field($fields)->group(implode(',', $groupBy));

        $result = $query->select()->toArray();

        $this->logOperation('groupAggregate', $collectionName, [
            'group_by' => $groupBy,
            'aggregates' => $aggregates,
            'result_count' => count($result),
        ]);

        return $result;
    }

    /**
     * 检查记录是否存在
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return bool 是否存在
     * @throws TenantIsolationException
     */
    public function exists(string $collectionName, array $conditions, array $options = []): bool
    {
        $tenantId = $this->requireTenantContext('exists');
        $tableName = $this->resolveTableName($collectionName, $tenantId, $options);

        $query = Db::table($tableName)->where('tenant_id', $tenantId);
        $this->applyConditions($query, $conditions);

        return $query->count() > 0;
    }

    // ========== 表单集成 ==========

    /**
     * 保存表单数据
     *
     * @param string $formName 表单名称
     * @param array $data 表单数据
     * @param array $options 选项
     * @return int|string 保存的 ID
     * @throws TenantIsolationException
     */
    public function saveFormData(string $formName, array $data, array $options = []): int|string
    {
        $tenantId = $this->requireTenantContext('saveFormData');
        $siteId = $options['site_id'] ?? 0;

        $id = $this->getFormDataManager()->save($formName, $data, $tenantId, $siteId);

        $this->logOperation('saveFormData', $formName, [
            'id' => $id,
        ]);

        return $id;
    }

    /**
     * 获取表单数据
     *
     * @param string $formName 表单名称
     * @param int|string $id 数据 ID
     * @param array $options 选项
     * @return array|null 表单数据
     * @throws TenantIsolationException
     */
    public function getFormData(string $formName, int|string $id, array $options = []): ?array
    {
        $tenantId = $this->requireTenantContext('getFormData');
        $siteId = $options['site_id'] ?? 0;

        return $this->getFormDataManager()->find($formName, $id, $tenantId, $siteId);
    }

    /**
     * 获取表单 Schema
     *
     * @param string $formName 表单名称
     * @param array $options 选项
     * @return array|null 表单 Schema
     * @throws TenantIsolationException
     */
    public function getFormSchema(string $formName, array $options = []): ?array
    {
        $tenantId = $this->requireTenantContext('getFormSchema');
        $siteId = $options['site_id'] ?? 0;

        return $this->getFormSchemaManager()->get($formName, $tenantId, $siteId);
    }

    /**
     * 根据表单 Schema 自动填充工作流变量
     *
     * @param string $formName 表单名称
     * @param int|string $dataId 数据 ID
     * @param array $variableMapping 变量映射 [表单字段 => 工作流变量]
     * @param array $options 选项
     * @return array 填充后的变量
     * @throws TenantIsolationException
     */
    public function autoFillVariables(
        string $formName,
        int|string $dataId,
        array $variableMapping = [],
        array $options = []
    ): array {
        $formData = $this->getFormData($formName, $dataId, $options);

        if ($formData === null) {
            return [];
        }

        $variables = [];

        // 如果没有指定映射，使用所有表单字段
        if (empty($variableMapping)) {
            $variables = $formData;
        } else {
            foreach ($variableMapping as $formField => $workflowVariable) {
                if (isset($formData[$formField])) {
                    $variables[$workflowVariable] = $formData[$formField];
                }
            }
        }

        $this->logOperation('autoFillVariables', $formName, [
            'data_id' => $dataId,
            'variables_count' => count($variables),
        ]);

        return $variables;
    }

    // ========== Collection 元数据 ==========

    /**
     * 获取 Collection 信息
     *
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return CollectionInterface|null
     * @throws TenantIsolationException
     */
    public function getCollection(string $collectionName, array $options = []): ?CollectionInterface
    {
        $tenantId = $this->requireTenantContext('getCollection');
        return $this->getCollectionManager()->get($collectionName, $tenantId);
    }

    /**
     * 获取 Collection 字段列表
     *
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return array 字段列表
     * @throws TenantIsolationException
     */
    public function getCollectionFields(string $collectionName, array $options = []): array
    {
        $collection = $this->getCollection($collectionName, $options);

        if ($collection === null) {
            return [];
        }

        $fields = [];
        foreach ($collection->getFields() as $name => $field) {
            $fields[$name] = [
                'name' => $field->getName(),
                'type' => $field->getType(),
                'label' => $field->getLabel(),
                'required' => $field->isRequired(),
                'default' => $field->getDefault(),
                'options' => $field->getOptions(),
            ];
        }

        return $fields;
    }

    // ========== 权限集成 ==========

    /**
     * 检查操作权限
     *
     * @param string $operation 操作类型 (create, read, update, delete)
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return bool 是否有权限
     */
    public function checkPermission(string $operation, string $collectionName, array $options = []): bool
    {
        // 尝试使用 Casbin 权限检查
        if (class_exists('\app\middleware\Permission')) {
            try {
                $userId = TenantContext::getUserId();
                $tenantId = TenantContext::getTenantId();

                // 如果没有完整的上下文，跳过权限检查（默认允许）
                if ($userId === null || $tenantId === null) {
                    return true;
                }

                // 构建权限码
                $permissionCode = "lowcode.{$collectionName}.{$operation}";

                // 使用 Casbin 检查
                if (function_exists('enforce')) {
                    return enforce($userId, $tenantId, $permissionCode, $operation);
                }

                return true; // 如果没有权限系统，默认允许
            } catch (\Throwable $e) {
                return true; // 权限检查失败时默认允许
            }
        }

        return true;
    }

    // ========== 辅助方法 ==========

    /**
     * 应用查询条件
     *
     * @param \think\db\Query $query 查询对象
     * @param array $conditions 条件数组
     * @return void
     */
    private function applyConditions($query, array $conditions): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'eq';
            $value = $condition['value'] ?? null;
            $logic = $condition['logic'] ?? 'and';

            if ($field === null) {
                continue;
            }

            $sqlOperator = self::CONDITION_OPERATORS[$operator] ?? '=';

            $method = $logic === 'or' ? 'whereOr' : 'where';

            switch ($operator) {
                case 'in':
                    $query->{$method}($field, 'in', (array) $value);
                    break;
                case 'nin':
                    $query->{$method}($field, 'not in', (array) $value);
                    break;
                case 'between':
                    $query->{$method}($field, 'between', $value);
                    break;
                case 'null':
                    $query->{$method}($field, 'null');
                    break;
                case 'notnull':
                    $query->{$method}($field, 'not null');
                    break;
                case 'like':
                    $query->{$method}($field, 'like', $value);
                    break;
                default:
                    $query->{$method}($field, $sqlOperator, $value);
            }
        }
    }

    /**
     * 解析表名
     *
     * @param string $collectionName Collection 名称
     * @param int $tenantId 租户 ID
     * @param array $options 选项
     * @return string 表名
     */
    private function resolveTableName(string $collectionName, int $tenantId, array $options = []): string
    {
        // 如果直接指定了表名，使用指定的
        if (!empty($options['table_name'])) {
            return $options['table_name'];
        }

        // 尝试通过 CollectionManager 获取表名
        try {
            $collection = $this->getCollectionManager()->get($collectionName, $tenantId);
            if ($collection !== null) {
                return $collection->getTableName();
            }
        } catch (\Throwable $e) {
            // 静默处理
        }

        // 回退到默认命名约定
        return 'lc_' . $collectionName;
    }

    /**
     * 要求租户上下文
     *
     * @param string $operation 操作名称
     * @return int 租户 ID
     * @throws TenantIsolationException
     */
    private function requireTenantContext(string $operation): int
    {
        return TenantIsolationGuard::requireTenantContext($operation);
    }

    /**
     * 记录操作日志
     *
     * @param string $operation 操作类型
     * @param string $collection Collection 名称
     * @param array $details 详细信息
     * @return void
     */
    private function logOperation(string $operation, string $collection, array $details = []): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        $this->operationLog[] = [
            'operation' => $operation,
            'collection' => $collection,
            'details' => $details,
            'tenant_id' => TenantContext::getTenantId(),
            'user_id' => TenantContext::getUserId(),
            'timestamp' => microtime(true),
        ];

        // 限制日志条目数
        if (count($this->operationLog) > $this->maxLogEntries) {
            array_shift($this->operationLog);
        }
    }

    /**
     * 清理敏感数据用于日志
     *
     * @param array $data 数据
     * @return array 清理后的数据
     */
    private function sanitizeDataForLog(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'credit_card'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields, true)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeDataForLog($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    // ========== 日志和配置 ==========

    /**
     * 获取操作日志
     *
     * @return array
     */
    public function getOperationLog(): array
    {
        return $this->operationLog;
    }

    /**
     * 清空操作日志
     *
     * @return void
     */
    public function clearOperationLog(): void
    {
        $this->operationLog = [];
    }

    /**
     * 设置是否启用日志
     *
     * @param bool $enabled 是否启用
     * @return void
     */
    public function setLoggingEnabled(bool $enabled): void
    {
        $this->loggingEnabled = $enabled;
    }

    /**
     * 设置最大日志条目数
     *
     * @param int $max 最大数量
     * @return void
     */
    public function setMaxLogEntries(int $max): void
    {
        $this->maxLogEntries = max(1, $max);
    }
}
