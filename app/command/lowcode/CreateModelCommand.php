<?php

declare(strict_types=1);

namespace app\command\lowcode;

use app\command\base\LowcodeCommand;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * Create Lowcode Model Command | 创建低代码模型命令
 *
 * Creates a new Collection (data model) with specified fields.
 * 创建新的Collection（数据模型）及其字段。
 *
 * Usage: php think lowcode:create-model Product --fields="name:string,price:decimal,stock:integer"
 * 用法: php think lowcode:create-model Product --fields="name:string,price:decimal,stock:integer"
 *
 * @package app\command\lowcode
 */
class CreateModelCommand extends LowcodeCommand
{
    /**
     * Configure command | 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('lowcode:create-model')
            ->setDescription('Create a new lowcode data model (Collection) | 创建新的低代码数据模型（Collection）')
            ->addArgument('name', Argument::REQUIRED, 'Collection name (e.g., Product) | Collection名称（例如：Product）')
            ->addOption('fields', 'f', Option::VALUE_REQUIRED, 'Field definitions (e.g., "name:string,price:decimal") | 字段定义（例如："name:string,price:decimal"）')
            ->addOption('title', 't', Option::VALUE_OPTIONAL, 'Collection title | Collection标题')
            ->addOption('description', 'd', Option::VALUE_OPTIONAL, 'Collection description | Collection描述')
            ->addOption('table-name', null, Option::VALUE_OPTIONAL, 'Custom table name | 自定义表名')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID（默认：0）', '0')
            ->addOption('site-id', null, Option::VALUE_OPTIONAL, 'Site ID (default: 0) | 站点ID（默认：0）', '0')
            ->addOption('force', null, Option::VALUE_NONE, 'Force overwrite if collection exists | 如果Collection存在则强制覆盖');
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
            $this->section('Creating Lowcode Model | 创建低代码模型');

            // Get arguments and options | 获取参数和选项
            $name = $input->getArgument('name');
            $fieldsStr = $input->getOption('fields');
            $title = $input->getOption('title') ?: $name;
            $description = $input->getOption('description') ?: '';
            $tableName = $input->getOption('table-name');
            $tenantId = $this->getTenantId();
            $siteId = $this->getSiteId();
            $force = $input->getOption('force');

            // Validate collection name | 验证Collection名称
            if (!$this->isValidCollectionName($name)) {
                $this->error("Invalid collection name: {$name}. Use alphanumeric characters and underscores only.");
                return 1;
            }

            // Get CollectionManager | 获取CollectionManager
            $manager = app()->make(CollectionManager::class);

            // Check if collection exists | 检查Collection是否存在
            $existing = $manager->get($name, $tenantId);
            if ($existing && !$force) {
                $this->error("Collection '{$name}' already exists. Use --force to overwrite.");
                return 1;
            }

            // Parse fields | 解析字段
            $fields = [];
            if ($fieldsStr) {
                $fields = $this->parseAndValidateFields($fieldsStr);
                if (empty($fields)) {
                    $this->error('No valid fields provided. Use --fields="name:type,..." format.');
                    return 1;
                }
            } else {
                // Interactive mode | 交互模式
                $this->info('No fields specified. Entering interactive mode...');
                $fields = $this->interactiveFieldInput();
                if (empty($fields)) {
                    $this->error('At least one field is required.');
                    return 1;
                }
            }

            // Display summary | 显示摘要
            $this->displaySummary($name, $title, $description, $fields, $tenantId, $siteId);

            // Confirm creation | 确认创建
            if (!$force && !$this->confirm('Create this collection?', true)) {
                $this->warning('Operation cancelled.');
                return 0;
            }

            // Create collection | 创建Collection
            $this->info('Creating collection...');

            $collection = new Collection($name, [
                'title' => $title,
                'description' => $description,
                'table_name' => $tableName,
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
            ]);

            // Add fields | 添加字段
            foreach ($fields as $fieldDef) {
                $field = FieldFactory::create(
                    $fieldDef['type'],
                    $fieldDef['name'],
                    $fieldDef['options'] ?? []
                );
                $collection->addField($field);
            }

            // Save collection | 保存Collection
            $manager->create($collection);

            $this->success("Collection '{$name}' created successfully!");
            $this->success("Table '{$collection->getTableName()}' created in database.");

            // Show next steps | 显示下一步操作
            $this->showNextSteps($name);

            return 0;
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Validate collection name | 验证Collection名称
     *
     * @param string $name Collection name | Collection名称
     * @return bool Is valid | 是否有效
     */
    protected function isValidCollectionName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    /**
     * Parse and validate fields | 解析并验证字段
     *
     * @param string $fieldsStr Fields string | 字段字符串
     * @return array Parsed fields | 解析后的字段
     */
    protected function parseAndValidateFields(string $fieldsStr): array
    {
        $fields = $this->parseFields($fieldsStr);
        $validFields = [];

        foreach ($fields as $field) {
            if (!$this->isValidFieldType($field['type'])) {
                $this->warning("Skipping field '{$field['name']}': invalid type '{$field['type']}'");
                continue;
            }

            $validFields[] = [
                'name' => $field['name'],
                'type' => $field['type'],
                'options' => $this->getDefaultFieldOptions($field['type']),
            ];
        }

        return $validFields;
    }

