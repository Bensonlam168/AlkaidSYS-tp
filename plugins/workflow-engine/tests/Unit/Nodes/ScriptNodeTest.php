<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use Alkaid\Workflow\Engine\Nodes\Types\ScriptNode;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * ScriptNode 单元测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Nodes
 */
class ScriptNodeTest extends TestCase
{
    protected ScriptNode $node;
    protected ExecutionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = new ScriptNode('script_001', [
            'name' => '脚本节点',
            'script_type' => ScriptNode::TYPE_EXPRESSION,
            'script' => '1 + 1',
        ]);

        $this->context = new ExecutionContext();
        $this->context->setInstanceId('inst_script_001');
        $this->context->setTenantId(100);
    }

    /**
     * 测试节点创建
     */
    public function testNodeCreation(): void
    {
        $this->assertEquals('script_001', $this->node->getId());
        $this->assertEquals(NodeInterface::TYPE_TASK, $this->node->getType());
        $this->assertEquals(NodeInterface::TASK_SCRIPT, $this->node->getSubType());
    }

    /**
     * 测试简单表达式执行
     */
    public function testSimpleExpressionExecution(): void
    {
        $result = $this->node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(2, $result->getData()['result']);
    }

    /**
     * 测试带变量的表达式
     */
    public function testExpressionWithVariables(): void
    {
        $this->context->setVariable('price', 100);
        $this->context->setVariable('quantity', 5);

        $node = new ScriptNode('expr_vars', [
            'script_type' => ScriptNode::TYPE_EXPRESSION,
            'script' => '$price * $quantity',
            'input_mapping' => [
                'price' => '${price}',
                'quantity' => '${quantity}',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(500, $result->getData()['result']);
    }

    /**
     * 测试 JSON 转换
     */
    public function testJsonTransform(): void
    {
        $this->context->setVariable('user', [
            'name' => 'John',
            'age' => 30,
            'email' => 'john@example.com',
        ]);

        $node = new ScriptNode('json_transform', [
            'script_type' => ScriptNode::TYPE_JSON_TRANSFORM,
            'script' => json_encode([
                'username' => '$_context.user.name',
                'userAge' => [
                    'source' => '$_context.user.age',
                    'transform' => 'string',
                ],
                'constant' => 'fixed_value',
            ]),
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertIsArray($result->getData()['result']);
        $this->assertEquals('John', $result->getData()['result']['username']);
        $this->assertEquals('30', $result->getData()['result']['userAge']);
        $this->assertEquals('fixed_value', $result->getData()['result']['constant']);
    }

    /**
     * 测试沙箱模式 - 允许的函数
     */
    public function testSandboxAllowedFunctions(): void
    {
        $node = new ScriptNode('sandbox_allowed', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return strtoupper("hello");',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('HELLO', $result->getData()['result']);
    }

    /**
     * 测试沙箱模式 - 禁止的函数
     */
    public function testSandboxDisallowedFunctions(): void
    {
        $node = new ScriptNode('sandbox_disallowed', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return file_get_contents("/etc/passwd");',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试沙箱模式 - 危险代码
     */
    public function testSandboxDangerousCode(): void
    {
        $node = new ScriptNode('sandbox_dangerous', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return eval("1+1");',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试 PHP 脚本执行 - 未授权
     */
    public function testPhpScriptUnauthorized(): void
    {
        $node = new ScriptNode('php_unauthorized', [
            'script_type' => ScriptNode::TYPE_PHP,
            'script' => 'return 1 + 1;',
            'allow_php_execution' => false,
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试输入映射
     */
    public function testInputMapping(): void
    {
        $this->context->setVariable('order_total', 1500);
        $this->context->setVariable('discount_rate', 0.1);

        $node = new ScriptNode('input_mapping', [
            'script_type' => ScriptNode::TYPE_EXPRESSION,
            'script' => '$total * (1 - $discount)',
            'input_mapping' => [
                'total' => '${order_total}',
                'discount' => '${discount_rate}',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals(1350, $result->getData()['result']);
    }

    /**
     * 测试输出映射
     */
    public function testOutputMapping(): void
    {
        $node = new ScriptNode('output_mapping', [
            'script_type' => ScriptNode::TYPE_JSON_TRANSFORM,
            'script' => json_encode([
                'calculated_value' => '100',
                'status' => 'completed',
            ]),
            'output_mapping' => [
                'result_value' => 'calculated_value',
                'result_status' => 'status',
            ],
        ]);

        $node->execute($this->context);

        $this->assertEquals('100', $this->context->getVariable('result_value'));
        $this->assertEquals('completed', $this->context->getVariable('result_status'));
    }

    /**
     * 测试空脚本
     */
    public function testEmptyScript(): void
    {
        $node = new ScriptNode('empty_script', [
            'script_type' => ScriptNode::TYPE_EXPRESSION,
            'script' => '',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试错误处理 - 继续模式
     */
    public function testErrorHandlingContinue(): void
    {
        $node = new ScriptNode('error_continue', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return undefined_function();',
            'error_handling' => [
                'mode' => 'continue',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertTrue($result->getData()['error']);
    }

    /**
     * 测试错误处理 - 失败模式
     */
    public function testErrorHandlingFail(): void
    {
        $node = new ScriptNode('error_fail', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return undefined_function();',
            'error_handling' => [
                'mode' => 'fail',
            ],
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_FAILED, $result->getStatus());
    }

    /**
     * 测试配置验证 - 有效配置
     */
    public function testValidConfigValidation(): void
    {
        $validConfig = [
            'script_type' => ScriptNode::TYPE_EXPRESSION,
            'script' => '1 + 1',
        ];
        $this->assertTrue($this->node->validateConfig($validConfig));
    }

    /**
     * 测试配置验证 - 缺少脚本
     */
    public function testInvalidConfigMissingScript(): void
    {
        $invalidConfig = [
            'script_type' => ScriptNode::TYPE_EXPRESSION,
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - PHP 脚本需要授权
     */
    public function testInvalidConfigPhpWithoutAuth(): void
    {
        $invalidConfig = [
            'script_type' => ScriptNode::TYPE_PHP,
            'script' => 'return 1;',
            'allow_php_execution' => false,
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试配置验证 - 文件脚本需要基础路径
     */
    public function testInvalidConfigFileWithoutBasePath(): void
    {
        $invalidConfig = [
            'script_type' => ScriptNode::TYPE_FILE,
            'script' => 'script.php',
        ];
        $this->assertFalse($this->node->validateConfig($invalidConfig));
    }

    /**
     * 测试获取配置 Schema
     */
    public function testGetConfigSchema(): void
    {
        $schema = $this->node->getConfigSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('script_type', $schema['properties']);
        $this->assertArrayHasKey('script', $schema['properties']);
        $this->assertArrayHasKey('input_mapping', $schema['properties']);
        $this->assertArrayHasKey('output_mapping', $schema['properties']);
    }

    /**
     * 测试数学表达式
     */
    public function testMathExpressions(): void
    {
        $testCases = [
            ['script' => '10 + 5', 'expected' => 15],
            ['script' => '10 - 5', 'expected' => 5],
            ['script' => '10 * 5', 'expected' => 50],
            ['script' => '10 / 5', 'expected' => 2],
            ['script' => '10 % 3', 'expected' => 1],
        ];

        foreach ($testCases as $case) {
            $node = new ScriptNode(null, [
                'script_type' => ScriptNode::TYPE_EXPRESSION,
                'script' => $case['script'],
            ]);

            $result = $node->execute($this->context);
            $this->assertEquals($case['expected'], $result->getData()['result'], "Failed for: {$case['script']}");
        }
    }

    /**
     * 测试比较表达式
     */
    public function testComparisonExpressions(): void
    {
        $testCases = [
            ['script' => '10 > 5', 'expected' => true],
            ['script' => '10 < 5', 'expected' => false],
            ['script' => '10 == 10', 'expected' => true],
            ['script' => '10 != 5', 'expected' => true],
        ];

        foreach ($testCases as $case) {
            $node = new ScriptNode(null, [
                'script_type' => ScriptNode::TYPE_EXPRESSION,
                'script' => $case['script'],
            ]);

            $result = $node->execute($this->context);
            $this->assertEquals($case['expected'], $result->getData()['result'], "Failed for: {$case['script']}");
        }
    }

    /**
     * 测试结果存储在上下文中
     */
    public function testResultStoredInContext(): void
    {
        $this->node->execute($this->context);

        $result = $this->context->getVariable('script_result_script_001');
        $this->assertEquals(2, $result);
    }

    /**
     * 测试默认输入变量
     */
    public function testDefaultInputVariables(): void
    {
        $node = new ScriptNode('default_inputs', [
            'script_type' => ScriptNode::TYPE_SANDBOX,
            'script' => 'return $_node_id;',
        ]);

        $result = $node->execute($this->context);

        $this->assertEquals(NodeResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertEquals('default_inputs', $result->getData()['result']);
    }
}
