<?php

declare(strict_types=1);

namespace app\command\lowcode;

use app\command\base\LowcodeCommand;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * Create Lowcode Form Command | 创建低代码表单命令
 *
 * Creates a new form schema based on a Collection.
 * 基于Collection创建新的表单Schema。
 *
 * Usage: php think lowcode:create-form product_form --collection=Product --title="Product Form"
 * 用法: php think lowcode:create-form product_form --collection=Product --title="产品表单"
 *
 * @package app\command\lowcode
 */
class CreateFormCommand extends LowcodeCommand
{
    /**
     * Configure command | 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('lowcode:create-form')
            ->setDescription('Create a new lowcode form schema | 创建新的低代码表单Schema')
            ->addArgument('name', Argument::REQUIRED, 'Form name (e.g., product_form) | 表单名称（例如：product_form）')
            ->addOption('collection', 'c', Option::VALUE_REQUIRED, 'Collection name to base form on | 基于的Collection名称')
            ->addOption('title', 't', Option::VALUE_OPTIONAL, 'Form title | 表单标题')
            ->addOption('description', 'd', Option::VALUE_OPTIONAL, 'Form description | 表单描述')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID（默认：0）', '0')
            ->addOption('site-id', null, Option::VALUE_OPTIONAL, 'Site ID (default: 0) | 站点ID（默认：0）', '0')
            ->addOption('force', null, Option::VALUE_NONE, 'Force overwrite if form exists | 如果表单存在则强制覆盖');
    }

    /**
     * Execute command | 执行命令
     *
     * @param Input $input Input instance | 输入实例
     * @param Output $output Output instance | 输出实例
     * @return int Exit code | 退出码
     */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $this->section('Creating Lowcode Form | 创建低代码表单');

            // Get arguments and options | 获取参数和选项
            $name = $input->getArgument('name');
            $collectionName = $input->getOption('collection');
            $title = $input->getOption('title') ?: $name;
            $description = $input->getOption('description') ?: '';
            $tenantId = $this->getTenantId();
            $siteId = $this->getSiteId();
            $force = $input->getOption('force');

            // Validate form name | 验证表单名称
            if (!$this->isValidFormName($name)) {
                $this->error("Invalid form name: {$name}. Use alphanumeric characters and underscores only.");
                return 1;
            }

            // Get managers | 获取管理器
            $formManager = app()->make(FormSchemaManager::class);
            $collectionManager = app()->make(CollectionManager::class);

            // Check if form exists | 检查表单是否存在
            $existing = $formManager->get($name, $tenantId, $siteId);
            if ($existing && !$force) {
                $this->error("Form '{$name}' already exists. Use --force to overwrite.");
                return 1;
            }

            // Get collection if specified | 如果指定了Collection则获取
            $collection = null;
            if ($collectionName) {
                $collection = $collectionManager->get($collectionName, $tenantId);
                if (!$collection) {
                    $this->error("Collection '{$collectionName}' not found.");
                    return 1;
                }
                $this->success("Found collection: {$collectionName}");
            }

            // Generate form schema | 生成表单Schema
            $schema = $this->generateFormSchema($collection);

            // Display summary | 显示摘要
            $this->displaySummary($name, $title, $description, $collectionName, $tenantId, $siteId, $schema);

            // Confirm creation | 确认创建
            if (!$force && !$this->confirm('Create this form?', true)) {
                $this->warning('Operation cancelled.');
                return 0;
            }

            // Create form | 创建表单
            $this->info('Creating form...');

            $formData = [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'collection_name' => $collectionName,
                'schema' => $schema,
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
            ];

            if ($existing) {
                // Update existing form | 更新现有表单
                $formManager->update($existing['id'], $formData, $tenantId);
                $this->success("Form '{$name}' updated successfully!");
            } else {
                // Create new form | 创建新表单
                $formManager->create($formData);
                $this->success("Form '{$name}' created successfully!");
            }

            // Show next steps | 显示下一步操作
            $this->showNextSteps($name, $collectionName);