    /**
     * Get default field options by type | 根据类型获取默认字段选项
     *
     * @param string $type Field type | 字段类型
     * @return array Default options | 默认选项
     */
    protected function getDefaultFieldOptions(string $type): array
    {
        $defaults = [
            'string' => ['nullable' => true, 'max_length' => 255],
            'text' => ['nullable' => true],
            'integer' => ['nullable' => true],
            'decimal' => ['nullable' => true, 'precision' => 10, 'scale' => 2],
            'boolean' => ['nullable' => true, 'default' => false],
            'date' => ['nullable' => true],
            'datetime' => ['nullable' => true],
            'timestamp' => ['nullable' => true],
            'json' => ['nullable' => true],
            'select' => ['nullable' => true, 'options' => []],
        ];

        return $defaults[$type] ?? ['nullable' => true];
    }

    /**
     * Interactive field input | 交互式字段输入
     *
     * @return array Fields | 字段
     */
    protected function interactiveFieldInput(): array
    {
        $fields = [];
        $this->info('Enter field definitions (press Enter with empty name to finish):');

        while (true) {
            $name = $this->ask('Field name');
            if (empty($name)) {
                break;
            }

            $type = $this->ask('Field type (string/integer/decimal/boolean/date/datetime/text/json/select)', 'string');
            if (!$this->isValidFieldType($type)) {
                $this->warning("Invalid field type: {$type}");
                continue;
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'options' => $this->getDefaultFieldOptions($type),
            ];

            $this->success("Added field: {$name}:{$type}");
        }

        return $fields;
    }

    /**
     * Display creation summary | 显示创建摘要
     *
     * @param string $name Collection name | Collection名称
     * @param string $title Collection title | Collection标题
     * @param string $description Collection description | Collection描述
     * @param array $fields Fields | 字段
     * @param int $tenantId Tenant ID | 租户ID
     * @param int $siteId Site ID | 站点ID
     * @return void
     */
    protected function displaySummary(string $name, string $title, string $description, array $fields, int $tenantId, int $siteId): void
    {
        $this->output->writeln('');
        $this->info('Collection Summary:');
        $this->output->writeln("  Name: {$name}");
        $this->output->writeln("  Title: {$title}");
        if ($description) {
            $this->output->writeln("  Description: {$description}");
        }
        $this->output->writeln("  Tenant ID: {$tenantId}");
        $this->output->writeln("  Site ID: {$siteId}");
        $this->output->writeln('');
        $this->info('Fields:');
        foreach ($fields as $field) {
            $this->output->writeln("  - {$field['name']}: {$field['type']}");
        }
        $this->output->writeln('');
    }

    /**
     * Show next steps | 显示下一步操作
     *
     * @param string $name Collection name | Collection名称
     * @return void
     */
    protected function showNextSteps(string $name): void
    {
        $this->output->writeln('');
        $this->info('Next steps:');
        $this->output->writeln("  1. Generate CRUD code: php think lowcode:generate crud {$name}");
        $this->output->writeln("  2. Create form: php think lowcode:create-form {$name}_form --collection={$name}");
        $this->output->writeln('');
    }
}
