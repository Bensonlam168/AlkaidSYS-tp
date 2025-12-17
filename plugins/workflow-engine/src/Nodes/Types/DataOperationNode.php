<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Data Operation Node | 数据操作节点
 *
 * A service task node that performs CRUD operations on data collections.
 * Supports create, read, update, delete, batch operations, and aggregations.
 * 对数据集合执行 CRUD 操作的服务任务节点。
 * 支持创建、读取、更新、删除、批量操作和聚合。
 *
 * Configuration options:
 * - operation: string - Operation type (create, read, update, delete, batch, aggregate)
 * - collection: string - Target collection/table name
 * - data: array - Data for create/update operations
 * - conditions: array - Query conditions for read/update/delete
 * - fields: array - Fields to retrieve (for read operations)
 * - order: array - Sort order
 * - limit: int - Maximum records
 * - offset: int - Pagination offset
 * - aggregation: array - Aggregation pipeline
 * - output_variable: string - Variable to store result
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class DataOperationNode extends AbstractNode
{
    /**
     * Operation types | 操作类型
     */
    public const OP_CREATE = 'create';
    public const OP_READ = 'read';
    public const OP_UPDATE = 'update';
    public const OP_DELETE = 'delete';
    public const OP_BATCH_CREATE = 'batch_create';
    public const OP_BATCH_UPDATE = 'batch_update';
    public const OP_BATCH_DELETE = 'batch_delete';
    public const OP_AGGREGATE = 'aggregate';
    public const OP_COUNT = 'count';
    public const OP_EXISTS = 'exists';

    /**
     * Condition operators | 条件运算符
     */
    public const COND_EQ = 'eq';           // Equal
    public const COND_NEQ = 'neq';         // Not equal
    public const COND_GT = 'gt';           // Greater than
    public const COND_GTE = 'gte';         // Greater than or equal
    public const COND_LT = 'lt';           // Less than
    public const COND_LTE = 'lte';         // Less than or equal
    public const COND_IN = 'in';           // In array
    public const COND_NIN = 'nin';         // Not in array
    public const COND_LIKE = 'like';       // Like pattern
    public const COND_BETWEEN = 'between'; // Between range
    public const COND_NULL = 'null';       // Is null
    public const COND_NOTNULL = 'notnull'; // Is not null

    /**
     * Aggregation functions | 聚合函数
     */
    public const AGG_SUM = 'sum';
    public const AGG_AVG = 'avg';
    public const AGG_MIN = 'min';
    public const AGG_MAX = 'max';
    public const AGG_COUNT = 'count';
    public const AGG_GROUP = 'group';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $config['sub_type'] = $config['sub_type'] ?? NodeInterface::TASK_SERVICE;
        parent::__construct($id, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_TASK;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? NodeInterface::TASK_SERVICE;
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Execute the data operation node | 执行数据操作节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $operation = $this->getConfigValue('operation', self::OP_READ);
        $collection = $this->resolveCollection($context);

        if (empty($collection)) {
            return NodeResult::failed('集合名称不能为空', 400);
        }

        try {
            // Get tenant ID for multi-tenant isolation
            $tenantId = $context->getTenantId();

            // Execute operation
            $result = match ($operation) {
                self::OP_CREATE => $this->executeCreate($collection, $context, $tenantId),
                self::OP_READ => $this->executeRead($collection, $context, $tenantId),
                self::OP_UPDATE => $this->executeUpdate($collection, $context, $tenantId),
                self::OP_DELETE => $this->executeDelete($collection, $context, $tenantId),
                self::OP_BATCH_CREATE => $this->executeBatchCreate($collection, $context, $tenantId),
                self::OP_BATCH_UPDATE => $this->executeBatchUpdate($collection, $context, $tenantId),
                self::OP_BATCH_DELETE => $this->executeBatchDelete($collection, $context, $tenantId),
                self::OP_AGGREGATE => $this->executeAggregate($collection, $context, $tenantId),
                self::OP_COUNT => $this->executeCount($collection, $context, $tenantId),
                self::OP_EXISTS => $this->executeExists($collection, $context, $tenantId),
                default => throw new \InvalidArgumentException("不支持的操作类型: {$operation}"),
            };

            // Store result in context
            $outputVar = $this->getConfigValue('output_variable', "data_result_{$this->id}");
            $context->setVariable($outputVar, $result['data'] ?? $result);

            return NodeResult::success([
                'operation' => $operation,
                'collection' => $collection,
                'affected_rows' => $result['affected_rows'] ?? 0,
                'data' => $result['data'] ?? null,
                'message' => $result['message'] ?? '数据操作成功',
            ], $this->getDefaultNextNodes());
        } catch (\Throwable $e) {
            $errorHandling = $this->getConfigValue('error_handling', []);
            $onError = $errorHandling['mode'] ?? 'fail';

            if ($onError === 'continue') {
                $context->setVariable("data_error_{$this->id}", [
                    'operation' => $operation,
                    'collection' => $collection,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                return NodeResult::success([
                    'operation' => $operation,
                    'collection' => $collection,
                    'error' => true,
                    'error_message' => $e->getMessage(),
                    'message' => '数据操作失败但继续执行',
                ], $this->getDefaultNextNodes());
            }

            return NodeResult::failed(
                "数据操作失败: {$e->getMessage()}",
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Resolve collection name | 解析集合名称
     *
     * @param ExecutionContext $context
     * @return string
     */
    protected function resolveCollection(ExecutionContext $context): string
    {
        $collection = $this->getConfigValue('collection', '');

        // Support variable reference
        if (str_starts_with($collection, '${')) {
            $varName = substr($collection, 2, -1);
            return (string)$context->getVariable($varName);
        }

        return $collection;
    }

    /**
     * Resolve data values | 解析数据值
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveData(ExecutionContext $context): array
    {
        $data = $this->getConfigValue('data', []);
        return $this->resolveArrayValues($data, $context);
    }

    /**
     * Resolve array values recursively | 递归解析数组值
     *
     * @param array $array
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveArrayValues(array $array, ExecutionContext $context): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->resolveArrayValues($value, $context);
            } elseif (is_string($value) && str_starts_with($value, '${')) {
                $varName = substr($value, 2, -1);
                $result[$key] = $context->getVariable($varName);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Resolve conditions | 解析查询条件
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveConditions(ExecutionContext $context): array
    {
        $conditions = $this->getConfigValue('conditions', []);
        return $this->resolveArrayValues($conditions, $context);
    }

    /**
     * Execute create operation | 执行创建操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeCreate(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $data = $this->resolveData($context);

        // Add tenant_id for multi-tenant isolation
        if ($tenantId !== null) {
            $data['tenant_id'] = $tenantId;
        }

        // Add timestamps
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        // In real implementation, use repository or ORM
        // This is a mock implementation
        $insertId = $this->mockInsert($collection, $data);

        return [
            'data' => array_merge(['id' => $insertId], $data),
            'affected_rows' => 1,
            'message' => '数据创建成功',
        ];
    }

    /**
     * Execute read operation | 执行读取操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeRead(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $conditions = $this->resolveConditions($context);
        $fields = $this->getConfigValue('fields', ['*']);
        $order = $this->getConfigValue('order', []);
        $limit = $this->getConfigValue('limit', 100);
        $offset = $this->getConfigValue('offset', 0);

        // Add tenant_id condition for multi-tenant isolation
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // Build query
        $query = $this->buildQuery($collection, $conditions, $fields, $order, $limit, $offset);

        // In real implementation, execute query
        // This is a mock implementation
        $data = $this->mockQuery($query);

        return [
            'data' => $data,
            'affected_rows' => count($data),
            'message' => '数据查询成功',
        ];
    }

    /**
     * Execute update operation | 执行更新操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeUpdate(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $data = $this->resolveData($context);
        $conditions = $this->resolveConditions($context);

        // Add tenant_id condition for multi-tenant isolation
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // Add updated timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        // In real implementation, execute update
        // This is a mock implementation
        $affectedRows = $this->mockUpdate($collection, $data, $conditions);

        return [
            'data' => $data,
            'affected_rows' => $affectedRows,
            'message' => "已更新 {$affectedRows} 条记录",
        ];
    }

    /**
     * Execute delete operation | 执行删除操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeDelete(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $conditions = $this->resolveConditions($context);

        // Add tenant_id condition for multi-tenant isolation
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // Check if soft delete is enabled
        $softDelete = $this->getConfigValue('soft_delete', true);

        if ($softDelete) {
            // Soft delete: update deleted_at timestamp
            $affectedRows = $this->mockUpdate($collection, [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], $conditions);
        } else {
            // Hard delete
            $affectedRows = $this->mockDelete($collection, $conditions);
        }

        return [
            'affected_rows' => $affectedRows,
            'message' => "已删除 {$affectedRows} 条记录",
        ];
    }

    /**
     * Execute batch create operation | 执行批量创建操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeBatchCreate(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $records = $this->getConfigValue('records', []);
        $records = $this->resolveArrayValues($records, $context);

        $now = date('Y-m-d H:i:s');
        $insertedIds = [];

        foreach ($records as $record) {
            // Add tenant_id for multi-tenant isolation
            if ($tenantId !== null) {
                $record['tenant_id'] = $tenantId;
            }

            // Add timestamps
            $record['created_at'] = $now;
            $record['updated_at'] = $now;

            $insertId = $this->mockInsert($collection, $record);
            $insertedIds[] = $insertId;
        }

        return [
            'data' => $insertedIds,
            'affected_rows' => count($insertedIds),
            'message' => "已创建 " . count($insertedIds) . " 条记录",
        ];
    }

    /**
     * Execute batch update operation | 执行批量更新操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeBatchUpdate(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $updates = $this->getConfigValue('updates', []);
        $updates = $this->resolveArrayValues($updates, $context);

        $totalAffected = 0;

        foreach ($updates as $update) {
            $data = $update['data'] ?? [];
            $conditions = $update['conditions'] ?? [];

            // Add tenant_id condition
            if ($tenantId !== null) {
                $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
            }

            $data['updated_at'] = date('Y-m-d H:i:s');
            $totalAffected += $this->mockUpdate($collection, $data, $conditions);
        }

        return [
            'affected_rows' => $totalAffected,
            'message' => "已更新 {$totalAffected} 条记录",
        ];
    }

    /**
     * Execute batch delete operation | 执行批量删除操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeBatchDelete(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $ids = $this->getConfigValue('ids', []);
        $ids = $this->resolveArrayValues($ids, $context);

        $conditions = [
            'id' => ['operator' => self::COND_IN, 'value' => $ids],
        ];

        // Add tenant_id condition
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        $softDelete = $this->getConfigValue('soft_delete', true);

        if ($softDelete) {
            $affectedRows = $this->mockUpdate($collection, [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], $conditions);
        } else {
            $affectedRows = $this->mockDelete($collection, $conditions);
        }

        return [
            'affected_rows' => $affectedRows,
            'message' => "已删除 {$affectedRows} 条记录",
        ];
    }

    /**
     * Execute aggregate operation | 执行聚合操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeAggregate(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $pipeline = $this->getConfigValue('aggregation', []);
        $conditions = $this->resolveConditions($context);

        // Add tenant_id condition
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // In real implementation, execute aggregation
        // This is a mock implementation
        $result = $this->mockAggregate($collection, $pipeline, $conditions);

        return [
            'data' => $result,
            'message' => '聚合操作成功',
        ];
    }

    /**
     * Execute count operation | 执行计数操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeCount(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $conditions = $this->resolveConditions($context);

        // Add tenant_id condition
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // Mock count
        $count = $this->mockCount($collection, $conditions);

        return [
            'data' => $count,
            'message' => "查询到 {$count} 条记录",
        ];
    }

    /**
     * Execute exists operation | 执行存在检查操作
     *
     * @param string $collection
     * @param ExecutionContext $context
     * @param int|null $tenantId
     * @return array
     */
    protected function executeExists(string $collection, ExecutionContext $context, ?int $tenantId): array
    {
        $conditions = $this->resolveConditions($context);

        // Add tenant_id condition
        if ($tenantId !== null) {
            $conditions['tenant_id'] = ['operator' => self::COND_EQ, 'value' => $tenantId];
        }

        // Mock exists check
        $exists = $this->mockCount($collection, $conditions) > 0;

        return [
            'data' => $exists,
            'message' => $exists ? '记录存在' : '记录不存在',
        ];
    }

    /**
     * Build query (mock) | 构建查询（模拟）
     *
     * @param string $collection
     * @param array $conditions
     * @param array $fields
     * @param array $order
     * @param int $limit
     * @param int $offset
     * @return array
     */
    protected function buildQuery(
        string $collection,
        array $conditions,
        array $fields,
        array $order,
        int $limit,
        int $offset
    ): array {
        return [
            'collection' => $collection,
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Mock insert operation | 模拟插入操作
     *
     * @param string $collection
     * @param array $data
     * @return int
     */
    protected function mockInsert(string $collection, array $data): int
    {
        // In real implementation, would use database
        return random_int(1, 999999);
    }

    /**
     * Mock query operation | 模拟查询操作
     *
     * @param array $query
     * @return array
     */
    protected function mockQuery(array $query): array
    {
        // In real implementation, would execute database query
        return [];
    }

    /**
     * Mock update operation | 模拟更新操作
     *
     * @param string $collection
     * @param array $data
     * @param array $conditions
     * @return int
     */
    protected function mockUpdate(string $collection, array $data, array $conditions): int
    {
        // In real implementation, would use database
        return 1;
    }

    /**
     * Mock delete operation | 模拟删除操作
     *
     * @param string $collection
     * @param array $conditions
     * @return int
     */
    protected function mockDelete(string $collection, array $conditions): int
    {
        // In real implementation, would use database
        return 1;
    }

    /**
     * Mock aggregate operation | 模拟聚合操作
     *
     * @param string $collection
     * @param array $pipeline
     * @param array $conditions
     * @return array
     */
    protected function mockAggregate(string $collection, array $pipeline, array $conditions): array
    {
        // In real implementation, would execute aggregation
        return [];
    }

    /**
     * Mock count operation | 模拟计数操作
     *
     * @param string $collection
     * @param array $conditions
     * @return int
     */
    protected function mockCount(string $collection, array $conditions): int
    {
        // In real implementation, would execute count query
        return 0;
    }

    /**
     * Get configuration schema | 获取配置 Schema
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => [
                        self::OP_CREATE,
                        self::OP_READ,
                        self::OP_UPDATE,
                        self::OP_DELETE,
                        self::OP_BATCH_CREATE,
                        self::OP_BATCH_UPDATE,
                        self::OP_BATCH_DELETE,
                        self::OP_AGGREGATE,
                        self::OP_COUNT,
                        self::OP_EXISTS,
                    ],
                    'default' => self::OP_READ,
                    'description' => '操作类型',
                ],
                'collection' => [
                    'type' => 'string',
                    'description' => '目标集合/表名',
                ],
                'data' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => '数据（用于创建/更新）',
                ],
                'records' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => '批量创建的记录列表',
                ],
                'conditions' => [
                    'type' => 'object',
                    'description' => '查询条件',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['*'],
                    'description' => '查询字段',
                ],
                'order' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'description' => '排序规则',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10000,
                    'default' => 100,
                    'description' => '最大记录数',
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => '分页偏移量',
                ],
                'aggregation' => [
                    'type' => 'array',
                    'description' => '聚合管道',
                ],
                'output_variable' => [
                    'type' => 'string',
                    'description' => '结果存储变量名',
                ],
                'soft_delete' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => '是否使用软删除',
                ],
                'error_handling' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['fail', 'continue'],
                            'default' => 'fail',
                        ],
                    ],
                    'description' => '错误处理配置',
                ],
            ],
            'required' => ['operation', 'collection'],
        ];
    }

    /**
     * Validate configuration | 验证配置
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config = []): bool
    {
        $configToValidate = !empty($config) ? $config : $this->config;

        // Collection is required
        if (empty($configToValidate['collection'])) {
            return false;
        }

        // Validate operation
        $operation = $configToValidate['operation'] ?? self::OP_READ;
        $validOperations = [
            self::OP_CREATE,
            self::OP_READ,
            self::OP_UPDATE,
            self::OP_DELETE,
            self::OP_BATCH_CREATE,
            self::OP_BATCH_UPDATE,
            self::OP_BATCH_DELETE,
            self::OP_AGGREGATE,
            self::OP_COUNT,
            self::OP_EXISTS,
        ];

        if (!in_array($operation, $validOperations, true)) {
            return false;
        }

        // Data is required for create/update operations
        if (in_array($operation, [self::OP_CREATE, self::OP_UPDATE], true)) {
            if (empty($configToValidate['data'])) {
                return false;
            }
        }

        // Records are required for batch create
        if ($operation === self::OP_BATCH_CREATE) {
            if (empty($configToValidate['records'])) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
