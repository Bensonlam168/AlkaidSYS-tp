<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Swoole;

use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ExecutionContext 单元测试
 */
#[CoversClass(ExecutionContext::class)]
class ExecutionContextTest extends TestCase
{
    /**
     * 测试基本构造
     */
    public function testBasicConstruction(): void
    {
        $context = new ExecutionContext('workflow_1', 'instance_1', 100);

        $this->assertEquals('workflow_1', $context->getWorkflowId());
        $this->assertEquals('instance_1', $context->getInstanceId());
        $this->assertEquals(100, $context->getTenantId());
        $this->assertGreaterThan(0, $context->getStartTime());
    }

    /**
     * 测试从配置数组创建
     */
    public function testCreateFromConfig(): void
    {
        $config = [
            'workflow_id' => 'wf_123',
            'instance_id' => 'inst_456',
            'tenant_id' => 200,
            'user_id' => 1001,
            'variables' => ['var1' => 'value1'],
            'trigger_data' => ['trigger' => 'data'],
            'business_key' => 'bk_001',
            'priority' => 8,
            'timeout' => 600,
            'metadata' => ['meta' => 'info'],
        ];

        $context = ExecutionContext::create($config);

        $this->assertEquals('wf_123', $context->getWorkflowId());
        $this->assertEquals('inst_456', $context->getInstanceId());
        $this->assertEquals(200, $context->getTenantId());
        $this->assertEquals(1001, $context->getUserId());
        $this->assertEquals(['var1' => 'value1'], $context->getVariables());
        $this->assertEquals(['trigger' => 'data'], $context->getTriggerData());
        $this->assertEquals('bk_001', $context->getBusinessKey());
        $this->assertEquals(8, $context->getPriority());
        $this->assertEquals(600, $context->getTimeout());
        $this->assertEquals(['meta' => 'info'], $context->getMetadata());
    }

    /**
     * 测试变量操作
     */
    public function testVariableOperations(): void
    {
        $context = new ExecutionContext();

        // 测试设置所有变量
        $context->setVariables(['var1' => 'value1', 'var2' => 'value2']);
        $this->assertEquals(['var1' => 'value1', 'var2' => 'value2'], $context->getVariables());

        // 测试获取单个变量
        $this->assertEquals('value1', $context->getVariable('var1'));
        $this->assertEquals('default', $context->getVariable('nonexistent', 'default'));

        // 测试设置单个变量
        $context->setVariable('var3', 'value3');
        $this->assertEquals('value3', $context->getVariable('var3'));

        // 测试检查变量是否存在
        $this->assertTrue($context->hasVariable('var1'));
        $this->assertFalse($context->hasVariable('nonexistent'));

        // 测试删除变量
        $context->removeVariable('var1');
        $this->assertFalse($context->hasVariable('var1'));

        // 测试合并变量
        $context->mergeVariables(['new_var' => 'new_value']);
        $this->assertEquals('new_value', $context->getVariable('new_var'));
    }

    /**
     * 测试节点输出操作
     */
    public function testNodeOutputOperations(): void
    {
        $context = new ExecutionContext();

        // 设置节点输出
        $context->setNodeOutput('node_1', ['output' => 'data1']);
        $context->setNodeOutput('node_2', ['output' => 'data2']);

        // 获取单个节点输出
        $this->assertEquals(['output' => 'data1'], $context->getNodeOutput('node_1'));
        $this->assertEquals(['output' => 'data2'], $context->getNodeOutput('node_2'));
        $this->assertNull($context->getNodeOutput('nonexistent'));

        // 检查节点是否有输出
        $this->assertTrue($context->hasNodeOutput('node_1'));
        $this->assertFalse($context->hasNodeOutput('nonexistent'));

        // 获取所有节点输出
        $allOutputs = $context->getAllNodeOutputs();
        $this->assertCount(2, $allOutputs);
        $this->assertArrayHasKey('node_1', $allOutputs);
        $this->assertArrayHasKey('node_2', $allOutputs);
    }

