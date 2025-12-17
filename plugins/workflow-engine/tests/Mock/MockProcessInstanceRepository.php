<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Mock;

use Alkaid\Workflow\Engine\interface\model\IProcessInstance;
use Alkaid\Workflow\Engine\Integration\ProcessInstanceRepository;

/**
 * 工作流实例数据访问层模拟实现
 *
 * @package Alkaid\Workflow\Engine\Tests\Mock
 */
class MockProcessInstanceRepository extends ProcessInstanceRepository
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
     * 获取工作流实例
     *
     * @param string $id 实例ID
     * @return IProcessInstance|null
     */
    public function findById(string $id): ?IProcessInstance
    {
        return $this->data[$id] ?? null;
    }

    /**
     * 保存工作流实例
     *
     * @param IProcessInstance $processInstance 工作流实例
     * @return bool
     */
    public function save(IProcessInstance $processInstance): bool
    {
        $id = $processInstance->getData('id') ?: 'test_instance_' . $this->nextId++;
        $processInstance->set('id', $id);
        $this->data[$id] = $processInstance;
        return true;
    }

    /**
     * 更新工作流实例
     *
     * @param string $id 实例ID
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
     * 删除工作流实例
     *
     * @param string $id 实例ID
     * @return bool
     */
    public function deleteById(string $id): bool
    {
        unset($this->data[$id]);
        return true;
    }

    /**
     * 创建测试用的工作流实例
     *
     * @param array $override 覆盖属性
     * @return IProcessInstance
     */
    public function createTestInstance(array $override = []): IProcessInstance
    {
        $instance = new class($override) implements IProcessInstance {
            private array $data;

            public function __construct(array $data = [])
            {
                $this->data = array_merge([
                    'id' => 'test_instance_' . uniqid(),
                    'definition_id' => 'test_def_' . uniqid(),
                    'tenant_id' => 1,
                    'business_key' => 'test_business_key',
                    'title' => '测试工作流实例',
                    'status' => 'pending',
                    'priority' => 5,
                    'current_node_id' => null,
                    'current_node_name' => null,
                    'context' => json_encode([]),
                    'variables' => json_encode([]),
                    'started_at' => null,
                    'completed_at' => null,
                    'suspended_at' => null,
                    'duration' => null,
                    'error_message' => null,
                    'error_stack' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                    'assignee' => null,
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

            public function toArray(): array
            {
                return $this->data;
            }

            public function validate(): array
            {
                return [];
            }
        };

        $this->save($instance);
        return $instance;
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
