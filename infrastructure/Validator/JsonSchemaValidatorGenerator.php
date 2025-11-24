<?php

declare(strict_types=1);

namespace Infrastructure\Validator;

use Domain\Validator\Interfaces\ValidatorGeneratorInterface;

/**
 * JSON Schema Validator Generator | JSON Schema 验证器生成器
 * 
 * Generates ThinkPHP validation rules from JSON Schema definitions.
 * 从 JSON Schema 定义生成 ThinkPHP 验证规则。
 * 
 * @package Infrastructure\Validator
 */
class JsonSchemaValidatorGenerator implements ValidatorGeneratorInterface
{
    /**
     * {@inheritDoc}
     */
    public function generateRules(array $schema): array
    {
        $rules = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $field => $prop) {
            $fieldRules = [];

            // Required | 必填字段
            if (in_array($field, $required)) {
                $fieldRules[] = 'require';
            }

            // Type | 类型
            if (isset($prop['type'])) {
                switch ($prop['type']) {
                    case 'integer':
                        $fieldRules[] = 'integer';
                        break;
                    case 'number':
                        $fieldRules[] = 'float';
                        break;
                    case 'boolean':
                        $fieldRules[] = 'boolean';
                        break;
                    case 'array':
                        $fieldRules[] = 'array';
                        break;
                    case 'string':
                        // string is default | 字符串是默认类型
                        break;
                }
            }

            // String constraints | 字符串约束
            if (isset($prop['minLength'])) {
                $fieldRules[] = 'min:' . $prop['minLength'];
            }
            if (isset($prop['maxLength'])) {
                $fieldRules[] = 'max:' . $prop['maxLength'];
            }
            if (isset($prop['pattern'])) {
                $fieldRules[] = 'regex:' . $prop['pattern'];
            }

            // Number constraints | 数字约束
            if (isset($prop['minimum'])) {
                $fieldRules[] = 'egt:' . $prop['minimum'];
            }
            if (isset($prop['maximum'])) {
                $fieldRules[] = 'elt:' . $prop['maximum'];
            }

            // Enum | 枚举值
            if (isset($prop['enum'])) {
                $fieldRules[] = 'in:' . implode(',', $prop['enum']);
            }

            if (!empty($fieldRules)) {
                $rules[$field] = implode('|', $fieldRules);
            }
        }

        return $rules;
    }
}
