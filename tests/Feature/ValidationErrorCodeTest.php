<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;
use think\exception\ValidateException;
use app\ExceptionHandle;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../vendor/topthink/framework/src/helper.php';
}

/**
 * Validation Error Code Test | 验证错误码测试
 *
 * Tests that validation errors consistently return code=422
 * 测试验证错误统一返回 code=422
 *
 * @package Tests\Feature
 */
class ValidationErrorCodeTest extends ThinkPHPTestCase
{
    /**
     * Test ValidateException returns code=422 | 测试 ValidateException 返回 code=422
     */
    public function testValidateExceptionReturnsCode422(): void
    {
        // Create a ValidateException | 创建验证异常
        $exception = new ValidateException([
            'name' => 'Name is required',
            'email' => 'Email format is invalid',
        ]);

        // Create a mock request with ThinkPHP App | 使用 ThinkPHP App 创建模拟请求
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod('POST');
        $request->setPathinfo('test/validation');

        // Create ExceptionHandle instance | 创建异常处理器实例
        $exceptionHandle = new ExceptionHandle($this->app());

        // Handle the exception | 处理异常
        $response = $exceptionHandle->render($request, $exception);

        // Assert HTTP status code is 422 | 断言 HTTP 状态码为 422
        $this->assertEquals(422, $response->getCode());

        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);

        // Assert business code is 422 (not 4001) | 断言业务码为 422（不是 4001）
        $this->assertEquals(422, $data['code'], 'Validation error code should be 422, not 4001');

        // Assert response structure | 断言响应结构
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);

        // Assert error message | 断言错误消息
        $this->assertStringContainsString('Validation failed', $data['message']);

        // Assert errors are in data | 断言错误详情在 data 中
        $this->assertArrayHasKey('errors', $data['data']);
        $this->assertIsArray($data['data']['errors']);
    }

    /**
     * Test ApiController::validationError returns code=422 | 测试 ApiController::validationError 返回 code=422
     */
    public function testApiControllerValidationErrorReturnsCode422(): void
    {
        // Create a test controller instance with App | 使用 App 创建测试控制器实例
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testValidation()
            {
                return $this->validationError([
                    'name' => 'Name is required',
                    'email' => 'Email format is invalid',
                ]);
            }
        };

        // Call the validation error method | 调用验证错误方法
        $response = $controller->testValidation();

        // Assert HTTP status code is 422 | 断言 HTTP 状态码为 422
        $this->assertEquals(422, $response->getCode());

        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);

        // Assert business code is 422 | 断言业务码为 422
        $this->assertEquals(422, $data['code']);

        // Assert response structure | 断言响应结构
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);

        // Assert errors are in data | 断言错误详情在 data 中
        $this->assertArrayHasKey('errors', $data['data']);
        $this->assertIsArray($data['data']['errors']);
    }

    /**
     * Test consistency between ValidateException and ApiController | 测试 ValidateException 和 ApiController 的一致性
     */
    public function testValidationErrorCodeConsistency(): void
    {
        // Get response from ValidateException | 获取 ValidateException 的响应
        $exception = new ValidateException(['name' => 'Name is required']);
        $exceptionHandle = new ExceptionHandle($this->app());
        $request = $this->app()->make(\think\Request::class);
        $response1 = $exceptionHandle->render($request, $exception);
        $data1 = json_decode($response1->getContent(), true);

        // Get response from ApiController | 获取 ApiController 的响应
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testValidation()
            {
                return $this->validationError(['name' => 'Name is required']);
            }
        };
        $response2 = $controller->testValidation();
        $data2 = json_decode($response2->getContent(), true);

        // Assert both return the same code | 断言两者返回相同的错误码
        $this->assertEquals(
            $data1['code'],
            $data2['code'],
            'ValidateException and ApiController::validationError should return the same error code'
        );

        // Assert both return HTTP 422 | 断言两者都返回 HTTP 422
        $this->assertEquals(422, $response1->getCode());
        $this->assertEquals(422, $response2->getCode());

        // Assert both have the same response structure | 断言两者有相同的响应结构
        $this->assertEquals(
            array_keys($data1),
            array_keys($data2),
            'Response structure should be consistent'
        );
    }

    /**
     * Test that code is NOT 4001 (old code) | 测试错误码不是 4001（旧错误码）
     */
    public function testValidationErrorCodeIsNot4001(): void
    {
        $exception = new ValidateException(['name' => 'Name is required']);
        $exceptionHandle = new ExceptionHandle($this->app());
        $request = $this->app()->make(\think\Request::class);
        $response = $exceptionHandle->render($request, $exception);
        $data = json_decode($response->getContent(), true);

        // Assert code is NOT 4001 | 断言错误码不是 4001
        $this->assertNotEquals(
            4001,
            $data['code'],
            'Validation error code should be 422, not the old code 4001'
        );
    }

    /**
     * Test validation error response format | 测试验证错误响应格式
     */
    public function testValidationErrorResponseFormat(): void
    {
        $exception = new ValidateException([
            'name' => 'Name is required',
            'email' => 'Email format is invalid',
        ]);
        $exceptionHandle = new ExceptionHandle($this->app());
        $request = $this->app()->make(\think\Request::class);
        $response = $exceptionHandle->render($request, $exception);
        $data = json_decode($response->getContent(), true);

        // Assert required fields exist | 断言必需字段存在
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);

        // Assert code is integer | 断言 code 是整数
        $this->assertIsInt($data['code']);

        // Assert message is string | 断言 message 是字符串
        $this->assertIsString($data['message']);

        // Assert timestamp is integer | 断言 timestamp 是整数
        $this->assertIsInt($data['timestamp']);

        // Assert data contains errors | 断言 data 包含 errors
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('errors', $data['data']);

        // Assert errors is array | 断言 errors 是数组
        $this->assertIsArray($data['data']['errors']);
        $this->assertNotEmpty($data['data']['errors']);
    }
}
