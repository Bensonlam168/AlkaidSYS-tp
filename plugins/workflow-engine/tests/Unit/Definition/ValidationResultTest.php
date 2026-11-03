<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Definition\ValidationResult;
use Alkaid\Workflow\Engine\Definition\WorkflowValidator;
use PHPUnit\Framework\TestCase;

/**
 * ValidationResult 单元测试
 */
class ValidationResultTest extends TestCase
{
    /**
     * 测试空结果
     */
    public function testEmptyResult(): void
    {
        $result = new ValidationResult([]);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->hasWarnings());
        $this->assertFalse($result->hasMessages());
        $this->assertEquals(0, $result->getErrorCount());
        $this->assertEquals(0, $result->getWarningCount());
        $this->assertEquals(0, $result->getMessageCount());
    }

    /**
     * 测试有错误的结果
     */
    public function testResultWithErrors(): void
    {
        $messages = [
            [
                'code' => 'ERROR_001',
                'message' => '错误1',
                'level' => WorkflowValidator::LEVEL_ERROR,
            ],
            [
                'code' => 'ERROR_002',
                'message' => '错误2',
                'level' => WorkflowValidator::LEVEL_ERROR,
            ],
        ];

        $result = new ValidationResult($messages);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertEquals(2, $result->getErrorCount());
    }

    /**
     * 测试有警告的结果
     */
    public function testResultWithWarnings(): void
    {
        $messages = [
            [
                'code' => 'WARNING_001',
                'message' => '警告1',
                'level' => WorkflowValidator::LEVEL_WARNING,
            ],
        ];

        $result = new ValidationResult($messages);

        $this->assertTrue($result->isValid()); // 警告不影响有效性
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals(1, $result->getWarningCount());
    }

    /**
     * 测试混合消息
     */
    public function testMixedMessages(): void
    {
        $messages = [
            ['code' => 'ERROR_001', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'WARNING_001', 'message' => '警告', 'level' => WorkflowValidator::LEVEL_WARNING],
            ['code' => 'INFO_001', 'message' => '信息', 'level' => WorkflowValidator::LEVEL_INFO],
        ];

        $result = new ValidationResult($messages);

        $this->assertFalse($result->isValid());
        $this->assertEquals(1, $result->getErrorCount());
        $this->assertEquals(1, $result->getWarningCount());
        $this->assertEquals(3, $result->getMessageCount());

        $this->assertCount(1, $result->getErrors());
        $this->assertCount(1, $result->getWarnings());
        $this->assertCount(1, $result->getInfos());
    }

    /**
     * 测试获取所有消息
     */
    public function testGetMessages(): void
    {
        $messages = [
            ['code' => 'MSG_001', 'message' => '消息1', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'MSG_002', 'message' => '消息2', 'level' => WorkflowValidator::LEVEL_WARNING],
        ];

        $result = new ValidationResult($messages);

        $this->assertEquals($messages, $result->getMessages());
    }

    /**
     * 测试获取错误代码
     */
    public function testGetErrorCodes(): void
    {
        $messages = [
            ['code' => 'ERROR_A', 'message' => '错误A', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'ERROR_B', 'message' => '错误B', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'WARNING_A', 'message' => '警告A', 'level' => WorkflowValidator::LEVEL_WARNING],
        ];

        $result = new ValidationResult($messages);

        $errorCodes = $result->getErrorCodes();
        $this->assertContains('ERROR_A', $errorCodes);
        $this->assertContains('ERROR_B', $errorCodes);
        $this->assertNotContains('WARNING_A', $errorCodes);
    }

    /**
     * 测试检查特定错误代码
     */
    public function testHasErrorCode(): void
    {
        $messages = [
            ['code' => 'MISSING_START', 'message' => '缺少开始节点', 'level' => WorkflowValidator::LEVEL_ERROR],
        ];

        $result = new ValidationResult($messages);

        $this->assertTrue($result->hasErrorCode('MISSING_START'));
        $this->assertFalse($result->hasErrorCode('MISSING_END'));
    }

    /**
     * 测试按规则分组
     */
    public function testGroupByRule(): void
    {
        $messages = [
            ['code' => 'ERR_1', 'message' => '错误1', 'level' => WorkflowValidator::LEVEL_ERROR, 'rule' => 'rule_a'],
            ['code' => 'ERR_2', 'message' => '错误2', 'level' => WorkflowValidator::LEVEL_ERROR, 'rule' => 'rule_a'],
            ['code' => 'ERR_3', 'message' => '错误3', 'level' => WorkflowValidator::LEVEL_ERROR, 'rule' => 'rule_b'],
        ];

        $result = new ValidationResult($messages);
        $grouped = $result->groupByRule();

        $this->assertArrayHasKey('rule_a', $grouped);
        $this->assertArrayHasKey('rule_b', $grouped);
        $this->assertCount(2, $grouped['rule_a']);
        $this->assertCount(1, $grouped['rule_b']);
    }

    /**
     * 测试按级别分组
     */
    public function testGroupByLevel(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'WARN', 'message' => '警告', 'level' => WorkflowValidator::LEVEL_WARNING],
            ['code' => 'INFO', 'message' => '信息', 'level' => WorkflowValidator::LEVEL_INFO],
        ];

        $result = new ValidationResult($messages);
        $grouped = $result->groupByLevel();

        $this->assertCount(1, $grouped[WorkflowValidator::LEVEL_ERROR]);
        $this->assertCount(1, $grouped[WorkflowValidator::LEVEL_WARNING]);
        $this->assertCount(1, $grouped[WorkflowValidator::LEVEL_INFO]);
    }

    /**
     * 测试合并结果
     */
    public function testMerge(): void
    {
        $result1 = new ValidationResult([
            ['code' => 'ERR_1', 'message' => '错误1', 'level' => WorkflowValidator::LEVEL_ERROR],
        ]);

        $result2 = new ValidationResult([
            ['code' => 'ERR_2', 'message' => '错误2', 'level' => WorkflowValidator::LEVEL_ERROR],
        ]);

        $result1->merge($result2);

        $this->assertEquals(2, $result1->getErrorCount());
        $this->assertTrue($result1->hasErrorCode('ERR_1'));
        $this->assertTrue($result1->hasErrorCode('ERR_2'));
    }

    /**
     * 测试过滤消息
     */
    public function testFilter(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'WARN', 'message' => '警告', 'level' => WorkflowValidator::LEVEL_WARNING],
        ];

        $result = new ValidationResult($messages);
        $filtered = $result->filter(fn ($msg) => $msg['level'] === WorkflowValidator::LEVEL_ERROR);

        $this->assertEquals(1, $filtered->getMessageCount());
        $this->assertTrue($filtered->hasErrorCode('ERR'));
        $this->assertFalse($filtered->hasErrorCode('WARN'));
    }

    /**
     * 测试获取摘要 - 验证通过
     */
    public function testGetSummaryValid(): void
    {
        $result = new ValidationResult([]);
        $this->assertEquals('验证通过', $result->getSummary());
    }

    /**
     * 测试获取摘要 - 有错误
     */
    public function testGetSummaryWithErrors(): void
    {
        $messages = [
            ['code' => 'ERR_1', 'message' => '错误1', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'ERR_2', 'message' => '错误2', 'level' => WorkflowValidator::LEVEL_ERROR],
            ['code' => 'WARN', 'message' => '警告', 'level' => WorkflowValidator::LEVEL_WARNING],
        ];

        $result = new ValidationResult($messages);
        $summary = $result->getSummary();

        $this->assertStringContainsString('2 个错误', $summary);
        $this->assertStringContainsString('1 个警告', $summary);
    }

    /**
     * 测试转换为数组
     */
    public function testToArray(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
        ];

        $result = new ValidationResult($messages);
        $array = $result->toArray();

        $this->assertFalse($array['valid']);
        $this->assertEquals(1, $array['error_count']);
        $this->assertEquals(0, $array['warning_count']);
        $this->assertIsArray($array['messages']);
    }

    /**
     * 测试转换为JSON
     */
    public function testToJson(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
        ];

        $result = new ValidationResult($messages);
        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertFalse($decoded['valid']);
    }

    /**
     * 测试抛出异常 - 有错误时
     */
    public function testThrowIfInvalid(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '发生了错误', 'level' => WorkflowValidator::LEVEL_ERROR],
        ];

        $result = new ValidationResult($messages);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/工作流验证失败/');
        $result->throwIfInvalid();
    }

    /**
     * 测试抛出异常 - 自定义异常类
     */
    public function testThrowIfInvalidCustomException(): void
    {
        $messages = [
            ['code' => 'ERR', 'message' => '错误', 'level' => WorkflowValidator::LEVEL_ERROR],
        ];

        $result = new ValidationResult($messages);

        $this->expectException(\RuntimeException::class);
        $result->throwIfInvalid(\RuntimeException::class);
    }

    /**
     * 测试不抛出异常 - 无错误时
     */
    public function testThrowIfInvalidNoException(): void
    {
        $result = new ValidationResult([]);

        // 不应该抛出异常
        $result->throwIfInvalid();
        $this->assertTrue(true); // 如果到这里，说明没有抛出异常
    }

    /**
     * 测试默认级别为错误
     */
    public function testDefaultLevelIsError(): void
    {
        $messages = [
            ['code' => 'MSG', 'message' => '消息'], // 没有 level
        ];

        $result = new ValidationResult($messages);

        $this->assertTrue($result->hasErrors());
        $this->assertEquals(1, $result->getErrorCount());
    }
}
