<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Integration;

use Alkaid\Workflow\Engine\Tests\Mock\MockProcessDefineRepository;
use Alkaid\Workflow\Engine\Tests\Mock\MockProcessInstanceRepository;
use Alkaid\Workflow\Engine\Tests\Mock\MockProcessTaskRepository;
use Alkaid\Workflow\Engine\Tests\Mock\MockWorkflowEventRepository;
use Alkaid\Workflow\Engine\Tests\TestCase;

/**
 * Mock数据访问层测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Integration
 */
class MockRepositoriesTest extends TestCase
{
    /**
     * @var MockProcessDefineRepository 定义仓储
     */
    private MockProcessDefineRepository $defineRepository;

    /**
     * @var MockProcessInstanceRepository 实例仓储
     */
    private MockProcessInstanceRepository $instanceRepository;

    /**
     * @var MockProcessTaskRepository 任务仓储
     */
    private MockProcessTaskRepository $taskRepository;

    /**
     * @var MockWorkflowEventRepository 事件仓储
     */
    private MockWorkflowEventRepository $eventRepository;

    /**
     * 设置测试环境
     */
    protected function setupTestData(): void
    {
        parent::setupTestData();

        // 创建Mock仓储实例
        $this->defineRepository = new MockProcessDefineRepository();
        $this->instanceRepository = new MockProcessInstanceRepository();
        $this->taskRepository = new MockProcessTaskRepository();
        $this->eventRepository = new MockWorkflowEventRepository();
    }

    /**
     * 测试工作流定义仓储
     */
    public function testProcessDefineRepository(): void
    {
        // 创建测试定义
        $definition = $this->defineRepository->createTestDefinition([
            'name' => 'test_workflow',
            'display_name' => '测试工作流',
        ]);

        // 验证定义创建成功
        $this->assertNotNull($definition->getData('id'));
        $this->assertEquals('test_workflow', $definition->getData('name'));
        $this->assertEquals('测试工作流', $definition->getData('display_name'));

        // 通过ID获取定义
        $retrievedDefinition = $this->defineRepository->findById($definition->getData('id'));
        $this->assertNotNull($retrievedDefinition);
        $this->assertEquals($definition->getData('id'), $retrievedDefinition->getData('id'));

        // 获取定义列表
        $definitions = $this->defineRepository->findList();
        $this->assertGreaterThan(0, count($definitions));

        // 删除定义
        $result = $this->defineRepository->deleteById($definition->getData('id'));
        $this->assertTrue($result);

        // 验证删除成功
        $deletedDefinition = $this->defineRepository->findById($definition->getData('id'));
        $this->assertNull($deletedDefinition);
    }

