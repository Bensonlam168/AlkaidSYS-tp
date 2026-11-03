<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use Alkaid\Workflow\Engine\Definition\WorkflowValidator;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;

/**
 * WorkflowDefinitionController 单元测试
 *
 * 测试工作流定义 API 的核心功能
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Api
 */
class WorkflowDefinitionControllerTest extends TestCase
{
    /**
     * 测试创建工作流定义
     */
    public function testCreateWorkflowDefinition(): void
    {
        // 使用 WorkflowBuilder 创建定义
        $builder = WorkflowBuilder::create('test_workflow', [
            'id' => 'wf_test_001',
            'tenant_id' => 100,
        ]);

        $builder
            ->displayName('测试工作流')
            ->description('这是一个测试工作流')
            ->asApproval()
            ->meta('category', 'test');

        $definition = $builder->getDefinition();

        $this->assertEquals('wf_test_001', $definition->getId());
        $this->assertEquals('test_workflow', $definition->getName());
        $this->assertEquals('测试工作流', $definition->getDisplayName());
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $definition->getType());
        $this->assertEquals(100, $definition->getTenantId());
        $this->assertEquals('test', $definition->getMeta('category'));
    }

    /**
     * 测试工作流定义验证
     */
    public function testWorkflowDefinitionValidation(): void
    {
        // 创建有效的工作流
        $builder = WorkflowBuilder::create('valid_workflow')
            ->start('start')
            ->userTask('approval')
            ->end('end');

        $definition = $builder->getDefinition();
        $validator = new WorkflowValidator();
        $result = $validator->validate($definition);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
    }

    /**
     * 测试无效工作流定义验证
     */
    public function testInvalidWorkflowDefinitionValidation(): void
    {
        // 创建无效的工作流（缺少开始节点）
        $definition = new WorkflowDefinition('invalid_workflow');

        $validator = new WorkflowValidator();
        $result = $validator->validate($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasErrorCode('MISSING_START_NODE'));
    }

    /**
     * 测试工作流定义发布
     */
    public function testPublishWorkflowDefinition(): void
    {
        $builder = WorkflowBuilder::create('publish_test')
            ->start('start')
            ->userTask('task')
            ->end('end');

        $definition = $builder->build(true);
        $definition->publish();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_PUBLISHED, $definition->getStatus());
        $this->assertNotNull($definition->getPublishedAt());
    }

    /**
     * 测试工作流定义归档
     */
    public function testArchiveWorkflowDefinition(): void
    {
        $builder = WorkflowBuilder::create('archive_test')
            ->start('start')
            ->userTask('task')
            ->end('end');

        $definition = $builder->build(true);
        $definition->publish();
        $definition->archive();

        $this->assertEquals(WorkflowDefinitionInterface::STATUS_ARCHIVED, $definition->getStatus());
    }

    /**
     * 测试工作流定义序列化
     */
    public function testWorkflowDefinitionSerialization(): void
    {
        $builder = WorkflowBuilder::create('serialize_test', ['id' => 'wf_serialize'])
            ->displayName('序列化测试')
            ->description('测试描述')
            ->asAutomation()
            ->start('start')
            ->serviceTask('service')
            ->end('end');

        $definition = $builder->getDefinition();

        // 序列化为数组
        $array = $definition->toArray();

        $this->assertEquals('wf_serialize', $array['id']);
        $this->assertEquals('serialize_test', $array['name']);
        $this->assertEquals('序列化测试', $array['display_name']);
        $this->assertIsArray($array['nodes']);
        $this->assertIsArray($array['connections']);

        // 序列化为 JSON
        $json = $definition->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals($array['id'], $decoded['id']);
    }

    /**
     * 测试从数组反序列化工作流定义
     */
    public function testWorkflowDefinitionDeserialization(): void
    {
        $data = [
            'id' => 'wf_deserialize',
            'name' => 'deserialize_test',
            'display_name' => '反序列化测试',
            'description' => '测试描述',
            'type' => WorkflowDefinitionInterface::TYPE_BUSINESS,
            'status' => WorkflowDefinitionInterface::STATUS_DRAFT,
            'version' => 1,
            'tenant_id' => 200,
            'nodes' => [],
            'connections' => [],
            'config' => ['timeout' => 3600],
            'metadata' => ['author' => 'test'],
        ];

        $definition = WorkflowDefinition::fromArray($data);

        $this->assertEquals('wf_deserialize', $definition->getId());
        $this->assertEquals('deserialize_test', $definition->getName());
        $this->assertEquals('反序列化测试', $definition->getDisplayName());
        $this->assertEquals(200, $definition->getTenantId());
        $this->assertEquals(3600, $definition->getConfig()['timeout'] ?? null);
        $this->assertEquals('test', $definition->getMeta('author'));
    }

    /**
     * 测试工作流定义版本管理
     */
    public function testWorkflowDefinitionVersioning(): void
    {
        $builder = WorkflowBuilder::create('version_test')
            ->version(1)
            ->start('start')
            ->end('end');

        $definition = $builder->getDefinition();
        $this->assertEquals(1, $definition->getVersion());

        // 模拟版本升级
        $definition->setVersion(2);
        $this->assertEquals(2, $definition->getVersion());
    }

    /**
     * 测试工作流定义类型设置
     */
    public function testWorkflowDefinitionTypes(): void
    {
        $builder = WorkflowBuilder::create('type_test');

        // 测试各种类型
        $builder->asBusiness();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_BUSINESS, $builder->getDefinition()->getType());

        $builder->asApproval();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_APPROVAL, $builder->getDefinition()->getType());

        $builder->asAutomation();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_AUTOMATION, $builder->getDefinition()->getType());

        $builder->asIntegration();
        $this->assertEquals(WorkflowDefinitionInterface::TYPE_INTEGRATION, $builder->getDefinition()->getType());
    }

    /**
     * 测试工作流定义配置
     */
    public function testWorkflowDefinitionConfig(): void
    {
        $config = [
            'timeout' => 7200,
            'retry_count' => 3,
            'notification' => [
                'enabled' => true,
                'channels' => ['email', 'sms'],
            ],
        ];

        $builder = WorkflowBuilder::create('config_test')
            ->config($config);

        $definition = $builder->getDefinition();
        $this->assertEquals($config, $definition->getConfig());
    }

    /**
     * 测试工作流定义元数据
     */
    public function testWorkflowDefinitionMetadata(): void
    {
        $builder = WorkflowBuilder::create('meta_test')
            ->meta('author', 'John Doe')
            ->meta('department', 'HR')
            ->meta('priority', 'high');

        $definition = $builder->getDefinition();

        $this->assertEquals('John Doe', $definition->getMeta('author'));
        $this->assertEquals('HR', $definition->getMeta('department'));
        $this->assertEquals('high', $definition->getMeta('priority'));
        $this->assertNull($definition->getMeta('nonexistent'));
    }
}
