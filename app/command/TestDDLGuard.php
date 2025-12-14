<?php

declare (strict_types=1);

namespace app\command;

use Infrastructure\Schema\SchemaBuilder;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\exception\HttpResponseException;

/**
 * TestDDLGuard Command | 运行时 DDL 环境防护测试命令
 *
 * 用于在不同 APP_ENV / 配置组合下验证 SchemaBuilder 的 T1-DDL-GUARD 行为：
 * - 当环境/开关不允许时，DDL 应被环境策略拒绝（返回 403 + code=5003）；
 * - 当允许时，可以在开发库中实际执行 DDL，并在测试结束后自动清理。
 */
class TestDDLGuard extends Command
{
    protected function configure()
    {
        $this->setName('test:ddl-guard')
            ->setDescription('Test runtime DDL environment guard behavior (T1-DDL-GUARD)');
    }

    protected function execute(Input $input, Output $output)
    {
        $env = (string) env('APP_ENV', 'production');
        $allowedEnvs = (string) env('SCHEMA_RUNTIME_DDL_ALLOWED_ENVS', 'dev,local,testing');
        $allowFlag = (bool) env('ALLOW_RUNTIME_DDL', false);

        $output->writeln("APP_ENV = {$env}");
        $output->writeln("SCHEMA_RUNTIME_DDL_ALLOWED_ENVS = {$allowedEnvs}");
        $output->writeln('ALLOW_RUNTIME_DDL = ' . ($allowFlag ? 'true' : 'false'));

        $builder = new SchemaBuilder();
        $tableName = 'test_ddl_guard_' . date('Ymd_His');

        $output->writeln("Attempting to create table {$tableName} via SchemaBuilder...");

        try {
            $result = $builder->createTable($tableName, [
                'id' => [
                    'type'           => 'int',
                    'length'         => 11,
                    'auto_increment' => true,
                    'primary'        => true,
                ],
            ]);

            if ($result) {
                $output->writeln('<info>DDL executed successfully (guard allowed).</info>');

                if ($builder->hasTable($tableName)) {
                    $builder->dropTable($tableName);
                    $output->writeln('<info>Cleanup: table dropped.</info>');
                }
            } else {
                $output->writeln('<comment>DDL execution returned false.</comment>');
            }
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $output->writeln('<info>Guard blocked runtime DDL as expected.</info>');
            if ($response) {
                $output->writeln('Response: ' . $response->getContent());
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Unexpected exception: ' . $e->getMessage() . '</error>');
            throw $e;
        }

        return 0;
    }
}