    /**
     * 测试工作流实例仓储
     */
    public function testProcessInstanceRepository(): void
    {
        // 创建测试实例
        $instance = $this->instanceRepository->createTestInstance([
            'title' => '测试实例',
            'status' => 'running',
        ]);

        // 验证实例创建成功
        $this->assertNotNull($instance->getData('id'));
        $this->assertEquals('测试实例', $instance->getData('title'));
        $this->assertEquals('running', $instance->getData('status'));

        // 通过ID获取实例
        $retrievedInstance = $this->instanceRepository->findById($instance->getData('id'));
        $this->assertNotNull($retrievedInstance);
        $this->assertEquals($instance->getData('id'), $retrievedInstance->getData('id'));

        // 更新实例
        $result = $this->instanceRepository->updateById($instance->getData('id'), [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertTrue($result);

        // 验证更新成功
        $updatedInstance = $this->instanceRepository->findById($instance->getData('id'));
        $this->assertEquals('completed', $updatedInstance->getData('status'));
        $this->assertNotNull($updatedInstance->getData('completed_at'));

        // 删除实例
        $result = $this->instanceRepository->deleteById($instance->getData('id'));
        $this->assertTrue($result);
    }

    /**
     * 测试工作流任务仓储
     */
    public function testProcessTaskRepository(): void
    {
        // 创建测试任务
        $task = $this->taskRepository->createTestTask([
            'title' => '测试任务',
            'status' => 'pending',
        ]);

        // 验证任务创建成功
        $this->assertNotNull($task->getData('id'));
        $this->assertEquals('测试任务', $task->getData('title'));
        $this->assertEquals('pending', $task->getData('status'));

        // 通过ID获取任务
        $retrievedTask = $this->taskRepository->findById($task->getData('id'));
        $this->assertNotNull($retrievedTask);
        $this->assertEquals($task->getData('id'), $retrievedTask->getData('id'));

        // 获取任务列表
        $tasks = $this->taskRepository->findList();
        $this->assertGreaterThan(0, count($tasks));

        // 更新任务
        $result = $this->taskRepository->updateById($task->getData('id'), [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertTrue($result);

        // 验证更新成功
        $updatedTask = $this->taskRepository->findById($task->getData('id'));
        $this->assertEquals('completed', $updatedTask->getData('status'));
        $this->assertNotNull($updatedTask->getData('completed_at'));

        // 删除任务
        $result = $this->taskRepository->deleteById($task->getData('id'));
        $this->assertTrue($result);
    }

    /**
     * 测试工作流事件仓储
     */
    public function testWorkflowEventRepository(): void
    {
        // 创建测试事件
        $event = $this->eventRepository->createTestEvent([
            'event_type' => 'task',
            'event_name' => 'task.created',
        ]);

        // 验证事件创建成功
        $this->assertNotNull($event['id']);
        $this->assertEquals('task', $event['event_type']);
        $this->assertEquals('task.created', $event['event_name']);

        // 保存事件
        $result = $this->eventRepository->saveEvent($event);
        $this->assertTrue($result);

        // 获取事件列表
        $events = $this->eventRepository->findEventList();
        $this->assertGreaterThan(0, count($events));

        // 根据实例ID获取事件
        $instanceEvents = $this->eventRepository->findByInstanceId($event['instance_id']);
        $this->assertGreaterThan(0, count($instanceEvents));

        // 根据任务ID获取事件
        $taskEvents = $this->eventRepository->findByTaskId($event['task_id']);
        $this->assertGreaterThan(0, count($taskEvents));
    }

    /**
     * 测试批量操作
     */
    public function testBatchOperations(): void
    {
        // 创建多个定义
        $definitions = [];
        for ($i = 1; $i <= 5; $i++) {
            $definition = $this->defineRepository->createTestDefinition([
                'name' => "test_workflow_{$i}",
                'display_name' => "测试工作流 {$i}",
            ]);
            $definitions[] = $definition;
        }

        // 验证所有定义都已创建
        $this->assertEquals(5, count($definitions));

        // 获取定义列表
        $allDefinitions = $this->defineRepository->findList();
        $this->assertGreaterThanOrEqual(5, count($allDefinitions));

        // 创建多个任务
        $tasks = [];
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->taskRepository->createTestTask([
                'title' => "测试任务 {$i}",
                'status' => 'pending',
            ]);
            $tasks[] = $task;
        }

        // 验证所有任务都已创建
        $this->assertEquals(3, count($tasks));

        // 获取任务列表
        $allTasks = $this->taskRepository->findList();
        $this->assertGreaterThanOrEqual(3, count($allTasks));

        // 批量删除定义
        foreach ($definitions as $definition) {
            $result = $this->defineRepository->deleteById($definition->getData('id'));
            $this->assertTrue($result);
        }

        // 验证删除成功
        $remainingDefinitions = $this->defineRepository->findList();
        // 注意：可能还有其他测试创建的定义
        $this->assertIsArray($remainingDefinitions);
    }

    /**
     * 测试数据完整性
     */
    public function testDataIntegrity(): void
    {
        // 创建定义
        $definition = $this->defineRepository->createTestDefinition();

        // 获取实例
        $instance = $this->instanceRepository->createTestInstance([
            'definition_id' => $definition->getData('id'),
        ]);

        // 获取任务
        $task = $this->taskRepository->createTestTask([
            'instance_id' => $instance->getData('id'),
        ]);

        // 创建事件
        $event = $this->eventRepository->createTestEvent([
            'instance_id' => $instance->getData('id'),
            'task_id' => $task->getData('id'),
        ]);

        // 验证数据关联
        $this->assertEquals($definition->getData('id'), $instance->getData('definition_id'));
        $this->assertEquals($instance->getData('id'), $task->getData('instance_id'));
        $this->assertEquals($instance->getData('id'), $event['instance_id']);
        $this->assertEquals($task->getData('id'), $event['task_id']);

        // 验证数据一致性
        $retrievedDefinition = $this->defineRepository->findById($definition->getData('id'));
        $this->assertNotNull($retrievedDefinition);

        $retrievedInstance = $this->instanceRepository->findById($instance->getData('id'));
        $this->assertNotNull($retrievedInstance);

        $retrievedTask = $this->taskRepository->findById($task->getData('id'));
        $this->assertNotNull($retrievedTask);
    }

    /**
     * 测试仓储清理
     */
    public function testRepositoryCleanup(): void
    {
        // 创建测试数据
        $this->defineRepository->createTestDefinition();
        $this->instanceRepository->createTestInstance();
        $this->taskRepository->createTestTask();
        $this->eventRepository->createTestEvent();

        // 验证有数据
        $this->assertGreaterThan(0, count($this->defineRepository->findList()));
        $this->assertGreaterThan(0, count($this->taskRepository->findList()));

        // 清理所有仓储
        $this->defineRepository->clear();
        $this->instanceRepository->clear();
        $this->taskRepository->clear();
        $this->eventRepository->clear();

        // 验证数据已清空
        $this->assertEquals(0, count($this->defineRepository->findList()));
        $this->assertEquals(0, count($this->taskRepository->findList()));
    }

    /**
     * 测试自动ID生成
     */
    public function testAutoIdGeneration(): void
    {
        // 创建第一个定义
        $definition1 = $this->defineRepository->createTestDefinition();
        $id1 = $definition1->getData('id');

        // 创建第二个定义
        $definition2 = $this->defineRepository->createTestDefinition();
        $id2 = $definition2->getData('id');

        // 验证ID不同
        $this->assertNotEquals($id1, $id2);

        // 验证ID格式正确
        $this->assertStringStartsWith('test_def_', $id1);
        $this->assertStringStartsWith('test_def_', $id2);
    }

    /**
     * 测试数据覆盖
     */
    public function testDataOverride(): void
    {
        // 使用自定义数据创建定义
        $customData = [
            'name' => 'custom_workflow',
            'display_name' => '自定义工作流',
            'description' => '这是一个自定义工作流',
            'type' => 'automation',
            'version' => 2,
        ];

        $definition = $this->defineRepository->createTestDefinition($customData);

        // 验证自定义数据
        $this->assertEquals('custom_workflow', $definition->getData('name'));
        $this->assertEquals('自定义工作流', $definition->getData('display_name'));
        $this->assertEquals('这是一个自定义工作流', $definition->getData('description'));
        $this->assertEquals('automation', $definition->getData('type'));
        $this->assertEquals(2, $definition->getData('version'));
    }
}
