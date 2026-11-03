<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Definition\WorkflowVersion;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowVersion 单元测试
 */
class WorkflowVersionTest extends TestCase
{
    private WorkflowVersion $version;
    private WorkflowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建一个测试工作流定义
        $this->definition = new WorkflowDefinition('test_workflow', [
            'id' => 'wf_001',
            'display_name' => '测试工作流',
            'description' => '工作流描述',
            'version' => 1,
            'tenant_id' => 100,
        ]);

        // 添加节点
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));
        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'end_001');

        // 创建版本
        $this->version = new WorkflowVersion('wf_001', 1, [
            'id' => 'ver_001',
            'tag' => 'v1.0',
            'description' => '初始版本',
            'tenant_id' => 100,
        ]);
    }

    /**
     * 测试版本基本属性
     */
    public function testBasicProperties(): void
    {
        $this->assertEquals('ver_001', $this->version->getId());
        $this->assertEquals('wf_001', $this->version->getWorkflowId());
        $this->assertEquals(1, $this->version->getVersion());
        $this->assertEquals('v1.0', $this->version->getTag());
        $this->assertEquals('初始版本', $this->version->getDescription());
        $this->assertEquals(100, $this->version->getTenantId());
    }

    /**
     * 测试默认状态
     */
    public function testDefaultStatus(): void
    {
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_DRAFT, $this->version->getStatus());
        $this->assertTrue($this->version->isDraft());
        $this->assertFalse($this->version->isPublished());
        $this->assertFalse($this->version->isArchived());
    }

    /**
     * 测试设置属性
     */
    public function testSetProperties(): void
    {
        $this->version->setTag('v1.1');
        $this->version->setDescription('更新的描述');
        $this->version->setTenantId(200);
        $this->version->setStatus(WorkflowDefinitionInterface::STATUS_PUBLISHED);

        $this->assertEquals('v1.1', $this->version->getTag());
        $this->assertEquals('更新的描述', $this->version->getDescription());
        $this->assertEquals(200, $this->version->getTenantId());
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $this->version->getStatus());
    }

    /**
     * 测试从工作流定义创建版本
     */
    public function testFromDefinition(): void
    {
        $version = WorkflowVersion::fromDefinition(
            $this->definition,
            '从定义创建的版本',
            'release-1.0'
        );

        $this->assertEquals('wf_001', $version->getWorkflowId());
        $this->assertEquals(1, $version->getVersion());
        $this->assertEquals('release-1.0', $version->getTag());
        $this->assertEquals('从定义创建的版本', $version->getDescription());
        $this->assertEquals(100, $version->getTenantId());

        // 检查快照
        $snapshot = $version->getSnapshot();
        $this->assertNotEmpty($snapshot);
        $this->assertEquals('wf_001', $snapshot['id']);
    }

    /**
     * 测试快照设置和获取
     */
    public function testSnapshot(): void
    {
        $snapshot = ['id' => 'wf_001', 'name' => 'test', 'nodes' => []];
        $this->version->setSnapshot($snapshot);

        $this->assertEquals($snapshot, $this->version->getSnapshot());
    }

    /**
     * 测试从快照恢复工作流定义
     */
    public function testRestoreDefinition(): void
    {
        $version = WorkflowVersion::fromDefinition($this->definition, '测试恢复');

        $restored = $version->restoreDefinition();

        $this->assertEquals('wf_001', $restored->getId());
        $this->assertEquals('test_workflow', $restored->getName());
        // 检查节点是否被恢复 - 注意：fromArray 需要 NodeRegistry 支持
    }

    /**
     * 测试变更记录
     */
    public function testChangelog(): void
    {
        $this->assertEmpty($this->version->getChangelog());

        $this->version->addChange('add', '添加了新节点', ['node_id' => 'task_002']);
        $this->version->addChange('modify', '修改了条件', ['connection_id' => 'conn_001']);

        $changelog = $this->version->getChangelog();
        $this->assertCount(2, $changelog);

        $this->assertEquals('add', $changelog[0]['type']);
        $this->assertEquals('添加了新节点', $changelog[0]['description']);
        $this->assertEquals('task_002', $changelog[0]['data']['node_id']);
        $this->assertNotEmpty($changelog[0]['timestamp']);

        $this->assertEquals('modify', $changelog[1]['type']);
    }

    /**
     * 测试发布版本
     */
    public function testPublish(): void
    {
        $this->assertNull($this->version->getPublishedAt());

        $this->version->publish();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $this->version->getStatus());
        $this->assertNotNull($this->version->getPublishedAt());
        $this->assertTrue($this->version->isPublished());
    }

    /**
     * 测试归档版本
     */
    public function testArchive(): void
    {
        $this->assertNull($this->version->getArchivedAt());

        $this->version->archive();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_ARCHIVED, $this->version->getStatus());
        $this->assertNotNull($this->version->getArchivedAt());
        $this->assertTrue($this->version->isArchived());
    }

    /**
     * 测试版本字符串
     */
    public function testVersionString(): void
    {
        $this->assertEquals('v1-v1.0', $this->version->getVersionString());

        $this->version->setTag(null);
        $this->assertEquals('v1', $this->version->getVersionString());
    }

    /**
     * 测试创建者ID
     */
    public function testCreatedBy(): void
    {
        $this->assertNull($this->version->getCreatedBy());

        $this->version->setCreatedBy(123);
        $this->assertEquals(123, $this->version->getCreatedBy());
    }

    /**
     * 测试创建时间
     */
    public function testCreatedAt(): void
    {
        $createdAt = $this->version->getCreatedAt();
        $this->assertNotEmpty($createdAt);

        // 验证是有效的日期时间格式
        $datetime = \DateTime::createFromFormat('Y-m-d H:i:s', $createdAt);
        $this->assertInstanceOf(\DateTime::class, $datetime);
    }

    /**
     * 测试序列化为数组
     */
    public function testToArray(): void
    {
        $this->version->setCreatedBy(456);
        $this->version->addChange('create', '创建版本');

        $array = $this->version->toArray();

        $this->assertEquals('ver_001', $array['id']);
        $this->assertEquals('wf_001', $array['workflow_id']);
        $this->assertEquals(1, $array['version']);
        $this->assertEquals('v1.0', $array['tag']);
        $this->assertEquals('初始版本', $array['description']);
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_DRAFT, $array['status']);
        $this->assertEquals(100, $array['tenant_id']);
        $this->assertEquals(456, $array['created_by']);
        $this->assertIsArray($array['changelog']);
        $this->assertCount(1, $array['changelog']);
    }

    /**
     * 测试从数组创建
     */
    public function testFromArray(): void
    {
        $data = [
            'id' => 'ver_from_array',
            'workflow_id' => 'wf_002',
            'version' => 3,
            'tag' => 'beta',
            'description' => '从数组创建',
            'status' => WorkflowDefinitionInterface::STATUS_PUBLISHED,
            'snapshot' => ['id' => 'wf_002'],
            'changelog' => [['type' => 'init', 'description' => '初始化']],
            'tenant_id' => 200,
            'created_at' => '2025-01-01 10:00:00',
            'created_by' => 789,
            'published_at' => '2025-01-02 10:00:00',
        ];

        $version = WorkflowVersion::fromArray($data);

        $this->assertEquals('ver_from_array', $version->getId());
        $this->assertEquals('wf_002', $version->getWorkflowId());
        $this->assertEquals(3, $version->getVersion());
        $this->assertEquals('beta', $version->getTag());
        $this->assertEquals('从数组创建', $version->getDescription());
        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $version->getStatus());
        $this->assertEquals(['id' => 'wf_002'], $version->getSnapshot());
        $this->assertEquals(200, $version->getTenantId());
        $this->assertEquals(789, $version->getCreatedBy());
        $this->assertEquals('2025-01-02 10:00:00', $version->getPublishedAt());
    }

    /**
     * 测试从数组创建（驼峰命名兼容）
     */
    public function testFromArrayCamelCase(): void
    {
        $data = [
            'workflowId' => 'wf_camel',
            'version' => 1,
            'tenantId' => 300,
        ];

        $version = WorkflowVersion::fromArray($data);

        $this->assertEquals('wf_camel', $version->getWorkflowId());
        $this->assertEquals(300, $version->getTenantId());
    }

    /**
     * 测试ID自动生成
     */
    public function testAutoGeneratedId(): void
    {
        $version = new WorkflowVersion('wf_test', 1);
        $id = $version->getId();

        $this->assertStringStartsWith('ver_', $id);
        $this->assertEquals(20, strlen($id)); // ver_ + 16 chars
    }

    /**
     * 测试链式调用
     */
    public function testFluentInterface(): void
    {
        $result = $this->version
            ->setTag('v2.0')
            ->setDescription('链式调用测试')
            ->setTenantId(500)
            ->setCreatedBy(999)
            ->addChange('test', '测试变更');

        $this->assertSame($this->version, $result);
        $this->assertEquals('v2.0', $this->version->getTag());
        $this->assertEquals('链式调用测试', $this->version->getDescription());
        $this->assertEquals(500, $this->version->getTenantId());
        $this->assertEquals(999, $this->version->getCreatedBy());
        $this->assertCount(1, $this->version->getChangelog());
    }
}
