<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../vendor/topthink/framework/src/helper.php';
}

/**
 * Trace ID Coverage Test | Trace ID 覆盖测试
 * 
 * Tests that all JSON responses include trace_id for observability.
 * 测试所有 JSON 响应都包含 trace_id 以便可观测性。
 * 
 * @package Tests\Feature
 */
class TraceIdCoverageTest extends ThinkPHPTestCase
{
    /**
     * Test ApiController success response includes trace_id | 测试 ApiController 成功响应包含 trace_id
     */
    public function testApiControllerSuccessResponseIncludesTraceId(): void
    {
        // Create a test controller instance | 创建测试控制器实例
        $controller = new class($this->app()) extends \app\controller\ApiController {
            public function testSuccess()
            {
                // Simulate trace_id in request header | 模拟请求头中的 trace_id
                $this->request->withHeader(['X-Trace-Id' => 'test-trace-id-123']);
                
                return $this->success(['message' => 'Test data']);
            }
        };
        
        // Call the success method | 调用成功方法
        $response = $controller->testSuccess();
        
        // Assert HTTP status code is 200 | 断言 HTTP 状态码为 200
        $this->assertEquals(200, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert trace_id field exists | 断言 trace_id 字段存在
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert trace_id value | 断言 trace_id 值
        $this->assertEquals('test-trace-id-123', $data['trace_id']);
    }

    /**
     * Test ApiController error response includes trace_id | 测试 ApiController 错误响应包含 trace_id
     */
    public function testApiControllerErrorResponseIncludesTraceId(): void
    {
        $controller = new class($this->app()) extends \app\controller\ApiController {
            public function testError()
            {
                // Simulate trace_id in request header | 模拟请求头中的 trace_id
                $this->request->withHeader(['X-Trace-Id' => 'test-trace-id-456']);
                
                return $this->error('Test error message', 400);
            }
        };
        
        $response = $controller->testError();
        
        // Assert HTTP status code is 400 | 断言 HTTP 状态码为 400
        $this->assertEquals(400, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert trace_id field exists | 断言 trace_id 字段存在
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert trace_id value | 断言 trace_id 值
        $this->assertEquals('test-trace-id-456', $data['trace_id']);
    }

    /**
     * Test ApiController validation error includes trace_id | 测试 ApiController 验证错误包含 trace_id
     */
    public function testApiControllerValidationErrorIncludesTraceId(): void
    {
        $controller = new class($this->app()) extends \app\controller\ApiController {
            public function testValidationError()
            {
                $this->request->withHeader(['X-Trace-Id' => 'test-trace-id-789']);
                
                return $this->validationError(['name' => 'Name is required']);
            }
        };
        
        $response = $controller->testValidationError();
        
        // Assert HTTP status code is 422 | 断言 HTTP 状态码为 422
        $this->assertEquals(422, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert trace_id field exists | 断言 trace_id 字段存在
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert trace_id value | 断言 trace_id 值
        $this->assertEquals('test-trace-id-789', $data['trace_id']);
    }

    /**
     * Test ResponseHelper jsonError includes trace_id | 测试 ResponseHelper jsonError 包含 trace_id
     */
    public function testResponseHelperJsonErrorIncludesTraceId(): void
    {
        $traceId = 'test-trace-id-helper';
        
        $response = \app\middleware\ResponseHelper::jsonError(
            2001,
            'Unauthorized',
            401,
            $traceId
        );
        
        // Assert HTTP status code is 401 | 断言 HTTP 状态码为 401
        $this->assertEquals(401, $response->getCode());
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert trace_id field exists | 断言 trace_id 字段存在
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert trace_id value | 断言 trace_id 值
        $this->assertEquals($traceId, $data['trace_id']);
        
        // Assert other fields | 断言其他字段
        $this->assertEquals(2001, $data['code']);
        $this->assertEquals('Unauthorized', $data['message']);
    }

    /**
     * Test ResponseHelper without trace_id | 测试 ResponseHelper 不传入 trace_id
     */
    public function testResponseHelperWithoutTraceId(): void
    {
        $response = \app\middleware\ResponseHelper::jsonError(
            2002,
            'Forbidden',
            403,
            null  // No trace_id | 不传入 trace_id
        );
        
        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);
        
        // Assert trace_id field does NOT exist when null | 断言 trace_id 为 null 时不存在
        $this->assertArrayNotHasKey('trace_id', $data);
    }

    /**
     * Test all required fields in error response | 测试错误响应中的所有必需字段
     */
    public function testErrorResponseStructure(): void
    {
        $controller = new class($this->app()) extends \app\controller\ApiController {
            public function testError()
            {
                $this->request->withHeader(['X-Trace-Id' => 'test-structure']);
                
                return $this->error('Error message', 500);
            }
        };
        
        $response = $controller->testError();
        $data = json_decode($response->getContent(), true);
        
        // Assert all required fields exist | 断言所有必需字段存在
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert field types | 断言字段类型
        $this->assertIsInt($data['code']);
        $this->assertIsString($data['message']);
        $this->assertIsInt($data['timestamp']);
        $this->assertIsString($data['trace_id']);
    }

    /**
     * Test success response structure | 测试成功响应结构
     */
    public function testSuccessResponseStructure(): void
    {
        $controller = new class($this->app()) extends \app\controller\ApiController {
            public function testSuccess()
            {
                $this->request->withHeader(['X-Trace-Id' => 'test-success-structure']);
                
                return $this->success(['key' => 'value']);
            }
        };
        
        $response = $controller->testSuccess();
        $data = json_decode($response->getContent(), true);
        
        // Assert all required fields exist | 断言所有必需字段存在
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('trace_id', $data);
        
        // Assert code is 0 for success | 断言成功时 code 为 0
        $this->assertEquals(0, $data['code']);
        
        // Assert data is correct | 断言数据正确
        $this->assertEquals(['key' => 'value'], $data['data']);
    }
}

