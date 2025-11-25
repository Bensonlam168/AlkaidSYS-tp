<?php

declare (strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use Infrastructure\Schema\SchemaBuilder;

class TestSchema extends Command
{
    protected function configure()
    {
        // Command name
        $this->setName('test:schema')
            ->setDescription('Test SchemaBuilder functionality');
    }

    protected function execute(Input $input, Output $output)
    {
        $builder = new SchemaBuilder();
        $tableName = 'test_schema_table';

        $output->writeln('Testing SchemaBuilder...');

        // 1. Drop table if exists
        if ($builder->hasTable($tableName)) {
            $builder->dropTable($tableName);
            $output->writeln('Dropped existing table.');
        }

        // 2. Create table
        $columns = [
            'id' => ['type' => 'int', 'length' => 11, 'auto_increment' => true, 'primary' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'default' => 'test'],
            'age' => ['type' => 'int', 'length' => 3, 'default' => 18]
        ];

        $output->writeln("Creating table {$tableName}...");
        $result = $builder->createTable($tableName, $columns, [], 'InnoDB', 'Test Table');

        if ($result && $builder->hasTable($tableName)) {
            $output->writeln('<info>Table created successfully.</info>');
        } else {
            $output->writeln('<error>Failed to create table.</error>');
            return;
        }

        // 3. Add column
        $output->writeln("Adding column 'email'...");
        $builder->addColumn($tableName, 'email', ['type' => 'varchar', 'length' => 100, 'nullable' => true]);

        if ($builder->hasColumn($tableName, 'email')) {
            $output->writeln('<info>Column added successfully.</info>');
        } else {
            $output->writeln('<error>Failed to add column.</error>');
        }

        // 4. Drop column
        $output->writeln("Dropping column 'age'...");
        $builder->dropColumn($tableName, 'age');

        if (!$builder->hasColumn($tableName, 'age')) {
            $output->writeln('<info>Column dropped successfully.</info>');
        } else {
            $output->writeln('<error>Failed to drop column.</error>');
        }

        // 5. Clean up
        $output->writeln('Cleaning up...');
        $builder->dropTable($tableName);

        if (!$builder->hasTable($tableName)) {
            $output->writeln('<info>Table dropped successfully.</info>');
        } else {
            $output->writeln('<error>Failed to drop table.</error>');
        }

        $output->writeln('Test complete.');
    }
}
