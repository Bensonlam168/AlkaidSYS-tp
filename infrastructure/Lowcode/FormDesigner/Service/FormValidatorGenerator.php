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
     * @param array $context 上下文（tenant_id/site_id/user_id 等，用于跨字段/租户校验）
     * @return Validate ThinkPHP Validate instance | ThinkPHP验证器实例
     */
    public function generate(array $schema, array $context = []): Validate
    {
        $rules = [];
        $messages = [];

        foreach ($schema['properties'] ?? [] as $field => $config) {
            $fieldRules = $this->generateFieldRules($field, $config, $schema);

            if ($fieldRules !== '') {
                $rules[$field] = $fieldRules;
                $messages = array_merge($messages, $this->generateFieldMessages($field, $config));
            }
        }

        $validate = new Validate();
        $this->registerCustomRules($validate, $context);
        $validate->rule($rules);
        $validate->message($messages);

        return $validate;
    }

    /**
     * 根据已生成的 rule/message 构建验证器（用于缓存复用）。
     */
    public function buildFromDefinition(array $rules, array $messages, array $context = []): Validate
    {
        $validate = new Validate();
        $this->registerCustomRules($validate, $context);
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
                if (isset($config['minItems'])) {
                    $rules[] = "min:{$config['minItems']}";
                }
                if (isset($config['maxItems'])) {
                    $rules[] = "max:{$config['maxItems']}";
                }
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
                case 'datetime':
                    $rules[] = 'date';
                    break;
                case 'uuid':
                    $rules[] = 'regex:^([a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$';
                    break;
                case 'mobile':
                    $rules[] = 'regex:^1[3-9][0-9]{9}$';
                    break;
            }
        }

        // Pattern validation | 正则校验
        if (isset($config['pattern']) && is_string($config['pattern']) && $config['pattern'] !== '') {
            $rules[] = 'regex:' . $config['pattern'];
        }

        // Cross-field/compare | 跨字段比较
        if (isset($config['x-compare']) && is_array($config['x-compare'])) {
            $op = $config['x-compare']['op'] ?? null;
            $target = $config['x-compare']['field'] ?? null;
            if ($op && $target) {
                $rules[] = "compare:{$op},{$target}";
            }
        }

        // Tenant / Site 安全校验
        if (($config['x-tenant'] ?? false) || $field === 'tenant_id') {
            $rules[] = 'tenantMatch';
        }
        if (($config['x-site'] ?? false) || $field === 'site_id') {
            $rules[] = 'siteMatch';
        }

        // Custom rule override hook | 自定义规则覆写
        if (isset($config['x-rules']) && is_array($config['x-rules'])) {
            $rules = array_merge($rules, $config['x-rules']);
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
        $messages["{$field}.regex"] = "{$title}格式不正确";
        $messages["{$field}.tenantMatch"] = '租户无权访问该数据（code 2002）';
        $messages["{$field}.siteMatch"] = '站点无权访问该数据（code 2002）';

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

        if (isset($config['x-compare']['message'])) {
            $messages["{$field}.compare"] = $config['x-compare']['message'];
        }

        // 自定义消息覆写：x-messages 键值对
        if (isset($config['x-messages']) && is_array($config['x-messages'])) {
            $messages = array_merge($messages, $config['x-messages']);
        }

        return $messages;
    }

    /**
     * 注册自定义规则（跨字段比较、多租户匹配等）
     */
    protected function registerCustomRules(Validate $validate, array $context): void
    {
        // 常用规则补齐：think-validate 不内置 alpha，这里补齐以便 Schema 扩展使用
        $validate->extend('alpha', function ($value): bool {
            if ($value === null) {
                return true;
            }
            return ctype_alpha((string) $value);
        }, ':attribute只能包含字母');

        // 跨字段比较：compare:op,otherField
        $validate->extend('compare', function ($value, $rule, array $data = []) {
            [$op, $otherField] = array_pad(explode(',', (string) $rule, 2), 2, null);
            $other = $data[$otherField] ?? null;
            if ($other === null) {
                return true;
            }
            return match ($op) {
                'gt' => $value > $other,
                'gte' => $value >= $other,
                'lt' => $value < $other,
                'lte' => $value <= $other,
                'eq' => $value == $other,
                'ne' => $value != $other,
                default => true,
            };
        });

        $tenantId = $context['tenant_id'] ?? null;
        $siteId = $context['site_id'] ?? null;

        $validate->extend('tenantMatch', function ($value) use ($tenantId) {
            if ($tenantId === null) {
                return true; // 无上下文则不拦截
            }
            return (int) $value === (int) $tenantId;
        });

        $validate->extend('siteMatch', function ($value) use ($siteId) {
            if ($siteId === null) {
                return true;
            }
            return (int) $value === (int) $siteId;
        });
    }
}
