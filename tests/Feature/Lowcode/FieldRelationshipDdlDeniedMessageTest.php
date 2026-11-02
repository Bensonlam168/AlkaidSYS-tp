<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Schema\SchemaBuilder;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;
use think\facade\Env;
use think\Response;

/**
 * 字段 / 关系 / 集合接口「运行时 DDL 被拒」错误文案回归（缺陷 #1）
 *
 * 背景：`SchemaBuilder::assertCanRunDDL()` 在运行环境策略不允许运行时 DDL 时抛
 * `think\exception\HttpResponseException`。核实后的结论是：
 *
 *   - 继承链 `HttpResponseException -> RuntimeException -> Exception` ⇒ `instanceof \Exception` 为 **true**；
 *   - 但构造函数只接收 `Response`、**没有调用父类** ⇒ `getMessage()` 恒为 **空串**。
 *
 * 也就是说 `catch (\Exception $e) { return $this->error($e->getMessage()); }`
 * 是「抓到了异常，却把原因丢干净」——对外变成 HTTP 400 + 空 message。
 *
 * 本用例**走真实 HTTP 链路**（路由 → Auth / Permission / Tenant 中间件 → 控制器 → Manager → SchemaBuilder），
 * 覆盖五个会真跑 DDL 的入口，锁住四件事：
 *   1. DDL 被拒时必须是 HTTP **403** + 业务码 **5003**，且 message **非空**、含 `operation` 与 `reason`；
 *   2. 生产类环境即便 `ALLOW_RUNTIME_DDL=true` 也拒绝，且文案说明「是生产策略」；
 *   3. message **不得**含堆栈（`#0`）、`SQLSTATE`、大写 SQL（`DROP TABLE` / `CREATE TABLE`）、绝对路径；
 *   4. 正常路径（DDL 放行）不受影响：加字段 / 删字段 / 建关系 / 删关系 / 删集合都成功。
 *
 * 为什么用真实 HTTP 而不是只 mock Manager：上一轮的 `CollectionDdlDeniedMessageTest` 已经证明
 * 「控制器对异常的处置」契约，但它是直接调控制器方法的。本用例补的是**另外三个控制器**
 * （Field / Relationship）在**真实路由**上是否也拿到了同一套文案（共享 trait 的实际效果），
 * 顺带把 Collection 的删除入口在 HTTP 层也覆盖一次（trait 提取前后的对外契约一致性）。
 *
 * 环境变量用 `Env::set()` 临时改、`tearDown()` 原样还原：`Env` 是进程级单例，
 * `APP_ENV=production` 泄漏会让后续用例的鉴权/DDL 前提失效。
 *
 * @package Tests\Feature\Lowcode
 */
final class FieldRelationshipDdlDeniedMessageTest extends ThinkPHPTestCase
{
    /**
     * 源集合名（物理表 `lc_ddl_denied_probe`）
     */
    private const COLLECTION = 'ddl_denied_probe';

    /**
     * 目标集合名（belongsToMany 关系的 target）
     */
    private const PEER_COLLECTION = 'ddl_denied_peer';

    /**
     * 源集合物理表名
     */
    private const TABLE = 'lc_ddl_denied_probe';

    /**
     * 目标集合物理表名
     */
    private const PEER_TABLE = 'lc_ddl_denied_peer';

    /**
     * 中间表名（显式指定，便于清理；默认命名规则是 lc_pivot_{源}_{目标}）
     */
    private const PIVOT_TABLE = 'lc_pivot_ddl_denied';

