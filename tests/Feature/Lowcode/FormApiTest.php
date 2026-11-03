<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Schema\SchemaBuilder;
use Tests\ThinkPHPTestCase;
use Tests\Helpers\AuthHelper;
use ReflectionProperty;
use think\db\PDOConnection;
use think\facade\Db;

/**
 * Form API Test | 表单API测试
 *
 * Tests FormSchemaController and FormDataController endpoints with authentication.
 * 测试带认证的 FormSchemaController 和 FormDataController 端点。
 *
 * @package Tests\Feature\Lowcode
 */
class FormApiTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up before tests | 用例开始前清理集合（表 + 元数据 + 两级缓存）
        Db::execute('TRUNCATE TABLE lowcode_forms');
        $this->purgeCollection('test_api_data', 'lc_test_api_data');

        // Ensure app\Request is bound to think\Request (critical for Auth middleware)
        // 确保 app\Request 绑定到 think\Request（对 Auth 中间件至关重要）
        // This is necessary because some tests may create Request instances directly
        // without using the container, which can override the binding.
        // 这是必要的，因为某些测试可能直接创建 Request 实例而不使用容器，
        // 这可能会覆盖绑定。
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // Manually load routes to ensure they are registered
        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // Bind SchemaBuilderInterface
        $this->app()->bind(
            \Domain\Schema\Interfaces\SchemaBuilderInterface::class,
            \Infrastructure\Schema\SchemaBuilder::class
        );

        // Ensure Form API tests always use the real CollectionManager implementation
        // 确保表单 API 测试始终使用真实的 CollectionManager 实现

        $app = $this->app();

        // 1. 删除已解析的 CollectionManager 实例，防止复用旧 Mock
        $app->delete(CollectionManager::class);

        // 2. 通过反射清理 bind 中针对 CollectionManager 的闭包绑定
        //    避免后续从容器解析时仍然返回 Mockery Mock
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
        } catch (\Throwable $e) {
            // Ignore reflection errors in tests
        }
    }

    protected function tearDown(): void
    {
        // Clean up after tests | 用例结束后清理集合（表 + 元数据 + 两级缓存）
        Db::execute('TRUNCATE TABLE lowcode_forms');
        $this->purgeCollection('test_api_data', 'lc_test_api_data');
        parent::tearDown();
    }

    /**
     * 清理测试集合：drop 物理表 + 删元数据 + 清「两级」缓存
     *
     * 原先只做前两件事，随机顺序下 `testFormDataApi` 会稳定 400（`fields not exists:[age]`）。
     * 断言失败处看起来是「业务校验」，实际是两层**进程级**缓存把「上一轮的集合/表结构」带了过来：
     *
     *   1. **think-orm 的进程内字段缓存**（`PDOConnection::$info`，键 `{database}.{table}`）——
     *      **本次 400 的直接原因**。`BaseQuery` 每次拼 INSERT 前都会 `getTableFields()`
     *      校验「要写的键是不是这张表的字段」，而这张表信息表在连接对象上**只增不减、
     *      没有任何失效入口**（`getSchemaInfo($table, true)` 是唯一的强制重读，
     *      但表已被 drop 时它会抛异常）。于是 `DROP TABLE` + 重建为新字段集之后，旧字段列表照样被复用。
     *      实测（MySQL general_log 佐证）：同进程先建 `lc_test_api_data`（仅 `name`）、
     *      再重建为（`name`, `age`），提交数据时 INSERT **根本没发到数据库**，
     *      Builder 直接抛 `fields not exists:[age]` → 控制器兜底成 `{"code":400}`。
     *
     *   2. **CollectionManager 的缓存**（File 驱动，键 `lowcode:collection:{tenantId}:{name}`）——
     *      同一类缺陷：存的是**已序列化的 Collection 对象**，键里只有「租户 + 集合名」，
     *      **不含字段集**，同名集合重建为不同字段时就会读到上一轮的对象。
     *      本类两个测试类当前都是「先建后读」，所以它不是本次 400 的触发点
     *      （实测：只清第 1 层、不清本层，用例仍然全绿）；但它与第 1 层是同一类问题，
     *      任何「只读不建」的用例都会踩，所以一并清掉。
     *
     * 因此三件事必须成对出现：drop 表、删元数据、清缓存。以后新增「会 drop 动态表」的用例
     * 请复用本方法，不要只写 `DROP TABLE` + `DELETE FROM lowcode_collections`。
     *
     * 注意：本方法只删**本测试自己建的那张表**与其缓存键，不做 `Cache::clear()`
     * （File 驱动的 clear() 会整目录删除 `runtime/cache/test/`，会波及并行进程与 Casbin 缓存）。
     *
     * @param string $collectionName 集合名（lowcode_collections.name）
     * @param string $tableName      物理表名（如 lc_test_api_data），仅允许字母/数字/下划线
     * @param int[]  $tenantIds      需要清缓存的租户（0 = 系统模板空间；本库测试租户为 1、2）
     */
    protected function purgeCollection(string $collectionName, string $tableName, array $tenantIds = [0, 1, 2]): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $tableName) !== 1) {
            throw new \InvalidArgumentException("非法的物理表名：{$tableName}");
        }

        // 1. 物理表：必须 drop，否则 create() 的 hasTable() 命中、CREATE TABLE 被跳过
        Db::execute('DROP TABLE IF EXISTS `' . $tableName . '`');

        // 2. think-orm 进程内字段缓存：表已不存在，先把它从连接对象里摘掉
        $this->forgetOrmTableSchema($tableName);

        // 3. 元数据：按 name 删（所有租户，与原先的 DELETE 语义一致）
        Db::execute('DELETE FROM lowcode_collections WHERE name = ?', [$collectionName]);

        // 4. CollectionManager 缓存：按租户逐个清
        //    这里刻意 new 一个「真实」的 manager 而不是从容器 make()：
        //    容器里可能残留别的测试类 bind 进去的 Mockery 替身，用它清缓存等于没清。
        $manager = new CollectionManager(
            new SchemaBuilder(),
            new CollectionRepository(),
            new FieldRepository(),
            new RelationshipRepository()
        );

        foreach ($tenantIds as $tenantId) {
            $manager->clearCache($collectionName, (int) $tenantId);
        }
    }

    /**
     * 摘掉 think-orm 对某张表的进程内结构缓存 | Forget think-orm's in-process table schema
     *
     * `think\db\PDOConnection::getSchemaInfo()` 先查 `$this->info['{database}.{table}']`，
     * 命中就直接返回；框架没有提供任何「失效」API（只有 `getSchemaInfo($table, true)` 强制重读，
     * 而那张表此刻往往已经被 drop，强制重读会抛异常）。
     *
     * 所以这里用反射把它从 `$info` 里删掉：下一次访问该表会重新 `SHOW FULL COLUMNS`，
     * 拿到的就是「重建之后」的字段集。只删目标表，其余表缓存原样保留。
     *
     * 关于第二层缓存：`config/database.php` 的 `fields_cache` 为 `false`，
     * 因此 `PDOConnection::$cache`（think-cache 驱动的字段缓存）在本仓库不生效；
     * 若将来打开它，这里也需要顺带删掉 `getSchemaCacheKey()` 对应的键。
     *
     * @param string $tableName 物理表名
     */
    protected function forgetOrmTableSchema(string $tableName): void
    {
        $connection = Db::connect();

        if (!$connection instanceof PDOConnection) {
            return;
        }

        $property = new ReflectionProperty($connection, 'info');
        $property->setAccessible(true);

        $info = $property->getValue($connection);
        if (!is_array($info) || $info === []) {
            return;
        }

        $qualified = (string) $connection->getConfig('database') . '.' . $tableName;
        $suffix = '.' . $tableName;

        $kept = [];
        foreach ($info as $schema => $tableInfo) {
            // 键格式为 `{database}.{table}`；用后缀匹配兼容库名前缀/表前缀的差异
            if ((string) $schema === $qualified || str_ends_with((string) $schema, $suffix)) {
                continue;
            }

            $kept[$schema] = $tableInfo;
        }

        if (count($kept) !== count($info)) {
            $property->setValue($connection, $kept);
        }
    }

    /**
     * Helper to make GET request
     */
    protected function get(string $uri, array $headers = [])
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * Helper to make POST request
     */
    protected function post(string $uri, array $data = [], array $headers = [])
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * Helper to make PUT request
     */
    protected function put(string $uri, array $data = [], array $headers = [])
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    /**
     * Helper to make DELETE request
     */
    protected function delete(string $uri, array $data = [], array $headers = [])
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    /**
     * Core request helper with authentication | 带认证的核心请求辅助方法
     *
     * Creates an authenticated request using AuthHelper to generate JWT token.
     * 使用 AuthHelper 生成 JWT token 创建已认证的请求。
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = [])
    {
        // Use container to create Request instance to ensure app\Request is used
        // 使用容器创建 Request 实例以确保使用 app\Request
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        // Set request data based on method
        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        } else {
            // For PUT/DELETE, ThinkPHP reads from put cache
            if (!empty($data)) {
                $request->withInput(json_encode($data));
                // Manually set the put data using reflection
                try {
                    $reflection = new \ReflectionClass($request);

                    // Set put property
                    if ($reflection->hasProperty('put')) {
                        $putProperty = $reflection->getProperty('put');
                        $putProperty->setAccessible(true);
                        $putProperty->setValue($request, $data);
                    }
                } catch (\Exception $e) {
                    // Ignore reflection errors
                }
            }
        }

        // Add authentication token | 添加认证 token
        // Generate JWT token for test user (user_id=1, tenant_id=1)
        // 为测试用户生成 JWT token（user_id=1, tenant_id=1）
        if (!isset($headers['Authorization'])) {
            $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Add default headers
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json';
        }
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        // Add default tenant headers if not present
        if (!isset($headers['X-Tenant-ID'])) {
            $headers['X-Tenant-ID'] = '1';
        }

        // Set all headers using withHeader() | 使用 withHeader() 设置所有 headers
        $request = $request->withHeader($headers);

        // Debug Route Matching
        try {
            // Check if route matches (ThinkPHP 8 signature might vary, trying safe approach)
            // $dispatch = $this->app()->route->check($uri);
            // Skipping check call to avoid signature error, relying on http->run result
        } catch (\Throwable $e) {
            echo "Route Check Exception for URI: $uri - " . $e->getMessage() . "\n";
        }

        // 通过基类的 runHttp() 统一处理 ThinkPHP 注册的全局 error/exception handler，
        // 避免 PHPUnit 11 报告 Risky 测试。
        $response = $this->runHttp($request);

        return $response;
    }

    /**
     * Test Controller Instantiation
     */
    public function testControllerInstantiation(): void
    {
        try {
            $controller = $this->app()->make(\app\controller\lowcode\FormSchemaController::class);
            $this->assertInstanceOf(\app\controller\lowcode\FormSchemaController::class, $controller);
        } catch (\Throwable $e) {
            echo 'Controller Instantiation Failed: ' . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Test Manager Instantiation
     */
    public function testManagerInstantiation(): void
    {
        try {
            $manager = $this->app()->make(\Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager::class);
            $this->assertInstanceOf(\Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager::class, $manager);
        } catch (\Throwable $e) {
            echo 'Manager Instantiation Failed: ' . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Test Form Schema CRUD | 测试表单Schema CRUD
     */
    public function testFormSchemaCrud(): void
    {
        // 1. Create Form
        $response = $this->post('v1/lowcode/forms', [
            'name' => 'test_form',
            'title' => 'Test Form',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input']
                ]
            ],
            'collection_name' => 'test_collection'
        ]);

        if ($response->getCode() !== 200) {
            file_put_contents('error_create.html', $response->getContent());
            echo "Create Form Failed. See error_create.html\n";
        }
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(0, $content['code']);

        // 2. Get Form
        $response = $this->get('v1/lowcode/forms/test_form');
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Test Form', $content['data']['title']);

        // 3. Update Form
        $response = $this->put('v1/lowcode/forms/test_form', [
            'title' => 'Updated Form'
        ]);
        $this->assertEquals(200, $response->getCode());

        // 4. List Forms
        $response = $this->get('v1/lowcode/forms');
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(1, $content['data']['list']);

        // 5. Delete Form
        $response = $this->delete('v1/lowcode/forms/test_form');
        $this->assertEquals(200, $response->getCode());

        // Verify deletion
        $response = $this->get('v1/lowcode/forms/test_form');
        $this->assertEquals(404, $response->getCode());
    }

    /**
     * Test Form Data API | 测试表单数据API
     */
    public function testFormDataApi(): void
    {
        // 1. Create Collection for testing
        $response = $this->post('v1/lowcode/collections', [
            'name' => 'test_api_data',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'age', 'type' => 'integer']
            ]
        ]);
        if ($response->getCode() !== 200) {
            echo 'Create Collection Failed (Code ' . $response->getCode() . '): ' . strip_tags($response->getContent()) . "\n";
        }
        $this->assertEquals(200, $response->getCode());

        // 2. Create Form bound to collection
        $this->post('v1/lowcode/forms', [
            'name' => 'data_form',
            'title' => 'Data Form',
            'collection_name' => 'test_api_data',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                    'age' => ['type' => 'integer', 'minimum' => 0, 'x-component' => 'NumberPicker']
                ]
            ]
        ]);

        // 3. Submit Data
        $response = $this->post('v1/lowcode/forms/data_form/data', [
            'name' => 'John Doe',
            'age' => 30
        ]);

        if ($response->getCode() !== 200) {
            file_put_contents('error_data.html', $response->getContent());
            echo "Submit Data Failed. See error_data.html\n";
        }
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $id = $content['data']['id'];
        $this->assertNotEmpty($id);

        // 4. Get Data
        $response = $this->get("v1/lowcode/forms/data_form/data/{$id}");
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('John Doe', $content['data']['name']);

        // 5. List Data
        $response = $this->get('v1/lowcode/forms/data_form/data');
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(1, $content['data']['list']);

        // 6. Delete Data
        $response = $this->delete("v1/lowcode/forms/data_form/data/{$id}");
        $this->assertEquals(200, $response->getCode());

        // Verify deletion
        $response = $this->get("v1/lowcode/forms/data_form/data/{$id}");
        $this->assertEquals(404, $response->getCode());
    }
}
