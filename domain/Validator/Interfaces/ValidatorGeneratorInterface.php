<?php

declare(strict_types=1);

namespace Domain\Validator\Interfaces;

/**
 * Validator Generator Interface | 验证器生成器接口
 *
 * Defines the contract for generating validation rules from schema.
 * 定义从 schema 生成验证规则的契约。
 *
 * @package Domain\Validator\Interfaces
 */
interface ValidatorGeneratorInterface
{
    /**
     * Generate ThinkPHP validation rules from a schema | 从 schema 生成 ThinkPHP 验证规则
     *
     * @param array $schema JSON Schema definition | JSON Schema 定义
     * @return array Validation rules | 验证规则
     */
    public function generateRules(array $schema): array;
}