    /**
     * 环境变量快照：任何用例结束后都必须原样还原
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
            'JWT_SECRET' => Env::get('JWT_SECRET'),
        ];

        // 与 FormApiTest 一致的三件套（原因见其 setUp 注释）：
        // 1. 绑定 app\Request，Auth / Tenant 中间件依赖它；
        // 2. 手工 include 路由文件，保证 fields / relationships 子路由已注册；
        // 3. 绑定 SchemaBuilder 真实实现。
        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->app()->bind(SchemaBuilderInterface::class, SchemaBuilder::class);

        // 容器里可能残留上一测试类注入的 CollectionManager 替身（跨类 Mock 泄漏），
        // 本用例要的是**真实**实现：删实例 + 清 bind 闭包。
        $app = $this->app();
        $app->delete(CollectionManager::class);
        try {
            $refApp = new \ReflectionObject($app);
            if ($refApp->hasProperty('bind')) {
                $bindProp = $refApp->getProperty('bind');
                $bindProp->setAccessible(true);
                $bind = $bindProp->getValue($app);
                if (is_array($bind) && array_key_exists(CollectionManager::class, $bind)) {
                    unset($bind[CollectionManager::class]);
                    $bindProp->setValue($app, $bind);
                }
            }
        } catch (\Throwable) {
            // 反射失败不影响主流程
        }

        // 基线状态：显式钉成「白名单环境 + 开关打开」。
        // 不依赖容器里恰好 APP_ENV=dev —— CI 未设置 APP_ENV 时 SchemaBuilder 会按
        // `production` 兜底，那样连「正常路径」都跑不了，用例会以一种与缺陷无关的方式红掉。
        Env::set('APP_ENV', 'testing');
        Env::set('ALLOW_RUNTIME_DDL', true);

        // 丢掉上一轮解析过的 Auth 中间件实例（见 forgetAuthMiddleware() 的说明）
        $this->forgetAuthMiddleware();

        // 前置清理（上次异常中断可能留下残留），随后把两个集合真正建出来
        $this->purge();
        $this->createCollections();
    }

    protected function tearDown(): void
    {
        // 先还原环境变量：purge() 要真删表，必须在 DDL 放行的前提下执行
        foreach ($this->envBackup as $key => $value) {
            Env::set($key, $value);
        }

        // 环境变量（尤其 JWT_SECRET）改动后必须重建 Auth 中间件，否则它会把本轮
        // 用过的密钥带进后续测试类 —— 后续用例签的 token 会全部判为 token_invalid。
        $this->forgetAuthMiddleware();

        $this->purge();

        parent::tearDown();
    }

    /**
     * 加字段（add_column）被拒：403 / 5003 / 有原因
     */
    public function testAddFieldDeniedCarriesReasonAndHint(): void
    {
        // 正常路径先行：DDL 放行时加字段必须成功（回归项）
        $ok = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/fields', [
            'name' => 'ok_field',
            'type' => 'string',
        ]);
        self::assertSame(200, $ok->getCode(), '正常路径加字段失败：' . $ok->getContent());

        // 关掉开关后加另一个字段：必须被闸门拦下，且原因可见
        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/fields', [
            'name' => 'denied_field',
            'type' => 'string',
        ]);

        $body = $this->assertDdlDenied($response, 'add_column');
        $message = (string) $body['message'];
        self::assertStringContainsString('reason=flag_disabled', $message);
        self::assertStringContainsString('ALLOW_RUNTIME_DDL', $message, '提示里必须指出该改哪个键');
        self::assertStringContainsString('dev / local / testing', $message);
    }

    /**
     * 删字段（drop_column）被拒：403 / 5003 / 有原因
     */
    public function testDropFieldDeniedCarriesReasonAndHint(): void
    {
        $created = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/fields', [
            'name' => 'to_drop',
            'type' => 'string',
        ]);
        self::assertSame(200, $created->getCode(), '准备阶段加字段失败：' . $created->getContent());

        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->send('DELETE', 'v1/lowcode/collections/' . self::COLLECTION . '/fields/to_drop', [
            'drop_column' => true,
        ]);

        $body = $this->assertDdlDenied($response, 'drop_column');
        self::assertStringContainsString('reason=flag_disabled', (string) $body['message']);

        // 还原开关后同一个动作必须成功（回归项：拒绝只源于策略，不是路径本身坏了）
        Env::set('ALLOW_RUNTIME_DDL', true);
        $after = $this->send('DELETE', 'v1/lowcode/collections/' . self::COLLECTION . '/fields/to_drop');
        self::assertSame(200, $after->getCode(), '还原开关后删字段仍失败：' . $after->getContent());
    }

    /**
     * 建关系中间表（create_table）被拒：403 / 5003 / 有原因
     */
    public function testAddRelationshipDeniedCarriesReasonAndHint(): void
    {
        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/relationships', [
            'name' => 'denied_rel',
            'type' => 'belongsToMany',
            'target_collection' => self::PEER_COLLECTION,
            'foreign_key' => self::PEER_COLLECTION . '_id',
            'options' => ['pivot_table' => self::PIVOT_TABLE],
        ]);

        $body = $this->assertDdlDenied($response, 'create_table');
        self::assertStringContainsString('reason=flag_disabled', (string) $body['message']);
    }

    /**
     * 删关系中间表（drop_table）被拒：403 / 5003 / 有原因
     *
     * 让 `dropTable` 真的被调用，前提是中间表存在且关系类型为 belongsToMany ——
     * 所以先在 DDL 放行时把关系建好，再关开关删它。
     */
    public function testDropRelationshipDeniedCarriesReasonAndHint(): void
    {
        $created = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/relationships', [
            'name' => 'probe_rel',
            'type' => 'belongsToMany',
            'target_collection' => self::PEER_COLLECTION,
            'foreign_key' => self::PEER_COLLECTION . '_id',
            'options' => ['pivot_table' => self::PIVOT_TABLE],
        ]);
        self::assertSame(200, $created->getCode(), '准备阶段建关系失败：' . $created->getContent());
        self::assertTrue(
            Db::query("SHOW TABLES LIKE '" . self::PIVOT_TABLE . "'") !== [],
            '准备阶段中间表未创建，本用例前提不成立'
        );

        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->send(
            'DELETE',
            'v1/lowcode/collections/' . self::COLLECTION . '/relationships/probe_rel',
            ['drop_pivot_table' => true]
        );

        $body = $this->assertDdlDenied($response, 'drop_table');
        self::assertStringContainsString('reason=flag_disabled', (string) $body['message']);

        // 还原开关后同一动作必须成功（回归项）
        Env::set('ALLOW_RUNTIME_DDL', true);
        $after = $this->send(
            'DELETE',
            'v1/lowcode/collections/' . self::COLLECTION . '/relationships/probe_rel',
            ['drop_pivot_table' => true]
        );
        self::assertSame(200, $after->getCode(), '还原开关后删关系仍失败：' . $after->getContent());
    }

    /**
     * 生产类环境：即便 ALLOW_RUNTIME_DDL=true 也拒绝，且文案说明是「生产策略」
     */
    public function testProductionEnvironmentDenialExplainsProductionPolicy(): void
    {
        // 生产环境还需要一个「够强」的 JWT 密钥，否则请求会先被 JwtService 的
        // 「生产密钥强度」闸门拦下（那是另一道安全防线，不是本用例要测的 DDL 闸门）。
        // 这里模拟**配置正确的生产部署**：强随机密钥 + APP_ENV=production + ALLOW_RUNTIME_DDL=true。
        Env::set('JWT_SECRET', bin2hex(random_bytes(32)));
        Env::set('APP_ENV', 'production');
        Env::set('ALLOW_RUNTIME_DDL', true);
        $this->forgetAuthMiddleware();

        $response = $this->send('POST', 'v1/lowcode/collections/' . self::COLLECTION . '/fields', [
            'name' => 'prod_denied',
            'type' => 'string',
        ]);

        $body = $this->assertDdlDenied($response, 'add_column');
        $message = (string) $body['message'];
        self::assertStringContainsString('reason=production_environment', $message);
        self::assertStringContainsString('生产类环境', $message, '文案必须点明这是生产环境策略');
        self::assertStringContainsString('迁移文件', $message, '文案必须给出替代做法（改用迁移）');
        // 开关取值为 true 也不能放行 —— 这是「生产永久禁止」这一层闸门的意义
        self::assertStringContainsString('与 ALLOW_RUNTIME_DDL 取值无关', $message);
    }

    /**
     * 删集合（drop_table）在真实 HTTP 上同样拿到可读文案
     *
     * 这一条是 trait 提取后的**对外契约一致性**验证：`CollectionController` 的实现从
     * 「类内三个方法」搬到了共享 trait，行为必须与上一轮完全一致。
     */
    public function testCollectionDeleteDeniedStillEnrichedOverHttp(): void
    {
        Env::set('ALLOW_RUNTIME_DDL', false);

        $response = $this->send('DELETE', 'v1/lowcode/collections/' . self::COLLECTION, [
            'drop_table' => true,
        ]);

        $body = $this->assertDdlDenied($response, 'drop_table');
        self::assertStringContainsString('reason=flag_disabled', (string) $body['message']);
    }

    /**
     * 断言「运行时 DDL 被拒」的对外契约
     *
     * @param Response $response 控制器响应
     * @param string $operation 期望的 DDL 操作码
     * @return array 解析后的响应体
     */
    private function assertDdlDenied(Response $response, string $operation): array
    {
        self::assertSame(
            403,
            $response->getCode(),
            'DDL 被拒必须是 403，实际：' . $response->getCode() . '，响应体：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        self::assertIsArray($body, '响应体不是合法 JSON：' . $response->getContent());
        self::assertSame(5003, $body['code'], 'DDL 被拒的业务码必须是 5003');

        $message = (string) ($body['message'] ?? '');
        self::assertNotSame('', trim($message), '错误 message 不得为空');
        self::assertStringContainsString('运行时 DDL 被环境策略拒绝', $message);
        self::assertStringContainsString('异常类型=' . \think\exception\HttpResponseException::class, $message);
        self::assertStringContainsString('operation=' . $operation, $message);

        // 不泄露实现细节：不得出现堆栈、驱动错误码、大写 SQL、绝对路径
        self::assertStringNotContainsString('#0', $message, 'message 不得包含调用堆栈');
        self::assertStringNotContainsString('SQLSTATE', $message, 'message 不得包含 SQL 驱动错误码');
        self::assertStringNotContainsString('DROP TABLE', $message, 'message 不得包含可执行 SQL');
        self::assertStringNotContainsString('CREATE TABLE', $message, 'message 不得包含可执行 SQL');
        self::assertStringNotContainsString('/var/www', $message, 'message 不得包含服务器绝对路径');

        return $body;
    }

    /**
     * 发出真实 HTTP 请求（经路由与中间件）
     *
     * 顺序要求：`Content-Type` 必须先于 `withInput()` 写入，否则 `Request::put()/delete()`
     * 解析不到 body（`getInputData()` 依赖 content-type 判断是否 json）。
     *
     * @param string $method HTTP 方法
     * @param string $uri 相对路径（不带前导斜杠）
     * @param array $data 请求体（POST / PUT / DELETE）
     * @return Response
     */
    private function send(string $method, string $uri, array $data = []): Response
    {
        /** @var \app\Request $request */
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader([
            // 测试库 id=1 是 test_system_user（active，admin 角色含 lowcode.read/write/delete），
            // 与被禁用的开发库 test_user 无关
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0),
            'X-Tenant-ID' => '1',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        if ($data !== []) {
            // withInput 会同时填充 post / put，POST 与 DELETE 取参都靠它
            $request->withInput(json_encode($data));
        }

        return $this->runHttp($request);
    }

    /**
     * 建好本用例需要的两个集合（物理表 + 元数据）
     */
    private function createCollections(): void
    {
        foreach ([
            self::COLLECTION,
            self::PEER_COLLECTION,
        ] as $name) {
            $response = $this->send('POST', 'v1/lowcode/collections', [
                'name' => $name,
                'title' => 'DDL Denied Probe ' . $name,
                'table_name' => 'lc_' . $name,
                'fields' => [
                    ['name' => 'name', 'type' => 'string'],
                ],
            ]);
            self::assertSame(200, $response->getCode(), "准备阶段建集合 {$name} 失败：" . $response->getContent());
        }
    }

    /**
     * 清理：中间表 → 两张物理表 → 集合/字段/关系/版本元数据 → 两级缓存
     *
     * 直接写 SQL 而不是调 Manager：本方法同时要在「上一个用例中途失败」这种状态下可用，
     * 不能依赖 Manager 的前置校验（例如关系元数据已丢失时 removeRelationship 会先抛「关系不存在」）。
     *
     * 注意元数据的键是 `collection_id`（不是 `collection_name`），所以先按名查 id 再按 id 删。
     */
    private function purge(): void
    {
        foreach ([self::PIVOT_TABLE, self::TABLE, self::PEER_TABLE] as $table) {
            Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $names = [self::COLLECTION, self::PEER_COLLECTION];
        $ids = Db::table('lowcode_collections')->whereIn('name', $names)->column('id');
        if ($ids !== []) {
            Db::table('lowcode_relationships')->whereIn('collection_id', $ids)->delete();
            Db::table('lowcode_fields')->whereIn('collection_id', $ids)->delete();
            Db::table('lowcode_schema_versions')
                ->where('object_type', 'collection')
                ->whereIn('object_name', $names)
                ->delete();
            Db::table('lowcode_collections')->whereIn('id', $ids)->delete();
        }

        $this->flushCollectionCache(self::COLLECTION);
        $this->flushCollectionCache(self::PEER_COLLECTION);
    }

    /**
     * 清掉 CollectionManager 的文件缓存键（含租户 0/1）
     *
     * @param string $collectionName 集合名
     */
    private function flushCollectionCache(string $collectionName): void
    {
        foreach ([0, 1] as $tenantId) {
            try {
                \think\facade\Cache::delete('lowcode:collection:' . $tenantId . ':' . $collectionName);
            } catch (\Throwable) {
                // 缓存驱动不可用时忽略：清理的目的只是避免跨用例读到旧结构
            }
        }
    }

    /**
     * 丢掉容器里缓存的 Auth 中间件实例
     *
     * `app\middleware\Auth` 在 `__construct()` 里 `new JwtService()`，而 `JwtService` 在
     * 构造时就把 `JWT_SECRET` **读进属性**；同时 `think\Container` 会把中间件实例缓存在
     * `instances` 里（实测：连续两次 `make(Auth::class)` 返回同一个对象）。
     *
     * 两者相乘的后果：本用例改了 `JWT_SECRET` 之后，中间件仍拿**旧密钥**校验新签发的 token，
     * 结果是 `token_invalid` / 401 —— 看起来像「生产环境把请求挡了」，其实与 DDL 闸门无关。
     * 因此每次改完密钥都要显式丢弃该实例，让它按当前环境重建。
     */
    private function forgetAuthMiddleware(): void
    {
        $this->app()->delete(\app\middleware\Auth::class);
    }
}
