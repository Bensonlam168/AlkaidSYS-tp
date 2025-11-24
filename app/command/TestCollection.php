<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Service\CollectionManager as LowcodeCollectionManager;

/**
 * Test CollectionManager (Legacy CLI, now powered by Lowcode) | 测试集合管理器（Legacy CLI，内部已迁移到 Lowcode）
 *
 * @deprecated since T1-DOMAIN-CLEANUP S3/S4, use test:lowcode-collection instead.
 * @package app\command
 */
class TestCollection extends Command
{
    protected function configure()
    {
        $this->setName('test:collection')
            ->setDescription('Test CollectionManager CRUD operations | 测试集合管理器CRUD操作');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("[DEPRECATED] test:collection is deprecated, use test:lowcode-collection instead. | [已废弃]请使用 test:lowcode-collection 替代此命令。");
        $output->writeln("Testing CollectionManager (via Lowcode) | 测试集合管理器（通过 Lowcode 实现）...");
        $output->writeln("");

        $manager = app()->make(LowcodeCollectionManager::class);
        $testCollectionName = 'test_collection_' . time();

        try {
            // 1. Create Collection | 创建集合
            $output->writeln("1. Creating Collection | 创建集合: {$testCollectionName}");
            $collection = new Collection($testCollectionName, [
                'title' => 'Test Collection (Legacy CLI via Lowcode)',
            ]);

            $nameField = FieldFactory::create('string', 'name', [
                'nullable' => false,
                'max_length' => 255,
            ]);
            $ageField = FieldFactory::create('integer', 'age', [
                'nullable' => true,
                'minimum' => 0,
                'maximum' => 150,
            ]);

            $collection->addField($nameField);
            $collection->addField($ageField);

            $manager->create($collection);
            $output->writeln("  <info>✓ Collection created | 集合已创建</info>");
            $output->writeln("");

            // 2. Get Collection | 获取集合
            $output->writeln("2. Getting Collection | 获取集合");
            $retrieved = $manager->get($testCollectionName);

            if ($retrieved && $retrieved->getName() === $testCollectionName) {
                $output->writeln("  <info>✓ Collection retrieved successfully | 集合获取成功</info>");
                $output->writeln("  Table name | 表名: " . $retrieved->getTableName());
                $output->writeln("  Fields count | 字段数量: " . count($retrieved->getFields()));
            } else {
                $output->writeln("  <error>✗ Failed to retrieve collection | 集合获取失败</error>");
                return 1;
            }
            $output->writeln("");

            // 3. Update Collection | 更新集合
            $output->writeln("3. Updating Collection | 更新集合");
            $emailField = FieldFactory::create('string', 'email', ['nullable' => true]);
            $retrieved->addField($emailField);

            $manager->update($retrieved);
            $output->writeln("  <info>✓ Collection updated | 集合已更新</info>");
            $output->writeln("");

            // 4. Verify Update | 验证更新
            $output->writeln("4. Verifying Update | 验证更新");
            $updated = $manager->get($testCollectionName);

            if ($updated && count($updated->getFields()) === 3) {
                $output->writeln("  <info>✓ Update verified | 更新已验证</info>");
                $output->writeln("  Updated fields count | 更新后字段数量: " . count($updated->getFields()));
            } else {
                $output->writeln("  <error>✗ Update verification failed | 更新验证失败</error>");
                return 1;
            }
            $output->writeln("");

            // 5. Delete Collection | 删除集合
            $output->writeln("5. Deleting Collection | 删除集合");
            $manager->delete($testCollectionName);
            
            $deleted = $manager->get($testCollectionName);
            if ($deleted === null) {
                $output->writeln("  <info>✓ Collection deleted successfully | 集合删除成功</info>");
            } else {
                $output->writeln("  <error>✗ Collection still exists | 集合仍然存在</error>");
                return 1;
            }
            $output->writeln("");

            $output->writeln("<info>All CollectionManager tests passed! | 所有集合管理器测试通过！</info>");
            return 0;

        } catch (\Exception $e) {
            $output->writeln("<error>Test failed | 测试失败: " . $e->getMessage() . "</error>");
            
            // Cleanup | 清理
            try {
                $manager->delete($testCollectionName);
            } catch (\Exception $cleanupError) {
                // Ignore cleanup errors
            }
            
            return 1;
        }
    }
}
