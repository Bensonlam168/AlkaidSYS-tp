<?php

declare(strict_types=1);

namespace app\command;

use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Test Field Types Command | 测试字段类型命令
 * 
 * Tests all 15+ field types in the lowcode data modeling system.
 * 测试低代码数据建模系统中的所有15+字段类型。
 */
class TestFieldTypes extends Command
{
    protected function configure()
    {
        $this->setName('test:field-types')
            ->setDescription('Test all lowcode field types | 测试所有低代码字段类型');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>=== Testing Lowcode Field Types | 测试低代码字段类型 ===</info>');
        $output->writeln('');

        // Get all registered types | 获取所有注册的类型
        $types = FieldFactory::getTypes();
        $output->writeln(sprintf('<comment>Registered %d field types:</comment> %s', 
            count($types), 
            implode(', ', $types)
        ));
        $output->writeln('');

        $passedCount = 0;
        $failedCount = 0;

        // Test each field type | 测试每种字段类型
        foreach ($this->getTestCases() as $testCase) {
            $type = $testCase['type'];
            $name = $testCase['name'];
            $options = $testCase['options'] ?? [];
            
            try {
                // Create field | 创建字段
                $field = FieldFactory::create($type, $name, $options);
                
                // Test field creation | 测试字段创建
                $this->assert($field->getName() === $name, "Field name should be '{$name}'");
                $this->assert($field->getType() === $type, "Field type should be '{$type}'");
                
                // Test validation | 测试验证
                foreach ($testCase['valid'] ?? [] as $value) {
                    $this->assert($field->validate($value), "Value should be valid for {$type}");
                }
                
                foreach ($testCase['invalid'] ?? [] as $value) {
                    $this->assert(!$field->validate($value), "Value should be invalid for {$type}");
                }
                
                // Test toArray | 测试toArray
                $array = $field->toArray();
                $this->assert(is_array($array), "toArray() should return array");
                $this->assert(isset($array['name']) && $array['name'] === $name, "toArray() should contain name");
                
                $output->writeln(sprintf('<info>✓</info> <comment>%s</comment> passed', ucfirst($type)));
                $passedCount++;
                
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>✗</error> <comment>%s</comment> failed: %s', ucfirst($type), $e->getMessage()));
                $failedCount++;
            }
        }

        // Summary | 总结
        $output->writeln('');
        $output->writeln('<info>=== Test Summary | 测试总结 ===</info>');
        $output->writeln(sprintf('<info>Passed:</info> %d', $passedCount));
        if ($failedCount > 0) {
            $output->writeln(sprintf('<error>Failed:</error> %d', $failedCount));
            return 1;
        } else {
            $output->writeln('<info>All tests passed! | 所有测试通过！</info>');
            return 0;
        }
    }

    /**
     * Get test cases for all field types | 获取所有字段类型的测试用例
     */
    protected function getTestCases(): array
    {
        return [
            // String
            ['type' => 'string', 'name' => 'username', 'options' => ['nullable' => false, 'max_length' => 50],
             'valid' => ['john', 'alice123'], 'invalid' => [123, null, str_repeat('a', 256)]],
            
            // Text
            ['type' => 'text', 'name' => 'description', 'options' => ['nullable' => false],
             'valid' => ['Short text', str_repeat('a', 1000)], 'invalid' => [123, [], null]],
            
            // Integer
            ['type' => 'integer', 'name' => 'age', 'options' => ['nullable' => false, 'minimum' => 0, 'maximum' => 150],
             'valid' => [0, 25, 150, '30'], 'invalid' => [-1, 151, 'abc', null]],
            
            // Decimal
            ['type' => 'decimal', 'name' => 'price', 'options' => ['nullable' => false, 'precision' => 10, 'scale' => 2],
             'valid' => [99.99, 0.01, '123.45'], 'invalid' => ['abc', null]],
            
            // Boolean
            ['type' => 'boolean', 'name' => 'is_active', 'options' => ['nullable' => false],
             'valid' => [true, false, 1, 0, '1', '0'], 'invalid' => ['yes', 2, null]],
            
            // Date
            ['type' => 'date', 'name' => 'birth_date', 'options' => ['nullable' => false],
             'valid' => ['2024-01-01', '2000-12-31'], 'invalid' => ['invalid', '2024-13-01', 123, null]],
            
            // Datetime
            ['type' => 'datetime', 'name' => 'created_at', 'options' => ['nullable' => false],
             'valid' => ['2024-01-01 12:00:00', '2024-01-01 12:00'], 'invalid' => ['invalid', 123, null]],
            
            // JSON
            ['type' => 'json', 'name' => 'config', 'options' => ['nullable' => false],
             'valid' => [['key' => 'value'], '{"key":"value"}'], 'invalid' => ['invalid json', 123, null]],
            
            // Bigint
            ['type' => 'bigint', 'name' => 'big_number', 'options' => ['nullable' => false],
             'valid' => [12345678901234, '9999999999'], 'invalid' => ['abc', null]],
            
            // Timestamp
            ['type' => 'timestamp', 'name' => 'updated_at', 'options' => ['nullable' => false],
             'valid' => [time(), '2024-01-01 12:00:00'], 'invalid' => ['invalid', -1, null]],
            
            // File
            ['type' => 'file', 'name' => 'attachment', 'options' => ['nullable' => false, 'allowed_extensions' => ['pdf', 'doc']],
             'valid' => ['/path/to/file.pdf', '/path/to/doc.doc'], 'invalid' => ['/path/to/image.jpg', 123, null]],
            
            // Image
            ['type' => 'image', 'name' => 'avatar', 'options' => ['nullable' => false],
             'valid' => ['/path/to/image.jpg', '/path/to/image.png'], 'invalid' => ['/path/to/file.pdf', 123, null]],
            
            // Select
            ['type' => 'select', 'name' => 'status', 'options' => ['nullable' => false, 'enum' => ['draft', 'published', 'archived']],
             'valid' => ['draft', 'published'], 'invalid' => ['invalid', null]],
            
            // Radio
            ['type' => 'radio', 'name' => 'gender', 'options' => ['nullable' => false, 'enum' => ['male', 'female']],
             'valid' => ['male', 'female'], 'invalid' => ['other', null]],
            
            // Checkbox
            ['type' => 'checkbox', 'name' => 'tags', 'options' => ['nullable' => false, 'enum' => ['red', 'blue', 'green']],
             'valid' => [['red'], ['red', 'blue'], '["red","green"]'], 'invalid' => [['invalid'], 'string', null]],
        ];
    }

    /**
     * Simple assertion helper | 简单断言辅助函数
     */
    protected function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}
