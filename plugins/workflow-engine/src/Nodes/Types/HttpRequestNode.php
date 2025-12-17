<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * HTTP Request Node | HTTP 请求节点
 *
 * A service task node that makes HTTP requests to external services.
 * Supports GET, POST, PUT, PATCH, DELETE methods with various auth types.
 * 向外部服务发送HTTP请求的服务任务节点。
 * 支持 GET, POST, PUT, PATCH, DELETE 方法及多种认证类型。
 *
 * Configuration options:
 * - url: string - Request URL (supports variable interpolation)
 * - method: string - HTTP method
 * - headers: array - Request headers
 * - body: mixed - Request body
 * - body_type: string - Body format (json, form, raw)
 * - auth: array - Authentication configuration
 * - timeout: int - Request timeout in seconds
 * - retry: array - Retry configuration
 * - response_mapping: array - Map response to variables
 * - error_handling: array - Error handling configuration
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class HttpRequestNode extends AbstractNode
{
    /**
     * HTTP methods | HTTP 方法
     */
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';

    /**
     * Body types | 请求体类型
     */
    public const BODY_JSON = 'json';
    public const BODY_FORM = 'form';
    public const BODY_MULTIPART = 'multipart';
    public const BODY_RAW = 'raw';
    public const BODY_XML = 'xml';

    /**
     * Auth types | 认证类型
     */
    public const AUTH_NONE = 'none';
    public const AUTH_BASIC = 'basic';
    public const AUTH_BEARER = 'bearer';
    public const AUTH_API_KEY = 'api_key';
    public const AUTH_OAUTH2 = 'oauth2';
    public const AUTH_DIGEST = 'digest';

    /**
     * Error handling modes | 错误处理模式
     */
    public const ON_ERROR_FAIL = 'fail';
    public const ON_ERROR_CONTINUE = 'continue';
    public const ON_ERROR_RETRY = 'retry';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $config['sub_type'] = $config['sub_type'] ?? NodeInterface::TASK_SERVICE;
        parent::__construct($id, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_TASK;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? NodeInterface::TASK_SERVICE;
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Execute the HTTP request node | 执行 HTTP 请求节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        try {
            // Build request
            $request = $this->buildRequest($context);

            // Execute with retry logic
            $response = $this->executeWithRetry($request, $context);

            // Process response
            return $this->processResponse($response, $context);
        } catch (\Throwable $e) {
            return $this->handleError($e, $context);
        }
    }

    /**
     * Build HTTP request | 构建 HTTP 请求
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function buildRequest(ExecutionContext $context): array
    {
        $url = $this->interpolateString($this->getConfigValue('url', ''), $context);
        $method = $this->getConfigValue('method', self::METHOD_GET);
        $headers = $this->buildHeaders($context);
        $body = $this->buildBody($context);
        $timeout = $this->getConfigValue('timeout', 30);

        // Build query parameters for GET requests
        $queryParams = $this->getConfigValue('query_params', []);
        if (!empty($queryParams)) {
            $resolvedParams = $this->resolveArrayValues($queryParams, $context);
            $queryString = http_build_query($resolvedParams);
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        return [
            'url' => $url,
            'method' => strtoupper($method),
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'verify_ssl' => $this->getConfigValue('verify_ssl', true),
            'follow_redirects' => $this->getConfigValue('follow_redirects', true),
            'max_redirects' => $this->getConfigValue('max_redirects', 5),
        ];
    }

    /**
     * Build request headers | 构建请求头
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function buildHeaders(ExecutionContext $context): array
    {
        $headers = [];

        // Default headers based on body type
        $bodyType = $this->getConfigValue('body_type', self::BODY_JSON);
        $headers['Content-Type'] = match ($bodyType) {
            self::BODY_JSON => 'application/json',
            self::BODY_FORM => 'application/x-www-form-urlencoded',
            self::BODY_MULTIPART => 'multipart/form-data',
            self::BODY_XML => 'application/xml',
            default => 'text/plain',
        };

        // Custom headers
        $customHeaders = $this->getConfigValue('headers', []);
        foreach ($customHeaders as $name => $value) {
            $headers[$name] = $this->interpolateString((string)$value, $context);
        }

        // Add authentication headers
        $authHeaders = $this->buildAuthHeaders($context);
        $headers = array_merge($headers, $authHeaders);

        return $headers;
    }

    /**
     * Build authentication headers | 构建认证头
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function buildAuthHeaders(ExecutionContext $context): array
    {
        $auth = $this->getConfigValue('auth', []);
        $authType = $auth['type'] ?? self::AUTH_NONE;

        return match ($authType) {
            self::AUTH_BASIC => [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->interpolateString($auth['username'] ?? '', $context) . ':' .
                    $this->interpolateString($auth['password'] ?? '', $context)
                ),
            ],
            self::AUTH_BEARER => [
                'Authorization' => 'Bearer ' . $this->interpolateString($auth['token'] ?? '', $context),
            ],
            self::AUTH_API_KEY => $this->buildApiKeyAuth($auth, $context),
            default => [],
        };
    }

    /**
     * Build API key authentication | 构建 API Key 认证
     *
     * @param array $auth
     * @param ExecutionContext $context
     * @return array
     */
    protected function buildApiKeyAuth(array $auth, ExecutionContext $context): array
    {
        $location = $auth['location'] ?? 'header';
        $keyName = $auth['key_name'] ?? 'X-API-Key';
        $keyValue = $this->interpolateString($auth['key_value'] ?? '', $context);

        if ($location === 'header') {
            return [$keyName => $keyValue];
        }

        // Query parameter handled in buildRequest
        return [];
    }

    /**
     * Build request body | 构建请求体
     *
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function buildBody(ExecutionContext $context): mixed
    {
        $method = $this->getConfigValue('method', self::METHOD_GET);

        // No body for GET, HEAD, OPTIONS
        if (in_array(strtoupper($method), [self::METHOD_GET, self::METHOD_HEAD, self::METHOD_OPTIONS], true)) {
            return null;
        }

        $body = $this->getConfigValue('body', []);
        $bodyType = $this->getConfigValue('body_type', self::BODY_JSON);

        // Resolve body values
        if (is_array($body)) {
            $body = $this->resolveArrayValues($body, $context);
        } elseif (is_string($body)) {
            $body = $this->interpolateString($body, $context);
        }

        return match ($bodyType) {
            self::BODY_JSON => json_encode($body, JSON_UNESCAPED_UNICODE),
            self::BODY_FORM => http_build_query($body),
            self::BODY_XML => $this->arrayToXml($body),
            default => $body,
        };
    }

    /**
     * Execute HTTP request with retry logic | 带重试逻辑执行 HTTP 请求
     *
     * @param array $request
     * @param ExecutionContext $context
     * @return array
     */
    protected function executeWithRetry(array $request, ExecutionContext $context): array
    {
        $retryConfig = $this->getConfigValue('retry', []);
        $maxRetries = $retryConfig['max_attempts'] ?? 0;
        $retryDelay = $retryConfig['delay'] ?? 1000; // milliseconds
        $retryOn = $retryConfig['on_status_codes'] ?? [500, 502, 503, 504];

        $lastException = null;
        $attempt = 0;

        do {
            try {
                $response = $this->executeRequest($request);

                // Check if should retry based on status code
                if ($attempt < $maxRetries && in_array($response['status_code'], $retryOn, true)) {
                    $attempt++;
                    usleep($retryDelay * 1000);
                    continue;
                }

                return $response;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt <= $maxRetries) {
                    usleep($retryDelay * 1000);
                }
            }
        } while ($attempt <= $maxRetries);

        // All retries failed
        if ($lastException) {
            throw $lastException;
        }

        throw new \RuntimeException('HTTP 请求失败，已达到最大重试次数');
    }

    /**
     * Execute the actual HTTP request | 执行实际的 HTTP 请求
     *
     * Uses cURL for HTTP requests
     *
     * @param array $request
     * @return array
     */
    protected function executeRequest(array $request): array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $request['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $request['follow_redirects'],
            CURLOPT_MAXREDIRS => $request['max_redirects'],
            CURLOPT_TIMEOUT => $request['timeout'],
            CURLOPT_SSL_VERIFYPEER => $request['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $request['verify_ssl'] ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $request['method'],
        ];

        // Set headers
        $headerLines = [];
        foreach ($request['headers'] as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $options[CURLOPT_HTTPHEADER] = $headerLines;

        // Set body
        if ($request['body'] !== null) {
            $options[CURLOPT_POSTFIELDS] = $request['body'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);

        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }

        // Parse response
        $headerSize = $info['header_size'];
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        return [
            'status_code' => $info['http_code'],
            'headers' => $this->parseResponseHeaders($responseHeaders),
            'body' => $responseBody,
            'content_type' => $info['content_type'] ?? '',
            'response_time' => $info['total_time'],
            'url' => $info['url'],
        ];
    }

    /**
     * Parse response headers | 解析响应头
     *
     * @param string $headerString
     * @return array
     */
    protected function parseResponseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Process HTTP response | 处理 HTTP 响应
     *
     * @param array $response
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function processResponse(array $response, ExecutionContext $context): NodeResult
    {
        $statusCode = $response['status_code'];
        $successCodes = $this->getConfigValue('success_codes', [200, 201, 202, 204]);

        // Parse response body
        $parsedBody = $this->parseResponseBody($response);

        // Store response in context
        $responseData = [
            'status_code' => $statusCode,
            'headers' => $response['headers'],
            'body' => $parsedBody,
            'raw_body' => $response['body'],
            'response_time' => $response['response_time'],
        ];

        // Apply response mapping
        $this->applyResponseMapping($responseData, $context);

        // Store full response
        $context->setVariable("http_response_{$this->id}", $responseData);

        // Check success
        if (in_array($statusCode, $successCodes, true)) {
            return NodeResult::success([
                'status_code' => $statusCode,
                'body' => $parsedBody,
                'response_time' => $response['response_time'],
                'message' => 'HTTP 请求成功',
            ], $this->getDefaultNextNodes());
        }

        // Handle non-success status codes
        $errorHandling = $this->getConfigValue('error_handling', []);
        $onError = $errorHandling['mode'] ?? self::ON_ERROR_FAIL;

        if ($onError === self::ON_ERROR_CONTINUE) {
            return NodeResult::success([
                'status_code' => $statusCode,
                'body' => $parsedBody,
                'error' => true,
                'message' => "HTTP 请求返回 {$statusCode}",
            ], $this->getDefaultNextNodes());
        }

        return NodeResult::failed(
            "HTTP 请求失败，状态码: {$statusCode}",
            $statusCode
        );
    }

    /**
     * Parse response body based on content type | 根据内容类型解析响应体
     *
     * @param array $response
     * @return mixed
     */
    protected function parseResponseBody(array $response): mixed
    {
        $contentType = $response['content_type'] ?? '';
        $body = $response['body'];

        if (str_contains($contentType, 'application/json')) {
            return json_decode($body, true) ?? $body;
        }

        if (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
            try {
                $xml = simplexml_load_string($body);
                return json_decode(json_encode($xml), true);
            } catch (\Throwable) {
                return $body;
            }
        }

        return $body;
    }

    /**
     * Apply response mapping | 应用响应映射
     *
     * @param array $responseData
     * @param ExecutionContext $context
     */
    protected function applyResponseMapping(array $responseData, ExecutionContext $context): void
    {
        $mapping = $this->getConfigValue('response_mapping', []);

        foreach ($mapping as $targetVar => $sourcePath) {
            $value = $this->extractValue($responseData, $sourcePath);
            $context->setVariable($targetVar, $value);
        }
    }

    /**
     * Extract value using path | 使用路径提取值
     *
     * @param array $data
     * @param string $path
     * @return mixed
     */
    protected function extractValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            // Handle array index notation [0]
            if (preg_match('/^(\w+)\[(\d+)\]$/', $key, $matches)) {
                $key = $matches[1];
                $index = (int)$matches[2];

                if (!isset($value[$key]) || !is_array($value[$key])) {
                    return null;
                }
                $value = $value[$key][$index] ?? null;
            } else {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }
        }

        return $value;
    }

    /**
     * Handle execution error | 处理执行错误
     *
     * @param \Throwable $e
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function handleError(\Throwable $e, ExecutionContext $context): NodeResult
    {
        $errorHandling = $this->getConfigValue('error_handling', []);
        $onError = $errorHandling['mode'] ?? self::ON_ERROR_FAIL;

        // Store error info
        $context->setVariable("http_error_{$this->id}", [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'type' => get_class($e),
        ]);

        if ($onError === self::ON_ERROR_CONTINUE) {
            return NodeResult::success([
                'error' => true,
                'error_message' => $e->getMessage(),
                'message' => 'HTTP 请求失败但继续执行',
            ], $this->getDefaultNextNodes());
        }

        return NodeResult::failed(
            "HTTP 请求异常: {$e->getMessage()}",
            $e->getCode() ?: 500
        );
    }

    /**
     * Interpolate string with context variables | 使用上下文变量插值字符串
     *
     * @param string $template
     * @param ExecutionContext $context
     * @return string
     */
    protected function interpolateString(string $template, ExecutionContext $context): string
    {
        return preg_replace_callback(
            '/\$\{([^}]+)\}/',
            function ($matches) use ($context) {
                $varPath = $matches[1];
                $value = $context->getVariable($varPath);

                if ($value === null && str_contains($varPath, '.')) {
                    $parts = explode('.', $varPath, 2);
                    $rootValue = $context->getVariable($parts[0]);
                    if (is_array($rootValue)) {
                        $value = $this->extractValue($rootValue, $parts[1]);
                    }
                }

                return is_scalar($value) ? (string)$value : json_encode($value);
            },
            $template
        );
    }

    /**
     * Resolve array values | 解析数组值
     *
     * @param array $array
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveArrayValues(array $array, ExecutionContext $context): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->resolveArrayValues($value, $context);
            } elseif (is_string($value)) {
                $result[$key] = $this->interpolateString($value, $context);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert array to XML | 将数组转换为 XML
     *
     * @param array $data
     * @param string $rootElement
     * @return string
     */
    protected function arrayToXml(array $data, string $rootElement = 'root'): string
    {
        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$rootElement}/>");
        $this->arrayToXmlRecursive($data, $xml);
        return $xml->asXML();
    }

    /**
     * Recursively convert array to XML | 递归转换数组为 XML
     *
     * @param array $data
     * @param \SimpleXMLElement $xml
     */
    protected function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                $key = 'item';
            }

            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->arrayToXmlRecursive($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }

    /**
     * Get configuration schema | 获取配置 Schema
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => '请求 URL',
                ],
                'method' => [
                    'type' => 'string',
                    'enum' => [
                        self::METHOD_GET,
                        self::METHOD_POST,
                        self::METHOD_PUT,
                        self::METHOD_PATCH,
                        self::METHOD_DELETE,
                        self::METHOD_HEAD,
                        self::METHOD_OPTIONS,
                    ],
                    'default' => self::METHOD_GET,
                    'description' => 'HTTP 方法',
                ],
                'headers' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => '请求头',
                ],
                'query_params' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => '查询参数',
                ],
                'body' => [
                    'description' => '请求体',
                ],
                'body_type' => [
                    'type' => 'string',
                    'enum' => [
                        self::BODY_JSON,
                        self::BODY_FORM,
                        self::BODY_MULTIPART,
                        self::BODY_RAW,
                        self::BODY_XML,
                    ],
                    'default' => self::BODY_JSON,
                    'description' => '请求体类型',
                ],
                'auth' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                self::AUTH_NONE,
                                self::AUTH_BASIC,
                                self::AUTH_BEARER,
                                self::AUTH_API_KEY,
                            ],
                        ],
                        'username' => ['type' => 'string'],
                        'password' => ['type' => 'string'],
                        'token' => ['type' => 'string'],
                        'key_name' => ['type' => 'string'],
                        'key_value' => ['type' => 'string'],
                        'location' => ['type' => 'string', 'enum' => ['header', 'query']],
                    ],
                    'description' => '认证配置',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 300,
                    'default' => 30,
                    'description' => '超时时间（秒）',
                ],
                'retry' => [
                    'type' => 'object',
                    'properties' => [
                        'max_attempts' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                        'delay' => ['type' => 'integer', 'minimum' => 0, 'default' => 1000],
                        'on_status_codes' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                    ],
                    'description' => '重试配置',
                ],
                'success_codes' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'default' => [200, 201, 202, 204],
                    'description' => '成功状态码',
                ],
                'response_mapping' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => '响应映射',
                ],
                'error_handling' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => [self::ON_ERROR_FAIL, self::ON_ERROR_CONTINUE],
                            'default' => self::ON_ERROR_FAIL,
                        ],
                    ],
                    'description' => '错误处理',
                ],
                'verify_ssl' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => '验证 SSL 证书',
                ],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * Validate configuration | 验证配置
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config = []): bool
    {
        $configToValidate = !empty($config) ? $config : $this->config;

        // URL is required
        if (empty($configToValidate['url'])) {
            return false;
        }

        // Validate method
        if (isset($configToValidate['method'])) {
            $validMethods = [
                self::METHOD_GET,
                self::METHOD_POST,
                self::METHOD_PUT,
                self::METHOD_PATCH,
                self::METHOD_DELETE,
                self::METHOD_HEAD,
                self::METHOD_OPTIONS,
            ];
            if (!in_array(strtoupper($configToValidate['method']), $validMethods, true)) {
                return false;
            }
        }

        // Validate timeout
        if (isset($configToValidate['timeout'])) {
            $timeout = $configToValidate['timeout'];
            if ($timeout < 1 || $timeout > 300) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
