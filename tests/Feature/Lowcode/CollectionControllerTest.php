<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Mockery;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;

class CollectionControllerTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure app\Request is bound to think\Request
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // Load lowcode routes
        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }
    }

    protected function tearDown(): void
    {
        // 删除可能已缓存的 CollectionManager 实例并清理 bind 映射，避免 Mock 泄露到其他测试
        $app = $this->app();

        // 1. 删除已解析实例
        $app->delete(CollectionManager::class);

        // 2. 通过反射清理 bind 中针对 CollectionManager 的闭包绑定
        //    否则后续测试从容器解析时仍会得到 Mockery Mock
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

        Mockery::close();
        parent::tearDown();
    }

    protected function request(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        bool $autoAuth = true
    ) {
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        } else {
            if (!empty($data)) {
                $request->withInput(json_encode($data));
                try {
                    $reflection = new \ReflectionClass($request);
                    foreach (['put', 'delete'] as $propName) {
                        if ($reflection->hasProperty($propName)) {
                            $prop = $reflection->getProperty($propName);
                            $prop->setAccessible(true);
                            $prop->setValue($request, $data);
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore reflection errors
                }
            }
        }

        if ($autoAuth && !isset($headers['Authorization'])) {
            $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if (!isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json';
        }
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }
        if (!isset($headers['X-Tenant-ID'])) {
            $headers['X-Tenant-ID'] = '1';
        }

        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }

    protected function get(string $uri, array $params = [], array $headers = [], bool $autoAuth = true)
    {
        return $this->request('GET', $uri, $params, $headers, $autoAuth);
    }

    protected function put(string $uri, array $data = [], array $headers = [], bool $autoAuth = true)
    {
        return $this->request('PUT', $uri, $data, $headers, $autoAuth);
    }

    protected function delete(string $uri, array $data = [], array $headers = [], bool $autoAuth = true)
    {
        return $this->request('DELETE', $uri, $data, $headers, $autoAuth);
    }

    protected function mockCollectionManager(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(CollectionManager::class);
        $this->app()->bind(CollectionManager::class, static fn () => $mock);

        return $mock;
    }

    public function testIndexIgnoresTenantIdInQuery(): void
    {
        $manager = $this->mockCollectionManager();

        $manager->shouldReceive('list')
            ->once()
            ->with(1, Mockery::type('array'), 1, 20)
            ->andReturn([
                'list' => [],
                'total' => 0,
                'page' => 1,
                'pageSize' => 20,
            ]);

        $response = $this->get('v1/lowcode/collections', ['tenant_id' => 999]);

        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(0, $content['code']);
        $this->assertArrayHasKey('data', $content);
        $this->assertArrayHasKey('list', $content['data']);
    }

    public function testReadIgnoresTenantIdInQuery(): void
    {
        $manager = $this->mockCollectionManager();

        $collection = Mockery::mock(\Domain\Lowcode\Collection\Interfaces\CollectionInterface::class);
        $collection->shouldReceive('toArray')->andReturn([
            'name' => 'test_collection',
            'title' => 'Test Collection',
        ]);

        $manager->shouldReceive('get')
            ->once()
            ->with('test_collection', 1)
            ->andReturn($collection);

        /** @var \app\Request $request */
        $request = $this->app()->make(\app\Request::class);
        $request->setTenantId(1);
        $request->setMethod('GET');
        $request->withGet([
            'tenant_id' => 999,
        ]);

        /** @var \app\controller\lowcode\CollectionController $controller */
        $controller = $this->app()->make(\app\controller\lowcode\CollectionController::class);
        $controllerReflection = new \ReflectionClass($controller);
        $managerProperty = $controllerReflection->getProperty('collectionManager');
        $managerProperty->setAccessible(true);
        $managerProperty->setValue($controller, $manager);

        $response = $controller->read('test_collection', $request);

        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(0, $content['code']);
        $this->assertEquals('Test Collection', $content['data']['title']);
    }

    public function testUpdateIgnoresTenantIdInBody(): void
    {
        $manager = $this->mockCollectionManager();

        $collection = Mockery::mock(\Domain\Lowcode\Collection\Interfaces\CollectionInterface::class);
        $collection->shouldReceive('setTitle')->with('Updated Title')->once();
        $collection->shouldReceive('setDescription')->zeroOrMoreTimes();
        $collection->shouldReceive('toArray')->andReturn([
            'name' => 'test_collection',
            'title' => 'Updated Title',
        ]);

        $manager->shouldReceive('get')
            ->once()
            ->with('test_collection', 1)
            ->andReturn($collection);

        $manager->shouldReceive('update')
            ->once()
            ->with($collection);

        // 构造带有伪造 tenant_id 的请求，但通过 Request::tenantId 注入真实租户
        /** @var \app\Request $request */
        $request = $this->app()->make(\app\Request::class);
        $request->setTenantId(1);
        $request->setMethod('PUT');
        $data = [
            'title' => 'Updated Title',
            'tenant_id' => 999,
        ];
        $request->withInput(json_encode($data));
        try {
            $reflection = new \ReflectionClass($request);
            if ($reflection->hasProperty('put')) {
                $prop = $reflection->getProperty('put');
                $prop->setAccessible(true);
                $prop->setValue($request, $data);
            }
        } catch (\Throwable $e) {
            // Ignore reflection errors in tests
        }

        // 直接调用控制器方法，注入 mock 的 CollectionManager
        /** @var \app\controller\lowcode\CollectionController $controller */
        $controller = $this->app()->make(\app\controller\lowcode\CollectionController::class);
        $controllerReflection = new \ReflectionClass($controller);
        $managerProperty = $controllerReflection->getProperty('collectionManager');
        $managerProperty->setAccessible(true);
        $managerProperty->setValue($controller, $manager);

        $response = $controller->update('test_collection', $request);

        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(0, $content['code']);
        $this->assertEquals('Updated Title', $content['data']['title']);
    }

    public function testDeleteIgnoresTenantIdInBody(): void
    {
        $manager = $this->mockCollectionManager();

        $manager->shouldReceive('delete')
            ->once()
            ->with('test_collection', false, 1);

        /** @var \app\Request $request */
        $request = $this->app()->make(\app\Request::class);
        $request->setTenantId(1);
        $request->setMethod('DELETE');
        $data = [
            'drop_table' => false,
            'tenant_id' => 999,
        ];
        $request->withInput(json_encode($data));
        try {
            $reflection = new \ReflectionClass($request);
            if ($reflection->hasProperty('delete')) {
                $prop = $reflection->getProperty('delete');
                $prop->setAccessible(true);
                $prop->setValue($request, $data);
            }
        } catch (\Throwable $e) {
            // Ignore reflection errors in tests
        }

        /** @var \app\controller\lowcode\CollectionController $controller */
        $controller = $this->app()->make(\app\controller\lowcode\CollectionController::class);
        $controllerReflection = new \ReflectionClass($controller);
        $managerProperty = $controllerReflection->getProperty('collectionManager');
        $managerProperty->setAccessible(true);
        $managerProperty->setValue($controller, $manager);

        $response = $controller->delete('test_collection', $request);

        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(0, $content['code']);
    }

    public function testCollectionsRequireAuthReturnsUnauthorizedWithTraceId(): void
    {
        $headers = [
            'Authorization' => 'Invalid',
            'Accept' => 'application/json',
            'X-Trace-Id' => 'test-trace-id-123',
        ];

        $response = $this->get('v1/lowcode/collections', [], $headers, false);

        $this->assertEquals(401, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(2001, $content['code']);
        $this->assertArrayHasKey('message', $content);
        $this->assertArrayHasKey('timestamp', $content);
        $this->assertEquals('test-trace-id-123', $content['trace_id']);
    }
}
