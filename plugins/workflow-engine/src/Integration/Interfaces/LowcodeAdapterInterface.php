<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration\Interfaces;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;

/**
 * 低代码适配器接口
 *
 * 定义工作流引擎与低代码系统集成的标准接口。
 *
 * @package Alkaid\Workflow\Engine\Integration\Interfaces
 */
interface LowcodeAdapterInterface
{
    // ========== CRUD 操作 ==========

    /**
     * 创建数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $data 数据
     * @param array $options 选项
     * @return int|string 新记录 ID
     */
    public function create(string $collectionName, array $data, array $options = []): int|string;

    /**
     * 读取数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return array 查询结果
     */
    public function read(string $collectionName, array $conditions = [], array $options = []): array;

    /**
     * 读取单条记录
     *
     * @param string $collectionName Collection 名称
     * @param int|string $id 记录 ID
     * @param array $options 选项
     * @return array|null 记录数据
     */
    public function find(string $collectionName, int|string $id, array $options = []): ?array;

    /**
     * 更新数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 更新条件
     * @param array $data 更新数据
     * @param array $options 选项
     * @return int 影响行数
     */
    public function update(string $collectionName, array $conditions, array $data, array $options = []): int;

    /**
     * 删除数据记录
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 删除条件
     * @param array $options 选项
     * @return int 影响行数
     */
    public function delete(string $collectionName, array $conditions, array $options = []): int;

    // ========== 批量操作 ==========

    /**
     * 批量创建记录
     *
     * @param string $collectionName Collection 名称
     * @param array $records 记录数组
     * @param array $options 选项
     * @return int 创建数量
     */
    public function batchCreate(string $collectionName, array $records, array $options = []): int;

    /**
     * 批量更新记录
     *
     * @param string $collectionName Collection 名称
     * @param array $updates 更新数组
     * @param array $options 选项
     * @return int 影响行数
     */
    public function batchUpdate(string $collectionName, array $updates, array $options = []): int;

    /**
     * 批量删除记录
     *
     * @param string $collectionName Collection 名称
     * @param array $ids ID 数组
     * @param array $options 选项
     * @return int 影响行数
     */
    public function batchDelete(string $collectionName, array $ids, array $options = []): int;

    // ========== 聚合操作 ==========

    /**
     * 执行聚合查询
     *
     * @param string $collectionName Collection 名称
     * @param string $function 聚合函数
     * @param string|null $field 聚合字段
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return mixed 聚合结果
     */
    public function aggregate(
        string $collectionName,
        string $function,
        ?string $field = null,
        array $conditions = [],
        array $options = []
    ): mixed;

    /**
     * 分组聚合查询
     *
     * @param string $collectionName Collection 名称
     * @param array $groupBy 分组字段
     * @param array $aggregates 聚合配置
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return array 聚合结果
     */
    public function groupAggregate(
        string $collectionName,
        array $groupBy,
        array $aggregates,
        array $conditions = [],
        array $options = []
    ): array;

    /**
     * 检查记录是否存在
     *
     * @param string $collectionName Collection 名称
     * @param array $conditions 查询条件
     * @param array $options 选项
     * @return bool 是否存在
     */
    public function exists(string $collectionName, array $conditions, array $options = []): bool;

    // ========== 表单集成 ==========

    /**
     * 保存表单数据
     *
     * @param string $formName 表单名称
     * @param array $data 表单数据
     * @param array $options 选项
     * @return int|string 保存的 ID
     */
    public function saveFormData(string $formName, array $data, array $options = []): int|string;

    /**
     * 获取表单数据
     *
     * @param string $formName 表单名称
     * @param int|string $id 数据 ID
     * @param array $options 选项
     * @return array|null 表单数据
     */
    public function getFormData(string $formName, int|string $id, array $options = []): ?array;

    /**
     * 获取表单 Schema
     *
     * @param string $formName 表单名称
     * @param array $options 选项
     * @return array|null 表单 Schema
     */
    public function getFormSchema(string $formName, array $options = []): ?array;

    /**
     * 根据表单 Schema 自动填充工作流变量
     *
     * @param string $formName 表单名称
     * @param int|string $dataId 数据 ID
     * @param array $variableMapping 变量映射
     * @param array $options 选项
     * @return array 填充后的变量
     */
    public function autoFillVariables(
        string $formName,
        int|string $dataId,
        array $variableMapping = [],
        array $options = []
    ): array;

    // ========== Collection 元数据 ==========

    /**
     * 获取 Collection 信息
     *
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return CollectionInterface|null
     */
    public function getCollection(string $collectionName, array $options = []): ?CollectionInterface;

    /**
     * 获取 Collection 字段列表
     *
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return array 字段列表
     */
    public function getCollectionFields(string $collectionName, array $options = []): array;

    // ========== 权限集成 ==========

    /**
     * 检查操作权限
     *
     * @param string $operation 操作类型
     * @param string $collectionName Collection 名称
     * @param array $options 选项
     * @return bool 是否有权限
     */
    public function checkPermission(string $operation, string $collectionName, array $options = []): bool;
}
