<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Lowcode;

use app\command\lowcode\CreateFormCommand;
use Tests\ThinkPHPTestCase;
use Tests\Traits\DatabaseTransactions;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * CreateFormCommand Test | CreateFormCommand测试
 *
 * Tests for the lowcode:create-form command.
 * lowcode:create-form命令的测试。
 *
 * ## 数据库隔离（ER-17）
 *
 * 本测试类会**真实写库**，且原先只有 `testExistingTenantStillCreatesForm()` 自己清理了
 * `lowcode_forms`，实测每跑一次仍会留下 **`lowcode_schema_versions` 的一行**
 * （`FormSchemaManager::create()` → `SchemaVersionService::recordForm()` 写的版本快照，
 *  object_type=form / object_name=cmd_tenant_ok_*，从来没人删）。
 *
 * 这里用 `DatabaseTransactions` 把整个类罩进事务，`#[After]` 在 `tearDown()` 之后回滚，
 * 所有写入（lowcode_forms + lowcode_schema_versions）都被丢弃。
 *
 * 为什么**可以**用事务：
 *   1. 本类没有 DDL —— 用例不带 `--collection`，`FormSchemaManager` 只写元数据表，
 *      不会走 `SchemaBuilder` 建物理表；
 *   2. 命令是**同进程**直调（`$this->command->run(...)`），读写的都是同一个数据库连接，
 *      事务内的未提交数据对它立即可见，所以 `assertSame(1, ...->count())` 这类断言仍然成立；
 *      不存在「另开连接/子进程读不到未提交数据」的问题。
 *
 * 已有的 `finally` 清理**保留**：它在事务内执行、随后被回滚，行为等价于没写，
 * 但能让本文件将来万一脱离 trait 时仍不留 `lowcode_forms` 残渣。
 *
 * @package Tests\Unit\Command\Lowcode
 */
class CreateFormCommandTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    protected CreateFormCommand $command;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CreateFormCommand();
    }

    /**
     * Test command configuration | 测试命令配置
     *
     * @return void
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('lowcode:create-form', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());

        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('collection'));
        $this->assertTrue($definition->hasOption('tenant-id'));
        $this->assertTrue($definition->hasOption('site-id'));
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasOption('fields'));
    }

    /**
     * Test validate form name | 测试验证表单名称
     *
     * @return void
     */
    public function testIsValidFormName(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('isValidFormName');
        $method->setAccessible(true);

        // Valid names | 有效名称
        $this->assertTrue($method->invoke($this->command, 'product_form'));
        $this->assertTrue($method->invoke($this->command, 'ProductForm'));
        $this->assertTrue($method->invoke($this->command, '_form'));

        // Invalid names | 无效名称
        $this->assertFalse($method->invoke($this->command, '123form'));
        $this->assertFalse($method->invoke($this->command, 'form-name'));
        $this->assertFalse($method->invoke($this->command, 'form name'));
        $this->assertFalse($method->invoke($this->command, ''));
    }

    /**
     * Test map field type to schema type | 测试字段类型映射到Schema类型
     *
     * @return void
     */
    public function testMapFieldTypeToSchemaType(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('mapFieldTypeToSchemaType');
        $method->setAccessible(true);

        $this->assertEquals('string', $method->invoke($this->command, 'string'));
        $this->assertEquals('string', $method->invoke($this->command, 'text'));
        $this->assertEquals('integer', $method->invoke($this->command, 'integer'));
        $this->assertEquals('integer', $method->invoke($this->command, 'bigint'));
        $this->assertEquals('number', $method->invoke($this->command, 'decimal'));
        $this->assertEquals('boolean', $method->invoke($this->command, 'boolean'));
        $this->assertEquals('string', $method->invoke($this->command, 'date'));
        $this->assertEquals('string', $method->invoke($this->command, 'datetime'));
        $this->assertEquals('object', $method->invoke($this->command, 'json'));
        $this->assertEquals('string', $method->invoke($this->command, 'select'));
        $this->assertEquals('array', $method->invoke($this->command, 'checkbox'));
        $this->assertEquals('string', $method->invoke($this->command, 'file'));
        $this->assertEquals('string', $method->invoke($this->command, 'image'));

        // Unknown type should default to string | 未知类型应默认为string
        $this->assertEquals('string', $method->invoke($this->command, 'unknown_type'));
    }

    /**
     * Test generateFormSchema with field filter | 测试生成表单Schema时的字段过滤
     *
     * @return void
     */
    public function testGenerateFormSchemaWithFieldFilter(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('generateFormSchema');
        $method->setAccessible(true);

        $includedFieldName = 'name';
        $excludedFieldName = 'description';

        $fieldIncluded = new class ($includedFieldName) {
            private string $name;

            public function __construct(string $name)
            {
                $this->name = $name;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getType(): string
            {
                return 'string';
            }

            public function toArray(): array
            {
                return [
                    'title' => 'Name',
                    'nullable' => false,
                ];
            }
        };

        $fieldExcluded = new class ($excludedFieldName) {
            private string $name;

            public function __construct(string $name)
            {
                $this->name = $name;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getType(): string
            {
                return 'string';
            }

            public function toArray(): array
            {
                return [
                    'title' => 'Description',
                    'nullable' => true,
                ];
            }
        };

        $collection = new class ([$fieldIncluded, $fieldExcluded]) {
            /**
             * @var array<int, object>
             */
            private array $fields;

            public function __construct(array $fields)
            {
                $this->fields = $fields;
            }

            public function getFields(): array
            {
                return $this->fields;
            }
        };

        $schema = $method->invoke($this->command, $collection, [$includedFieldName]);

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('required', $schema);
        $this->assertArrayHasKey($includedFieldName, $schema['properties']);
        $this->assertArrayNotHasKey($excludedFieldName, $schema['properties']);
        $this->assertTrue(in_array($includedFieldName, $schema['required'], true));
    }

    /**
     * 租户存在性校验必须先于写库执行（R14 回归覆盖）
     *
     * 背景：`lowcode_forms.tenant_id` 带外键 `fk_lowcode_forms_tenant_id → tenants.id`。
     * 传入「不存在但为正」的租户ID 时，若命令不做前置存在性校验，就会一路走到写库，
     * 最后撞外键抛 1452（一条使用者看不懂的裸 SQL 错误）。修复方式是在任何写库动作之前
     * 先查 `tenants`，命中不存在就给出可读业务错误并返回 1。
     *
     * 本用例钉住两件事：
     *   1. 行为：退出码非 0、输出可读错误、不暴露外键错误；
     *   2. **位置**：校验发生在写库之前 —— 断言命令未进入写库阶段，且 `lowcode_forms` 行数未增加。
     * 若后人把校验挪到 `$formManager->create()` 之后（或删掉 `return 1`），本用例会失败。
     *
     * @return void
     */
    public function testNonExistentTenantIsRejectedBeforeAnyWrite(): void
    {
        // 构造一个「正数且一定不存在」的租户ID：现有最大租户ID + 1000
        $missingTenantId = (int) Db::name('tenants')->max('id') + 1000;
        $this->assertGreaterThan(0, $missingTenantId, '用于测试的租户ID必须是正数');
        $this->assertSame(
            0,
            (int) Db::name('tenants')->where('id', $missingTenantId)->count(),
            "前提不成立：租户 {$missingTenantId} 已存在，无法验证「租户不存在」分支"
        );

        // 唯一表单名，避免与其它用例/残留数据冲突（slug 冲突问题已裁定延后，这里自行规避）
        $formName = 'cmd_tenant_guard_' . uniqid();
        $formsBefore = (int) Db::name('lowcode_forms')->count();

        [$exitCode, $output] = $this->runCreateForm([
            $formName,
            '--tenant-id=' . $missingTenantId,
            '--force',
            '--format=text',
        ]);

        // 1) 退出码非 0（约定为 1）
        $this->assertSame(
            1,
            $exitCode,
            "租户不存在时应以退出码 1 结束，实际输出：\n{$output}"
        );

        // 2) 输出必须是可读业务错误，且带上租户ID，便于使用者定位
        $this->assertStringContainsString('Invalid --tenant-id', $output, '应命中 --tenant-id 校验分支');
        $this->assertStringContainsString('不存在', $output, "输出应包含「不存在」字样，实际输出：\n{$output}");
        $this->assertStringContainsString((string) $missingTenantId, $output, '错误文案应回显具体的租户ID');

        // 3) 不得把数据库外键错误直接暴露给使用者（R14 的原始症状）
        $this->assertStringNotContainsString('1452', $output, "不应向使用者抛出裸 SQL 1452，实际输出：\n{$output}");
        $this->assertStringNotContainsString(
            'Cannot add or update a child row',
            $output,
            "不应向使用者抛出外键约束错误，实际输出：\n{$output}"
        );

        // 4) 必须停在租户校验：不能进入写库阶段
        $this->assertStringNotContainsString(
            'Creating form',
            $output,
            "租户校验失败后不得再执行写库动作，实际输出：\n{$output}"
        );

        // 5) 关键位置断言：校验发生在写库之前，lowcode_forms 行数未增加
        $this->assertSame(
            $formsBefore,
            (int) Db::name('lowcode_forms')->count(),
            '租户不存在时不得向 lowcode_forms 写入任何数据（校验必须早于写库）'
        );
        $this->assertSame(
            0,
            (int) Db::name('lowcode_forms')->where('name', $formName)->count(),
            "租户不存在时不得写出表单 {$formName}"
        );
    }

    /**
     * 对照组：租户存在时命令仍能正常建表单
     *
     * 作用有二：
     *   1. 防止「租户存在性校验」修过头（例如查询写错导致永远返回「不存在」）；
     *   2. 证明上一条用例的「lowcode_forms 行数未增加」断言不是恒真 —— 同等条件下
     *      合法租户确实会新增一行。
     *
     * @return void
     */
    public function testExistingTenantStillCreatesForm(): void
    {
        $this->assertSame(
            1,
            (int) Db::name('tenants')->where('id', 1)->count(),
            '前提不成立：租户 1 必须存在（默认租户）'
        );

        $formName = 'cmd_tenant_ok_' . uniqid();

        try {
            [$exitCode, $output] = $this->runCreateForm([
                $formName,
                '--tenant-id=1',
                '--site-id=0',
                '--force',
                '--format=text',
            ]);

            $this->assertSame(0, $exitCode, "合法租户应创建成功，实际输出：\n{$output}");
            $this->assertStringContainsString('created successfully', $output, "实际输出：\n{$output}");
            $this->assertSame(
                1,
                (int) Db::name('lowcode_forms')->where('name', $formName)->count(),
                '合法租户应真的写入一行 lowcode_forms'
            );
        } finally {
            // 清理本用例写入的表单，避免污染其它用例。
            // 注（ER-17）：本类已挂 DatabaseTransactions，这条 DELETE 实际是在事务内执行、
            // 随后随回滚一并丢弃；保留它是为了「本文件脱离 trait 时也不留 lowcode_forms 残渣」。
            // 事务回滚同时覆盖了原先没人清的 lowcode_schema_versions（版本快照）。
            Db::name('lowcode_forms')->where('name', $formName)->delete();
        }
    }

    /**
     * 执行 lowcode:create-form 并回收「退出码 + 缓冲输出」
     *
     * 为什么不用 `think\Console::call()`：
     *   `Console::call()` 的返回值是 `Output`（buffer 驱动），它把 `Command::run()` 的
     *   **退出码丢掉了**（见 vendor/topthink/framework/src/think/Console.php::call()：
     *   `$this->find($command)->run($input, $output);` 之后只 return $output）。
     *   本用例必须断言「退出码非 0」，因此这里按 `call()` 的内部实现构造输入输出，
     *   直接调用 `run()` 同时拿到退出码与输出：
     *     new Input($parameters)  +  new Output('buffer')  +  Command::run()
     *
     * @param array<int, string> $parameters 命令行 token（不含命令名本身）
     * @return array{0: int, 1: string} [退出码, 缓冲输出]
     */
    private function runCreateForm(array $parameters): array
    {
        $output = new Output('buffer');
        $exitCode = $this->command->run(new Input($parameters), $output);

        return [$exitCode, (string) $output->fetch()];
    }
}
