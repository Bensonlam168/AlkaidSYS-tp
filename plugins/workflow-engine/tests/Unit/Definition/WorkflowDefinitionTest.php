<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Definition\WorkflowConnection;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowDefinition 单元测试
 */
class WorkflowDefinitionTest extends TestCase
{
    private WorkflowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->definition = new WorkflowDefinition('test_workflow', [
            'id' => 'wf_001',
            'display_name' => '测试工作流',
            'description' => '这是一个测试工作流',
            'tenant_id' => 100,
        ]);
    }

    /**
     * 测试工作流基本属性
     */
    public function testBasicProperties(): void
    {
        $this->assertEquals('wf_001', $this->definition->getId());
        $this->assertEquals('test_workflow', $this->definition->getName());
        $this->assertEquals('测试工作流', $this->definition->getDisplayName());
        $this->assertEquals('这是一个测试工作流', $this->definition->getDescription());
        $this->assertEquals(100, $this->definition->getTenantId());
    }

    /**
     * 测试默认状态和类型
     */
    public function testDefaultStatusAndType(): void
    {
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_DRAFT, $this->definition->getStatus());
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_BUSINESS, $this->definition->getType());
        $this->assertEquals(1, $this->definition->getVersion());
    }

    /**
     * 测试设置属性
     */
    public function testSetProperties(): void
    {
        $this->definition->setName('updated_workflow');
        $this->definition->setDisplayName('更新的工作流');
        $this->definition->setDescription('更新的描述');
        $this->definition->setType(WorkflowDefinitionInterface::TYPE_APPROVAL);
        $this->definition->setTenantId(200);

        $this->assertEquals('updated_workflow', $this->definition->getName());
        $this->assertEquals('更新的工作流', $this->definition->getDisplayName());
        $this->assertEquals('更新的描述', $this->definition->getDescription());
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $this->definition->getType());
        $this->assertEquals(200, $this->definition->getTenantId());
    }

    /**
     * 测试添加节点
     */
    public function testAddNode(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $this->definition->addNode($startNode);

        $this->assertTrue($this->definition->hasNode('start_001'));
        $this->assertNotNull($this->definition->getNode('start_001'));
        $this->assertCount(1, $this->definition->getNodes());
    }

    /**
     * 测试移除节点
     */
    public function testRemoveNode(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->connect('start_001', 'task_001');

        $this->definition->removeNode('task_001');

        $this->assertFalse($this->definition->hasNode('task_001'));
        // 移除节点时应该同时移除相关连接
        $this->assertEmpty($this->definition->getNodeOutputConnections('start_001'));
    }

    /**
     * 测试按名称获取节点
     */
    public function testGetNodeByName(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'my_start']);
        $this->definition->addNode($startNode);

        $node = $this->definition->getNodeByName('my_start');
        $this->assertNotNull($node);
        $this->assertEquals('start_001', $node->getId());

        $this->assertNull($this->definition->getNodeByName('nonexistent'));
    }

    /**
     * 测试按类型获取节点
     */
    public function testGetNodesByType(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode1 = new TaskNode('task_001', ['name' => 'task1']);
        $taskNode2 = new TaskNode('task_002', ['name' => 'task2']);
        $endNode = new EndNode('end_001', ['name' => 'end']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode1);
        $this->definition->addNode($taskNode2);
        $this->definition->addNode($endNode);

        $taskNodes = $this->definition->getTaskNodes();
        $this->assertCount(2, $taskNodes);
    }

    /**
     * 测试添加连接
     */
    public function testAddConnection(): void
    {
        $connection = new WorkflowConnection('node_a', 'node_b', ['id' => 'conn_001']);
        $this->definition->addConnection($connection);

        $this->assertNotNull($this->definition->getConnection('conn_001'));
        $this->assertCount(1, $this->definition->getConnections());
    }

    /**
     * 测试快捷连接方法
     */
    public function testConnect(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->connect('start_001', 'task_001');

        $connections = $this->definition->getConnections();
        $this->assertCount(1, $connections);

        $conn = array_values($connections)[0];
        $this->assertEquals('start_001', $conn->getSourceNodeId());
        $this->assertEquals('task_001', $conn->getTargetNodeId());
    }

    /**
     * 测试移除连接
     */
    public function testRemoveConnection(): void
    {
        $connection = new WorkflowConnection('node_a', 'node_b', ['id' => 'conn_001']);
        $this->definition->addConnection($connection);
        $this->definition->removeConnection('conn_001');

        $this->assertNull($this->definition->getConnection('conn_001'));
    }

    /**
     * 测试获取开始节点
     */
    public function testGetStartNode(): void
    {
        $this->assertNull($this->definition->getStartNode());

        $startNode = new StartNode('start_001', ['name' => 'start']);
        $this->definition->addNode($startNode);

        $start = $this->definition->getStartNode();
        $this->assertNotNull($start);
        $this->assertEquals('start_001', $start->getId());
    }

    /**
     * 测试获取结束节点
     */
    public function testGetEndNodes(): void
    {
        $this->assertEmpty($this->definition->getEndNodes());

        $endNode1 = new EndNode('end_001', ['name' => 'end1']);
        $endNode2 = new EndNode('end_002', ['name' => 'end2']);

        $this->definition->addNode($endNode1);
        $this->definition->addNode($endNode2);

        $endNodes = $this->definition->getEndNodes();
        $this->assertCount(2, $endNodes);
    }

    /**
     * 测试获取节点输出连接
     */
    public function testGetNodeOutputConnections(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode1 = new TaskNode('task_001', ['name' => 'task1']);
        $taskNode2 = new TaskNode('task_002', ['name' => 'task2']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode1);
        $this->definition->addNode($taskNode2);

        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('start_001', 'task_002');

        $outputConnections = $this->definition->getNodeOutputConnections('start_001');
        $this->assertCount(2, $outputConnections);
    }

    /**
     * 测试获取节点输入连接
     */
    public function testGetNodeInputConnections(): void
    {
        $taskNode1 = new TaskNode('task_001', ['name' => 'task1']);
        $taskNode2 = new TaskNode('task_002', ['name' => 'task2']);
        $endNode = new EndNode('end_001', ['name' => 'end']);

        $this->definition->addNode($taskNode1);
        $this->definition->addNode($taskNode2);
        $this->definition->addNode($endNode);

        $this->definition->connect('task_001', 'end_001');
        $this->definition->connect('task_002', 'end_001');

        $inputConnections = $this->definition->getNodeInputConnections('end_001');
        $this->assertCount(2, $inputConnections);
    }

    /**
     * 测试获取下一个节点
     */
    public function testGetNextNodes(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->connect('start_001', 'task_001');

        $nextNodes = $this->definition->getNextNodes('start_001');
        $this->assertCount(1, $nextNodes);
        $this->assertEquals('task_001', $nextNodes[0]->getId());
    }

    /**
     * 测试获取上一个节点
     */
    public function testGetPreviousNodes(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->connect('start_001', 'task_001');

        $previousNodes = $this->definition->getPreviousNodes('task_001');
        $this->assertCount(1, $previousNodes);
        $this->assertEquals('start_001', $previousNodes[0]->getId());
    }

    /**
     * 测试验证 - 缺少开始节点
     */
    public function testValidateMissingStartNode(): void
    {
        $taskNode = new TaskNode('task_001', ['name' => 'task']);
        $endNode = new EndNode('end_001', ['name' => 'end']);

        $this->definition->addNode($taskNode);
        $this->definition->addNode($endNode);
        $this->definition->connect('task_001', 'end_001');

        $errors = $this->definition->validate();
        $this->assertNotEmpty($errors);

        $errorCodes = array_column($errors, 'code');
        $this->assertContains('MISSING_START_NODE', $errorCodes);
    }

    /**
     * 测试验证 - 缺少结束节点
     */
    public function testValidateMissingEndNode(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->connect('start_001', 'task_001');

        $errors = $this->definition->validate();
        $this->assertNotEmpty($errors);

        $errorCodes = array_column($errors, 'code');
        $this->assertContains('MISSING_END_NODE', $errorCodes);
    }

    /**
     * 测试验证 - 有效工作流
     */
    public function testValidateValidWorkflow(): void
    {
        $this->createValidWorkflow();

        $errors = $this->definition->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($this->definition->isValid());
    }

    /**
     * 测试发布工作流
     */
    public function testPublish(): void
    {
        $this->createValidWorkflow();

        $this->definition->publish();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $this->definition->getStatus());
        $this->assertNotNull($this->definition->getPublishedAt());
        $this->assertTrue($this->definition->isPublished());
    }

    /**
     * 测试发布无效工作流抛出异常
     */
    public function testPublishInvalidWorkflowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition->publish();
    }

    /**
     * 测试归档工作流
     */
    public function testArchive(): void
    {
        $this->definition->archive();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_ARCHIVED, $this->definition->getStatus());
        $this->assertTrue($this->definition->isArchived());
    }

    /**
     * 测试禁用工作流
     */
    public function testDisable(): void
    {
        $this->definition->disable();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_DISABLED, $this->definition->getStatus());
        $this->assertTrue($this->definition->isDisabled());
    }

    /**
     * 测试版本管理
     */
    public function testVersionManagement(): void
    {
        $this->assertEquals(1, $this->definition->getVersion());

        $this->definition->incrementVersion();
        $this->assertEquals(2, $this->definition->getVersion());

        $this->definition->setVersion(5);
        $this->assertEquals(5, $this->definition->getVersion());
    }

    /**
     * 测试创建新版本
     */
    public function testCreateNewVersion(): void
    {
        $this->createValidWorkflow();
        $this->definition->setVersion(3);

        $newVersion = $this->definition->createNewVersion();

        $this->assertEquals(4, $newVersion->getVersion());
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_DRAFT, $newVersion->getStatus());
        $this->assertNotEquals($this->definition->getId(), $newVersion->getId());
    }

    /**
     * 测试克隆工作流
     */
    public function testClone(): void
    {
        $this->createValidWorkflow();

        $clone = $this->definition->clone('cloned_wf');

        $this->assertEquals('cloned_wf', $clone->getId());
        $this->assertEquals($this->definition->getName(), $clone->getName());
        $this->assertCount(count($this->definition->getNodes()), $clone->getNodes());
        $this->assertCount(count($this->definition->getConnections()), $clone->getConnections());
    }

    /**
     * 测试元数据管理
     */
    public function testMetadata(): void
    {
        $this->definition->setMetadata(['category' => 'finance', 'priority' => 'high']);

        $this->assertEquals('finance', $this->definition->getMeta('category'));
        $this->assertEquals('high', $this->definition->getMeta('priority'));
        $this->assertNull($this->definition->getMeta('nonexistent'));
        $this->assertEquals('default', $this->definition->getMeta('nonexistent', 'default'));

        $this->definition->setMeta('owner', 'admin');
        $this->assertEquals('admin', $this->definition->getMeta('owner'));
    }

    /**
     * 测试配置管理
     */
    public function testConfig(): void
    {
        $config = ['timeout' => 3600, 'retry' => 3];
        $this->definition->setConfig($config);

        $this->assertEquals($config, $this->definition->getConfig());
    }

    /**
     * 测试序列化为数组
     */
    public function testToArray(): void
    {
        $this->createValidWorkflow();
        $array = $this->definition->toArray();

        $this->assertEquals('wf_001', $array['id']);
        $this->assertEquals('test_workflow', $array['name']);
        $this->assertEquals('测试工作流', $array['display_name']);
        $this->assertIsArray($array['nodes']);
        $this->assertIsArray($array['connections']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'wf_from_array',
            'name' => 'from_array_workflow',
            'display_name' => '从数组创建的工作流',
            'description' => '测试描述',
            'type' => WorkflowDefinitionInterface::TYPE_AUTOMATION,
            'status' => WorkflowDefinitionInterface::STATUS_DRAFT,
            'version' => 2,
            'tenant_id' => 50,
            'metadata' => ['key' => 'value'],
        ];

        $definition = WorkflowDefinition::fromArray($data);

        $this->assertEquals('wf_from_array', $definition->getId());
        $this->assertEquals('from_array_workflow', $definition->getName());
        $this->assertEquals('从数组创建的工作流', $definition->getDisplayName());
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_AUTOMATION, $definition->getType());
        $this->assertEquals(2, $definition->getVersion());
        $this->assertEquals(50, $definition->getTenantId());
    }

    /**
     * 测试JSON序列化
     */
    public function testJsonSerialization(): void
    {
        $this->createValidWorkflow();

        $json = $this->definition->toJson();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('wf_001', $decoded['id']);
    }

    /**
     * 测试从JSON创建
     */
    public function testFromJson(): void
    {
        $json = json_encode([
            'id' => 'wf_from_json',
            'name' => 'json_workflow',
            'display_name' => '从JSON创建',
        ]);

        $definition = WorkflowDefinition::fromJson($json);
        $this->assertEquals('wf_from_json', $definition->getId());
        $this->assertEquals('json_workflow', $definition->getName());
    }

    /**
     * 测试无效JSON抛出异常
     */
    public function testFromJsonInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkflowDefinition::fromJson('invalid json');
    }

    /**
     * 测试ID自动生成
     */
    public function testAutoGeneratedId(): void
    {
        $definition = new WorkflowDefinition('auto_id_workflow');
        $id = $definition->getId();

        $this->assertStringStartsWith('wf_', $id);
    }

    /**
     * 测试状态检查方法
     */
    public function testStatusChecks(): void
    {
        $this->assertTrue($this->definition->isDraft());
        $this->assertFalse($this->definition->isPublished());
        $this->assertFalse($this->definition->isArchived());
        $this->assertFalse($this->definition->isDisabled());
    }

    /**
     * 创建有效的工作流用于测试
     */
    private function createValidWorkflow(): void
    {
        $startNode = new StartNode('start_001', ['name' => 'start']);
        $taskNode = new TaskNode('task_001', ['name' => 'task']);
        $endNode = new EndNode('end_001', ['name' => 'end']);

        $this->definition->addNode($startNode);
        $this->definition->addNode($taskNode);
        $this->definition->addNode($endNode);

        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'end_001');
    }
}
