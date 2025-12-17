<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Mock;

use Alkaid\Workflow\Engine\Integration\WorkflowEventRepository;

/**
 * 工作流事件数据访问层模拟实现
 *
 * @package Alkaid\Workflow\Engine\Tests\Mock
 */
class MockWorkflowEventRepository extends WorkflowEventRepository
{
    /**
     * @var array 模拟数据存储
     */
    private array $data = [];

    /**
     * @var int 自增ID
     */
    private int $nextId = 1;

    /**
     * 保存工作流事件日志
     *
     * @param array $eventData 事件数据
     * @return bool
     */
    public function saveEvent(array $eventData): bool
    {
        $id = $eventData['id'] ?? 'test_event_' . $this->nextId++;
        $eventData['id'] = $id;
        $this->data[$id] = $eventData;
        return true;
    }

    /**
     * 获取事件日志列表
     *
     * @param array $conditions 查询条件
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function findEventList(array $conditions = [], int $limit = 10, int $offset = 0): array
    {
        $results = array_values($this->data);
        return array_slice($results, $offset, $limit);
    }

    /**
     * 获取实例相关的事件日志
     *
     * @param string $instanceId 实例ID
     * @param int $limit 限制数量
     * @return array
     */
    public function findByInstanceId(string $instanceId, int $limit = 100): array
    {
        return array_filter($this->data, function ($event) use ($instanceId) {
            return $event['instance_id'] === $instanceId;
        });
    }

    /**
     * 获取任务相关的事件日志
     *
     * @param string $taskId 任务ID
     * @param int $limit 限制数量
     * @return array
     */
    public function findByTaskId(string $taskId, int $limit = 100): array
    {
        return array_filter($this->data, function ($event) use ($taskId) {
            return $event['task_id'] === $taskId;
        });
    }

    /**
     * 创建测试用的事件日志
     *
     * @param array $override 覆盖属性
     * @return array
     */
    public function createTestEvent(array $override = []): array
    {
        $event = array_merge([
            'id' => 'test_event_' . $this->nextId++,
            'instance_id' => 'test_instance_' . uniqid(),
            'task_id' => 'test_task_' . uniqid(),
            'tenant_id' => 1,
            'event_type' => 'task',
            'event_name' => 'task.created',
            'event_data' => json_encode(['test' => true]),
            'user_id' => 1,
            'user_name' => '测试用户',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Agent',
            'created_at' => date('Y-m-d H:i:s'),
        ], $override);

        $this->saveEvent($event);
        return $event;
    }

    /**
     * 清理所有数据
     */
    public function clear(): void
    {
        $this->data = [];
        $this->nextId = 1;
    }
}
