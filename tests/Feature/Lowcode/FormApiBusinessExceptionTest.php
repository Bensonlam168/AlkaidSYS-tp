<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;
use think\facade\Db;

/**
 * 表单接口「业务异常 → 对外响应」逐字节回归 | Business-exception responses are byte-stable
 *
 * ## 这条用例钉住什么
 *
 * `FormSchemaController` / `FormDataController` 的 8 个 catch 分支此前都是
 * `catch (\Exception $e) { return $this->error($e->getMessage()); }`，
 * 本轮接入了共享 trait `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses`
 * （新增 `catch (HttpResponseException)` 分支，通用分支改用 `describeExceptionMessage()`）。
 *
 * 「接入后普通业务异常的行为不变」这句话必须被**真实 HTTP 响应的字节**钉住，而不是靠阅读代码：
 * `describeExceptionMessage()` 内部做了 `trim()`，一旦哪天它改成「改写文案」或「加前缀」，
 * 调用方（前端表单错误提示、SDK 断言）看到的 message 就会变，而门禁不会红。
 *
 * 因此本用例走**真实链路**（路由 → Auth / Permission 中间件 → 控制器 → 真实 Manager → 真实校验代码），
 * 逐条触发 8 个 catch 分支里的**真实业务异常**，并对响应体做「除 `timestamp` 外逐字节相等」的断言：
 *
 * | # | 动作 | 触发方式 | 期望 message |
 * | --- | --- | --- | --- |
 * | 1 | `FormSchemaController::save` | 缺 `title` | `title is required` |
 * | 2 | `FormSchemaController::update` | 已存在表单 + `layout` 非法 | `layout must be one of: horizontal, vertical, inline` |
 * | 3 | `FormSchemaController::duplicate` | 已存在表单 + `new_name` 含非法字符 | `name must be alphanumeric with underscores` |
 * | 4 | `FormDataController::index` | 表单不存在 | `Form not found: <name>` |
 * | 5 | `FormDataController::read` | 表单不存在 | `Form not found: <name>` |
 * | 6 | `FormDataController::save` | 表单不存在 | `Form not found: <name>` |
 * | 7 | `FormDataController::delete` | 表单不存在 | `Form not found: <name>` |
 * | 8 | `FormSchemaController::delete` | `get()` 命中缓存但行已消失 | `Form not found: ID <id>` |
 *
 * ## 几个刻意的做法
 *
 * - **`X-Trace-Id` 写死**：`ApiController` 会把 trace_id 回显进响应体，不固定就无法做字节比对；
 * - **`timestamp` 归一化**：它由 `time()` 生成，天然每次不同，与本次改造无关。
 *   断言里用 `<T>` 占位替换后再比较，其余字段（`code` / `message` / `data` / `trace_id`）严格相等；
 * - **第 8 条要「缓存命中 + 行已消失」**：`FormSchemaManager::delete()` 先 `findById()` 再看是否为空，
 *   只有 `get()`（走缓存）与 `delete()`（走库）看到的世界不一致时才会抛
 *   `Form not found: ID {id}` —— 这是该 catch 分支唯一可达的真实业务异常；
 * - **固定主键 910001**：让第 8 条的 message 在每次运行之间保持稳定的字节。
 *
 * @package Tests\Feature\Lowcode
 */
final class FormApiBusinessExceptionTest extends ThinkPHPTestCase
{
    /**
     * 夹具表单名（租户 1）
     */
    private const FORM = 'catch_probe_form';

    /**
     * 夹具表单主键（固定值：让「Form not found: ID {n}」稳定）
     */
    private const FORM_ID = 910001;

    /**
     * 不存在的表单名（用于 FormDataController 的四个入口）
     */
    private const MISSING_FORM = 'catch_probe_missing_form';

    /**
     * 固定 trace_id（响应体会回显它，不固定则无法逐字节比对）
     */
    private const TRACE_ID = 'catch-probe-fixed-trace-id';

    protected function setUp(): void
    {
        parent::setUp();

        // 与 FormApiTest 一致：绑定自定义 Request（Auth / Tenant 中间件依赖它）、
        // 手工 include 路由文件、并确保 CollectionManager 用的是**真实**实现
        // （容器里可能残留其它测试类注入的 Mock 替身）。
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->forgetCollectionManagerMock();
        $this->resetFormFixtures();
    }

