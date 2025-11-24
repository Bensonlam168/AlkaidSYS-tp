<?php

declare(strict_types=1);

namespace app\command;

use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Service\CollectionManager as LowcodeCollectionManager;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Test Lowcode CollectionManager | 测试 Lowcode CollectionManager
 */
class TestLowcodeCollection extends Command
{
    protected function configure()
    {
        $this->setName('test:lowcode-collection')
            ->setDescription('Test Lowcode CollectionManager CRUD operations | 测试 Lowcode CollectionManager 的增删改查');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>Testing Lowcode CollectionManager | 测试 Lowcode CollectionManager...</info>');
        $output->writeln('');

        $manager = app()->make(LowcodeCollectionManager::class);
        $testCollectionName = 'test_lowcode_collection_' . time();

        try {
            // 1. Create Collection | 创建集合
            $output->writeln("1. Creating Collection | 创建集合: {$testCollectionName}");

            $collection = new Collection($testCollectionName, [
                'title' => 'Test Lowcode Collection',
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
            $output->writeln('  <info>✓ Collection created | 集合已创建</info>');
            $output->writeln('');

            // 2. Get Collection | 获取集合
            $output->writeln('2. Getting Collection | 获取集合');
            $retrieved = $manager->get($testCollectionName);

            if ($retrieved && $retrieved->getName() === $testCollectionName) {
                $output->writeln('  <info>✓ Collection retrieved successfully | 集合获取成功</info>');
                $output->writeln('  Table name | 表名: ' . $retrieved->getTableName());
                $output->writeln('  Fields count | 字段数量: ' . count($retrieved->getFields()));
            } else {
                $output->writeln('  <error>✗ Failed to retrieve collection | 集合获取失败</error>');
                return 1;
            }
            $output->writeln('');

            // 3. Update Collection | 更新集合
            $output->writeln('3. Updating Collection | 更新集合');
            $emailField = FieldFactory::create('string', 'email', [
                'nullable' => true,
                'max_length' => 128,
            ]);
            $retrieved->addField($emailField);

            $manager->update($retrieved);
            $output->writeln('  <info>✓ Collection updated | 集合已更新</info>');
            $output->writeln('');

            // 4. Verify Update | 验证更新
            $output->writeln('4. Verifying Update | 验证更新');
            $updated = $manager->get($testCollectionName);

            if ($updated && count($updated->getFields()) === 3) {
                $output->writeln('  <info>✓ Update verified | 更新已验证</info>');
                $output->writeln('  Updated fields count | 更新后字段数量: ' . count($updated->getFields()));
            } else {
                $output->writeln('  <error>✗ Update verification failed | 更新验证失败</error>');
                return 1;
            }
            $output->writeln('');

            // 5. Delete Collection metadata | 删除集合元数据
            $output->writeln('5. Deleting Collection metadata | 删除集合元数据');
            $manager->delete($testCollectionName, true);

            $deleted = $manager->get($testCollectionName);
            if ($deleted === null) {
                $output->writeln('  <info>✓ Collection deleted successfully | 集合元数据删除成功</info>');
            } else {
                $output->writeln('  <error>✗ Collection still exists | 集合仍然存在</error>');
                return 1;
            }
            $output->writeln('');

            $output->writeln('<info>All Lowcode CollectionManager tests passed! | 所有 Lowcode CollectionManager 测试通过！</info>');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>Test failed | 测试失败: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}

