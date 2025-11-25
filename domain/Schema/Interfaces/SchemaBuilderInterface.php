<?php

declare(strict_types=1);

namespace Domain\Schema\Interfaces;

/**
 * Schema Builder Interface | Schema构建器接口
 *
 * Defines the contract for runtime DDL operations on database tables.
 * 定义数据库表运行时DDL操作的契约。
 *
 * @package Domain\Schema\Interfaces
 */
interface SchemaBuilderInterface
{
    /**
     * Create a new table with the given schema | 使用给定的schema创建新表
     *
     * @param string $tableName Table name | 表名
     * @param array $columns Column definitions | 列定义
     * @param array $indexes Index definitions | 索引定义
     * @param string $engine Storage engine (e.g., InnoDB) | 存储引擎（例如InnoDB）
     * @param string $comment Table comment | 表注释
     * @return bool
     */
    public function createTable(string $tableName, array $columns, array $indexes = [], string $engine = 'InnoDB', string $comment = ''): bool;

    /**
     * Drop a table if it exists | 删除表（如果存在）
     *
     * @param string $tableName Table name | 表名
     * @return bool
     */
    public function dropTable(string $tableName): bool;

    /**
     * Check if a table exists | 检查表是否存在
     *
     * @param string $tableName Table name | 表名
     * @return bool
     */
    public function hasTable(string $tableName): bool;

    /**
     * Add a column to an existing table | 向现有表添加列
     *
     * @param string $tableName Table name | 表名
     * @param string $columnName Column name | 列名
     * @param array $definition Column definition | 列定义
     * @return bool
     */
    public function addColumn(string $tableName, string $columnName, array $definition): bool;

    /**
     * Drop a column from a table | 从表中删除列
     *
     * @param string $tableName Table name | 表名
     * @param string $columnName Column name | 列名
     * @return bool
     */
    public function dropColumn(string $tableName, string $columnName): bool;

    /**
     * Check if a column exists in a table | 检查表中是否存在列
     *
     * @param string $tableName Table name | 表名
     * @param string $columnName Column name | 列名
     * @return bool
     */
    public function hasColumn(string $tableName, string $columnName): bool;
}
