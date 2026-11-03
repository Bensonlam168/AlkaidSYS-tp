<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\HttpRequestNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * HttpRequestNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class HttpRequestNodeTest extends TestCase
{
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_http_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $node = new HttpRequestNode('http_001', [
            'name' => 'HTTP 请求',
            'url' => 'https://api.example.com/test',
            'method' => HttpRequestNode::METHOD_GET,
        ]);

        $this->assertEquals('http_001', $node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $node->getType());
        $this->assertEquals(NodeInterface::TASK_SERVICE, $node->getSubType());
    }

    /**
     * 测试URL变量插值
     */
    public function testUrlInterpolation(): void
    {
        $node = new HttpRequestNode('http_interp', [
            'name' => 'URL插值',
            'url' => 'https://api.example.com/users/${user_id}/orders/${order_id}',
            'method' => HttpRequestNode::METHOD_GET,
        ]);

        $this->context->setVariable('user_id', '12345');
        $this->context->setVariable('order_id', 'order_001');

        // 使用反射测试 buildRequest 方法
        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildRequest');
        $method->setAccessible(true);

        $request = $method->invoke($node, $this->context);

        $this->assertStringContainsString('12345', $request['url']);
        $this->assertStringContainsString('order_001', $request['url']);
    }

    /**
     * 测试请求头构建
     */
    public function testHeadersBuilding(): void
    {
        $node = new HttpRequestNode('http_headers', [
            'name' => 'Headers测试',
            'url' => 'https://api.example.com',
            'method' => HttpRequestNode::METHOD_POST,
            'body_type' => HttpRequestNode::BODY_JSON,
            'headers' => [
                'X-Custom-Header' => '${custom_value}',
                'X-Static-Header' => 'static_value',
            ],
        ]);

        $this->context->setVariable('custom_value', 'dynamic_123');

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($node, $this->context);

        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('dynamic_123', $headers['X-Custom-Header']);
        $this->assertEquals('static_value', $headers['X-Static-Header']);
    }

    /**
     * 测试Basic认证头
     */
    public function testBasicAuth(): void
    {
        $node = new HttpRequestNode('http_basic_auth', [
            'name' => 'Basic认证',
            'url' => 'https://api.example.com',
            'auth' => [
                'type' => HttpRequestNode::AUTH_BASIC,
                'username' => 'user',
                'password' => 'pass',
            ],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildAuthHeaders');
        $method->setAccessible(true);

        $authHeaders = $method->invoke($node, $this->context);

        $this->assertArrayHasKey('Authorization', $authHeaders);
        $this->assertStringStartsWith('Basic ', $authHeaders['Authorization']);
        $this->assertEquals('Basic ' . base64_encode('user:pass'), $authHeaders['Authorization']);
    }

    /**
     * 测试Bearer认证头
     */
    public function testBearerAuth(): void
    {
        $node = new HttpRequestNode('http_bearer_auth', [
            'name' => 'Bearer认证',
            'url' => 'https://api.example.com',
            'auth' => [
                'type' => HttpRequestNode::AUTH_BEARER,
                'token' => '${api_token}',
            ],
        ]);

        $this->context->setVariable('api_token', 'my_secret_token');

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildAuthHeaders');
        $method->setAccessible(true);

        $authHeaders = $method->invoke($node, $this->context);

        $this->assertEquals('Bearer my_secret_token', $authHeaders['Authorization']);
    }

    /**
     * 测试API Key认证
     */
    public function testApiKeyAuth(): void
    {
        $node = new HttpRequestNode('http_apikey_auth', [
            'name' => 'API Key认证',
            'url' => 'https://api.example.com',
            'auth' => [
                'type' => HttpRequestNode::AUTH_API_KEY,
                'location' => 'header',
                'key_name' => 'X-API-Key',
                'key_value' => 'my_api_key',
            ],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildAuthHeaders');
        $method->setAccessible(true);

        $authHeaders = $method->invoke($node, $this->context);

        $this->assertEquals('my_api_key', $authHeaders['X-API-Key']);
    }

    /**
     * 测试JSON请求体构建
     */
    public function testJsonBodyBuilding(): void
    {
        $node = new HttpRequestNode('http_json_body', [
            'name' => 'JSON Body',
            'url' => 'https://api.example.com',
            'method' => HttpRequestNode::METHOD_POST,
            'body_type' => HttpRequestNode::BODY_JSON,
            'body' => [
                'name' => '${user_name}',
                'email' => 'test@example.com',
                'data' => [
                    'nested' => '${nested_value}',
                ],
            ],
        ]);

        $this->context->setVariable('user_name', 'John Doe');
        $this->context->setVariable('nested_value', 'nested_data');

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildBody');
        $method->setAccessible(true);

        $body = $method->invoke($node, $this->context);
        $decoded = json_decode($body, true);

        $this->assertEquals('John Doe', $decoded['name']);
        $this->assertEquals('test@example.com', $decoded['email']);
        $this->assertEquals('nested_data', $decoded['data']['nested']);
    }

    /**
     * 测试Form请求体构建
     */
    public function testFormBodyBuilding(): void
    {
        $node = new HttpRequestNode('http_form_body', [
            'name' => 'Form Body',
            'url' => 'https://api.example.com',
            'method' => HttpRequestNode::METHOD_POST,
            'body_type' => HttpRequestNode::BODY_FORM,
            'body' => [
                'field1' => 'value1',
                'field2' => 'value2',
            ],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildBody');
        $method->setAccessible(true);

        $body = $method->invoke($node, $this->context);

        $this->assertStringContainsString('field1=value1', $body);
        $this->assertStringContainsString('field2=value2', $body);
    }

    /**
     * 测试查询参数
     */
    public function testQueryParams(): void
    {
        $node = new HttpRequestNode('http_query', [
            'name' => '查询参数',
            'url' => 'https://api.example.com/search',
            'method' => HttpRequestNode::METHOD_GET,
            'query_params' => [
                'q' => '${search_term}',
                'page' => '1',
                'limit' => '10',
            ],
        ]);

        $this->context->setVariable('search_term', 'test query');

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildRequest');
        $method->setAccessible(true);

        $request = $method->invoke($node, $this->context);

        $this->assertStringContainsString('q=test+query', $request['url']);
        $this->assertStringContainsString('page=1', $request['url']);
        $this->assertStringContainsString('limit=10', $request['url']);
    }

    /**
     * 测试响应头解析
     */
    public function testResponseHeaderParsing(): void
    {
        $node = new HttpRequestNode('http_parse', [
            'name' => '响应解析',
            'url' => 'https://api.example.com',
        ]);

        $headerString = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Request-Id: req_123\r\n\r\n";

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('parseResponseHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($node, $headerString);

        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('req_123', $headers['X-Request-Id']);
    }

    /**
     * 测试响应值提取
     */
    public function testValueExtraction(): void
    {
        $node = new HttpRequestNode('http_extract', [
            'name' => '值提取',
            'url' => 'https://api.example.com',
        ]);

        $data = [
            'body' => [
                'user' => [
                    'name' => 'John',
                    'orders' => [
                        ['id' => 'order_1'],
                        ['id' => 'order_2'],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('extractValue');
        $method->setAccessible(true);

        // 测试简单路径
        $this->assertEquals(['user' => $data['body']['user']], ['user' => $method->invoke($node, $data, 'body.user')]);

        // 测试数组索引
        $this->assertEquals('order_1', $method->invoke($node, $data, 'body.user.orders[0].id'));
    }

    /**
     * 测试配置验证
     */
    public function testConfigValidation(): void
    {
        $node = new HttpRequestNode('http_valid', [
            'name' => '验证测试',
            'url' => 'https://api.example.com',
        ]);

        // 有效配置
        $validConfig = [
            'url' => 'https://api.example.com',
            'method' => HttpRequestNode::METHOD_POST,
            'timeout' => 30,
        ];
        $this->assertTrue($node->validateConfig($validConfig));

        // 无效配置 - 缺少URL
        $invalidNoUrl = [
            'method' => HttpRequestNode::METHOD_GET,
        ];
        $this->assertFalse($node->validateConfig($invalidNoUrl));

        // 无效配置 - 无效方法
        $invalidMethod = [
            'url' => 'https://api.example.com',
            'method' => 'INVALID',
        ];
        $this->assertFalse($node->validateConfig($invalidMethod));

        // 无效配置 - 超时太大
        $invalidTimeout = [
            'url' => 'https://api.example.com',
            'timeout' => 500,
        ];
        $this->assertFalse($node->validateConfig($invalidTimeout));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $node = new HttpRequestNode('http_schema', [
            'name' => '配置测试',
            'url' => 'https://api.example.com',
        ]);

        $schema = $node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('url', $schema['properties']);
        $this->assertArrayHasKey('method', $schema['properties']);
        $this->assertArrayHasKey('headers', $schema['properties']);
        $this->assertArrayHasKey('auth', $schema['properties']);
        $this->assertArrayHasKey('timeout', $schema['properties']);
        $this->assertArrayHasKey('retry', $schema['properties']);
        $this->assertContains('url', $schema['required']);
    }

    /**
     * 测试错误处理模式 - 继续执行
     */
    public function testErrorHandlingContinue(): void
    {
        $node = new HttpRequestNode('http_continue', [
            'name' => '继续执行',
            'url' => 'https://api.example.com',
            'error_handling' => [
                'mode' => HttpRequestNode::ON_ERROR_CONTINUE,
            ],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('handleError');
        $method->setAccessible(true);

        $result = $method->invoke($node, new \RuntimeException('Test error'), $this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertTrue($result->getData()['error']);
    }

    /**
     * 测试错误处理模式 - 失败
     */
    public function testErrorHandlingFail(): void
    {
        $node = new HttpRequestNode('http_fail', [
            'name' => '失败处理',
            'url' => 'https://api.example.com',
            'error_handling' => [
                'mode' => HttpRequestNode::ON_ERROR_FAIL,
            ],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('handleError');
        $method->setAccessible(true);

        $result = $method->invoke($node, new \RuntimeException('Test error', 500), $this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试GET请求无Body
     */
    public function testGetRequestNoBody(): void
    {
        $node = new HttpRequestNode('http_get_nobody', [
            'name' => 'GET无Body',
            'url' => 'https://api.example.com',
            'method' => HttpRequestNode::METHOD_GET,
            'body' => ['should' => 'be ignored'],
        ]);

        $reflection = new \ReflectionClass($node);
        $method = $reflection->getMethod('buildBody');
        $method->setAccessible(true);

        $body = $method->invoke($node, $this->context);

        $this->assertNull($body);
    }

    /**
     * 测试各种HTTP方法
     */
    public function testHttpMethods(): void
    {
        $methods = [
            HttpRequestNode::METHOD_GET,
            HttpRequestNode::METHOD_POST,
            HttpRequestNode::METHOD_PUT,
            HttpRequestNode::METHOD_PATCH,
            HttpRequestNode::METHOD_DELETE,
            HttpRequestNode::METHOD_HEAD,
            HttpRequestNode::METHOD_OPTIONS,
        ];

        foreach ($methods as $httpMethod) {
            $node = new HttpRequestNode("http_{$httpMethod}", [
                'name' => "测试{$httpMethod}",
                'url' => 'https://api.example.com',
                'method' => $httpMethod,
            ]);

            $this->assertTrue($node->validateConfig([
                'url' => 'https://api.example.com',
                'method' => $httpMethod,
            ]), "Method {$httpMethod} should be valid");
        }
    }
}
