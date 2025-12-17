<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Mock;

use Alkaid\Workflow\Engine\interface\model\IProcessDefine;
use Alkaid\Workflow\Engine\Integration\ProcessDefineRepository;

/**
 * 工作流定义数据访问层模拟实现
 *
 * @package Alkaid\Workflow\Engine\Tests\Mock
 */
class MockProcessDefineRepository extends ProcessDefineRepository
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
     * 获取工作流定义
     *
     * @param string $id 定义ID
     * @return IProcessDefine|null
     */
    public function findById(string $id): ?IProcessDefine
    {
        return $this->data[$id] ?? null;
    }

    /**
     * 获取工作流定义列表
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
     * 保存工作流定义
     *
     * @param IProcessDefine $processDefine 工作流定义
     * @return bool
     */
    public function save(IProcessDefine $processDefine): bool
    {
        $id = $processDefine->getData('id') ?: 'test_def_' . $this->nextId++;
        $processDefine->set('id', $id);
        $this->data[$id] = $processDefine;
        return true;
    }

    /**
     * 删除工作流定义
     *
     * @param string $id 定义ID
     * @return bool
     */
    public function deleteById(string $id): bool
    {
        unset($this->data[$id]);
        return true;
    }

    /**
     * 创建测试用的工作流定义
     *
     * @param array $override 覆盖属性
     * @return IProcessDefine
     */
    public function createTestDefinition(array $override = []): IProcessDefine
    {
        $definition = new class($override) implements IProcessDefine {
            private array $data;

            public function __construct(array $data = [])
            {
                $this->data = array_merge([
                    'id' => 'test_def_' . uniqid(),
                    'tenant_id' => 1,
                    'name' => 'test_workflow',
                    'display_name' => '测试工作流',
                    'description' => '这是一个测试工作流',
                    'type' => 'approval',
                    'category' => 'test',
                    'icon' => 'test-icon',
                    'version' => 1,
                    'status' => 'draft',
                    'bpmn_xml' => '<xml>test</xml>',
                    'nodes_config' => json_encode([]),
                    'triggers_config' => json_encode([]),
                    'variables_schema' => json_encode([]),
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                    'updated_by' => 1,
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

        $this->save($definition);
        return $definition;
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
