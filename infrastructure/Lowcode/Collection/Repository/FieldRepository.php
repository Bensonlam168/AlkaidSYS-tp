<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\facade\Db;

/**
 * Field Repository | 字段仓储
 * 
 * Handles persistence of Field entities.
 * 处理字段实体的持久化。
 * 
 * @package Infrastructure\Lowcode\Collection\Repository
 */
class FieldRepository
{
    protected string $table = 'lowcode_fields';

    /**
     * Save field | 保存字段
     * 
     * @param FieldInterface $field Field to save | 要保存的字段
     * @param int $collectionId Collection ID | Collection ID
     * @return int Field ID | 字段ID
     */
    public function save(FieldInterface $field, int $collectionId): int
    {
        $data = [
            'collection_id' => $collectionId,
            'name' => $field->getName(),
            'type' => $field->getType(),
            'db_type' => $field->getDbType(),
            'title' => $field->getTitle(),
            'nullable' => $field->isNullable(),
            'default' => $field->getDefault(),
            'options' => json_encode($field->getOptions()),
        ];

        // Check if field exists | 检查字段是否存在
        $exists = Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->where('name', $field->getName())
            ->find();

        if ($exists) {
            // Update existing | 更新已存在的
            Db::name($this->table)
                ->where('id', $exists['id'])
                ->update($data);
            return (int)$exists['id'];
        } else {
            // Insert new | 插入新的
            return (int)Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find fields by collection ID | 按Collection ID查找字段
     * 
     * @param int $collectionId Collection ID | Collection ID
     * @return array<string, FieldInterface>
     */
    public function findByCollectionId(int $collectionId): array
    {
        $rows = Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $fields = [];
        foreach ($rows as $row) {
            $field = $this->hydrate($row);
            $fields[$field->getName()] = $field;
        }

        return $fields;
    }

    /**
     * Delete field | 删除字段
     * 
     * @param int $collectionId Collection ID | Collection ID
     * @param string $fieldName Field name | 字段名称
     * @return bool
     */
    public function delete(int $collectionId, string $fieldName): bool
    {
        return (bool)Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->where('name', $fieldName)
            ->delete();
    }

    /**
     * Delete all fields by collection ID | 按Collection ID删除所有字段
     * 
     * @param int $collectionId Collection ID | Collection ID
     * @return bool
     */
    public function deleteByCollectionId(int $collectionId): bool
    {
        return (bool)Db::name($this->table)
            ->where('collection_id', $collectionId)
            ->delete();
    }

    /**
     * Hydrate field from database row | 从数据库行填充字段
     * 
     * @param array $data Database row | 数据库行
     * @return FieldInterface
     */
    protected function hydrate(array $data): FieldInterface
    {
        $options = json_decode($data['options'], true) ?? [];
        $options['title'] = $data['title'];
        $options['nullable'] = (bool)$data['nullable'];
        $options['default'] = $data['default'];

        return FieldFactory::create(
            $data['type'],
            $data['name'],
            $options
        );
    }
}