            return 0;
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Validate form name | 验证表单名称
     *
     * @param string $name Form name | 表单名称
     * @return bool Is valid | 是否有效
     */
    protected function isValidFormName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    /**
     * Generate form schema from collection | 从Collection生成表单Schema
     *
     * @param \Domain\Lowcode\Collection\Interfaces\CollectionInterface|null $collection Collection instance | Collection实例
     * @return array Form schema | 表单Schema
     */
    protected function generateFormSchema($collection): array
    {
        if (!$collection) {
            // Return empty schema | 返回空Schema
            return [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ];
        }

        $properties = [];
        $required = [];

        foreach ($collection->getFields() as $field) {
            $fieldName = $field->getName();
            $fieldType = $field->getType();
            $fieldArray = $field->toArray();

            // Map field type to JSON Schema type | 将字段类型映射到JSON Schema类型
            $schemaType = $this->mapFieldTypeToSchemaType($fieldType);

            $properties[$fieldName] = [
                'type' => $schemaType,
                'title' => $fieldArray['title'] ?? ucfirst($fieldName),
                'description' => $fieldArray['description'] ?? '',
            ];

            // Add field-specific properties | 添加字段特定属性
            if ($fieldType === 'string' && isset($fieldArray['max_length'])) {
                $properties[$fieldName]['maxLength'] = $fieldArray['max_length'];
            }

            if ($fieldType === 'integer') {
                if (isset($fieldArray['minimum'])) {
                    $properties[$fieldName]['minimum'] = $fieldArray['minimum'];
                }
                if (isset($fieldArray['maximum'])) {
                    $properties[$fieldName]['maximum'] = $fieldArray['maximum'];
                }
            }

            if ($fieldType === 'select' && isset($fieldArray['options'])) {
                $properties[$fieldName]['enum'] = array_column($fieldArray['options'], 'value');
            }

            // Add to required if not nullable | 如果不可为空则添加到required
            if (isset($fieldArray['nullable']) && !$fieldArray['nullable']) {
                $required[] = $fieldName;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Map field type to JSON Schema type | 将字段类型映射到JSON Schema类型
     *
     * @param string $fieldType Field type | 字段类型
     * @return string JSON Schema type | JSON Schema类型
     */
    protected function mapFieldTypeToSchemaType(string $fieldType): string
    {
        $mapping = [
            'string' => 'string',
            'text' => 'string',
            'integer' => 'integer',
            'bigint' => 'integer',
            'decimal' => 'number',
            'boolean' => 'boolean',
            'date' => 'string',
            'datetime' => 'string',
            'timestamp' => 'string',
            'json' => 'object',
            'select' => 'string',
            'radio' => 'string',
            'checkbox' => 'array',
            'file' => 'string',
            'image' => 'string',
        ];

        return $mapping[$fieldType] ?? 'string';
    }

    /**
     * Display creation summary | 显示创建摘要
     *
     * @param string $name Form name | 表单名称
     * @param string $title Form title | 表单标题
     * @param string $description Form description | 表单描述
     * @param string|null $collectionName Collection name | Collection名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int $siteId Site ID | 站点ID
     * @param array $schema Form schema | 表单Schema
     * @return void
     */
    protected function displaySummary(string $name, string $title, string $description, ?string $collectionName, int $tenantId, int $siteId, array $schema): void
    {
        $this->output->writeln('');
        $this->info('Form Summary:');
        $this->output->writeln("  Name: {$name}");
        $this->output->writeln("  Title: {$title}");
        if ($description) {
            $this->output->writeln("  Description: {$description}");
        }
        if ($collectionName) {
            $this->output->writeln("  Collection: {$collectionName}");
        }
        $this->output->writeln("  Tenant ID: {$tenantId}");
        $this->output->writeln("  Site ID: {$siteId}");
        $this->output->writeln('');
        $this->info('Form Fields:');
        $fieldCount = count($schema['properties'] ?? []);
        $this->output->writeln("  Total fields: {$fieldCount}");
        foreach ($schema['properties'] ?? [] as $fieldName => $fieldSchema) {
            $required = in_array($fieldName, $schema['required'] ?? [], true) ? ' (required)' : '';
            $this->output->writeln("  - {$fieldName}: {$fieldSchema['type']}{$required}");
        }
        $this->output->writeln('');
    }

    /**
     * Show next steps | 显示下一步操作
     *
     * @param string $name Form name | 表单名称
     * @param string|null $collectionName Collection name | Collection名称
     * @return void
     */
    protected function showNextSteps(string $name, ?string $collectionName): void
    {
        $this->output->writeln('');
        $this->info('Next steps:');
        $this->output->writeln("  1. Test the form via API: POST /v1/lowcode/forms/{$name}/data");
        if ($collectionName) {
            $this->output->writeln("  2. Generate CRUD code: php think lowcode:generate crud {$collectionName}");
        }
        $this->output->writeln('');
    }
}