    /**
     * 测试当前节点信息
     */
    public function testCurrentNodeInfo(): void
    {
        $context = new ExecutionContext();

        $context->setCurrentNodeId('current_node')
            ->setCurrentNodeName('当前节点');

        $this->assertEquals('current_node', $context->getCurrentNodeId());
        $this->assertEquals('当前节点', $context->getCurrentNodeName());
    }

    /**
     * 测试执行路径
     */
    public function testExecutionPath(): void
    {
        $context = new ExecutionContext();

        // 添加到执行路径
        $context->addToExecutionPath('node_1')
            ->addToExecutionPath('node_2')
            ->addToExecutionPath('node_3');

        $path = $context->getExecutionPath();
        $this->assertEquals(['node_1', 'node_2', 'node_3'], $path);

        // 检查节点是否已执行
        $this->assertTrue($context->hasExecuted('node_1'));
        $this->assertTrue($context->hasExecuted('node_2'));
        $this->assertFalse($context->hasExecuted('node_4'));
    }

    /**
     * 测试取消功能
     */
    public function testCancelFunction(): void
    {
        $context = new ExecutionContext();

        $this->assertFalse($context->isCancelled());
        $this->assertTrue($context->canContinue());

        $context->cancel();

        $this->assertTrue($context->isCancelled());
        $this->assertFalse($context->canContinue());
    }

    /**
     * 测试暂停和恢复功能
     */
    public function testSuspendAndResumeFunction(): void
    {
        $context = new ExecutionContext();

        $this->assertFalse($context->isSuspended());
        $this->assertTrue($context->canContinue());

        // 暂停
        $context->suspend();
        $this->assertTrue($context->isSuspended());
        $this->assertFalse($context->canContinue());

        // 恢复
        $context->resume();
        $this->assertFalse($context->isSuspended());
        $this->assertTrue($context->canContinue());
    }

    /**
     * 测试错误管理
     */
    public function testErrorManagement(): void
    {
        $context = new ExecutionContext();

        $this->assertFalse($context->hasErrors());
        $this->assertEmpty($context->getErrors());
        $this->assertNull($context->getLastError());

        // 添加错误
        $context->addError('node_1', '错误信息1', 500);
        $context->addError('node_2', '错误信息2', 404);

        $this->assertTrue($context->hasErrors());
        $this->assertCount(2, $context->getErrors());

        // 获取最后一个错误
        $lastError = $context->getLastError();
        $this->assertEquals('node_2', $lastError['node_id']);
        $this->assertEquals('错误信息2', $lastError['error']);
        $this->assertEquals(404, $lastError['code']);

        // 清除错误
        $context->clearErrors();
        $this->assertFalse($context->hasErrors());
    }

    /**
     * 测试子上下文创建
     */
    public function testChildContextCreation(): void
    {
        $parent = ExecutionContext::create([
            'workflow_id' => 'parent_wf',
            'instance_id' => 'parent_inst',
            'tenant_id' => 100,
            'user_id' => 1001,
            'variables' => ['parent_var' => 'parent_value'],
            'timeout' => 600,
        ]);

        $child = $parent->createChildContext('child_wf', ['child_var' => 'child_value']);

        // 验证子上下文
        $this->assertEquals('child_wf', $child->getWorkflowId());
        $this->assertNull($child->getInstanceId());
        $this->assertEquals(100, $child->getTenantId());
        $this->assertEquals(1001, $child->getUserId());
        $this->assertEquals(600, $child->getTimeout());

        // 验证变量继承和覆盖
        $this->assertEquals('parent_value', $child->getVariable('parent_var'));
        $this->assertEquals('child_value', $child->getVariable('child_var'));

        // 验证父子关系
        $this->assertSame($parent, $child->getParentContext());
        $this->assertTrue($child->isChildContext());
        $this->assertFalse($parent->isChildContext());

        // 验证根上下文
        $this->assertSame($parent, $child->getRootContext());
        $this->assertSame($parent, $parent->getRootContext());
    }

