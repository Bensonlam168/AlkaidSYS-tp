<?php

declare(strict_types=1);

namespace Domain\Lowcode\Collection\Enum;

/**
 * Relationship Type Enum | 关系类型枚举
 * 
 * Defines supported relationship types.
 * 定义支持的关系类型。
 * 
 * @package Domain\Lowcode\Collection\Enum
 */
class RelationType
{
    /**
     * One-to-one relationship | 一对一关系
     */
    public const HAS_ONE = 'hasOne';

    /**
     * One-to-many relationship | 一对多关系
     */
    public const HAS_MANY = 'hasMany';

    /**
     * Belongs to relationship | 属于关系
     */
    public const BELONGS_TO = 'belongsTo';

    /**
     * Many-to-many relationship | 多对多关系
     */
    public const BELONGS_TO_MANY = 'belongsToMany';

    /**
     * Get all relation types | 获取所有关系类型
     * 
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::HAS_ONE,
            self::HAS_MANY,
            self::BELONGS_TO,
            self::BELONGS_TO_MANY,
        ];
    }

    /**
     * Check if type is valid | 检查类型是否有效
     * 
     * @param string $type Type to check | 要检查的类型
     * @return bool
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