    protected function tearDown(): void
    {
        Db::execute('DELETE FROM lowcode_forms WHERE tenant_id = 1 AND id = ?', [self::FORM_ID]);
        Cache::delete($this->cacheKey(self::FORM));
        Cache::delete($this->cacheKey(self::MISSING_FORM));

        parent::tearDown();
    }

    /**
     * 8 个 catch 分支的真实业务异常响应必须与改造前逐字节相同（`timestamp` 除外）
     */
    public function testBusinessExceptionResponsesAreByteStable(): void
    {
        // 1) save：create() 的必填校验（title 缺失）
        $this->assertResponse(
            'FormSchema@save',
            $this->send('POST', 'v1/lowcode/forms', ['name' => 'catch_probe_new_form']),
            'title is required'
        );

        // 2) update：已存在表单 + layout 非法 → validateFormData() 抛 InvalidArgumentException
        //    （校验发生在 repository->save() 之前，因此本用例不写库）
        $this->assertResponse(
            'FormSchema@update',
            $this->send('PUT', 'v1/lowcode/forms/' . self::FORM, ['layout' => 'invalid_layout']),
            'layout must be one of: horizontal, vertical, inline'
        );

        // 3) duplicate：已存在表单 + new_name 含非法字符 → create() 复用同一套 name 校验
        $this->assertResponse(
            'FormSchema@duplicate',
            $this->send('POST', 'v1/lowcode/forms/' . self::FORM . '/duplicate', ['new_name' => 'bad-name!']),
            'name must be alphanumeric with underscores'
        );

        // 4) FormData::index：表单不存在
        $this->assertResponse(
            'FormData@index',
            $this->send('GET', 'v1/lowcode/forms/' . self::MISSING_FORM . '/data'),
            'Form not found: ' . self::MISSING_FORM
        );

        // 5) FormData::read：表单不存在
        $this->assertResponse(
            'FormData@read',
            $this->send('GET', 'v1/lowcode/forms/' . self::MISSING_FORM . '/data/1'),
            'Form not found: ' . self::MISSING_FORM
        );

        // 6) FormData::save：表单不存在（先解析表单再校验数据）
        $this->assertResponse(
            'FormData@save',
            $this->send('POST', 'v1/lowcode/forms/' . self::MISSING_FORM . '/data', []),
            'Form not found: ' . self::MISSING_FORM
        );

        // 7) FormData::delete：表单不存在
        $this->assertResponse(
            'FormData@delete',
            $this->send('DELETE', 'v1/lowcode/forms/' . self::MISSING_FORM . '/data/1'),
            'Form not found: ' . self::MISSING_FORM
        );

        // 8) FormSchema::delete：先 GET 预热表单缓存，再把行删掉 ——
        //    此时 get() 命中缓存（找得到），delete() 的 findById() 找不到 → 抛真实业务异常
        $this->send('GET', 'v1/lowcode/forms/' . self::FORM);
        Db::execute('DELETE FROM lowcode_forms WHERE tenant_id = 1 AND id = ?', [self::FORM_ID]);

        $this->assertResponse(
            'FormSchema@delete',
            $this->send('DELETE', 'v1/lowcode/forms/' . self::FORM),
            'Form not found: ID ' . self::FORM_ID
        );
    }

    /**
     * 断言：HTTP 400 + 业务码 400 + 响应体除 timestamp 外与期望**逐字节相同**
     *
     * @param string $label    分支名（失败信息里用它指出是哪一条）
     * @param \think\Response $response 实际响应
     * @param string $message  期望 message（与真实业务异常的 getMessage() 完全一致）
     */
    private function assertResponse(string $label, \think\Response $response, string $message): void
    {
        $actual = (string) $response->getContent();

        // timestamp 由 time() 生成、每次不同，与本次改造无关；其余字段必须逐字节相等
        $normalized = preg_replace('/"timestamp":\d+/', '"timestamp":<T>', $actual) ?? $actual;

        $expected = sprintf(
            '{"code":400,"message":%s,"data":null,"timestamp":<T>,"trace_id":"%s"}',
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::TRACE_ID
        );

        $this->assertSame(400, $response->getCode(), "[{$label}] HTTP 状态码应为 400，实际响应：{$actual}");
        $this->assertSame(
            $expected,
            $normalized,
            "[{$label}] 业务异常响应体必须与改造前逐字节相同（message 非空时不得被 trim / 改写 / 加前缀）"
        );
    }

