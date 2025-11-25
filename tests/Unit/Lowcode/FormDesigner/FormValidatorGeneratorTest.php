<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\FormDesigner;

use Tests\ThinkPHPTestCase;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorGenerator;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use think\Validate;

/**
 * Form Validator Generator Test | 表单验证器生成器测试
 *
 * Tests FormValidatorGenerator service.
 * 测试FormValidatorGenerator服务。
 *
 * @package Tests\Unit\Lowcode\FormDesigner
 */
class FormValidatorGeneratorTest extends ThinkPHPTestCase
{
    protected FormValidatorGenerator $generator;
    protected FormValidatorManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new FormValidatorGenerator();
        $this->manager = new FormValidatorManager($this->generator);
    }

    /**
     * Test basic validator generation | 测试基本验证器生成
     */
    public function testBasicValidatorGeneration(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '姓名',
                ],
            ],
            'required' => ['name'],
        ];

        $validator = $this->generator->generate($schema);

        $this->assertInstanceOf(Validate::class, $validator);

        $rules = $this->getValidatorRules($validator);
        $this->assertArrayHasKey('name', $rules);
        $this->assertStringContainsString('require', $rules['name']);
    }

    /**
     * Helper method to get validator rules using reflection | 使用反射获取验证规则的辅助方法
     */
    protected function getValidatorRules(Validate $validator): array
    {
        $reflection = new \ReflectionClass($validator);
        $property = $reflection->getProperty('rule');
        $property->setAccessible(true);
        return $property->getValue($validator);
    }

    /**
     * Helper method to get validator messages using reflection | 使用反射获取验证消息的辅助方法
     */
    protected function getValidatorMessages(Validate $validator): array
    {
        $reflection = new \ReflectionClass($validator);
        $property = $reflection->getProperty('message');
        $property->setAccessible(true);
        return $property->getValue($validator);
    }

    /**
     * Test string length validation | 测试字符串长度验证
     */
    public function testStringLengthValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'title' => '用户名',
                    'minLength' => 3,
                    'maxLength' => 20,
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);
        $messages = $this->getValidatorMessages($validator);

        $this->assertStringContainsString('min:3', $rules['username']);
        $this->assertStringContainsString('max:20', $rules['username']);
        $this->assertEquals('用户名长度不能少于3个字符', $messages['username.min']);
        $this->assertEquals('用户名长度不能超过20个字符', $messages['username.max']);
    }

    /**
     * Test number range validation | 测试数字范围验证
     */
    public function testNumberRangeValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'age' => [
                    'type' => 'integer',
                    'title' => '年龄',
                    'minimum' => 18,
                    'maximum' => 100,
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);
        $messages = $this->getValidatorMessages($validator);

        $this->assertStringContainsString('number', $rules['age']);
        $this->assertStringContainsString('egt:18', $rules['age']);
        $this->assertStringContainsString('elt:100', $rules['age']);
        $this->assertEquals('年龄不能小于18', $messages['age.egt']);
        $this->assertEquals('年龄不能大于100', $messages['age.elt']);
    }

    /**
     * Test enum validation | 测试枚举验证
     */
    public function testEnumValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'title' => '状态',
                    'enum' => ['draft', 'published', 'archived'],
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);
        $messages = $this->getValidatorMessages($validator);

        $this->assertStringContainsString('in:draft,published,archived', $rules['status']);
        $this->assertStringContainsString('draft、published、archived', $messages['status.in']);
    }

    /**
     * Test format validation | 测试格式验证
     */
    public function testFormatValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'title' => '邮箱',
                    'format' => 'email',
                ],
                'website' => [
                    'type' => 'string',
                    'title' => '网站',
                    'format' => 'url',
                ],
                'birthday' => [
                    'type' => 'string',
                    'title' => '生日',
                    'format' => 'date',
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);

        $this->assertStringContainsString('email', $rules['email']);
        $this->assertStringContainsString('url', $rules['website']);
        $this->assertStringContainsString('date', $rules['birthday']);
    }

    /**
     * Test boolean validation | 测试布尔值验证
     */
    public function testBooleanValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'active' => [
                    'type' => 'boolean',
                    'title' => '激活状态',
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);
        $this->assertStringContainsString('boolean', $rules['active']);
    }

    /**
     * Test array validation | 测试数组验证
     */
    public function testArrayValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'title' => '标签',
                ],
            ],
        ];

        $validator = $this->generator->generate($schema);

        $rules = $this->getValidatorRules($validator);
        $this->assertStringContainsString('array', $rules['tags']);
    }

    /**
     * Test actual validation | 测试实际验证
     */
    public function testActualValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '商品名称',
                    'minLength' => 2,
                    'maxLength' => 50,
                ],
                'price' => [
                    'type' => 'number',
                    'title' => '价格',
                    'minimum' => 0,
                    'maximum' => 999999.99,
                ],
            ],
            'required' => ['name', 'price'],
        ];

        $validator = $this->generator->generate($schema);

        // Valid data | 有效数据
        $validData = [
            'name' => '测试商品',
            'price' => 99.99,
        ];
        $this->assertTrue($validator->check($validData));

        // Invalid data - missing required | 无效数据 - 缺少必填
        $invalidData1 = [
            'price' => 99.99,
        ];
        $validator2 = $this->generator->generate($schema);
        $this->assertFalse($validator2->check($invalidData1));

        // Invalid data - too short | 无效数据 - 太短
        $invalidData2 = [
            'name' => 'a',
            'price' => 99.99,
        ];
        $validator3 = $this->generator->generate($schema);
        $this->assertFalse($validator3->check($invalidData2));

        // Invalid data - out of range | 无效数据 - 超出范围
        $invalidData3 = [
            'name' => '测试商品',
            'price' => -10,
        ];
        $validator4 = $this->generator->generate($schema);
        $this->assertFalse($validator4->check($invalidData3));
    }

    /**
     * Test validator manager caching | 测试验证器管理器缓存
     */
    public function testValidatorManagerCaching(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '姓名',
                ],
            ],
        ];

        // First call - should generate and cache | 第一次调用 - 应生成并缓存
        $validator1 = $this->manager->getValidator($schema, 'test_form');

        // Second call - should retrieve from cache | 第二次调用 - 应从缓存获取
        $validator2 = $this->manager->getValidator($schema, 'test_form');

        $this->assertInstanceOf(Validate::class, $validator1);
        $this->assertInstanceOf(Validate::class, $validator2);
    }

    /**
     * Test validator manager validate method | 测试验证器管理器validate方法
     */
    public function testValidatorManagerValidate(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'title' => '邮箱',
                    'format' => 'email',
                ],
            ],
            'required' => ['email'],
        ];

        // Valid data | 有效数据
        $result1 = $this->manager->validate(['email' => 'test@example.com'], $schema);
        $this->assertTrue($result1);

        // Invalid data - missing required | 无效数据 - 缺少必填
        $result2 = $this->manager->validate([], $schema);
        $this->assertIsString($result2);
        $this->assertStringContainsString('邮箱', $result2);
    }

    /**
     * Test get rules method | 测试getRules方法
     */
    public function testGetRulesMethod(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '姓名',
                    'minLength' => 2,
                ],
            ],
            'required' => ['name'],
        ];

        $rules = $this->manager->getRules($schema);

        $this->assertIsArray($rules);
        $this->assertArrayHasKey('name', $rules);
        $this->assertStringContainsString('require', $rules['name']);
        $this->assertStringContainsString('min:2', $rules['name']);
    }

    /**
     * Test get messages method | 测试getMessages方法
     */
    public function testGetMessagesMethod(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'age' => [
                    'type' => 'integer',
                    'title' => '年龄',
                    'minimum' => 18,
                ],
            ],
        ];

        $messages = $this->manager->getMessages($schema);

        $this->assertIsArray($messages);
        $this->assertArrayHasKey('age.egt', $messages);
        $this->assertEquals('年龄不能小于18', $messages['age.egt']);
    }
}