    /**
     * 测试超时功能
     */
    public function testTimeoutFunction(): void
    {
        $context = new ExecutionContext();
        $context->setTimeout(1); // 1秒超时

        // 初始状态不超时
        $this->assertFalse($context->isTimeout());
        $this->assertGreaterThan(0, $context->getRemainingTime());

        // 模拟超时（实际测试中可能需要使用mock）
        $context->setTimeout(0); // 设置为0秒立即超时

        // 注意：由于执行时间已经过了，设置0秒会导致超时
        $this->assertTrue($context->isTimeout());
        $this->assertEquals(0, $context->getRemainingTime());
    }

    /**
     * 测试执行持续时间
     */
    public function testDuration(): void
    {
        $context = new ExecutionContext();

        // 等待一小段时间
        usleep(10000); // 10ms

        $duration = $context->getDuration();
        $this->assertGreaterThan(0, $duration);
    }

    /**
     * 测试优先级
     */
    public function testPriority(): void
    {
        $context = new ExecutionContext();

        // 默认优先级
        $this->assertEquals(5, $context->getPriority());

        // 设置有效优先级
        $context->setPriority(8);
        $this->assertEquals(8, $context->getPriority());

        // 边界值测试
        $context->setPriority(0);
        $this->assertEquals(1, $context->getPriority()); // 最小值

        $context->setPriority(15);
        $this->assertEquals(10, $context->getPriority()); // 最大值
    }

    /**
     * 测试元数据操作
     */
    public function testMetadataOperations(): void
    {
        $context = new ExecutionContext();

        // 设置元数据
        $context->setMetadata(['key1' => 'value1']);
        $this->assertEquals(['key1' => 'value1'], $context->getMetadata());

        // 获取单个元数据
        $this->assertEquals('value1', $context->getMeta('key1'));
        $this->assertEquals('default', $context->getMeta('nonexistent', 'default'));

        // 设置单个元数据
        $context->setMeta('key2', 'value2');
        $this->assertEquals('value2', $context->getMeta('key2'));
    }

    /**
     * 测试克隆上下文
     */
    public function testCloneContext(): void
    {
        $original = ExecutionContext::create([
            'workflow_id' => 'wf_1',
            'instance_id' => 'inst_1',
            'tenant_id' => 100,
            'user_id' => 1001,
            'variables' => ['var1' => 'value1'],
            'trigger_data' => ['trigger' => 'data'],
            'business_key' => 'bk_001',
            'priority' => 8,
            'timeout' => 600,
            'metadata' => ['meta' => 'info'],
        ]);

        $original->setNodeOutput('node_1', ['output' => 'data']);
        $original->addToExecutionPath('node_1');

        $clone = $original->clone();

        // 验证克隆数据相等
        $this->assertEquals($original->getWorkflowId(), $clone->getWorkflowId());
        $this->assertEquals($original->getInstanceId(), $clone->getInstanceId());
        $this->assertEquals($original->getTenantId(), $clone->getTenantId());
        $this->assertEquals($original->getUserId(), $clone->getUserId());
        $this->assertEquals($original->getVariables(), $clone->getVariables());
        $this->assertEquals($original->getAllNodeOutputs(), $clone->getAllNodeOutputs());
        $this->assertEquals($original->getExecutionPath(), $clone->getExecutionPath());
        $this->assertEquals($original->getMetadata(), $clone->getMetadata());

        // 验证修改克隆不影响原始
        $clone->setVariable('var2', 'value2');
        $this->assertFalse($original->hasVariable('var2'));
    }

