<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Schema\SchemaBuilder;
use Mockery;
use Tests\ThinkPHPTestCase;
use think\exception\HttpResponseException;
use think\facade\Env;

/**
 * 表单接口「运行时 DDL 被拒」响应回归 | Runtime-DDL denial responses
 *
 * ## 背景：这条分支今天是「预防性」的，但必须可验证
 *
 * `FormSchemaController` / `FormDataController` 当前**不触达**运行时 DDL 闸门
 * （`FormSchemaManager` 只写元数据；`FormDataManager` 只解析 Collection 并对已存在的物理表读写），
 * 因此现实里走不到 `HttpResponseException` 这一支。本轮把 8 处 catch 接到共享 trait
 * `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses` 属于**预防性接线**
 * —— 一旦将来给表单加「缺表建表 / 缺列加列」，闸门会立刻出现在这条链上。
 *
 * 「预防性接线」最容易变成「写了但从没跑过」，所以本用例做两件事：
 *   1. **用真实的闸门产物**：`SchemaBuilder::createTable()` 在 `APP_ENV=testing` +
 *      `ALLOW_RUNTIME_DDL=false` 下会真的抛 `HttpResponseException`（reason = `flag_disabled`）。
 *      把它原样喂给控制器 —— 而不是自己 `new` 一个假异常，避免「假异常的形态和真异常不一样」；
 *   2. **断言对外契约**：HTTP 403 + 业务码 5003 + message **非空**、含 `operation` 与 `reason`、
 *      且不含堆栈（`#0`）/ `SQLSTATE` / 绝对路径。
 *
 * ## 为什么用 Mockery 替身来「制造」闸门
 *
 * 闸门在 `SchemaBuilder` 里，而表单的两个 Manager 今天不调它。要让真实闸门出现在**表单控制器**的
 * 调用链上，只能让 Manager 抛同一个异常对象。替身只负责「在正确的调用点抛出真实闸门异常」，
 * 异常本身是**真跑一次 `SchemaBuilder::createTable()` 得到的** —— 契约断言因此仍有意义。
 *
 * `Env` 是进程级单例：`APP_ENV` / `ALLOW_RUNTIME_DDL` 在 `tearDown()` 里原样还原，
 * 否则会污染后续用例的 DDL 前提。
 *
 * @package Tests\Feature\Lowcode
 */
final class FormApiDdlDeniedResponseTest extends ThinkPHPTestCase
{
    /**
     * 环境变量快照（用例结束后原样还原）
     *
     * @var array<string, mixed>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'APP_ENV'           => Env::get('APP_ENV'),
            'ALLOW_RUNTIME_DDL' => Env::get('ALLOW_RUNTIME_DDL'),
        ];

        // 与 FormApiTest 一致：绑定自定义 Request、手工 include 路由文件
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->bypassAuthAndPermission();
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
     * `FormSchemaController::save`：Manager 抛真实闸门异常 → 403 / 5003 + 可读原因
     */
    public function testFormSchemaSaveReturnsForbiddenWithReason(): void
    {
        $denial = $this->realDdlDenial();

        $manager = Mockery::mock(FormSchemaManager::class);
        $manager->shouldReceive('create')->andThrow($denial);
        $this->app()->bind(FormSchemaManager::class, $manager);

        $response = $this->send('POST', 'v1/lowcode/forms', ['name' => 'ddl_denied_probe']);

        $this->assertDdlDeniedResponse($response, 'FormSchemaController::save', 'create_table');
    }

    /**
     * `FormDataController::save`：Manager 抛真实闸门异常 → 403 / 5003 + 可读原因
     *
     * 这条同时证明 `save()` 里的三层 catch 顺序正确：`HttpResponseException` 在
     * `ForbiddenException` 与 `\Exception` 之前命中，不会被降级成 400 + 空 message。
     */
    public function testFormDataSaveReturnsForbiddenWithReason(): void
    {
        $denial = $this->realDdlDenial();

        $manager = Mockery::mock(FormDataManager::class);
        $manager->shouldReceive('save')->andThrow($denial);
        $this->app()->bind(FormDataManager::class, $manager);

        $response = $this->send('POST', 'v1/lowcode/forms/ddl_denied_probe/data', ['name' => 'x']);

        $this->assertDdlDeniedResponse($response, 'FormDataController::save', 'create_table');
    }

