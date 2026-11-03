<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\DataOperationNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * DataOperationNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class DataOperationNodeTest extends TestCase
{
    protected DataOperationNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = new DataOperationNode('dataop_001', [
            'name' => '数据操作节点',
            'operation' => DataOperationNode::OP_READ,
            'collection' => 'users',
            'conditions' => [
                'status' => ['operator' => DataOperationNode::COND_EQ, 'value' => 'active'],
            ],
            'fields' => ['id', 'name', 'email'],
            'limit' => 10,
        ]);

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_dataop_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $this->assertEquals('dataop_001', $this->node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
        $this->assertEquals(NodeInterface::TASK_SERVICE, $this->node->getSubType());
    }

    /**
     * 测试读取操作
     */
    public function testReadOperation(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(DataOperationNode::OP_READ, $result->getData()['operation']);
        $this->assertEquals('users', $result->getData()['collection']);
    }

    /**
     * 测试创建操作
     */
    public function testCreateOperation(): void
    {
        $node = new DataOperationNode('create_op', [
            'operation' => DataOperationNode::OP_CREATE,
            'collection' => 'orders',
            'data' => [
                'customer_id' => 123,
                'total' => 1500.00,
                'status' => 'pending',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(1, $result->getData()['affected_rows']);
        $this->assertArrayHasKey('id', $result->getData()['data']);
    }

    /**
     * 测试更新操作
     */
    public function testUpdateOperation(): void
    {
        $node = new DataOperationNode('update_op', [
            'operation' => DataOperationNode::OP_UPDATE,
            'collection' => 'orders',
            'data' => [
                'status' => 'completed',
            ],
            'conditions' => [
                'id' => ['operator' => DataOperationNode::COND_EQ, 'value' => 1],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertGreaterThanOrEqual(1, $result->getData()['affected_rows']);
    }

    /**
     * 测试删除操作 - 软删除
     */
    public function testSoftDeleteOperation(): void
    {
        $node = new DataOperationNode('soft_delete', [
            'operation' => DataOperationNode::OP_DELETE,
            'collection' => 'orders',
            'conditions' => [
                'id' => ['operator' => DataOperationNode::COND_EQ, 'value' => 1],
            ],
            'soft_delete' => true,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试删除操作 - 硬删除
     */
    public function testHardDeleteOperation(): void
    {
        $node = new DataOperationNode('hard_delete', [
            'operation' => DataOperationNode::OP_DELETE,
            'collection' => 'temp_records',
            'conditions' => [
                'id' => ['operator' => DataOperationNode::COND_EQ, 'value' => 1],
            ],
            'soft_delete' => false,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试批量创建
     */
    public function testBatchCreateOperation(): void
    {
        $node = new DataOperationNode('batch_create', [
            'operation' => DataOperationNode::OP_BATCH_CREATE,
            'collection' => 'items',
            'records' => [
                ['name' => 'Item 1', 'price' => 100],
                ['name' => 'Item 2', 'price' => 200],
                ['name' => 'Item 3', 'price' => 300],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(3, $result->getData()['affected_rows']);
    }

    /**
     * 测试批量删除
     */
    public function testBatchDeleteOperation(): void
    {
        $node = new DataOperationNode('batch_delete', [
            'operation' => DataOperationNode::OP_BATCH_DELETE,
            'collection' => 'items',
            'ids' => [1, 2, 3],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试计数操作
     */
    public function testCountOperation(): void
    {
        $node = new DataOperationNode('count_op', [
            'operation' => DataOperationNode::OP_COUNT,
            'collection' => 'users',
            'conditions' => [
                'status' => ['operator' => DataOperationNode::COND_EQ, 'value' => 'active'],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertIsInt($result->getData()['data']);
    }

    /**
     * 测试存在检查操作
     */
    public function testExistsOperation(): void
    {
        $node = new DataOperationNode('exists_op', [
            'operation' => DataOperationNode::OP_EXISTS,
            'collection' => 'users',
            'conditions' => [
                'email' => ['operator' => DataOperationNode::COND_EQ, 'value' => 'test@example.com'],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertIsBool($result->getData()['data']);
    }

    /**
     * 测试聚合操作
     */
    public function testAggregateOperation(): void
    {
        $node = new DataOperationNode('aggregate_op', [
            'operation' => DataOperationNode::OP_AGGREGATE,
            'collection' => 'orders',
            'aggregation' => [
                ['$group' => ['_id' => '$status', 'total' => ['$sum' => '$amount']]],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
    }

    /**
     * 测试变量解析
     */
    public function testVariableResolution(): void
    {
        $this->context->setVariable('target_collection', 'products');
        $this->context->setVariable('search_status', 'available');

        $node = new DataOperationNode('var_resolve', [
            'operation' => DataOperationNode::OP_READ,
            'collection' => '${target_collection}',
            'conditions' => [
                'status' => ['operator' => DataOperationNode::COND_EQ, 'value' => '${search_status}'],
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('products', $result->getData()['collection']);
    }

    /**
     * 测试数据变量解析
     */
    public function testDataVariableResolution(): void
    {
        $this->context->setVariable('user_id', 456);
        $this->context->setVariable('order_amount', 999.99);

        $node = new DataOperationNode('data_var_resolve', [
            'operation' => DataOperationNode::OP_CREATE,
            'collection' => 'orders',
            'data' => [
                'user_id' => '${user_id}',
                'amount' => '${order_amount}',
                'status' => 'new',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(456, $result->getData()['data']['user_id']);
        $this->assertEquals(999.99, $result->getData()['data']['amount']);
    }

    /**
     * 测试输出变量
     */
    public function testOutputVariable(): void
    {
        $node = new DataOperationNode('output_var', [
            'operation' => DataOperationNode::OP_READ,
            'collection' => 'users',
            'output_variable' => 'queried_users',
        ]);

        $node->execute($this->context);

        $this->assertNotNull($this->context->getVariable('queried_users'));
    }

    /**
     * 测试多租户隔离
     */
    public function testMultiTenantIsolation(): void
    {
        // 设置租户 ID
        $this->context->setTenantId(100);

        $node = new DataOperationNode('tenant_isolation', [
            'operation' => DataOperationNode::OP_CREATE,
            'collection' => 'items',
            'data' => [
                'name' => 'Test Item',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(100, $result->getData()['data']['tenant_id']);
    }

    /**
     * 测试空集合名称
     */
    public function testEmptyCollection(): void
    {
        $node = new DataOperationNode('empty_collection', [
            'operation' => DataOperationNode::OP_READ,
            'collection' => '',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试不支持的操作类型
     */
    public function testUnsupportedOperation(): void
    {
        $node = new DataOperationNode('unsupported_op', [
            'operation' => 'unsupported',
            'collection' => 'test',
        ]);

        // Force set config to bypass validation
        $reflection = new \ReflectionClass($node);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($node);
        $config['operation'] = 'unsupported';
        $property->setValue($node, $config);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试错误处理 - 继续模式
     */
    public function testErrorHandlingContinue(): void
    {
        $node = new DataOperationNode('error_continue', [
            'operation' => 'invalid_operation',
            'collection' => 'test',
            'error_handling' => [
                'mode' => 'continue',
            ],
        ]);

        // Force set config
        $reflection = new \ReflectionClass($node);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($node);
        $config['operation'] = 'invalid_operation';
        $property->setValue($node, $config);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertTrue($result->getData()['error']);
    }

    /**
     * 测试配置验证 - 有效配置
     */
    public function testValidConfigValidation(): void
    {
        $validConfig = [
            'operation' => DataOperationNode::OP_READ,
            'collection' => 'users',
        ];
        $this->assertTrue($this->node->validateConfig($validConfig));
    }

    /**
     * 测试配置验证 - 缺少集合
     */
    public function testInvalidConfigMissingCollection(): void
    {
        $invalidConfig = [
            'operation' => DataOperationNode::OP_READ,
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 创建操作缺少数据
     */
    public function testInvalidConfigCreateMissingData(): void
    {
        $invalidConfig = [
            'operation' => DataOperationNode::OP_CREATE,
            'collection' => 'users',
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 批量创建缺少记录
     */
    public function testInvalidConfigBatchCreateMissingRecords(): void
    {
        $invalidConfig = [
            'operation' => DataOperationNode::OP_BATCH_CREATE,
            'collection' => 'users',
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 无效操作类型
     */
    public function testInvalidConfigInvalidOperation(): void
    {
        $invalidConfig = [
            'operation' => 'invalid_operation',
            'collection' => 'users',
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('operation', $schema['properties']);
        $this->assertArrayHasKey('collection', $schema['properties']);
        $this->assertArrayHasKey('data', $schema['properties']);
        $this->assertArrayHasKey('conditions', $schema['properties']);
        $this->assertArrayHasKey('fields', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
        $this->assertArrayHasKey('offset', $schema['properties']);
    }

    /**
     * 测试分页参数
     */
    public function testPaginationParameters(): void
    {
        $node = new DataOperationNode('pagination', [
            'operation' => DataOperationNode::OP_READ,
            'collection' => 'users',
            'limit' => 20,
            'offset' => 40,
        ]);

        $config = $node->getConfig();
        $this->assertEquals(20, $config['limit']);
        $this->assertEquals(40, $config['offset']);
    }

    /**
     * 测试排序参数
     */
    public function testOrderParameters(): void
    {
        $node = new DataOperationNode('ordering', [
            'operation' => DataOperationNode::OP_READ,
            'collection' => 'users',
            'order' => [
                'created_at' => 'desc',
                'name' => 'asc',
            ],
        ]);

        $config = $node->getConfig();
        $this->assertEquals(['created_at' => 'desc', 'name' => 'asc'], $config['order']);
    }

    /**
     * 测试条件运算符
     */
    public function testConditionOperators(): void
    {
        $allOperators = [
            DataOperationNode::COND_EQ,
            DataOperationNode::COND_NEQ,
            DataOperationNode::COND_GT,
            DataOperationNode::COND_GTE,
            DataOperationNode::COND_LT,
            DataOperationNode::COND_LTE,
            DataOperationNode::COND_IN,
            DataOperationNode::COND_NIN,
            DataOperationNode::COND_LIKE,
            DataOperationNode::COND_BETWEEN,
            DataOperationNode::COND_NULL,
            DataOperationNode::COND_NOTNULL,
        ];

        $this->assertCount(12, $allOperators);

        foreach ($allOperators as $op) {
            $this->assertIsString($op);
        }
    }

    /**
     * 测试聚合函数常量
     */
    public function testAggregationFunctions(): void
    {
        $this->assertEquals('sum', DataOperationNode::AGG_SUM);
        $this->assertEquals('avg', DataOperationNode::AGG_AVG);
        $this->assertEquals('min', DataOperationNode::AGG_MIN);
        $this->assertEquals('max', DataOperationNode::AGG_MAX);
        $this->assertEquals('count', DataOperationNode::AGG_COUNT);
        $this->assertEquals('group', DataOperationNode::AGG_GROUP);
    }

    /**
     * 测试时间戳自动添加
     */
    public function testTimestampsAutoAdded(): void
    {
        $node = new DataOperationNode('timestamps', [
            'operation' => DataOperationNode::OP_CREATE,
            'collection' => 'posts',
            'data' => [
                'title' => 'Test Post',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertArrayHasKey('created_at', $result->getData()['data']);
        $this->assertArrayHasKey('updated_at', $result->getData()['data']);
    }
}