    /**
     * 测试转换为数组
     */
    public function testToArray(): void
    {
        $context = ExecutionContext::create([
            'workflow_id' => 'wf_1',
            'instance_id' => 'inst_1',
            'tenant_id' => 100,
            'user_id' => 1001,
            'variables' => ['var1' => 'value1'],
        ]);

        $array = $context->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('wf_1', $array['workflow_id']);
        $this->assertEquals('inst_1', $array['instance_id']);
        $this->assertEquals(100, $array['tenant_id']);
        $this->assertEquals(1001, $array['user_id']);
        $this->assertEquals(['var1' => 'value1'], $array['variables']);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('cancelled', $array);
        $this->assertArrayHasKey('suspended', $array);
    }

    /**
     * 测试转换为 JSON
     */
    public function testToJson(): void
    {
        $context = ExecutionContext::create([
            'workflow_id' => 'wf_1',
            'tenant_id' => 100,
        ]);

        $json = $context->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('wf_1', $decoded['workflow_id']);
        $this->assertEquals(100, $decoded['tenant_id']);
    }

    /**
     * 测试从数组恢复
     */
    public function testFromArray(): void
    {
        $data = [
            'workflow_id' => 'wf_1',
            'instance_id' => 'inst_1',
            'tenant_id' => 100,
            'user_id' => 1001,
            'variables' => ['var1' => 'value1'],
            'node_outputs' => ['node_1' => ['output' => 'data']],
            'current_node_id' => 'current',
            'current_node_name' => '当前节点',
            'execution_path' => ['node_1', 'node_2'],
            'cancelled' => false,
            'suspended' => false,
            'errors' => [['node_id' => 'n1', 'error' => 'err']],
            'metadata' => ['meta' => 'info'],
            'timeout' => 600,
            'trigger_data' => ['trigger' => 'data'],
            'business_key' => 'bk_001',
            'priority' => 8,
        ];

        $context = ExecutionContext::fromArray($data);

        $this->assertEquals('wf_1', $context->getWorkflowId());
        $this->assertEquals('inst_1', $context->getInstanceId());
        $this->assertEquals(100, $context->getTenantId());
        $this->assertEquals(1001, $context->getUserId());
        $this->assertEquals(['var1' => 'value1'], $context->getVariables());
        $this->assertEquals(['output' => 'data'], $context->getNodeOutput('node_1'));
        $this->assertEquals('current', $context->getCurrentNodeId());
        $this->assertEquals('当前节点', $context->getCurrentNodeName());
        $this->assertEquals(['node_1', 'node_2'], $context->getExecutionPath());
        $this->assertFalse($context->isCancelled());
        $this->assertFalse($context->isSuspended());
        $this->assertEquals(['meta' => 'info'], $context->getMetadata());
        $this->assertEquals(600, $context->getTimeout());
        $this->assertEquals(['trigger' => 'data'], $context->getTriggerData());
        $this->assertEquals('bk_001', $context->getBusinessKey());
        $this->assertEquals(8, $context->getPriority());
    }

    /**
     * 测试链式调用
     */
    public function testChainedCalls(): void
    {
        $context = (new ExecutionContext())
            ->setWorkflowId('wf_1')
            ->setInstanceId('inst_1')
            ->setTenantId(100)
            ->setUserId(1001)
            ->setVariable('var1', 'value1')
            ->setCurrentNodeId('node_1')
            ->setCurrentNodeName('节点1')
            ->setBusinessKey('bk_001')
            ->setPriority(8)
            ->setTimeout(600)
            ->setMeta('meta_key', 'meta_value');

        $this->assertEquals('wf_1', $context->getWorkflowId());
        $this->assertEquals('inst_1', $context->getInstanceId());
        $this->assertEquals(100, $context->getTenantId());
        $this->assertEquals(1001, $context->getUserId());
        $this->assertEquals('value1', $context->getVariable('var1'));
        $this->assertEquals('node_1', $context->getCurrentNodeId());
        $this->assertEquals('节点1', $context->getCurrentNodeName());
        $this->assertEquals('bk_001', $context->getBusinessKey());
        $this->assertEquals(8, $context->getPriority());
        $this->assertEquals(600, $context->getTimeout());
        $this->assertEquals('meta_value', $context->getMeta('meta_key'));
    }
}
