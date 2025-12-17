<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Definition\ValidationResult;
use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Definition\WorkflowValidator;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowBuilder 单元测试
 */
class WorkflowBuilderTest extends TestCase
{
    private WorkflowBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new WorkflowBuilder('test_workflow');
    }

    /**
     * 测试创建构建器
     */
    public function testCreateBuilder(): void
    {
        $builder = WorkflowBuilder::create('my_workflow', [
            'id' => 'wf_001',
            'tenant_id' => 100,
        ]);

        $definition = $builder->getDefinition();
        $this->assertEquals('wf_001', $definition->getId());
        $this->assertEquals('my_workflow', $definition->getName());
        $this->assertEquals(100, $definition->getTenantId());
    }

    /**
     * 测试设置基本属性
     */
    public function testSetBasicProperties(): void
    {
        $this->builder
            ->id('wf_custom')
            ->displayName('自定义工作流')
            ->description('这是描述')
            ->version(2)
            ->tenant(50);

        $definition = $this->builder->getDefinition();

        $this->assertEquals('wf_custom', $definition->getId());
        $this->assertEquals('自定义工作流', $definition->getDisplayName());
        $this->assertEquals('这是描述', $definition->getDescription());
        $this->assertEquals(2, $definition->getVersion());
        $this->assertEquals(50, $definition->getTenantId());
    }

    /**
     * 测试设置工作流类型
     */
    public function testSetWorkflowType(): void
    {
        $this->builder->type(WorkflowDefinitionInterface::TYPE_APPROVAL);
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $this->builder->getDefinition()->getType());

        $this->builder->asAutomation();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_AUTOMATION, $this->builder->getDefinition()->getType());

        $this->builder->asBusiness();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_BUSINESS, $this->builder->getDefinition()->getType());

        $this->builder->asIntegration();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_INTEGRATION, $this->builder->getDefinition()->getType());

        $this->builder->asApproval();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $this->builder->getDefinition()->getType());
    }

    /**
     * 测试设置元数据
     */
    public function testSetMeta(): void
    {
        $this->builder
            ->meta('category', 'finance')
            ->meta('priority', 'high');

        $definition = $this->builder->getDefinition();
        $this->assertEquals('finance', $definition->getMeta('category'));
        $this->assertEquals('high', $definition->getMeta('priority'));
    }

    /**
     * 测试设置配置
     */
    public function testSetConfig(): void
    {
        $config = ['timeout' => 3600, 'retry' => 3];
        $this->builder->config($config);

        $this->assertEquals($config, $this->builder->getDefinition()->getConfig());
    }

    /**
     * 测试添加开始节点
     */
    public function testAddStartNode(): void
    {
        $this->builder->start('my_start', ['display_name' => '开始']);

        $definition = $this->builder->getDefinition();
        $startNode = $definition->getStartNode();

        $this->assertNotNull($startNode);
        $this->assertEquals('my_start', $startNode->getName());
        $this->assertEquals($startNode->getId(), $this->builder->getCurrentNodeId());
    }

    /**
     * 测试添加结束节点
     */
    public function testAddEndNode(): void
    {
        $this->builder
            ->start('start')
            ->end('my_end');

        $definition = $this->builder->getDefinition();
        $endNodes = $definition->getEndNodes();

        $this->assertCount(1, $endNodes);
        $this->assertEquals('my_end', $endNodes[0]->getName());

        // 结束节点后，当前节点应该为 null
        $this->assertNull($this->builder->getCurrentNodeId());

        // 检查自动连接
        $connections = $definition->getConnections();
        $this->assertCount(1, $connections);
    }

    /**
     * 测试添加任务节点
     */
    public function testAddTaskNode(): void
    {
        $this->builder
            ->start('start')
            ->task('my_task', 'service', ['display_name' => '服务任务']);

        $definition = $this->builder->getDefinition();
        $node = $definition->getNodeByName('my_task');

        $this->assertNotNull($node);
        $this->assertEquals(NodeInterface::TYPE_TASK, $node->getType());
        $this->assertEquals('service', $node->getSubType());
    }

    /**
     * 测试便捷任务方法
     */
    public function testConvenienceTaskMethods(): void
    {
        $this->builder
            ->start('start')
            ->userTask('user_task')
            ->serviceTask('service_task')
            ->scriptTask('script_task')
            ->end('end');

        $definition = $this->builder->getDefinition();

        $userTask = $definition->getNodeByName('user_task');
        $this->assertEquals('user', $userTask->getSubType());

        $serviceTask = $definition->getNodeByName('service_task');
        $this->assertEquals('service', $serviceTask->getSubType());

        $scriptTask = $definition->getNodeByName('script_task');
        $this->assertEquals('script', $scriptTask->getSubType());
    }

    /**
     * 测试添加网关节点
     */
    public function testAddGatewayNode(): void
    {
        $this->builder
            ->start('start')
            ->gateway('my_gateway', 'exclusive');

        $definition = $this->builder->getDefinition();
        $node = $definition->getNodeByName('my_gateway');

        $this->assertNotNull($node);
        $this->assertEquals(NodeInterface::TYPE_GATEWAY, $node->getType());
        $this->assertEquals('exclusive', $node->getSubType());
    }

    /**
     * 测试便捷网关方法
     */
    public function testConvenienceGatewayMethods(): void
    {
        $this->builder
            ->start('start')
            ->exclusiveGateway('exclusive_gw')
            ->parallelGateway('parallel_gw')
            ->inclusiveGateway('inclusive_gw');

        $definition = $this->builder->getDefinition();

        $exclusiveGw = $definition->getNodeByName('exclusive_gw');
        $this->assertEquals('exclusive', $exclusiveGw->getSubType());

        $parallelGw = $definition->getNodeByName('parallel_gw');
        $this->assertEquals('parallel', $parallelGw->getSubType());

        $inclusiveGw = $definition->getNodeByName('inclusive_gw');
        $this->assertEquals('inclusive', $inclusiveGw->getSubType());
    }

    /**
     * 测试添加自定义节点
     */
    public function testAddCustomNode(): void
    {
        $customNode = new TaskNode('custom_001', [
            'name' => 'custom_task',
            'sub_type' => 'manual',
        ]);

        $this->builder
            ->start('start')
            ->addNode($customNode);

        $definition = $this->builder->getDefinition();
        $node = $definition->getNode('custom_001');

        $this->assertNotNull($node);
        $this->assertEquals('custom_task', $node->getName());
    }

    /**
     * 测试手动连接节点
     */
    public function testConnect(): void
    {
        $this->builder
            ->start('start')
            ->task('task1')
            ->task('task2')
            ->end('end');

        // 获取节点ID
        $task1Id = $this->builder->getNodeId('task1');
        $task2Id = $this->builder->getNodeId('task2');
        $endId = $this->builder->getNodeId('end');

        // 手动添加额外连接
        $this->builder->connect($task1Id, $endId);

        $definition = $this->builder->getDefinition();
        $task1OutputConns = $definition->getNodeOutputConnections($task1Id);

        // task1 应该有两个输出连接（到 task2 和 end）
        $this->assertCount(2, $task1OutputConns);
    }

    /**
     * 测试 then 方法
     */
    public function testThen(): void
    {
        $this->builder
            ->start('start')
            ->task('task1');

        $task1Id = $this->builder->getNodeId('task1');

        // 添加 end 但不自动连接
        $this->builder->addNode(new EndNode('end_001', ['name' => 'end']), false);

        // 使用 then 连接
        $this->builder->at($task1Id)->then('end_001');

        $definition = $this->builder->getDefinition();
        $task1OutputConns = $definition->getNodeOutputConnections($task1Id);

        $this->assertCount(1, $task1OutputConns);
        $this->assertEquals('end_001', $task1OutputConns[0]->getTargetNodeId());
    }

    /**
     * 测试条件分支
     */
    public function testBranch(): void
    {
        $this->builder
            ->start('start')
            ->exclusiveGateway('decision');

        $gatewayId = $this->builder->getCurrentNodeId();

        // 添加分支目标（不自动连接）
        $this->builder->addNode(new TaskNode('approve_001', ['name' => 'approve']), false);
        $this->builder->addNode(new TaskNode('reject_001', ['name' => 'reject']), false);

        // 添加条件分支
        $this->builder
            ->at($gatewayId)
            ->branch('approve_001', '$amount <= 1000', '低额审批')
            ->branch('reject_001', '$amount > 1000', '高额审批');

        $definition = $this->builder->getDefinition();
        $gatewayOutputConns = $definition->getNodeOutputConnections($gatewayId);

        $this->assertCount(2, $gatewayOutputConns);
    }

    /**
     * 测试默认分支
     */
    public function testDefaultBranch(): void
    {
        $this->builder
            ->start('start')
            ->exclusiveGateway('decision');

        $gatewayId = $this->builder->getCurrentNodeId();

        // 添加目标节点（不自动连接）
        $this->builder->addNode(new TaskNode('default_001', ['name' => 'default_task']), false);

        $this->builder
            ->at($gatewayId)
            ->defaultBranch('default_001', '默认路径');

        $definition = $this->builder->getDefinition();
        $gatewayOutputConns = $definition->getNodeOutputConnections($gatewayId);

        $this->assertCount(1, $gatewayOutputConns);
        $this->assertTrue($gatewayOutputConns[0]->isDefault());
    }

    /**
     * 测试 at 方法跳转
     */
    public function testAt(): void
    {
        $this->builder
            ->start('start')
            ->task('task1')
            ->task('task2');

        $task1Id = $this->builder->getNodeId('task1');

        $this->builder->at($task1Id);
        $this->assertEquals($task1Id, $this->builder->getCurrentNodeId());
    }

    /**
     * 测试 atNode 方法
     */
    public function testAtNode(): void
    {
        $this->builder
            ->start('start')
            ->task('task1')
            ->task('task2');

        $this->builder->atNode('task1');

        $task1Id = $this->builder->getNodeId('task1');
        $this->assertEquals($task1Id, $this->builder->getCurrentNodeId());
    }

    /**
     * 测试获取节点ID
     */
    public function testGetNodeId(): void
    {
        $this->builder->start('my_start');

        $nodeId = $this->builder->getNodeId('my_start');
        $this->assertNotNull($nodeId);

        $nonexistent = $this->builder->getNodeId('nonexistent');
        $this->assertNull($nonexistent);
    }

    /**
     * 测试获取最后添加的节点ID
     */
    public function testGetLastNodeId(): void
    {
        $this->builder
            ->start('start')
            ->task('task1')
            ->task('task2');

        $lastId = $this->builder->getLastNodeId();
        $task2Id = $this->builder->getNodeId('task2');

        $this->assertEquals($task2Id, $lastId);
    }

    /**
     * 测试验证
     */
    public function testValidate(): void
    {
        $this->builder
            ->start('start')
            ->task('task')
            ->end('end');

        $result = $this->builder->validate();

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    /**
     * 测试 isValid 方法
     */
    public function testIsValid(): void
    {
        $this->assertFalse($this->builder->isValid()); // 空工作流无效

        $this->builder
            ->start('start')
            ->task('task')
            ->end('end');

        $this->assertTrue($this->builder->isValid());
    }

    /**
     * 测试设置自定义验证器
     */
    public function testSetValidator(): void
    {
        $validator = new WorkflowValidator(['detect_cycles' => false]);
        $this->builder->setValidator($validator);

        $this->builder
            ->start('start')
            ->task('task')
            ->end('end');

        // 验证应该使用自定义验证器
        $result = $this->builder->validate();
        $this->assertTrue($result->isValid());
    }

    /**
     * 测试构建工作流
     */
    public function testBuild(): void
    {
        $this->builder
            ->start('start')
            ->task('task')
            ->end('end');

        $definition = $this->builder->build();

        $this->assertInstanceOf(WorkflowDefinition::class, $definition);
        $this->assertNotNull($definition->getStartNode());
        $this->assertCount(1, $definition->getEndNodes());
    }

    /**
     * 测试构建无效工作流抛出异常
     */
    public function testBuildInvalidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build();
    }

    /**
     * 测试跳过验证构建
     */
    public function testBuildWithoutValidation(): void
    {
        $definition = $this->builder->build(false);

        $this->assertInstanceOf(WorkflowDefinition::class, $definition);
    }

    /**
     * 测试构建并发布
     */
    public function testBuildAndPublish(): void
    {
        $this->builder
            ->start('start')
            ->task('task')
            ->end('end');

        $definition = $this->builder->buildAndPublish();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $definition->getStatus());
        $this->assertNotNull($definition->getPublishedAt());
    }

    /**
     * 测试导出为数组
     */
    public function testToArray(): void
    {
        $this->builder
            ->id('wf_export')
            ->start('start')
            ->end('end');

        $array = $this->builder->toArray();

        $this->assertEquals('wf_export', $array['id']);
        $this->assertIsArray($array['nodes']);
        $this->assertIsArray($array['connections']);
    }

    /**
     * 测试导出为JSON
     */
    public function testToJson(): void
    {
        $this->builder
            ->id('wf_json')
            ->start('start')
            ->end('end');

        $json = $this->builder->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('wf_json', $decoded['id']);
    }

    /**
     * 测试完整的审批流程构建
     */
    public function testBuildCompleteApprovalWorkflow(): void
    {
        // 创建构建器
        $builder = WorkflowBuilder::create('leave_approval')
            ->id('wf_leave_001')
            ->displayName('请假审批流程')
            ->description('员工请假审批工作流')
            ->asApproval()
            ->tenant(100)
            ->meta('category', 'hr');

        // 添加开始节点和主管审批
        $builder
            ->start('start', ['display_name' => '提交申请'])
            ->userTask('manager_approval', ['display_name' => '主管审批'])
            ->exclusiveGateway('check_days');

        // 获取网关ID
        $gatewayId = $builder->getCurrentNodeId();

        // 添加简单审批分支结束节点
        $builder->addNode(new EndNode('end_simple', ['name' => 'end_simple', 'display_name' => '简单审批完成']));
        $endSimpleId = $builder->getNodeId('end_simple');

        // 添加HR审批任务节点
        $builder->addNode(new TaskNode('hr_approval_001', [
            'name' => 'hr_approval',
            'sub_type' => 'user',
            'display_name' => 'HR审批',
        ]));
        $hrApprovalId = $builder->getNodeId('hr_approval');

        // 添加HR审批结束节点
        $builder->addNode(new EndNode('end_hr', ['name' => 'end_hr', 'display_name' => 'HR审批完成']));
        $endHrId = $builder->getNodeId('end_hr');

        // 从网关添加条件分支
        $builder
            ->at($gatewayId)
            ->branch($endSimpleId, '$days <= 3', '3天以内直接批准')
            ->branch($hrApprovalId, '$days > 3', '超过3天需HR审批');

        // 连接HR审批到结束
        $builder->connect($hrApprovalId, $endHrId);

        // 构建时跳过验证（因为这是演示性测试）
        $workflow = $builder->build(false);

        $this->assertEquals('wf_leave_001', $workflow->getId());
        $this->assertEquals('请假审批流程', $workflow->getDisplayName());
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $workflow->getType());
        $this->assertEquals(100, $workflow->getTenantId());
        $this->assertEquals('hr', $workflow->getMeta('category'));
    }

    /**
     * 测试链式调用
     */
    public function testFluentInterface(): void
    {
        $result = $this->builder
            ->id('wf_001')
            ->displayName('测试')
            ->description('描述')
            ->type(WorkflowDefinitionInterface::TYPE_BUSINESS)
            ->tenant(1)
            ->version(1)
            ->meta('key', 'value')
            ->config(['k' => 'v'])
            ->start('start')
            ->task('task')
            ->end('end');

        $this->assertInstanceOf(WorkflowBuilder::class, $result);
        $this->assertSame($this->builder, $result);
    }
}
