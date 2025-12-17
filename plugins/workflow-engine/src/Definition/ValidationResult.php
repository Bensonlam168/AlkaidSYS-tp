<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

/**
 * 验证结果
 *
 * 封装工作流验证的结果，包括错误、警告和信息。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class ValidationResult
{
    /**
     * 所有验证消息
     *
     * @var array
     */
    protected array $messages = [];

    /**
     * 构造函数
     *
     * @param array $messages 验证消息列表
     */
    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    /**
     * 是否有效（无错误）
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return !$this->hasErrors();
    }

    /**
     * 是否有错误
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return count($this->getErrors()) > 0;
    }

    /**
     * 是否有警告
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->getWarnings()) > 0;
    }

    /**
     * 是否有消息
     *
     * @return bool
     */
    public function hasMessages(): bool
    {
        return !empty($this->messages);
    }

    /**
     * 获取所有消息
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * 获取错误
     *
     * @return array
     */
    public function getErrors(): array
    {
        return array_filter(
            $this->messages,
            fn(array $msg) => ($msg['level'] ?? WorkflowValidator::LEVEL_ERROR) === WorkflowValidator::LEVEL_ERROR
        );
    }

    /**
     * 获取警告
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return array_filter(
            $this->messages,
            fn(array $msg) => ($msg['level'] ?? '') === WorkflowValidator::LEVEL_WARNING
        );
    }

    /**
     * 获取信息
     *
     * @return array
     */
    public function getInfos(): array
    {
        return array_filter(
            $this->messages,
            fn(array $msg) => ($msg['level'] ?? '') === WorkflowValidator::LEVEL_INFO
        );
    }

    /**
     * 按规则分组消息
     *
     * @return array<string, array>
     */
    public function groupByRule(): array
    {
        $grouped = [];
        foreach ($this->messages as $message) {
            $rule = $message['rule'] ?? 'unknown';
            if (!isset($grouped[$rule])) {
                $grouped[$rule] = [];
            }
            $grouped[$rule][] = $message;
        }
        return $grouped;
    }

    /**
     * 按级别分组消息
     *
     * @return array<string, array>
     */
    public function groupByLevel(): array
    {
        return [
            WorkflowValidator::LEVEL_ERROR => array_values($this->getErrors()),
            WorkflowValidator::LEVEL_WARNING => array_values($this->getWarnings()),
            WorkflowValidator::LEVEL_INFO => array_values($this->getInfos()),
        ];
    }

    /**
     * 获取错误代码列表
     *
     * @return array<string>
     */
    public function getErrorCodes(): array
    {
        return array_unique(array_map(
            fn(array $msg) => $msg['code'] ?? 'UNKNOWN',
            $this->getErrors()
        ));
    }

    /**
     * 检查是否包含特定错误代码
     *
     * @param string $code 错误代码
     * @return bool
     */
    public function hasErrorCode(string $code): bool
    {
        return in_array($code, $this->getErrorCodes(), true);
    }

    /**
     * 获取所有消息代码（不限级别）
     *
     * @return array<string>
     */
    public function getAllCodes(): array
    {
        return array_unique(array_map(
            fn(array $msg) => $msg['code'] ?? 'UNKNOWN',
            $this->messages
        ));
    }

    /**
     * 检查是否包含特定消息代码（不限级别）
     *
     * @param string $code 消息代码
     * @return bool
     */
    public function hasCode(string $code): bool
    {
        return in_array($code, $this->getAllCodes(), true);
    }

    /**
     * 获取错误数量
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        return count($this->getErrors());
    }

    /**
     * 获取警告数量
     *
     * @return int
     */
    public function getWarningCount(): int
    {
        return count($this->getWarnings());
    }

    /**
     * 获取总消息数量
     *
     * @return int
     */
    public function getMessageCount(): int
    {
        return count($this->messages);
    }

    /**
     * 合并另一个验证结果
     *
     * @param ValidationResult $other 其他验证结果
     * @return static
     */
    public function merge(ValidationResult $other): static
    {
        $this->messages = array_merge($this->messages, $other->getMessages());
        return $this;
    }

    /**
     * 过滤消息
     *
     * @param callable $filter 过滤函数
     * @return static
     */
    public function filter(callable $filter): static
    {
        return new static(array_filter($this->messages, $filter));
    }

    /**
     * 获取摘要字符串
     *
     * @return string
     */
    public function getSummary(): string
    {
        $errorCount = $this->getErrorCount();
        $warningCount = $this->getWarningCount();
        $infoCount = count($this->getInfos());

        if ($this->isValid() && $warningCount === 0 && $infoCount === 0) {
            return '验证通过';
        }

        $parts = [];
        if ($errorCount > 0) {
            $parts[] = "{$errorCount} 个错误";
        }
        if ($warningCount > 0) {
            $parts[] = "{$warningCount} 个警告";
        }
        if ($infoCount > 0) {
            $parts[] = "{$infoCount} 条信息";
        }

        return implode(', ', $parts);
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'error_count' => $this->getErrorCount(),
            'warning_count' => $this->getWarningCount(),
            'messages' => $this->messages,
        ];
    }

    /**
     * 转换为JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 抛出异常（如果有错误）
     *
     * @param string $exceptionClass 异常类名
     * @return void
     * @throws \Exception
     */
    public function throwIfInvalid(string $exceptionClass = \InvalidArgumentException::class): void
    {
        if ($this->hasErrors()) {
            $errorMessages = array_map(
                fn(array $msg) => $msg['message'] ?? 'Unknown error',
                $this->getErrors()
            );
            throw new $exceptionClass('工作流验证失败: ' . implode('; ', $errorMessages));
        }
    }
}
