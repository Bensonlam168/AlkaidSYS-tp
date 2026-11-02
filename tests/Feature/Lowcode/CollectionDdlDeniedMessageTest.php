<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Schema\SchemaBuilder;
use Mockery;
use Tests\ThinkPHPTestCase;
use think\exception\HttpResponseException;
use think\facade\Env;
use think\Response;

/**
 * Collection 删除接口的「运行时 DDL 被拒」错误文案回归（缺陷 B）
 *
 * 背景：`SchemaBuilder::assertCanRunDDL()` 拦截时抛 `think\exception\HttpResponseException`，
 * 该异常**只携带一个已构造好的 Response**，构造函数没有调用父类 ⇒ `getMessage()` 恒为空串。
 * 旧实现 `catch (\Exception $e) { return $this->error($e->getMessage()); }` 因此把
 * 「403 + 业务码 5003 + 明确原因」降级成「400 + 空 message」，客户端只能看到一个没有原因的错误。
 *
 * 本用例锁住四件事（都是**功能断言**，不涉及墙钟阈值）：
 *   1. 生产类环境被拒时：HTTP 403、业务码 5003、message 非空且含 reason
 *      （回归「生产即使打开 ALLOW_RUNTIME_DDL 也拒绝」这一层闸门）；
 *   2. ALLOW_RUNTIME_DDL 关闭时：message 指向 `ALLOW_RUNTIME_DDL`，调用方据此可自救；
 *   3. 两个分支都**不得**把堆栈 / SQL 拼进对外 message；
 *   4. 其它异常维持原行为（回传自身 message，HTTP 400），仅 message 为空时回落到类名。
 *
 * 为什么不走真实 HTTP + 真库：开关关掉时建表本身就会被拦，无法先「造一个集合再删」；
 * 而「闸门是否真的拦得住」已由 `Tests\Unit\Schema\SchemaBuilderTest` 覆盖。
 * 这里刻意只测**控制器对异常的处置契约**，把 SchemaBuilder 抛出的真实异常原样喂进来。
 *
 * @package Tests\Feature\Lowcode
 */
class CollectionDdlDeniedMessageTest extends ThinkPHPTestCase
{
    /**
     * 环境变量快照：任何用例结束后都必须原样还原
     *
     * `Env` 是进程级单例，`APP_ENV=production` 泄漏会让后续用例的鉴权前提失效。
     *
     * @var array<string, mixed>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'APP_ENV' => Env::get('APP_ENV'),
            'ALLOW_RUNTIME_DDL' => Env::get('ALLOW_RUNTIME_DDL'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            Env::set($key, $value);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 生产类环境：即使 ALLOW_RUNTIME_DDL=true 也必须被拒，且给出可操作提示
     */
    public function testProductionEnvironmentDenialCarriesReasonAndHint(): void
    {
        Env::set('APP_ENV', 'production');
        Env::set('ALLOW_RUNTIME_DDL', true);

        $response = $this->callDeleteThrowing($this->schemaDenial());

        $this->assertSame(403, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(5003, $body['code']);

        $message = (string) $body['message'];
        $this->assertNotSame('', trim($message), '错误 message 不得为空');
        $this->assertStringContainsString('运行时 DDL 被环境策略拒绝', $message);
        $this->assertStringContainsString('reason=production_environment', $message);
        $this->assertStringContainsString('迁移文件', $message);
        $this->assertStringNotContainsString('#0', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('DROP TABLE', $message);
    }

    /**
     * 开发环境但开关未打开：提示必须把「改哪个键」讲清楚
     */
    public function testFlagDisabledDenialPointsAtAllowRuntimeDdl(): void
    {
        Env::set('APP_ENV', 'dev');
        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->callDeleteThrowing($this->schemaDenial());

        $this->assertSame(403, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(5003, $body['code']);

        $message = (string) $body['message'];
        $this->assertNotSame('', trim($message), '错误 message 不得为空');
        $this->assertStringContainsString('reason=flag_disabled', $message);
        $this->assertStringContainsString('ALLOW_RUNTIME_DDL', $message);
        $this->assertStringContainsString('dev / local / testing', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
    }

    /**
     * 其它异常：保留原 message（行为不变）
     */
    public function testGenericExceptionKeepsOriginalMessage(): void
    {
        $response = $this->callDeleteThrowing(new \RuntimeException('Collection not found: probe'));

        $this->assertSame(400, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Collection not found: probe', $body['message']);
    }

    /**
     * 其它异常但 message 为空：回落到异常类名，避免再次出现「无原因的错误」
     */
    public function testEmptyExceptionMessageFallsBackToClassName(): void
    {
        $response = $this->callDeleteThrowing(new \RuntimeException(''));

        $this->assertSame(400, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(\RuntimeException::class, $body['message']);
    }

    /**
     * 用真实的 SchemaBuilder 产出真实的 HttpResponseException（不触碰数据库）
     *
     * `assertCanRunDDL()` 位于 `dropTable()` 的最前面，闸门拒绝时不会执行任何 SQL，
     * 因此这里既不需要建表，也不会在测试库里留下残留。
     */
    private function schemaDenial(): HttpResponseException
    {
        try {
            (new SchemaBuilder())->dropTable('lc_probe_ddl_denied');
        } catch (HttpResponseException $e) {
            return $e;
        }

        $this->fail('SchemaBuilder 未按预期拦截运行时 DDL（本用例的前提不成立）');
    }

    /**
     * 构造「CollectionManager 抛指定异常」的场景并调用控制器 delete()
     *
     * 直接调用控制器方法（不经 HTTP 内核）：本用例只校验控制器的异常 → 响应映射，
     * 路由/中间件链路已由 `CollectionControllerTest` 与 `WorkflowLowcodeRouteReachabilityTest` 覆盖。
     */
    private function callDeleteThrowing(\Throwable $throwable): Response
    {
        $manager = Mockery::mock(CollectionManager::class);
        $manager->shouldReceive('delete')
            ->once()
            ->with('probe_collection', true, 1)
            ->andThrow($throwable);

        /** @var \app\Request $request */
        $request = $this->app()->make(\app\Request::class);
        $request->setTenantId(1);
        $request->setMethod('DELETE');
        // Content-Type 必须早于 withInput()，否则 `delete()` 取不到 body（详见 CollectionControllerTest 说明）
        $request->withHeader(['Content-Type' => 'application/json']);
        $request->withInput(json_encode(['drop_table' => true]));

        /** @var \app\controller\lowcode\CollectionController $controller */
        $controller = new \app\controller\lowcode\CollectionController($this->app(), $manager);

        return $controller->delete('probe_collection', $request);
    }
}
