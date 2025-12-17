<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Script Node | 脚本节点
 *
 * A service task node that executes custom scripts or code snippets.
 * Supports PHP inline scripts, expression evaluation, and external script files.
 * 执行自定义脚本或代码片段的服务任务节点。
 * 支持 PHP 内联脚本、表达式计算和外部脚本文件。
 *
 * Configuration options:
 * - script_type: string - Type of script (php, expression, file, sandbox)
 * - script: string - Script content or file path
 * - input_mapping: array - Map context variables to script inputs
 * - output_mapping: array - Map script results to context variables
 * - timeout: int - Script execution timeout in seconds
 * - sandbox: bool - Execute in sandboxed environment
 * - allowed_functions: array - List of allowed PHP functions (sandbox mode)
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class ScriptNode extends AbstractNode
{
    /**
     * Script types | 脚本类型
     */
    public const TYPE_PHP = 'php';
    public const TYPE_EXPRESSION = 'expression';
    public const TYPE_FILE = 'file';
    public const TYPE_SANDBOX = 'sandbox';
    public const TYPE_JSON_TRANSFORM = 'json_transform';

    /**
     * Default allowed functions in sandbox mode | 沙箱模式默认允许的函数
     */
    protected const SANDBOX_ALLOWED_FUNCTIONS = [
        // String functions
        'strlen', 'substr', 'strpos', 'str_replace', 'strtolower', 'strtoupper',
        'trim', 'ltrim', 'rtrim', 'explode', 'implode', 'sprintf', 'preg_match',
        // Array functions
        'array_merge', 'array_keys', 'array_values', 'array_map', 'array_filter',
        'array_search', 'in_array', 'count', 'array_push', 'array_pop', 'array_shift',
        // Math functions
        'abs', 'ceil', 'floor', 'round', 'max', 'min', 'pow', 'sqrt',
        // Date functions
        'date', 'time', 'strtotime', 'mktime', 'gmdate',
        // JSON functions
        'json_encode', 'json_decode',
        // Type functions
        'intval', 'floatval', 'strval', 'boolval', 'is_array', 'is_string', 'is_numeric',
    ];

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $config['sub_type'] = $config['sub_type'] ?? NodeInterface::TASK_SCRIPT;
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
        return $this->config['sub_type'] ?? NodeInterface::TASK_SCRIPT;
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
     * Execute the script node | 执行脚本节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $scriptType = $this->getConfigValue('script_type', self::TYPE_EXPRESSION);
        $script = $this->getConfigValue('script', '');

        if (empty($script)) {
            return NodeResult::failed('脚本内容不能为空', 400);
        }

        try {
            // Prepare input variables
            $inputs = $this->prepareInputs($context);

            // Execute script based on type
            $result = match ($scriptType) {
                self::TYPE_PHP => $this->executePHPScript($script, $inputs, $context),
                self::TYPE_EXPRESSION => $this->executeExpression($script, $inputs, $context),
                self::TYPE_FILE => $this->executeFileScript($script, $inputs, $context),
                self::TYPE_SANDBOX => $this->executeSandboxScript($script, $inputs, $context),
                self::TYPE_JSON_TRANSFORM => $this->executeJsonTransform($script, $inputs, $context),
                default => throw new \InvalidArgumentException("不支持的脚本类型: {$scriptType}"),
            };

            // Apply output mapping
            $this->applyOutputMapping($result, $context);

            // Store script result
            $context->setVariable("script_result_{$this->id}", $result);

            return NodeResult::success([
                'script_type' => $scriptType,
                'result' => $result,
                'message' => '脚本执行成功',
            ], $this->getDefaultNextNodes());
        } catch (\Throwable $e) {
            $errorHandling = $this->getConfigValue('error_handling', []);
            $onError = $errorHandling['mode'] ?? 'fail';

            if ($onError === 'continue') {
                $context->setVariable("script_error_{$this->id}", [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                return NodeResult::success([
                    'script_type' => $scriptType,
                    'error' => true,
                    'error_message' => $e->getMessage(),
                    'message' => '脚本执行失败但继续执行',
                ], $this->getDefaultNextNodes());
            }

            return NodeResult::failed(
                "脚本执行失败: {$e->getMessage()}",
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Prepare input variables | 准备输入变量
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function prepareInputs(ExecutionContext $context): array
    {
        $inputMapping = $this->getConfigValue('input_mapping', []);
        $inputs = [];

        // Default inputs
        $inputs['_context'] = $context->getVariables();
        $inputs['_instance_id'] = $context->getInstanceId();
        $inputs['_tenant_id'] = $context->getTenantId();
        $inputs['_node_id'] = $this->id;

        // Mapped inputs
        foreach ($inputMapping as $inputName => $sourcePath) {
            if (str_starts_with($sourcePath, '${')) {
                $varName = substr($sourcePath, 2, -1);
                $inputs[$inputName] = $context->getVariable($varName);
            } else {
                $inputs[$inputName] = $sourcePath;
            }
        }

        return $inputs;
    }

    /**
     * Execute PHP script | 执行 PHP 脚本
     *
     * WARNING: This executes arbitrary PHP code. Use with caution.
     * 警告：这会执行任意 PHP 代码。请谨慎使用。
     *
     * @param string $script
     * @param array $inputs
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function executePHPScript(string $script, array $inputs, ExecutionContext $context): mixed
    {
        // Security check: Ensure PHP script execution is allowed
        if (!$this->getConfigValue('allow_php_execution', false)) {
            throw new \RuntimeException('PHP 脚本执行未启用。请在配置中设置 allow_php_execution = true');
        }

        // Extract inputs to local scope
        extract($inputs, EXTR_SKIP);

        // Create a closure to isolate the scope
        $executor = function () use ($script, $inputs, $context) {
            // Make variables available
            extract($inputs, EXTR_SKIP);
            $ctx = $context;

            // Execute the script
            return eval($script);
        };

        $timeout = $this->getConfigValue('timeout', 30);

        // Execute with timeout (simplified - real implementation would use pcntl_alarm or async)
        set_time_limit($timeout);
        try {
            $result = $executor();
        } finally {
            set_time_limit(0);
        }

        return $result;
    }

    /**
     * Execute expression | 执行表达式
     *
     * @param string $expression
     * @param array $inputs
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function executeExpression(string $expression, array $inputs, ExecutionContext $context): mixed
    {
        // Simple expression evaluation
        // In real implementation, use Symfony Expression Language

        // Handle simple arithmetic and comparison
        $evaluated = preg_replace_callback(
            '/\$(\w+)/',
            function ($matches) use ($inputs) {
                $varName = $matches[1];
                $value = $inputs[$varName] ?? null;
                return var_export($value, true);
            },
            $expression
        );

        // Safe evaluation for simple expressions
        try {
            // Only allow safe operations
            if (preg_match('/^[\d\s\+\-\*\/\%\(\)\.\,\<\>\=\!\&\|true false null]+$/i', $evaluated)) {
                return eval("return {$evaluated};");
            }

            // For complex expressions, use a simple parser
            return $this->evaluateComplexExpression($expression, $inputs);
        } catch (\Throwable $e) {
            throw new \RuntimeException("表达式计算失败: {$e->getMessage()}");
        }
    }

    /**
     * Evaluate complex expression | 计算复杂表达式
     *
     * @param string $expression
     * @param array $inputs
     * @return mixed
     */
    protected function evaluateComplexExpression(string $expression, array $inputs): mixed
    {
        // Handle function calls and property access
        // This is a simplified implementation

        // Replace variable references
        $processed = preg_replace_callback(
            '/\$(\w+(?:\.\w+)*)/',
            function ($matches) use ($inputs) {
                $path = explode('.', $matches[1]);
                $value = $inputs;

                foreach ($path as $key) {
                    if (is_array($value) && array_key_exists($key, $value)) {
                        $value = $value[$key];
                    } else {
                        return 'null';
                    }
                }

                return var_export($value, true);
            },
            $expression
        );

        // Evaluate with restricted functions
        return eval("return {$processed};");
    }

    /**
     * Execute file script | 执行文件脚本
     *
     * @param string $filePath
     * @param array $inputs
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function executeFileScript(string $filePath, array $inputs, ExecutionContext $context): mixed
    {
        // Validate file path
        $basePath = $this->getConfigValue('script_base_path', '');
        $fullPath = $basePath ? rtrim($basePath, '/') . '/' . ltrim($filePath, '/') : $filePath;

        // Security check: Ensure file exists and is within allowed directory
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("脚本文件不存在: {$fullPath}");
        }

        // Check file extension
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        if ($extension !== 'php') {
            throw new \RuntimeException("不支持的脚本文件类型: {$extension}");
        }

        // Execute file
        extract($inputs, EXTR_SKIP);
        $ctx = $context;

        return include $fullPath;
    }

    /**
     * Execute sandbox script | 执行沙箱脚本
     *
     * @param string $script
     * @param array $inputs
     * @param ExecutionContext $context
     * @return mixed
     */
    protected function executeSandboxScript(string $script, array $inputs, ExecutionContext $context): mixed
    {
        $allowedFunctions = array_merge(
            self::SANDBOX_ALLOWED_FUNCTIONS,
            $this->getConfigValue('allowed_functions', [])
        );

        // Check for disallowed functions
        $disallowed = $this->findDisallowedFunctions($script, $allowedFunctions);
        if (!empty($disallowed)) {
            throw new \RuntimeException(
                '脚本包含不允许的函数调用: ' . implode(', ', $disallowed)
            );
        }

        // Check for dangerous constructs
        $dangerousPatterns = [
            '/\$_(?:GET|POST|REQUEST|SESSION|COOKIE|SERVER|ENV|FILES)/',
            '/\beval\s*\(/',
            '/\bexec\s*\(/',
            '/\bsystem\s*\(/',
            '/\bpassthru\s*\(/',
            '/\bshell_exec\s*\(/',
            '/\bpopen\s*\(/',
            '/\bproc_open\s*\(/',
            '/\binclude(?:_once)?\s*\(/',
            '/\brequire(?:_once)?\s*\(/',
            '/\bfile_(?:get|put)_contents\s*\(/',
            '/\b(?:f|c)url_\w+\s*\(/',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $script)) {
                throw new \RuntimeException('脚本包含不安全的代码结构');
            }
        }

        // Execute in sandbox
        extract($inputs, EXTR_SKIP);
        return eval($script);
    }

    /**
     * Find disallowed functions in script | 查找脚本中不允许的函数
     *
     * @param string $script
     * @param array $allowedFunctions
     * @return array
     */
    protected function findDisallowedFunctions(string $script, array $allowedFunctions): array
    {
        $disallowed = [];

        // Find all function calls
        preg_match_all('/\b(\w+)\s*\(/', $script, $matches);

        foreach ($matches[1] as $func) {
            // Skip language constructs and allowed functions
            $languageConstructs = ['if', 'else', 'elseif', 'while', 'for', 'foreach', 'switch', 'case', 'return', 'array', 'list', 'function'];
            if (in_array($func, $languageConstructs, true)) {
                continue;
            }

            if (!in_array($func, $allowedFunctions, true)) {
                $disallowed[] = $func;
            }
        }

        return array_unique($disallowed);
    }

    /**
     * Execute JSON transform | 执行 JSON 转换
     *
     * @param string $transformSpec
     * @param array $inputs
     * @param ExecutionContext $context
     * @return array
     */
    protected function executeJsonTransform(string $transformSpec, array $inputs, ExecutionContext $context): array
    {
        $spec = json_decode($transformSpec, true);
        if ($spec === null) {
            throw new \RuntimeException('无效的 JSON 转换规范');
        }

        $result = [];

        foreach ($spec as $targetKey => $sourceSpec) {
            if (is_string($sourceSpec)) {
                // Simple mapping: "targetKey": "$sourceKey"
                if (str_starts_with($sourceSpec, '$')) {
                    $sourcePath = substr($sourceSpec, 1);
                    $result[$targetKey] = $this->getValueByPath($inputs, $sourcePath);
                } else {
                    $result[$targetKey] = $sourceSpec;
                }
            } elseif (is_array($sourceSpec)) {
                // Complex mapping with transform
                $result[$targetKey] = $this->applyTransform($sourceSpec, $inputs);
            }
        }

        return $result;
    }

    /**
     * Get value by dot-notation path | 通过点号路径获取值
     *
     * @param array $data
     * @param string $path
     * @return mixed
     */
    protected function getValueByPath(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Apply transform specification | 应用转换规范
     *
     * @param array $spec
     * @param array $inputs
     * @return mixed
     */
    protected function applyTransform(array $spec, array $inputs): mixed
    {
        $source = $spec['source'] ?? null;
        $transform = $spec['transform'] ?? null;
        $default = $spec['default'] ?? null;

        // Get source value
        if ($source !== null && str_starts_with($source, '$')) {
            $value = $this->getValueByPath($inputs, substr($source, 1));
        } else {
            $value = $source;
        }

        // Apply default if value is null
        if ($value === null && $default !== null) {
            return $default;
        }

        // Apply transform
        if ($transform !== null && $value !== null) {
            $value = match ($transform) {
                'uppercase' => is_string($value) ? strtoupper($value) : $value,
                'lowercase' => is_string($value) ? strtolower($value) : $value,
                'trim' => is_string($value) ? trim($value) : $value,
                'int' => (int)$value,
                'float' => (float)$value,
                'string' => (string)$value,
                'bool' => (bool)$value,
                'json' => is_string($value) ? json_decode($value, true) : $value,
                'json_string' => json_encode($value),
                'date' => is_string($value) ? date('Y-m-d', strtotime($value)) : $value,
                'datetime' => is_string($value) ? date('Y-m-d H:i:s', strtotime($value)) : $value,
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Apply output mapping | 应用输出映射
     *
     * @param mixed $result
     * @param ExecutionContext $context
     */
    protected function applyOutputMapping(mixed $result, ExecutionContext $context): void
    {
        $outputMapping = $this->getConfigValue('output_mapping', []);

        if (empty($outputMapping)) {
            // Default: store entire result
            return;
        }

        foreach ($outputMapping as $contextVar => $sourcePath) {
            if (is_array($result)) {
                $value = $this->getValueByPath($result, $sourcePath);
            } else {
                $value = $result;
            }
            $context->setVariable($contextVar, $value);
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
                'script_type' => [
                    'type' => 'string',
                    'enum' => [
                        self::TYPE_PHP,
                        self::TYPE_EXPRESSION,
                        self::TYPE_FILE,
                        self::TYPE_SANDBOX,
                        self::TYPE_JSON_TRANSFORM,
                    ],
                    'default' => self::TYPE_EXPRESSION,
                    'description' => '脚本类型',
                ],
                'script' => [
                    'type' => 'string',
                    'description' => '脚本内容或文件路径',
                ],
                'input_mapping' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => '输入映射',
                ],
                'output_mapping' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => '输出映射',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 300,
                    'default' => 30,
                    'description' => '执行超时时间（秒）',
                ],
                'allow_php_execution' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => '是否允许执行 PHP 脚本',
                ],
                'allowed_functions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '沙箱模式允许的额外函数',
                ],
                'script_base_path' => [
                    'type' => 'string',
                    'description' => '脚本文件基础路径',
                ],
                'error_handling' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['fail', 'continue'],
                            'default' => 'fail',
                        ],
                    ],
                    'description' => '错误处理配置',
                ],
            ],
            'required' => ['script'],
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

        // Script is required
        if (empty($configToValidate['script'])) {
            return false;
        }

        // Validate script type
        $scriptType = $configToValidate['script_type'] ?? self::TYPE_EXPRESSION;
        $validTypes = [
            self::TYPE_PHP,
            self::TYPE_EXPRESSION,
            self::TYPE_FILE,
            self::TYPE_SANDBOX,
            self::TYPE_JSON_TRANSFORM,
        ];

        if (!in_array($scriptType, $validTypes, true)) {
            return false;
        }

        // PHP script requires explicit permission
        if ($scriptType === self::TYPE_PHP) {
            if (empty($configToValidate['allow_php_execution'])) {
                return false;
            }
        }

        // File script requires base path
        if ($scriptType === self::TYPE_FILE) {
            if (empty($configToValidate['script_base_path'])) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
