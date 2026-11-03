<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\interface\model\IProcessDefine;

/**
 * 工作流定义数据访问层
 *
 * @package Alkaid\Workflow\Engine\Integration
 */
class ProcessDefineRepository
{
    /**
     * 获取工作流定义
     *
     * @param string $id 定义ID
     * @return IProcessDefine|null
     */
    public function findById(string $id): ?IProcessDefine
    {
        // TODO: 实现数据访问逻辑
        return null;
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
        // TODO: 实现数据访问逻辑
        return [];
    }

    /**
     * 保存工作流定义
     *
     * @param IProcessDefine $processDefine 工作流定义
     * @return bool
     */
    public function save(IProcessDefine $processDefine): bool
    {
        // TODO: 实现数据访问逻辑
        return false;
    }

    /**
     * 删除工作流定义
     *
     * @param string $id 定义ID
     * @return bool
     */
    public function deleteById(string $id): bool
    {
        // TODO: 实现数据访问逻辑
        return false;
    }
}