    /**
     * 断言「运行时 DDL 被拒」的对外契约
     *
     * @param \think\Response $response  实际响应
     * @param string          $where     触发点（失败信息用）
     * @param string          $operation 期望出现在 message 里的 operation
     */
    private function assertDdlDeniedResponse(\think\Response $response, string $where, string $operation): void
    {
        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body, "[{$where}] 响应体应为 JSON 对象，实际：" . $response->getContent());

        // 契约 1：HTTP 403 + 业务码 5003（沿用 SchemaBuilder 原响应，不得降级成 400）
        $this->assertSame(403, $response->getCode(), "[{$where}] DDL 被拒必须是 HTTP 403，实际：" . $response->getContent());
        $this->assertSame(5003, $body['code'] ?? null, "[{$where}] 业务码必须沿用 5003");

        // 契约 2：message 非空，且点名 operation / reason（这正是「空 message」缺陷要修的东西）
        $message = (string) ($body['message'] ?? '');
        $this->assertNotSame('', trim($message), "[{$where}] message 不得为空 —— 空 message 等于「没有原因的错误」");
        $this->assertStringContainsString("operation={$operation}", $message, "[{$where}] message 应点名 operation");
        $this->assertStringContainsString('reason=flag_disabled', $message, "[{$where}] message 应点名 reason");
        $this->assertStringContainsString(
            'ALLOW_RUNTIME_DDL',
            $message,
            "[{$where}] 提示里必须指出该改哪个键（否则调用方拿不到可操作下一步）"
        );

        // 契约 3：不泄露实现细节（生产环境即便走到这里也不能回显堆栈 / SQL / 绝对路径）
        foreach (['#0', 'SQLSTATE', 'CREATE TABLE', '/var/www'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message, "[{$where}] message 不得包含「{$forbidden}」");
        }
    }

    /**
     * 真跑一次闸门，拿到**真实**的 `HttpResponseException`
     *
     * 为什么不用 `new HttpResponseException(Response::create([...]))`：那等于用本用例自己对契约的理解
     * 去喂被测代码（自证）。这里固定 `APP_ENV=testing`（白名单内）+ `ALLOW_RUNTIME_DDL=false`，
     * 让 `SchemaBuilder::assertCanRunDDL()` 走到 `flag_disabled` 分支，异常体由**被测方的生产者**构造。
     */
    private function realDdlDenial(): HttpResponseException
    {
        Env::set('APP_ENV', 'testing');
        Env::set('ALLOW_RUNTIME_DDL', false);

        try {
            (new SchemaBuilder())->createTable('lc_ddl_denied_probe_should_not_exist', [], []);
        } catch (HttpResponseException $e) {
            // 闸门必须在 DDL 执行前拦下：断言一次「表没被真的建出来」
            $this->assertFalse(
                (new SchemaBuilder())->hasTable('lc_ddl_denied_probe_should_not_exist'),
                '闸门应在执行 DDL 之前抛异常（表不得被建出来）'
            );

            return $e;
        }

        $this->fail('SchemaBuilder::createTable() 未被运行时 DDL 闸门拦下：本用例的前提不成立');
    }

    /**
     * 发一次 HTTP 请求（无认证：Auth / Permission 已被替换为 no-op，本用例只验证控制器的 catch 契约）
     *
     * @param array<string, mixed> $data 请求数据
     */
    private function send(string $method, string $uri, array $data = []): \think\Response
    {
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } else {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'X-Tenant-ID'  => '1',
        ]);

        return $this->runHttp($request);
    }

    /**
     * 覆盖 Auth / Permission 中间件为 no-op（本用例不验证鉴权，只验证异常契约）
     */
    private function bypassAuthAndPermission(): void
    {
        $noop = new class () {
            /**
             * @param mixed $request 请求
             * @param \Closure $next 下一环
             * @return mixed
             */
            public function handle($request, \Closure $next)
            {
                return $next($request);
            }
        };

        $this->app()->bind(\app\middleware\Auth::class, fn () => $noop);
        $this->app()->bind(\app\middleware\Permission::class, fn () => $noop);
    }
}