    /**
     * 发一次真实 HTTP 请求（带认证与固定 trace_id）
     *
     * @param array<string, mixed> $data 请求数据（GET 走 query，POST 走 body，PUT/DELETE 走 put 缓存）
     */
    private function send(string $method, string $uri, array $data = []): \think\Response
    {
        // param() 取的是 method(true)，即 $_SERVER['REQUEST_METHOD']（见 think\Request::param()）；
        // CLI 下没有该键会回落 GET，PUT 的请求体就不会并入 param()，第 2 条分支也就触达不到。
        $hadServerMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
        $backupServerMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            $request = $this->app()->make(\think\Request::class);
            $request->setMethod($method);
            $request->setPathinfo($uri);

            if ($method === 'GET') {
                $request->withGet($data);
            } elseif ($method === 'POST') {
                $request->withPost($data);
                $request->withInput(json_encode($data));
            } else {
                $request->withInput(json_encode($data));
                // PUT / DELETE 的 body 需要写进 Request::$put（think\Request::param() 从这里读）
                try {
                    $reflection = new \ReflectionClass($request);
                    if ($reflection->hasProperty('put')) {
                        $putProperty = $reflection->getProperty('put');
                        $putProperty->setAccessible(true);
                        $putProperty->setValue($request, $data);
                    }
                } catch (\Throwable) {
                    // 反射失败时保持原样：本条用例会在断言里体现出来，而不是被静默吞掉
                }
            }

            $request = $request->withHeader([
                'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'X-Tenant-ID'   => '1',
                'X-Trace-Id'    => self::TRACE_ID,
            ]);

            return $this->runHttp($request);
        } finally {
            if ($hadServerMethod) {
                $_SERVER['REQUEST_METHOD'] = $backupServerMethod;
            } else {
                unset($_SERVER['REQUEST_METHOD']);
            }
        }
    }

    /**
     * 重建夹具：固定主键的表单行 + 干净的缓存
     *
     * 清缓存是必须的：`FormSchemaManager` 的缓存键只有「租户 + 站点 + 表单名」，
     * 上一次运行留下的缓存会让 `get()` 返回一个已不存在的行，把第 2/3 条的前置条件搞坏。
     */
    private function resetFormFixtures(): void
    {
        Db::execute('DELETE FROM lowcode_forms WHERE tenant_id = 1 AND id = ?', [self::FORM_ID]);
        Cache::delete($this->cacheKey(self::FORM));
        Cache::delete($this->cacheKey(self::MISSING_FORM));

        Db::name('lowcode_forms')->insert([
            'id'              => self::FORM_ID,
            'tenant_id'       => 1,
            'site_id'         => 0,
            'name'            => self::FORM,
            'title'           => 'Business Exception Probe Form',
            'schema'          => json_encode(['type' => 'object', 'properties' => []]),
            'collection_name' => null,
            'layout'          => 'horizontal',
            'status'          => 1,
        ]);
    }

    /**
     * 表单缓存的键（与 FormSchemaManager::getCacheKey() 同形：lowcode:form:t{tenant}:s{site}:{name}）
     */
    private function cacheKey(string $name): string
    {
        return 'lowcode:form:t1:s0:' . $name;
    }

    /**
     * 丢掉容器里可能残留的 CollectionManager 替身（跨测试类的 Mock 泄漏）
     *
     * 与 FormApiTest::setUp() 同一做法：本用例要的是真实实现，否则
     * `FormDataManager` 会拿到上一个测试类留下的 Mockery Mock 并报「no expectations specified」。
     */
    private function forgetCollectionManagerMock(): void
    {
        $app = $this->app();
        $app->delete(CollectionManager::class);

        try {
            $refApp = new \ReflectionObject($app);
            if (!$refApp->hasProperty('bind')) {
                return;
            }

            $bindProp = $refApp->getProperty('bind');
            $bindProp->setAccessible(true);
            $bind = $bindProp->getValue($app);

            if (is_array($bind) && array_key_exists(CollectionManager::class, $bind)) {
                unset($bind[CollectionManager::class]);
                $bindProp->setValue($app, $bind);
            }
        } catch (\Throwable) {
            // 反射失败不影响本用例（真实实现会被重新解析）
        }
    }
}
