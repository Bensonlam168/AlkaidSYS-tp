<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Expression;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Alkaid\Workflow\Engine\Expression\ExpressionEngine;
use Alkaid\Workflow\Engine\Expression\Context\ExpressionContext;
use Alkaid\Workflow\Engine\Expression\Cache\ExpressionCache;

/**
 * ExpressionEngine Unit Tests
 */
class ExpressionEngineTest extends TestCase
{
    private ExpressionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ExpressionEngine();
    }

    #[Test]
    public function testConstructorRegistersDefaultProviders(): void
    {
        $providers = $this->engine->getRegisteredProviders();

        $this->assertArrayHasKey('date', $providers);
        $this->assertArrayHasKey('math', $providers);
        $this->assertArrayHasKey('string', $providers);
        $this->assertArrayHasKey('array', $providers);
        $this->assertArrayHasKey('workflow', $providers);
    }

    #[Test]
    public function testConstructorWithoutDefaultProviders(): void
    {
        $engine = new ExpressionEngine(1000, false);
        $providers = $engine->getRegisteredProviders();

        $this->assertEmpty($providers);
    }

    #[Test]
    public function testEvaluateSimpleExpression(): void
    {
        $result = $this->engine->evaluate('1 + 2');
        $this->assertEquals(3, $result);
    }

    #[Test]
    public function testEvaluateWithVariables(): void
    {
        $context = ['x' => 10, 'y' => 5];
        $result = $this->engine->evaluate('x + y', $context);
        $this->assertEquals(15, $result);
    }

    #[Test]
    public function testEvaluateWithNestedVariables(): void
    {
        $context = [
            'user' => [
                'name' => 'Alice',
                'age' => 30,
            ],
        ];
        $result = $this->engine->evaluate('user["name"]', $context);
        $this->assertEquals('Alice', $result);
    }

    #[Test]
    public function testEvaluateEmptyExpression(): void
    {
        $result = $this->engine->evaluate('');
        $this->assertNull($result);

        $result = $this->engine->evaluate('   ');
        $this->assertNull($result);
    }

    #[Test]
    public function testEvaluateConditionTrue(): void
    {
        $result = $this->engine->evaluateCondition('5 > 3');
        $this->assertTrue($result);
    }

    #[Test]
    public function testEvaluateConditionFalse(): void
    {
        $result = $this->engine->evaluateCondition('3 > 5');
        $this->assertFalse($result);
    }

    #[Test]
    public function testEvaluateConditionWithVariables(): void
    {
        $context = ['amount' => 1000, 'threshold' => 500];
        $result = $this->engine->evaluateCondition('amount > threshold', $context);
        $this->assertTrue($result);
    }

    #[Test]
    public function testValidateValidExpression(): void
    {
        $this->assertTrue($this->engine->validate('1 + 2', []));
        $this->assertTrue($this->engine->validate('x > 0', ['x']));
    }

    #[Test]
    public function testValidateInvalidExpression(): void
    {
        $this->assertFalse($this->engine->validate('1 +'));
        $this->assertFalse($this->engine->validate(''));
    }

    #[Test]
    public function testCompileExpression(): void
    {
        // Compile a simple expression without variables
        $compiled = $this->engine->compile('1 + 2');
        $this->assertNotNull($compiled);
        $this->assertIsString($compiled);
    }

    #[Test]
    public function testCompileEmptyExpression(): void
    {
        $compiled = $this->engine->compile('');
        $this->assertNull($compiled);
    }

    #[Test]
    public function testExtractVariables(): void
    {
        $variables = $this->engine->extractVariables('amount > 100 and status == "approved"');

        $this->assertContains('amount', $variables);
        $this->assertContains('status', $variables);
        $this->assertNotContains('and', $variables);
    }

    #[Test]
    public function testRegisterFunction(): void
    {
        $this->engine->registerFunction('double', fn($x) => $x * 2);

        $this->assertTrue($this->engine->hasFunction('double'));

        $result = $this->engine->evaluate('double(5)');
        $this->assertEquals(10, $result);
    }

    #[Test]
    public function testRegisterFunctionWithCustomCompiler(): void
    {
        $this->engine->registerFunction(
            'triple',
            fn($x) => $x * 3,
            fn($x) => "({$x}) * 3"
        );

        $result = $this->engine->evaluate('triple(4)');
        $this->assertEquals(12, $result);
    }

    #[Test]
    public function testGetRegisteredFunctions(): void
    {
        $functions = $this->engine->getRegisteredFunctions();

        $this->assertIsArray($functions);
        $this->assertNotEmpty($functions);
        $this->assertContains('now', $functions);
        $this->assertContains('abs', $functions);
        $this->assertContains('strlen', $functions);
    }

    #[Test]
    public function testClearCache(): void
    {
        // Evaluate to populate cache
        $this->engine->evaluate('1 + 1');

        $statsBefore = $this->engine->getCacheStats();
        $this->engine->clearCache();
        $statsAfter = $this->engine->getCacheStats();

        $this->assertEquals(0, $statsAfter['size']);
    }

    #[Test]
    public function testGetCacheStats(): void
    {
        $this->engine->evaluate('1 + 1');

        $stats = $this->engine->getCacheStats();

        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('size', $stats);
    }

    #[Test]
    public function testStrictModeThrowsOnError(): void
    {
        $this->engine->setStrictMode(true);

        $this->expectException(\RuntimeException::class);
        $this->engine->evaluate('invalid_var');
    }

    #[Test]
    public function testNonStrictModeReturnsNullOnError(): void
    {
        $this->engine->setStrictMode(false);

        $result = $this->engine->evaluate('invalid_var');
        $this->assertNull($result);
    }

    #[Test]
    public function testCacheEnabled(): void
    {
        $this->assertTrue($this->engine->isCacheEnabled());

        $this->engine->setCacheEnabled(false);
        $this->assertFalse($this->engine->isCacheEnabled());
    }

    #[Test]
    public function testGetValidationError(): void
    {
        $error = $this->engine->getValidationError('1 +');
        $this->assertNotNull($error);

        $error = $this->engine->getValidationError('1 + 1');
        $this->assertNull($error);
    }

    #[Test]
    public function testEvaluateMultiple(): void
    {
        $expressions = [
            'sum' => '1 + 2',
            'product' => '3 * 4',
            'comparison' => '5 > 3',
        ];

        $results = $this->engine->evaluateMultiple($expressions);

        $this->assertEquals(3, $results['sum']);
        $this->assertEquals(12, $results['product']);
        $this->assertTrue($results['comparison']);
    }

    #[Test]
    public function testEvaluateWithFallback(): void
    {
        $result = $this->engine->evaluateWithFallback('invalid', [], 'default');
        $this->assertEquals('default', $result);

        $result = $this->engine->evaluateWithFallback('1 + 1', [], 'default');
        $this->assertEquals(2, $result);
    }

    #[Test]
    public function testGetFunctionDocumentation(): void
    {
        $docs = $this->engine->getFunctionDocumentation();

        $this->assertArrayHasKey('date', $docs);
        $this->assertArrayHasKey('math', $docs);
        $this->assertArrayHasKey('string', $docs);

        $this->assertEquals('日期和时间处理函数', $docs['date']['description']);
    }

    #[Test]
    public function testTestExpression(): void
    {
        $result = $this->engine->testExpression('1 + 1');
        $this->assertTrue($result['valid']);
        $this->assertEquals(2, $result['result']);
        $this->assertNull($result['error']);

        // Test with undefined variable to trigger error
        $this->engine->setStrictMode(true);
        $result = $this->engine->testExpression('undefined_variable');
        $this->assertFalse($result['valid']);
        $this->assertNull($result['result']);
        $this->assertNotNull($result['error']);
    }

    #[Test]
    public function testCreateSandbox(): void
    {
        $sandbox = $this->engine->createSandbox(['abs', 'round']);

        $this->assertTrue($sandbox->hasFunction('abs'));
        $this->assertTrue($sandbox->hasFunction('round'));
        $this->assertFalse($sandbox->hasFunction('now'));
    }

    #[Test]
    public function testEvaluateWithContext(): void
    {
        $context = new ExpressionContext();
        $context->setWorkflowVariable('amount', 100);
        $context->setProcessVariable('status', 'active');

        $result = $this->engine->evaluateWithContext(
            'workflow["amount"] * 2',
            $context
        );

        $this->assertEquals(200, $result);
    }

    // ============================================
    // Date Functions Tests
    // ============================================

    #[Test]
    public function testNowFunction(): void
    {
        $result = $this->engine->evaluate('now()');
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result);
    }

    #[Test]
    public function testTodayFunction(): void
    {
        $result = $this->engine->evaluate('today()');
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $result);
        $this->assertEquals(date('Y-m-d'), $result);
    }

    #[Test]
    public function testDateDiffFunction(): void
    {
        $result = $this->engine->evaluate('dateDiff("2024-01-01", "2024-01-10")');
        $this->assertEquals(9, $result);
    }

    #[Test]
    public function testDateAddFunction(): void
    {
        $result = $this->engine->evaluate('dateAdd("2024-01-01", "P1D")');
        $this->assertStringContains('2024-01-02', $result);
    }

    #[Test]
    public function testIsWeekdayFunction(): void
    {
        // Monday
        $result = $this->engine->evaluate('isWeekday("2024-12-16")');
        $this->assertTrue($result);

        // Sunday
        $result = $this->engine->evaluate('isWeekday("2024-12-15")');
        $this->assertFalse($result);
    }

    #[Test]
    public function testAgeFunction(): void
    {
        $birthdate = date('Y-m-d', strtotime('-25 years'));
        $result = $this->engine->evaluate("age(\"{$birthdate}\")");
        $this->assertEquals(25, $result);
    }

    // ============================================
    // Math Functions Tests
    // ============================================

    #[Test]
    public function testAbsFunction(): void
    {
        $result = $this->engine->evaluate('abs(-5)');
        $this->assertEquals(5, $result);
    }

    #[Test]
    public function testRoundFunction(): void
    {
        $result = $this->engine->evaluate('round(3.14159, 2)');
        $this->assertEquals(3.14, $result);
    }

    #[Test]
    public function testCeilFunction(): void
    {
        $result = $this->engine->evaluate('ceil(3.2)');
        $this->assertEquals(4.0, $result);
    }

    #[Test]
    public function testFloorFunction(): void
    {
        $result = $this->engine->evaluate('floor(3.9)');
        $this->assertEquals(3.0, $result);
    }

    #[Test]
    public function testSumFunction(): void
    {
        $result = $this->engine->evaluate('sum(values)', ['values' => [1, 2, 3, 4, 5]]);
        $this->assertEquals(15, $result);
    }

    #[Test]
    public function testAvgFunction(): void
    {
        $result = $this->engine->evaluate('avg(values)', ['values' => [10, 20, 30]]);
        $this->assertEquals(20.0, $result);
    }

    #[Test]
    public function testMinMaxFunctions(): void
    {
        $context = ['values' => [5, 2, 8, 1, 9]];
        $this->assertEquals(1, $this->engine->evaluate('min(values)', $context));
        $this->assertEquals(9, $this->engine->evaluate('max(values)', $context));
    }

    #[Test]
    public function testClampFunction(): void
    {
        $this->assertEquals(5, $this->engine->evaluate('clamp(3, 5, 10)'));
        $this->assertEquals(7, $this->engine->evaluate('clamp(7, 5, 10)'));
        $this->assertEquals(10, $this->engine->evaluate('clamp(15, 5, 10)'));
    }

    #[Test]
    public function testPercentageFunction(): void
    {
        $result = $this->engine->evaluate('percentage(25, 100)');
        $this->assertEquals(25.0, $result);
    }

    #[Test]
    public function testMoneyFunction(): void
    {
        $result = $this->engine->evaluate('money(1234.56)');
        $this->assertEquals('¥1,234.56', $result);

        $result = $this->engine->evaluate('money(1234.56, "USD")');
        $this->assertEquals('$1,234.56', $result);
    }

    // ============================================
    // String Functions Tests
    // ============================================

    #[Test]
    public function testStrlenFunction(): void
    {
        $result = $this->engine->evaluate('strlen("hello")');
        $this->assertEquals(5, $result);

        // Test multi-byte
        $result = $this->engine->evaluate('strlen("你好")');
        $this->assertEquals(2, $result);
    }

    #[Test]
    public function testUpperLowerFunctions(): void
    {
        $this->assertEquals('HELLO', $this->engine->evaluate('upper("hello")'));
        $this->assertEquals('hello', $this->engine->evaluate('lower("HELLO")'));
    }

    #[Test]
    public function testContainsFunction(): void
    {
        $this->assertTrue($this->engine->evaluate('strContains("hello world", "world")'));
        $this->assertFalse($this->engine->evaluate('strContains("hello world", "foo")'));
    }

    #[Test]
    public function testStartsWithEndsWithFunctions(): void
    {
        $this->assertTrue($this->engine->evaluate('startsWith("hello world", "hello")'));
        $this->assertFalse($this->engine->evaluate('startsWith("hello world", "world")'));

        $this->assertTrue($this->engine->evaluate('endsWith("hello world", "world")'));
        $this->assertFalse($this->engine->evaluate('endsWith("hello world", "hello")'));
    }

    #[Test]
    public function testReplaceFunction(): void
    {
        $result = $this->engine->evaluate('replace("world", "PHP", "hello world")');
        $this->assertEquals('hello PHP', $result);
    }

    #[Test]
    public function testConcatFunction(): void
    {
        $result = $this->engine->evaluate('concat("hello", " ", "world")');
        $this->assertEquals('hello world', $result);
    }

    #[Test]
    public function testTrimFunction(): void
    {
        $result = $this->engine->evaluate('trim("  hello  ")');
        $this->assertEquals('hello', $result);
    }

    #[Test]
    public function testSlugFunction(): void
    {
        $result = $this->engine->evaluate('slug("Hello World!")');
        $this->assertEquals('hello-world', $result);
    }

    #[Test]
    public function testCamelSnakeKebabFunctions(): void
    {
        $this->assertEquals('helloWorld', $this->engine->evaluate('camel("hello_world")'));
        $this->assertEquals('hello_world', $this->engine->evaluate('snake("helloWorld")'));
        $this->assertEquals('hello-world', $this->engine->evaluate('kebab("helloWorld")'));
    }

    #[Test]
    public function testMaskFunction(): void
    {
        $result = $this->engine->evaluate('mask("1234567890", 3, 4)');
        $this->assertEquals('123****890', $result);
    }

    #[Test]
    public function testTruncateFunction(): void
    {
        $result = $this->engine->evaluate('truncate("hello world", 8)');
        $this->assertEquals('hello...', $result);
    }

    // ============================================
    // Array Functions Tests
    // ============================================

    #[Test]
    public function testCountFunction(): void
    {
        $result = $this->engine->evaluate('count(items)', ['items' => [1, 2, 3, 4, 5]]);
        $this->assertEquals(5, $result);
    }

    #[Test]
    public function testFirstLastFunctions(): void
    {
        $context = ['items' => [1, 2, 3]];
        $this->assertEquals(1, $this->engine->evaluate('first(items)', $context));
        $this->assertEquals(3, $this->engine->evaluate('last(items)', $context));
    }

    #[Test]
    public function testKeysValuesFunctions(): void
    {
        $context = ['data' => ['a' => 1, 'b' => 2]];
        $this->assertEquals(['a', 'b'], $this->engine->evaluate('keys(data)', $context));
        $this->assertEquals([1, 2], $this->engine->evaluate('values(data)', $context));
    }

    #[Test]
    public function testMergeFunction(): void
    {
        $context = ['arr1' => [1, 2], 'arr2' => [3, 4]];
        $result = $this->engine->evaluate('merge(arr1, arr2)', $context);
        $this->assertEquals([1, 2, 3, 4], $result);
    }

    #[Test]
    public function testUniqueFunction(): void
    {
        $result = $this->engine->evaluate('unique(items)', ['items' => [1, 2, 2, 3, 3, 3]]);
        $this->assertEquals([1, 2, 3], $result);
    }

    #[Test]
    public function testPluckFunction(): void
    {
        $context = [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
        ];
        $result = $this->engine->evaluate('pluck(users, "name")', $context);
        $this->assertEquals(['Alice', 'Bob'], $result);
    }

    #[Test]
    public function testGroupByFunction(): void
    {
        $context = [
            'items' => [
                ['type' => 'fruit', 'name' => 'apple'],
                ['type' => 'fruit', 'name' => 'banana'],
                ['type' => 'vegetable', 'name' => 'carrot'],
            ],
        ];
        $result = $this->engine->evaluate('groupBy(items, "type")', $context);

        $this->assertArrayHasKey('fruit', $result);
        $this->assertArrayHasKey('vegetable', $result);
        $this->assertCount(2, $result['fruit']);
        $this->assertCount(1, $result['vegetable']);
    }

    #[Test]
    public function testWhereFunction(): void
    {
        $context = [
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
                ['name' => 'Charlie', 'age' => 35],
            ],
        ];
        $result = $this->engine->evaluate('where(users, "age", ">", 25)', $context);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function testSortByFunction(): void
    {
        $context = [
            'users' => [
                ['name' => 'Charlie', 'age' => 35],
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
        ];
        $result = $this->engine->evaluate('sortBy(users, "age")', $context);
        $this->assertEquals('Bob', $result[0]['name']);
        $this->assertEquals('Charlie', $result[2]['name']);
    }

    #[Test]
    public function testTakeSkipFunctions(): void
    {
        $context = ['items' => [1, 2, 3, 4, 5]];
        $this->assertEquals([1, 2], $this->engine->evaluate('take(items, 2)', $context));
        $this->assertEquals([3, 4, 5], $this->engine->evaluate('skip(items, 2)', $context));
    }

    // ============================================
    // Workflow Functions Tests
    // ============================================

    #[Test]
    public function testVarFunction(): void
    {
        $context = [
            'order' => [
                'amount' => 1000,
                'status' => 'pending',
            ],
        ];
        $result = $this->engine->evaluate('var("order.amount")', $context);
        $this->assertEquals(1000, $result);
    }

    #[Test]
    public function testHasVarFunction(): void
    {
        $context = ['value' => 123];
        $this->assertTrue($this->engine->evaluate('hasVar("value")', $context));
        $this->assertFalse($this->engine->evaluate('hasVar("missing")', $context));
    }

    #[Test]
    public function testWorkflowProcessTaskFunctions(): void
    {
        $context = [
            'workflow' => ['name' => 'test-workflow'],
            'process' => ['id' => 'process-123'],
            'task' => ['assignee' => 'user-1'],
        ];

        $this->assertEquals('test-workflow', $this->engine->evaluate('workflow("name")', $context));
        $this->assertEquals('process-123', $this->engine->evaluate('process("id")', $context));
        $this->assertEquals('user-1', $this->engine->evaluate('task("assignee")', $context));
    }

    #[Test]
    public function testNodeStatusFunctions(): void
    {
        $context = [
            'nodeStatuses' => [
                'node1' => 'completed',
                'node2' => 'failed',
                'node3' => 'pending',
            ],
        ];

        $this->assertEquals('completed', $this->engine->evaluate('nodeStatus("node1")', $context));
        $this->assertTrue($this->engine->evaluate('isCompleted("node1")', $context));
        $this->assertTrue($this->engine->evaluate('isFailed("node2")', $context));
        $this->assertTrue($this->engine->evaluate('isPending("node3")', $context));
    }

    #[Test]
    public function testOutputFunction(): void
    {
        $context = [
            'outputs' => [
                'approval_node' => [
                    'approved' => true,
                    'comment' => 'Looks good',
                ],
            ],
        ];

        $result = $this->engine->evaluate('output("approval_node", "approved")', $context);
        $this->assertTrue($result);

        $result = $this->engine->evaluate('output("approval_node")', $context);
        $this->assertIsArray($result);
    }

    #[Test]
    public function testCurrentUserTenantFunctions(): void
    {
        $context = [
            '_user_id' => 123,
            '_tenant_id' => 456,
        ];

        $this->assertEquals(123, $this->engine->evaluate('currentUser()', $context));
        $this->assertEquals(456, $this->engine->evaluate('currentTenant()', $context));
    }

    #[Test]
    public function testHasRoleFunctions(): void
    {
        $context = [
            '_user_roles' => ['admin', 'manager'],
        ];

        $this->assertTrue($this->engine->evaluate('hasRole("admin")', $context));
        $this->assertFalse($this->engine->evaluate('hasRole("guest")', $context));
        $this->assertTrue($this->engine->evaluate('hasAnyRole(roles)', array_merge($context, ['roles' => ['admin', 'guest']])));
    }

    #[Test]
    public function testBranchFunction(): void
    {
        $this->assertEquals('yes', $this->engine->evaluate('branch(true, "yes", "no")'));
        $this->assertEquals('no', $this->engine->evaluate('branch(false, "yes", "no")'));
    }

    #[Test]
    public function testCoalesceFunction(): void
    {
        $context = ['a' => null, 'b' => null, 'c' => 'value'];
        $result = $this->engine->evaluate('coalesce(a, b, c)', $context);
        $this->assertEquals('value', $result);
    }

    #[Test]
    public function testDefaultFunction(): void
    {
        $context = ['empty' => '', 'filled' => 'value'];
        $this->assertEquals('default', $this->engine->evaluate('default(empty, "default")', $context));
        $this->assertEquals('value', $this->engine->evaluate('default(filled, "default")', $context));
    }

    // ============================================
    // Complex Expression Tests
    // ============================================

    #[Test]
    public function testComplexCondition(): void
    {
        $context = [
            'order' => [
                'amount' => 15000,
                'type' => 'premium',
            ],
            'user' => [
                'level' => 'gold',
            ],
        ];

        $expression = 'order["amount"] > 10000 and (order["type"] == "premium" or user["level"] == "gold")';
        $result = $this->engine->evaluateCondition($expression, $context);
        $this->assertTrue($result);
    }

    #[Test]
    public function testExpressionWithMultipleFunctions(): void
    {
        $context = [
            'items' => [
                ['price' => 100, 'qty' => 2],
                ['price' => 50, 'qty' => 3],
            ],
        ];

        // Get sum of (price * qty) for each item
        $priceQty1 = $this->engine->evaluate('items[0]["price"] * items[0]["qty"]', $context);
        $priceQty2 = $this->engine->evaluate('items[1]["price"] * items[1]["qty"]', $context);

        $this->assertEquals(200, $priceQty1);
        $this->assertEquals(150, $priceQty2);
    }

    #[Test]
    public function testCachePerformance(): void
    {
        $expression = 'x + y';
        $context = ['x' => 1, 'y' => 2];

        // First evaluation (miss)
        $this->engine->evaluate($expression, $context);
        $stats1 = $this->engine->getCacheStats();

        // Second evaluation (hit)
        $this->engine->evaluate($expression, $context);
        $stats2 = $this->engine->getCacheStats();

        $this->assertGreaterThan($stats1['hits'], $stats2['hits']);
    }

    /**
     * Helper method for string contains assertion
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
