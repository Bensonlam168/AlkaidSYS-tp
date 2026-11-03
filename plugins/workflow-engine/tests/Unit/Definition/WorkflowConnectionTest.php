<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;
use Alkaid\Workflow\Engine\Definition\WorkflowConnection;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowConnection 单元测试
 */
class WorkflowConnectionTest extends TestCase
{
    private WorkflowConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new WorkflowConnection('node_001', 'node_002', [
            'id' => 'conn_001',
            'name' => 'test_connection',
            'label' => '测试连接',
        ]);
    }

    /**
     * 测试连接基本属性
     */
    public function testConnectionProperties(): void
    {
        $this->assertEquals('conn_001', $this->connection->getId());
        $this->assertEquals('test_connection', $this->connection->getName());
        $this->assertEquals('node_001', $this->connection->getSourceNodeId());
        $this->assertEquals('node_002', $this->connection->getTargetNodeId());
        $this->assertEquals('测试连接', $this->connection->getLabel());
    }

    /**
     * 测试默认连接类型
     */
    public function testDefaultConnectionType(): void
    {
        $this->assertEquals(WorkflowConnectionInterface::TYPE_SEQUENCE, $this->connection->getType());
    }

    /**
     * 测试条件连接
     */
    public function testConditionalConnection(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002', [
            'condition' => '$amount > 1000',
        ]);

        $this->assertEquals(WorkflowConnectionInterface::TYPE_CONDITIONAL, $connection->getType());
        $this->assertEquals('$amount > 1000', $connection->getCondition());
    }

    /**
     * 测试设置条件自动更改类型
     */
    public function testSetConditionChangesType(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002');
        $this->assertEquals(WorkflowConnectionInterface::TYPE_SEQUENCE, $connection->getType());

        $connection->setCondition('$status == "approved"');
        $this->assertEquals(WorkflowConnectionInterface::TYPE_CONDITIONAL, $connection->getType());
        $this->assertEquals('$status == "approved"', $connection->getCondition());
    }

    /**
     * 测试默认流连接
     */
    public function testDefaultFlowConnection(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002', [
            'default' => true,
        ]);

        $this->assertEquals(WorkflowConnectionInterface::TYPE_DEFAULT, $connection->getType());
        $this->assertTrue($connection->isDefault());
    }

    /**
     * 测试设置默认状态
     */
    public function testSetDefault(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002');
        $connection->setDefault(true);

        $this->assertTrue($connection->isDefault());
        $this->assertEquals(WorkflowConnectionInterface::TYPE_DEFAULT, $connection->getType());
    }

    /**
     * 测试优先级
     */
    public function testPriority(): void
    {
        $this->assertEquals(0, $this->connection->getPriority());

        $this->connection->setPriority(10);
        $this->assertEquals(10, $this->connection->getPriority());
    }

    /**
     * 测试标签设置
     */
    public function testLabel(): void
    {
        $this->connection->setLabel('新标签');
        $this->assertEquals('新标签', $this->connection->getLabel());

        $this->connection->setLabel(null);
        $this->assertNull($this->connection->getLabel());
    }

    /**
     * 测试元数据
     */
    public function testMetadata(): void
    {
        $this->connection->setMetadata(['color' => 'blue', 'weight' => 5]);
        $metadata = $this->connection->getMetadata();

        $this->assertEquals('blue', $metadata['color']);
        $this->assertEquals(5, $metadata['weight']);

        // 测试单个元数据
        $this->assertEquals('blue', $this->connection->getMeta('color'));
        $this->assertNull($this->connection->getMeta('nonexistent'));
        $this->assertEquals('default', $this->connection->getMeta('nonexistent', 'default'));

        // 设置单个元数据
        $this->connection->setMeta('style', 'dashed');
        $this->assertEquals('dashed', $this->connection->getMeta('style'));
    }

    /**
     * 测试工厂方法 - 顺序流
     */
    public function testSequenceFactory(): void
    {
        $connection = WorkflowConnection::sequence('node_a', 'node_b', '顺序连接');

        $this->assertEquals('node_a', $connection->getSourceNodeId());
        $this->assertEquals('node_b', $connection->getTargetNodeId());
        $this->assertEquals(WorkflowConnectionInterface::TYPE_SEQUENCE, $connection->getType());
        $this->assertEquals('顺序连接', $connection->getLabel());
    }

    /**
     * 测试工厂方法 - 条件流
     */
    public function testConditionalFactory(): void
    {
        $connection = WorkflowConnection::conditional('node_a', 'node_b', '$value > 100', '高值分支');

        $this->assertEquals('node_a', $connection->getSourceNodeId());
        $this->assertEquals('node_b', $connection->getTargetNodeId());
        $this->assertEquals(WorkflowConnectionInterface::TYPE_CONDITIONAL, $connection->getType());
        $this->assertEquals('$value > 100', $connection->getCondition());
        $this->assertEquals('高值分支', $connection->getLabel());
    }

    /**
     * 测试工厂方法 - 默认流
     */
    public function testDefaultFlowFactory(): void
    {
        $connection = WorkflowConnection::defaultFlow('node_a', 'node_b');

        $this->assertEquals('node_a', $connection->getSourceNodeId());
        $this->assertEquals('node_b', $connection->getTargetNodeId());
        $this->assertEquals(WorkflowConnectionInterface::TYPE_DEFAULT, $connection->getType());
        $this->assertTrue($connection->isDefault());
        $this->assertEquals('默认', $connection->getLabel());
    }

    /**
     * 测试序列化为数组
     */
    public function testToArray(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002', [
            'id' => 'conn_test',
            'name' => 'test',
            'condition' => '$x > 10',
            'priority' => 5,
            'label' => '测试',
            'metadata' => ['key' => 'value'],
        ]);

        $array = $connection->toArray();

        $this->assertEquals('conn_test', $array['id']);
        $this->assertEquals('test', $array['name']);
        $this->assertEquals('node_001', $array['source_node_id']);
        $this->assertEquals('node_002', $array['target_node_id']);
        $this->assertEquals('$x > 10', $array['condition']);
        $this->assertEquals(5, $array['priority']);
        $this->assertEquals('测试', $array['label']);
        $this->assertEquals(['key' => 'value'], $array['metadata']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'conn_from_array',
            'name' => 'from_array_connection',
            'source_node_id' => 'node_a',
            'target_node_id' => 'node_b',
            'type' => WorkflowConnectionInterface::TYPE_CONDITIONAL,
            'condition' => '$status == "active"',
            'priority' => 3,
            'default' => false,
            'label' => '活跃状态',
            'metadata' => ['source' => 'array'],
        ];

        $connection = WorkflowConnection::fromArray($data);

        $this->assertEquals('conn_from_array', $connection->getId());
        $this->assertEquals('from_array_connection', $connection->getName());
        $this->assertEquals('node_a', $connection->getSourceNodeId());
        $this->assertEquals('node_b', $connection->getTargetNodeId());
        $this->assertEquals('$status == "active"', $connection->getCondition());
        $this->assertEquals(3, $connection->getPriority());
        $this->assertEquals('活跃状态', $connection->getLabel());
    }

    /**
     * 测试从数组创建（驼峰命名兼容）
     */
    public function testFromArrayCamelCase(): void
    {
        $data = [
            'sourceNodeId' => 'node_x',
            'targetNodeId' => 'node_y',
        ];

        $connection = WorkflowConnection::fromArray($data);

        $this->assertEquals('node_x', $connection->getSourceNodeId());
        $this->assertEquals('node_y', $connection->getTargetNodeId());
    }

    /**
     * 测试ID自动生成
     */
    public function testAutoGeneratedId(): void
    {
        $connection = new WorkflowConnection('node_001', 'node_002');
        $id = $connection->getId();

        $this->assertStringStartsWith('conn_', $id);
        $this->assertEquals(17, strlen($id)); // conn_ + 12 chars
    }

    /**
     * 测试名称设置
     */
    public function testSetName(): void
    {
        $this->connection->setName('updated_name');
        $this->assertEquals('updated_name', $this->connection->getName());
    }

    /**
     * 测试类型设置
     */
    public function testSetType(): void
    {
        $this->connection->setType(WorkflowConnectionInterface::TYPE_MESSAGE);
        $this->assertEquals(WorkflowConnectionInterface::TYPE_MESSAGE, $this->connection->getType());
    }
}
