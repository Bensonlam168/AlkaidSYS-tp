<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\ValidationResult;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Definition\WorkflowValidator;
use Alkaid\Workflow\Engine\Definition\WorkflowConnection;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use Alkaid\Workflow\Engine\Nodes\Types\GatewayNode;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowValidator 单元测试
 */
class WorkflowValidatorTest extends TestCase
{
    private WorkflowValidator $validator;
    private WorkflowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WorkflowValidator();
        $this->definition = new WorkflowDefinition('test_workflow', [
            'id' => 'wf_001',
        ]);
    }

    /**
     * 测试验证有效工作流
     */
    public function testValidateValidWorkflow(): void
    {
        $this->createValidWorkflow();

        $result = $this->validator->validate($this->definition);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
    }

    /**
     * 测试快速验证方法
     */
    public function testIsValid(): void
    {
        $this->createValidWorkflow();
        $this->assertTrue($this->validator->isValid($this->definition));

        $emptyDefinition = new WorkflowDefinition('empty');
        $this->assertFalse($this->validator->isValid($emptyDefinition));
    }

    /**
     * 测试验证缺少开始节点
     */
    public function testValidateMissingStartNode(): void
    {
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));
        $this->definition->connect('task_001', 'end_001');

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('MISSING_START_NODE'));
    }

    /**
     * 测试验证缺少结束节点
     */
    public function testValidateMissingEndNode(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->connect('start_001', 'task_001');

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('MISSING_END_NODE'));
    }

    /**
     * 测试验证多个开始节点
     */
    public function testValidateMultipleStartNodes(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start1']));
        $this->definition->addNode(new StartNode('start_002', ['name' => 'start2']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('MULTIPLE_START_NODES'));
    }

    /**
     * 测试验证节点缺少输入连接
     */
    public function testValidateNodeMissingInputConnection(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        // 只连接 start -> task，task -> end 没有连接
        $this->definition->connect('start_001', 'task_001');

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('NO_INPUT_CONNECTION'));
    }

    /**
     * 测试验证节点缺少输出连接
     */
    public function testValidateNodeMissingOutputConnection(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        // 只连接 start -> task 和 task -> end，但 task 没有实际输出
        // 这里我们只连接 start 和 end
        $this->definition->connect('start_001', 'end_001');

        $result = $this->validator->validate($this->definition);

        // task_001 没有连接，会报错
        $this->assertTrue($result->hasErrors());
    }

    /**
     * 测试验证无效的源节点
     */
    public function testValidateInvalidSourceNode(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        // 添加一个引用不存在节点的连接
        $connection = new WorkflowConnection('nonexistent_node', 'end_001');
        $this->definition->addConnection($connection);

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('INVALID_SOURCE_NODE'));
    }

    /**
     * 测试验证无效的目标节点
     */
    public function testValidateInvalidTargetNode(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        // 添加一个引用不存在节点的连接
        $connection = new WorkflowConnection('start_001', 'nonexistent_node');
        $this->definition->addConnection($connection);

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('INVALID_TARGET_NODE'));
    }

    /**
     * 测试验证自环连接
     */
    public function testValidateSelfLoop(): void
    {
        $this->createValidWorkflow();

        // 添加自环连接
        $connection = new WorkflowConnection('task_001', 'task_001');
        $this->definition->addConnection($connection);

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasCode('SELF_LOOP')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试不可达的结束节点
     */
    public function testValidateUnreachableEndNode(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end1']));
        $this->definition->addNode(new EndNode('end_002', ['name' => 'end2'])); // 孤立的结束节点

        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'end_001');
        // end_002 没有连接，不可达

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('UNREACHABLE_END_NODE'));
    }

    /**
     * 测试孤立节点检测
     */
    public function testValidateOrphanNodes(): void
    {
        $this->createValidWorkflow();

        // 添加一个孤立的任务节点
        $this->definition->addNode(new TaskNode('orphan_task', ['name' => 'orphan']));

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasCode('ORPHAN_NODE')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试禁用孤立节点检测
     */
    public function testDisableOrphanNodeDetection(): void
    {
        $this->createValidWorkflow();
        $this->definition->addNode(new TaskNode('orphan_task', ['name' => 'orphan']));

        $validator = new WorkflowValidator(['detect_orphan_nodes' => false]);
        $result = $validator->validate($this->definition);

        $this->assertFalse($result->hasCode('ORPHAN_NODE')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试循环检测
     */
    public function testValidateCycleDetection(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task1']));
        $this->definition->addNode(new TaskNode('task_002', ['name' => 'task2']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        // 创建循环: task_001 -> task_002 -> task_001
        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'task_002');
        $this->definition->connect('task_002', 'task_001'); // 形成循环
        $this->definition->connect('task_002', 'end_001');

        $result = $this->validator->validate($this->definition);

        // 循环是信息级别，使用 hasCode 检查
        $this->assertTrue($result->hasCode('CYCLE_DETECTED'));
    }

    /**
     * 测试禁用循环检测
     */
    public function testDisableCycleDetection(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task1']));
        $this->definition->addNode(new TaskNode('task_002', ['name' => 'task2']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'task_002');
        $this->definition->connect('task_002', 'task_001');
        $this->definition->connect('task_002', 'end_001');

        $validator = new WorkflowValidator(['detect_cycles' => false]);
        $result = $validator->validate($this->definition);

        $this->assertFalse($result->hasCode('CYCLE_DETECTED')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试条件表达式验证
     */
    public function testValidateConditionExpression(): void
    {
        $this->createValidWorkflow();

        // 添加一个带无效条件的连接
        $connection = WorkflowConnection::conditional('task_001', 'end_001', '((unbalanced');
        $this->definition->addConnection($connection);

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasCode('INVALID_CONDITION_SYNTAX')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试危险条件表达式检测
     */
    public function testValidateDangerousCondition(): void
    {
        $this->createValidWorkflow();

        // 添加一个带危险代码的条件
        $connection = WorkflowConnection::conditional('task_001', 'end_001', 'eval($code)');
        $this->definition->addConnection($connection);

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasWarnings());
        $this->assertTrue($result->hasCode('INVALID_CONDITION_SYNTAX')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试禁用条件验证
     */
    public function testDisableConditionValidation(): void
    {
        $this->createValidWorkflow();
        $connection = WorkflowConnection::conditional('task_001', 'end_001', '((unbalanced');
        $this->definition->addConnection($connection);

        $validator = new WorkflowValidator(['validate_conditions' => false]);
        $result = $validator->validate($this->definition);

        $this->assertFalse($result->hasCode('INVALID_CONDITION_SYNTAX')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试添加自定义验证规则
     */
    public function testAddCustomRule(): void
    {
        $this->createValidWorkflow();

        $this->validator->addRule('custom_rule', function ($definition) {
            if (count($definition->getNodes()) < 5) {
                return [[
                    'code' => 'TOO_FEW_NODES',
                    'message' => '工作流节点数量不足',
                    'level' => WorkflowValidator::LEVEL_WARNING,
                ]];
            }
            return [];
        });

        $result = $this->validator->validate($this->definition);

        $this->assertTrue($result->hasCode('TOO_FEW_NODES')); // 使用 hasCode 检查所有级别
    }

    /**
     * 测试移除验证规则
     */
    public function testRemoveRule(): void
    {
        // 移除开始节点验证规则
        $this->validator->removeRule('start_node');

        // 现在验证不会检查开始节点
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));
        $this->definition->connect('task_001', 'end_001');

        $result = $this->validator->validate($this->definition);

        $this->assertFalse($result->hasErrorCode('MISSING_START_NODE'));
    }

    /**
     * 测试获取规则名称
     */
    public function testGetRuleNames(): void
    {
        $ruleNames = $this->validator->getRuleNames();

        $this->assertContains('start_node', $ruleNames);
        $this->assertContains('end_node', $ruleNames);
        $this->assertContains('node_connections', $ruleNames);
        $this->assertContains('connection_validity', $ruleNames);
        $this->assertContains('reachability', $ruleNames);
        $this->assertContains('orphan_nodes', $ruleNames);
        $this->assertContains('cycle_detection', $ruleNames);
    }

    /**
     * 测试选择性运行规则
     */
    public function testValidateWithSelectedRules(): void
    {
        // 只运行 start_node 规则
        $result = $this->validator->validate($this->definition, ['start_node']);

        $this->assertTrue($result->hasErrorCode('MISSING_START_NODE'));
        $this->assertFalse($result->hasErrorCode('MISSING_END_NODE'));
    }

    /**
     * 测试链式配置
     */
    public function testFluentConfiguration(): void
    {
        $validator = new WorkflowValidator();
        $result = $validator
            ->setDetectCycles(false)
            ->setDetectOrphanNodes(false)
            ->setValidateConditions(false);

        $this->assertSame($validator, $result);
    }

    /**
     * 创建有效的工作流用于测试
     */
    private function createValidWorkflow(): void
    {
        $this->definition->addNode(new StartNode('start_001', ['name' => 'start']));
        $this->definition->addNode(new TaskNode('task_001', ['name' => 'task']));
        $this->definition->addNode(new EndNode('end_001', ['name' => 'end']));

        $this->definition->connect('start_001', 'task_001');
        $this->definition->connect('task_001', 'end_001');
    }
}
