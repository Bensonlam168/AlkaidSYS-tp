<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

/**
 * 持久化实体基类：以「数据库行 + 引擎取数口径」为最小职责
 *
 * 引擎的运行时模型（src/model/*）是**执行期内存对象**（NodeModel/TaskModel/ProcessModel），
 * 它们不落库；落库实体是另一层，本类就是这一层的基类。
 *
 * 引擎对实体的唯一契约是 `getData('键')`：
 * `ProcessEngines`、`EndProcessHandler`、`CounterSignHandler` 等都用 `getData()`
 * 读取 `id` / `process_define_id` / `process_instance_id` / `task_state` /
 * `task_name` / `variable` / `operator` / `parent_id` 这些**引擎视角的键名**，
 * 而本仓库的列名与之并不一致（例如 `process_define_id` ↔ `definition_id`）。
 * 因此：
 * - 行数据的真值只保存一份（`$attributes`，与表列一一对应，JSON 列已解码为数组）；
 * - `getData($key)` 先按子类 `columnFor()` 把引擎键映射到列名，再交给 `readColumn()`；
 * - `set($key, $value)` 逆向写回同一份行数据，便于引擎侧的「就地改状态」写法。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
abstract class AbstractRowEntity
{
    /**
     * @var array<string, mixed> 数据库行（JSON 列已解码为数组）
     */
    protected array $attributes = [];

    /**
     * @param array<string, mixed> $row 数据库行
     */
    public function __construct(array $row = [])
    {
        $this->attributes = $this->hydrate($row);
    }

    /**
     * 由数据库行水合实体
     *
     * @param array<string, mixed> $row 数据库行
     */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    /**
     * 读取实体数据（引擎视角键名）
     *
     * @param string $key     引擎视角键名
     * @param mixed  $default 缺省值
     * @return mixed
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        $value = $this->readColumn($this->columnFor($key));

        return $value ?? $default;
    }

    /**
     * 写入实体数据（引擎视角键名）
     *
     * @param string $key   引擎视角键名
     * @param mixed  $value 值
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$this->columnFor($key)] = $value;

        return $this;
    }

    /**
     * 是否包含某列
     */
    public function has(string $column): bool
    {
        return array_key_exists($column, $this->attributes);
    }

    /**
     * 主键
     */
    public function getId(): int|string|null
    {
        return $this->attributes['id'] ?? null;
    }

    /**
     * 所属租户
     */
    public function getTenantId(): ?int
    {
        $tenantId = $this->attributes['tenant_id'] ?? null;

        return $tenantId === null ? null : (int) $tenantId;
    }

    /**
     * 原始行数据（列名 => 值，JSON 列已解码）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * 原始行数据（列名 => 值）
     *
     * @return array<string, mixed>
     */
    public function getRow(): array
    {
        return $this->attributes;
    }

    /**
     * 转成可直接写库的列数据（JSON 列重新编码为字符串）
     *
     * @param string[] $only 只导出指定列（为空则导出全部）
     * @return array<string, mixed>
     */
    public function toPersistArray(array $only = []): array
    {
        $columns = $only === [] ? array_keys($this->attributes) : $only;
        $data = [];

        foreach ($columns as $column) {
            if (!array_key_exists($column, $this->attributes)) {
                continue;
            }

            $value = $this->attributes[$column];

            if (in_array($column, $this->jsonColumns(), true) && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * 引擎视角键名 => 本表列名（子类按需覆盖）
     */
    protected function columnFor(string $key): string
    {
        return $key;
    }

    /**
     * 声明哪些列是 JSON 列（子类按需覆盖）
     *
     * @return string[]
     */
    protected function jsonColumns(): array
    {
        return [];
    }

    /**
     * 读取列值（子类可覆盖以派生计算值）
     *
     * @param string $column 列名
     * @return mixed
     */
    protected function readColumn(string $column): mixed
    {
        return $this->attributes[$column] ?? null;
    }

    /**
     * 行数据水合：把 JSON 列的字符串解码成数组
     *
     * 注意：MySQL 的 json 列经 PDO 取回是**字符串**，不解码会让
     * `variableToDict()` 这类消费者拿到字符串而不是结构。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        foreach ($this->jsonColumns() as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $raw = $row[$column];

            if (!is_string($raw)) {
                continue;
            }

            if ($raw === '') {
                $row[$column] = [];
                continue;
            }

            $decoded = json_decode($raw, true);
            $row[$column] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
