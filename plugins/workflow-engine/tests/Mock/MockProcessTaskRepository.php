<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Mock;

use Alkaid\Workflow\Engine\interface\model\IProcessTask;
use Alkaid\Workflow\Engine\Integration\ProcessTaskRepository;

/**
 * 工作流任务数据访问层模拟实现
 *
 * @package Alkaid\Workflow\Engine\Tests\Mock
 */
class MockProcessTaskRepository extends ProcessTaskRepository
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
     * 获取工作流任务
     *
     * @param string $id 任务ID
     * @return IProcessTask|null
     */
    public function findById(string $id): ?IProcessTask
    {
        return $this->data[$id] ?? null;
    }

    /**
     * 获取工作流任务列表
     *
     * @param array $conditions 查询条件
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function findList(array $conditions = [], int $limit = 10, int $offset = 0): array
    {
        $results = array_values($this->data);
        return array_slice($results, $offset, $limit);
    }

    /**
     * 保存工作流任务
     *
     * @param IProcessTask $processTask 工作流任务
     * @return bool
     */
    public function save(IProcessTask $processTask): bool
    {
        $id = $processTask->getData('id') ?: 'test_task_' . $this->nextId++;
        $processTask->set('id', $id);
        $this->data[$id] = $processTask;
        return true;
    }

    /**
     * 更新工作流任务
     *
     * @param string $id 任务ID
     * @param array $data 更新数据
     * @return bool
     */
    public function updateById(string $id, array $data): bool
    {
        if (isset($this->data[$id])) {
            $this->data[$id]->setData($data);
            return true;
        }
        return false;
    }

    /**
     * 删除工作流任务
     *
     * @param string $id 任务ID
     * @return bool
     */
    public function deleteById(string $id): bool
    {
        unset($this->data[$id]);
        return true;
    }

    /**
     * 创建测试用的工作流任务
     *
     * @param array $override 覆盖属性
     * @return IProcessTask
     */
    public function createTestTask(array $override = []): IProcessTask
    {
        $task = new class ($override) implements IProcessTask {
            private array $data;

            public function __construct(array $data = [])
            {
                $this->data = array_merge([
                    'id' => 'test_task_' . uniqid(),
                    'instance_id' => 'test_instance_' . uniqid(),
                    'tenant_id' => 1,
                    'node_id' => 'test_node',
                    'node_name' => '测试节点',
                    'task_type' => 'user_task',
                    'task_sub_type' => 'approval',
                    'title' => '测试任务',
                    'description' => '这是一个测试任务',
                    'status' => 'pending',
                    'priority' => 5,
                    'assignee' => 1,
                    'assignee_name' => '测试用户',
                    'candidate_users' => json_encode([]),
                    'candidate_groups' => json_encode([]),
                    'form_data' => json_encode([]),
                    'result' => json_encode([]),
                    'comment' => null,
                    'due_date' => null,
                    'reminder_times' => json_encode([]),
                    'completed_at' => null,
                    'rejected_at' => null,
                    'cancelled_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                ], $data);
            }

            public function getData($key = null)
            {
                if ($key === null) {
                    return $this->data;
                }
                return $this->data[$key] ?? null;
            }

            public function setData($key, $value = null)
            {
                if (is_array($key)) {
                    $this->data = array_merge($this->data, $key);
                } else {
                    $this->data[$key] = $value;
                }
                return $this;
            }

            /**
             * 设置单个属性
             *
             * 仓库层 save() 会用 set('id', $id) 回填主键，匿名替身必须提供同名方法，
             * 否则运行期抛 "Call to undefined method ...::set()"。此处委托 setData()，
             * 保持与 setData 一致的语义与返回（$this，可链式）。
             *
             * @param string|array $key   属性名；传数组时等价于 setData() 的批量合并
             * @param mixed        $value 属性值
             * @return $this
             */
            public function set($key, $value = null)
            {
                return $this->setData($key, $value);
            }

            public function toArray(): array
            {
                return $this->data;
            }

            public function validate(): array
            {
                return [];
            }
        };

        $this->save($task);
        return $task;
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
