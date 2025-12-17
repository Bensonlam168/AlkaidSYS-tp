<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\interface\model\IProcessInstance;

/**
 * 工作流实例数据访问层
 *
 * @package Alkaid\Workflow\Engine\Integration
 */
class ProcessInstanceRepository
{
    /**
     * 获取工作流实例
     *
     * @param string $id 实例ID
     * @return IProcessInstance|null
     */
    public function findById(string $id): ?IProcessInstance
    {
        // TODO: 实现数据访问逻辑
        return null;
    }

    /**
     * 保存工作流实例
     *
     * @param IProcessInstance $processInstance 工作流实例
     * @return bool
     */
    public function save(IProcessInstance $processInstance): bool
    {
        // TODO: 实现数据访问逻辑
        return false;
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
        // TODO: 实现数据访问逻辑
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
        // TODO: 实现数据访问逻辑
        return false;
    }
}
