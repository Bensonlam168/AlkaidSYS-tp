<?php

declare(strict_types=1);

namespace Tests\Integration\Lowcode;

use Tests\Support\CliProcess;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * Lowcode CLI 测试库守卫回归测试 | CLI test-database guard regression test
 *
 * 钉住两件事（ER-15 的第二半）：
 *   1. 测试上下文（ALKAID_TESTING=1）下，CLI 命令若解析出的库名不含 `_test`，
 *      必须**非 0 退出并明确报错**，而不是继续写库；
 *   2. 拦截发生在**任何写库动作之前** —— 测试库里也不该出现这一行
 *      （守卫在 Command::run() 里、先于 execute()，所以连测试库都没碰到）。
 *
 * 为什么需要这条用例：父进程的守卫（tests/ThinkPHPTestCase）只能保护自己这个进程。
 * 子进程是全新 PHP 进程，会重新 load `.env`；一旦有人把环境文件/参数改回开发库，
 * 只有 CLI 侧这道守卫能挡住 —— 没有用例钉住它，就等于没有守卫。
 *
 * 正向路径（子进程真的写到测试库）由 LowcodeCliE2ETest 覆盖，这里只做反向对照。
 *
 * @package Tests\Integration\Lowcode
 */
class LowcodeCliTestDatabaseGuardTest extends ThinkPHPTestCase
{
    /**
     * 与 LowcodeCliE2ETest 保持一致的隔离租户
     */
    private const TEST_TENANT_ID = 99997;

    /**
     * 故意把子进程指向开发库：守卫必须以非 0 退出，且不得写入任何一行
     */
    public function testCliRefusesToRunAgainstNonTestDatabase(): void
    {
        $name = 'e2e_guard_' . time() . '_' . random_int(1000, 9999);

        // 唯一允许「故意把子进程指向错误库」的入口（见 CliProcess::thinkWithEnvOverrides 的说明）
        $result = CliProcess::thinkWithEnvOverrides(
            [
                'lowcode:create-model',
                $name,
                '--fields=title:string',
                '--tenant-id=' . self::TEST_TENANT_ID,
                '--site-id=0',
                '--force',
            ],
            ['DB_NAME' => 'alkaid_sys']
        );

        $this->assertNotSame(
            0,
            $result->exitCode,
            "测试上下文下指向开发库必须非 0 退出（否则等于没有守卫）。实际输出：\n" . $result->output
        );

        $this->assertStringContainsString(
            '拒绝在非测试库上执行',
            $result->output,
            "错误信息必须明确指出拒绝原因。实际输出：\n" . $result->output
        );

        $this->assertStringContainsString(
            'alkaid_sys',
            $result->output,
            "错误信息必须带上解析出的库名，便于定位是哪里配错了。实际输出：\n" . $result->output
        );

        // 反向证明「拦截在写库之前」：测试库里也不该出现这一行
        $row = Db::table('lowcode_collections')
            ->where('name', $name)
            ->where('tenant_id', self::TEST_TENANT_ID)
            ->find();

        $this->assertNull($row, '守卫必须在写库之前拦截（测试库也不应出现该 Collection）');
    }
}
