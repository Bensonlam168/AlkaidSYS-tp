<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\Schema;

use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorGenerator;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use Infrastructure\Lowcode\Schema\SchemaParser;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;

/**
 * SchemaParser 测试
 */
class SchemaParserTest extends ThinkPHPTestCase
{
    protected SchemaParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $validatorGenerator = new FormValidatorGenerator();
        $validatorManager = new FormValidatorManager($validatorGenerator);
        $this->parser = new SchemaParser($validatorManager);
    }

    public function testParseReturnsComponentsValidatorAndPermissions(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'tenant_id'],
            'x-permissions' => ['forms:view'],
            'properties' => [
                'tenant_id' => [
                    'type' => 'integer',
                    'title' => '租户',
                    'x-component' => 'Select',
                    'x-permissions' => ['forms:manage'],
                    'x-tenant' => true,
                ],
                'name' => [
                    'type' => 'string',
                    'title' => '名称',
                    'x-component' => 'Input',
                    'minLength' => 2,
                ],
                'status' => [
                    'type' => 'string',
                    'title' => '状态',
                    'enum' => ['draft', 'published'],
                    'x-component' => 'Select',
                ],
            ],
        ];

        $result = $this->parser->parse($schema, ['tenant_id' => 9, 'site_id' => 1], 'demo-form');

        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('validator', $result);
        $this->assertArrayHasKey('permissions', $result);

        $components = $result['components'];
        $this->assertCount(3, $components);
        $this->assertEquals('tenant_id', $components[0]['field']);
        $this->assertTrue($components[0]['required']);
        $this->assertEquals('Select', $components[0]['component']);

        $validator = $result['validator'];
        $this->assertArrayHasKey('rules', $validator);
        $this->assertSame('require|number|tenantMatch', $validator['rules']['tenant_id']);
        $this->assertStringContainsString('min:2', $validator['rules']['name']);
        $this->assertEquals('租户无权访问该数据（code 2002）', $validator['messages']['tenant_id.tenantMatch']);

        $permissions = $result['permissions'];
        $this->assertEquals(['forms:view'], $permissions['schema']);
        $this->assertEquals(['forms:manage'], $permissions['fields']['tenant_id']);
    }
}
