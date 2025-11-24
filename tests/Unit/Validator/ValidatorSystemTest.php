<?php

declare(strict_types=1);

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Infrastructure\Validator\JsonSchemaValidatorGenerator;

/**
 * Validator System Unit Tests | 验证器系统单元测试
 */
class ValidatorSystemTest extends TestCase
{
    protected JsonSchemaValidatorGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new JsonSchemaValidatorGenerator();
    }

    /**
     * Test basic required rule generation | 测试基本必填规则生成
     */
    public function testBasicRequiredRuleGeneration()
    {
        $schema = [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertArrayHasKey('name', $rules);
        $this->assertStringContainsString('require', $rules['name']);
    }

    /**
     * Test type rule generation | 测试类型规则生成
     */
    public function testTypeRuleGeneration()
    {
        $schema = [
            'properties' => [
                'age' => ['type' => 'integer'],
                'price' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'tags' => ['type' => 'array'],
            ],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('integer', $rules['age']);
        $this->assertStringContainsString('float', $rules['price']);
        $this->assertStringContainsString('boolean', $rules['active']);
        $this->assertStringContainsString('array', $rules['tags']);
    }

    /**
     * Test string constraints | 测试字符串约束
     */
    public function testStringConstraints()
    {
        $schema = [
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 20,
                ],
                'email' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$',
                ],
            ],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('min:3', $rules['username']);
        $this->assertStringContainsString('max:20', $rules['username']);
        $this->assertStringContainsString('regex:', $rules['email']);
    }

    /**
     * Test number constraints | 测试数字约束
     */
    public function testNumberConstraints()
    {
        $schema = [
            'properties' => [
                'age' => [
                    'type' => 'integer',
                    'minimum' => 18,
                    'maximum' => 100,
                ],
            ],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('egt:18', $rules['age']);
        $this->assertStringContainsString('elt:100', $rules['age']);
    }

    /**
     * Test enum constraint | 测试枚举约束
     */
    public function testEnumConstraint()
    {
        $schema = [
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive', 'pending'],
                ],
            ],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('in:active,inactive,pending', $rules['status']);
    }

    /**
     * Test combined rules | 测试组合规则
     */
    public function testCombinedRules()
    {
        $schema = [
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 20,
                ],
            ],
            'required' => ['username'],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('require', $rules['username']);
        $this->assertStringContainsString('min:3', $rules['username']);
        $this->assertStringContainsString('max:20', $rules['username']);
    }

    /**
     * Test empty schema | 测试空schema
     */
    public function testEmptySchema()
    {
        $schema = [];

        $rules = $this->generator->generateRules($schema);

        $this->assertEmpty($rules);
    }

    /**
     * Test optional fields | 测试可选字段
     */
    public function testOptionalFields()
    {
        $schema = [
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $rules = $this->generator->generateRules($schema);

        $this->assertStringContainsString('require', $rules['name']);
        // Optional field email should not have 'require' rule, or may not have a rule at all
        // 可选字段email不应该有'require'规则，或者可能根本没有规则
        if (isset($rules['email'])) {
            $this->assertStringNotContainsString('require', $rules['email']);
        } else {
            // If optional field has no validation rules, that's also acceptable
            // 如果可选字段没有验证规则，这也是可接受的
            $this->assertTrue(true);
        }
    }
}
