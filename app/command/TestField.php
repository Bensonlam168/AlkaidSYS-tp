<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;

/**
 * Test Field Type System (Legacy CLI, now powered by Lowcode) | 测试字段类型系统（Legacy CLI，内部已迁移到 Lowcode）
 *
 * @deprecated since T1-DOMAIN-CLEANUP S3/S4, use test:field-types or Lowcode APIs instead.
 * @package app\command
 */
class TestField extends Command
{
    protected function configure()
    {
        $this->setName('test:field')
            ->setDescription('Test Field Type System | 测试字段类型系统');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("[DEPRECATED] test:field is deprecated, use test:field-types or Lowcode APIs instead. | [已废弃] 请使用 test:field-types 或 Lowcode 字段 API。");
        $output->writeln("");
        $output->writeln("Testing Field Type System | 测试字段类型系统...");
        $output->writeln("");

        // 1. Test FieldFactory | 测试字段工厂
        $output->writeln("1. Testing FieldFactory | 测试字段工厂");
        $types = FieldFactory::getTypes();
        $output->writeln("  Registered types | 已注册类型: " . implode(', ', $types));

        if (count($types) >= 4) {
            $output->writeln("  <info>✓ Factory contains expected types | 工厂包含预期类型</info>");
        } else {
            $output->writeln("  <error>✗ Factory missing types | 工厂缺少类型</error>");
            return 1;
        }
        $output->writeln("");

        // 2. Test Field Creation | 测试字段创建
        $output->writeln("2. Testing Field Creation | 测试字段创建");
        try {
            $stringField = FieldFactory::create('string', 'username', [
                'nullable' => false,
                'default' => 'guest'
            ]);
            $output->writeln("  Created string field | 创建字符串字段: " . $stringField->getName());
            $output->writeln("  <info>✓ Field creation successful | 字段创建成功</info>");
        } catch (\Exception $e) {
            $output->writeln("  <error>✗ Field creation failed | 字段创建失败: " . $e->getMessage() . "</error>");
            return 1;
        }
        $output->writeln("");

        // 3. Test Field Validation | 测试字段验证
        $output->writeln("3. Testing Field Validation | 测试字段验证");
        $validString = $stringField->validate("test");
        $invalidString = $stringField->validate(123);
        
        if ($validString && !$invalidString) {
            $output->writeln("  <info>✓ Validation works correctly | 验证工作正常</info>");
        } else {
            $output->writeln("  <error>✗ Validation failed | 验证失败</error>");
            return 1;
        }
        $output->writeln("");

        // 4. Test toArray | 测试toArray方法
        $output->writeln("4. Testing toArray() | 测试toArray()方法");
        $array = $stringField->toArray();
        $output->writeln("  Field array | 字段数组: " . json_encode($array, JSON_UNESCAPED_UNICODE));
        
        if (isset($array['name']) && isset($array['type'])) {
            $output->writeln("  <info>✓ toArray() works correctly | toArray()工作正常</info>");
        } else {
            $output->writeln("  <error>✗ toArray() missing keys | toArray()缺少键</error>");
            return 1;
        }
        $output->writeln("");

        // 5. Test Direct Field Creation | 测试直接字段创建
        $output->writeln("5. Testing Direct Field Creation | 测试直接字段创建");
        $intField = FieldFactory::create('integer', 'age', ['nullable' => true]);
        $validInt = $intField->validate(25);
        $validNull = $intField->validate(null);
        
        if ($validInt && $validNull) {
            $output->writeln("  <info>✓ Direct field creation works | 直接字段创建工作正常</info>");
        } else {
            $output->writeln("  <error>✗ Direct field creation failed | 直接字段创建失败</error>");
            return 1;
        }
        $output->writeln("");

        $output->writeln("<info>All tests passed! | 所有测试通过！</info>");
        return 0;
    }
}
