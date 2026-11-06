<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Multitenant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use RuntimeException;
use Swoole\Coroutine;

/**
 * 协程上下文隔离守卫测试
 *
 * 守的是 `TenantContext` 类注释里的两条不变量：
 *   1. 协程内的上下文只来自本协程，**不继承**进程级默认值；
 *   2. `user_id = null`（未登录）是一个明确的取值，读侧不得把它当成「没设过」
 *      再去借一个身份回来。
 *
 * 为什么必须有这组用例：`ResolvesWorkflowTenant::tenantId()` 靠
 * `TenantContext::getTenantId() === null` 判定「没有租户」并抛 `TenantIsolationException`。
 * 只要读侧在协程内回落到进程级默认值，fail-closed 就会静默失效 ——
 * 表现是「跨租户读写，日志里看不出异常」，不是报错，因此只能靠测试守住。
 *
 * 注意：断言一律放在协程**外**。协程里抛出的异常会被 Swoole 吞掉/致命化，
 * 落到 PHPUnit 报告里是「进程莫名退出」，而不是一条可读的失败。
 */
class TenantContextCoroutineIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole') || !class_exists(Coroutine::class)) {
            $this->markTestSkipped('未安装 Swoole 扩展：协程隔离用例无法在本环境运行');
        }

        TenantContext::clearAll();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();

        parent::tearDown();
    }

    /**
     * 非协程行为必须一字不变（回归基线）
     *
     * 修「协程内不回落」时最容易顺手改坏的就是非协程路径 —— CLI 命令、
     * 队列 worker、定时任务全都走这条路径。
     */
    #[Test]
    public function testNonCoroutineBehaviourIsUnchanged(): void
    {
        $this->assertNull(TenantContext::getTenantId());

        TenantContext::setTenantId(42);
        $this->assertSame(42, TenantContext::getTenantId());

        TenantContext::setUserId(7);
        $this->assertSame(7, TenantContext::getUserId());

        TenantContext::clear();
        $this->assertNull(TenantContext::getTenantId());
        $this->assertNull(TenantContext::getUserId());
    }

    /**
     * 协程内未设租户 —— 必须为 null，不得继承进程级默认值
     */
    #[Test]
    public function testCoroutineWithoutTenantDoesNotInheritProcessDefault(): void
    {
        // 「某个非协程代码（CLI 命令 / 队列 worker）先设过一次租户」
        TenantContext::setTenantId(42);

        $observed = 'not-run';

        Coroutine\run(function () use (&$observed): void {
            $observed = TenantContext::getTenantId();
        });

        $this->assertNull($observed, '协程内没设租户就必须是 null：继承默认值会让 fail-closed 失效');
        $this->assertSame(42, TenantContext::getTenantId(), '协程不得改写进程级默认值');
    }

    /**
     * `getTenantId(required: true)` 的语义不得改变：协程内没有租户照样抛异常
     */
    #[Test]
    public function testRequiredTenantStillThrowsInsideCoroutine(): void
    {
        TenantContext::setTenantId(42);

        $message = null;

        Coroutine\run(function () use (&$message): void {
            try {
                TenantContext::getTenantId(required: true);
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }
        });

        $this->assertNotNull($message, '协程内没有租户时 required=true 必须抛 RuntimeException');
        $this->assertStringContainsString('Tenant ID is required', (string) $message);
    }

    /**
     * 未登录请求不得继承上一个人的身份：`initialize($t, null)` 写出的 null 必须真的清掉旧值
     */
    #[Test]
    public function testInitializeWithNullUserIdDoesNotInheritPreviousIdentity(): void
    {
        TenantContext::setUserId(7);
        TenantContext::setTenantId(9);

        $observed = [];

        Coroutine\run(function () use (&$observed): void {
            TenantContext::initialize(1, null);

            $observed = [
                'tenant_id' => TenantContext::getTenantId(),
                'user_id' => TenantContext::getUserId(),
                'raw_user_id' => TenantContext::get('user_id', 'DEFAULT'),
            ];
        });

        $this->assertSame(1, $observed['tenant_id']);
        $this->assertNull($observed['user_id'], '未登录协程不得借到别的用户身份');
        $this->assertNull($observed['raw_user_id'], 'get() 同口径：键存在且为 null 就是 null，不是「没设过」');
    }

    /**
     * 协程内 `initialize()` 的整槽替换语义：旧键必须被覆盖掉
     */
    #[Test]
    public function testInitializeReplacesWholeSlotInsideCoroutine(): void
    {
        $observed = [];

        Coroutine\run(function () use (&$observed): void {
            TenantContext::initialize(1, 10, ['marker' => 'first']);
            TenantContext::initialize(2, null, []);

            $observed = [
                'tenant_id' => TenantContext::getTenantId(),
                'user_id' => TenantContext::getUserId(),
                'marker' => TenantContext::get('marker'),
            ];
        });

        $this->assertSame(2, $observed['tenant_id']);
        $this->assertNull($observed['user_id']);
        $this->assertNull($observed['marker'], '第二次 initialize 必须清掉第一次留下的额外键');
    }

    /**
     * 两个协程分别 initialize 租户 1 / 2，在 `Coroutine::sleep()` 处交错后各归各
     */
    #[Test]
    public function testConcurrentCoroutinesKeepTheirOwnContext(): void
    {
        $observed = [];

        Coroutine\run(function () use (&$observed): void {
            foreach ([1, 2] as $tenantId) {
                Coroutine::create(function () use ($tenantId, &$observed): void {
                    TenantContext::initialize($tenantId, $tenantId * 10);

                    $before = TenantContext::getTenantId();
                    Coroutine::sleep(0.01);
                    $after = TenantContext::getTenantId();
                    Coroutine::sleep(0.01);

                    $observed[$tenantId] = [
                        'before' => $before,
                        'after' => $after,
                        'final' => [TenantContext::getTenantId(), TenantContext::getUserId()],
                    ];
                });
            }
        });

        $this->assertCount(2, $observed, '两个协程都必须跑完');

        foreach ([1, 2] as $tenantId) {
            $this->assertSame($tenantId, $observed[$tenantId]['before'], '交错前不得被另一协程改写');
            $this->assertSame($tenantId, $observed[$tenantId]['after'], '交错后不得被另一协程改写');
            $this->assertSame([$tenantId, $tenantId * 10], $observed[$tenantId]['final']);
        }

        $this->assertNull(TenantContext::getTenantId(), '协程结束后不得残留租户上下文');
    }

    /**
     * `runWithTenant()` 的还原语义：协程内退出后既不留租户、也不留用户
     */
    #[Test]
    public function testRunWithTenantLeavesNoResidueInsideCoroutine(): void
    {
        TenantContext::setTenantId(42);

        $observed = [];

        Coroutine\run(function () use (&$observed): void {
            $inside = TenantContext::runWithTenant(200, static fn () => [
                TenantContext::getTenantId(),
                TenantContext::getUserId(),
            ], 400);

            $observed = [
                'inside' => $inside,
                'after_tenant' => TenantContext::getTenantId(),
                'after_user' => TenantContext::getUserId(),
            ];
        });

        $this->assertSame([200, 400], $observed['inside'], '作用域内必须看到本次写入的租户/用户');
        $this->assertNull($observed['after_tenant'], '退出后不得残留租户（更不得把进程级默认值写进协程槽位）');
        $this->assertNull($observed['after_user'], '退出后不得残留用户');
    }
}
