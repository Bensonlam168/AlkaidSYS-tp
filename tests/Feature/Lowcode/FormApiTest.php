<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Tests\ThinkPHPTestCase;
use Tests\Helpers\AuthHelper;
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
        // Force debug ON to see errors
        putenv('APP_DEBUG=1');
        parent::setUp();

        if ($this->app()) {
            $this->app()->debug(true);
            $this->app()->config->set(['type' => 'html'], 'trace');
        }

        // Clean up before tests
        Db::execute('TRUNCATE TABLE lowcode_forms');
        Db::execute('DROP TABLE IF EXISTS lc_test_api_data');
        Db::execute('DELETE FROM lowcode_collections WHERE name = "test_api_data"');

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
        $this->app()->bind(\Domain\Schema\Interfaces\SchemaBuilderInterface::class, \Infrastructure\Schema\SchemaBuilder::class);
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        Db::execute('TRUNCATE TABLE lowcode_forms');
        Db::execute('DROP TABLE IF EXISTS lc_test_api_data');
        Db::execute('DELETE FROM lowcode_collections WHERE name = "test_api_data"');
        parent::tearDown();
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
            echo "Controller Instantiation Failed: " . $e->getMessage() . "\n";
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
            echo "Manager Instantiation Failed: " . $e->getMessage() . "\n";
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
            echo "Create Collection Failed (Code " . $response->getCode() . "): " . strip_tags($response->getContent()) . "\n";
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
