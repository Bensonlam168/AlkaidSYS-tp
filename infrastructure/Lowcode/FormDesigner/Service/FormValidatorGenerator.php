<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\FormDesigner\Service;

use think\Validate;

/**
 * Form Validator Generator | 表单验证器生成器
 *
 * Generates ThinkPHP validators from JSON Schema.
 * 从JSON Schema生成ThinkPHP验证器。
 *
 * Based on design: 09-lowcode-framework/43-lowcode-form-designer.md lines 583-718
 *
 * @package Infrastructure\Lowcode\FormDesigner\Service
 */
class FormValidatorGenerator
{
    /**
     * Generate validator from Schema | 从Schema生成验证器
     *
     * @param array $schema JSON Schema | JSON Schema
     * @return Validate ThinkPHP Validate instance | ThinkPHP验证器实例
     */
    public function generate(array $schema): Validate
    {
        $rules = [];
        $messages = [];

        foreach ($schema['properties'] ?? [] as $field => $config) {
            $fieldRules = $this->generateFieldRules($field, $config, $schema);

            if ($fieldRules) {
                $rules[$field] = $fieldRules;
                $messages = array_merge($messages, $this->generateFieldMessages($field, $config));
            }
        }

        $validate = new Validate();
        $validate->rule($rules);
        $validate->message($messages);

        return $validate;
    }

    /**
     * Generate field validation rules | 生成字段验证规则
     *
     * @param string $field Field name | 字段名
     * @param array $config Field configuration | 字段配置
     * @param array $schema Complete schema | 完整schema
     * @return string Validation rules | 验证规则
     */
    protected function generateFieldRules(string $field, array $config, array $schema): string
    {
        $rules = [];

        // Required validation | 必填验证
        if (in_array($field, $schema['required'] ?? [])) {
            $rules[] = 'require';
        }

        // Type validation | 类型验证
        switch ($config['type']) {
            case 'string':
                // String length validation | 字符串长度验证
                if (isset($config['minLength'])) {
                    $rules[] = "min:{$config['minLength']}";
                }
                if (isset($config['maxLength'])) {
                    $rules[] = "max:{$config['maxLength']}";
                }
                break;

            case 'number':
            case 'integer':
                $rules[] = 'number';

                // Number range validation | 数字范围验证
                if (isset($config['minimum'])) {
                    $rules[] = "egt:{$config['minimum']}";
                }
                if (isset($config['maximum'])) {
                    $rules[] = "elt:{$config['maximum']}";
                }
                break;

            case 'boolean':
                $rules[] = 'boolean';
                break;

            case 'array':
                $rules[] = 'array';
                break;
        }

        // Enum validation | 枚举验证
        if (isset($config['enum'])) {
            $rules[] = 'in:' . implode(',', $config['enum']);
        }

        // Format validation | 格式验证
        if (isset($config['format'])) {
            switch ($config['format']) {
                case 'email':
                    $rules[] = 'email';
                    break;
                case 'url':
                    $rules[] = 'url';
                    break;
                case 'date':
                    $rules[] = 'date';
                    break;
            }
        }

        return implode('|', $rules);
    }

    /**
     * Generate field error messages | 生成字段错误消息
     *
     * @param string $field Field name | 字段名
     * @param array $config Field configuration | 字段配置
     * @return array Error messages | 错误消息
     */
    protected function generateFieldMessages(string $field, array $config): array
    {
        $messages = [];
        $title = $config['title'] ?? $field;

        $messages["{$field}.require"] = "{$title}不能为空";
        $messages["{$field}.number"] = "{$title}必须是数字";
        $messages["{$field}.boolean"] = "{$title}必须是布尔值";
        $messages["{$field}.array"] = "{$title}必须是数组";
        $messages["{$field}.email"] = "{$title}格式不正确";
        $messages["{$field}.url"] = "{$title}格式不正确";
        $messages["{$field}.date"] = "{$title}格式不正确";

        if (isset($config['minLength'])) {
            $messages["{$field}.min"] = "{$title}长度不能少于{$config['minLength']}个字符";
        }
        if (isset($config['maxLength'])) {
            $messages["{$field}.max"] = "{$title}长度不能超过{$config['maxLength']}个字符";
        }
        if (isset($config['minimum'])) {
            $messages["{$field}.egt"] = "{$title}不能小于{$config['minimum']}";
        }
        if (isset($config['maximum'])) {
            $messages["{$field}.elt"] = "{$title}不能大于{$config['maximum']}";
        }
        if (isset($config['enum'])) {
            $messages["{$field}.in"] = "{$title}必须是以下值之一：" . implode('、', $config['enum']);
        }

        return $messages;
    }
}
