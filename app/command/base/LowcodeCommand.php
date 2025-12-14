<?php

declare(strict_types=1);

namespace app\command\base;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\output\Question;
use think\console\output\Confirm;
use think\console\Table;

/**
 * Lowcode Command Base Class | 低代码命令基类
 *
 * Provides common functionality for all lowcode CLI commands.
 * 为所有低代码CLI命令提供通用功能。
 *
 * @package app\command\base
 */
abstract class LowcodeCommand extends Command
{
    /**
     * Output success message | 输出成功消息
     *
     * @param string $message Success message | 成功消息
     * @return void
     */
    protected function success(string $message): void
    {
        $this->output->writeln("<info>✓ {$message}</info>");
    }

    /**
     * Output error message | 输出错误消息
     *
     * @param string $message Error message | 错误消息
     * @return void
     */
    protected function error(string $message): void
    {
        $this->output->writeln("<error>✗ {$message}</error>");
    }

    /**
     * Output warning message | 输出警告消息
     *
     * @param string $message Warning message | 警告消息
     * @return void
     */
    protected function warning(string $message): void
    {
        $this->output->writeln("<comment>⚠ {$message}</comment>");
    }

    /**
     * Output info message | 输出信息消息
     *
     * @param string $message Info message | 信息消息
     * @return void
     */
    protected function info(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    /**
     * Output section header | 输出章节标题
     *
     * @param string $title Section title | 章节标题
     * @return void
     */
    protected function section(string $title): void
    {
        $this->output->writeln('');
        $this->output->writeln("<fg=cyan;options=bold>{$title}</>");
        $this->output->writeln(str_repeat('=', strlen($title)));
    }

    /**
     * Ask a question | 询问问题
     *
     * @param string $question Question text | 问题文本
     * @param string|null $default Default value | 默认值
     * @return string User's answer | 用户的回答
     */
    protected function ask(string $question, ?string $default = null): string
    {
        $questionObj = new Question($question, $default);
        return $this->output->ask($this->input, $questionObj);
    }

    /**
     * Ask a confirmation question | 询问确认问题
     *
     * @param string $question Question text | 问题文本
     * @param bool $default Default value | 默认值
     * @return bool User's confirmation | 用户的确认
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        $confirmObj = new Confirm($question, $default);
        return $this->output->confirm($this->input, $confirmObj);
    }

    /**
     * Display a table | 显示表格
     *
     * @param array $headers Table headers | 表头
     * @param array $rows Table rows | 表格行
     * @return void
     */
    protected function displayTable(array $headers, array $rows): void
    {
        $table = new Table();
        $table->setHeader($headers);
        $table->setRows($rows);
        $this->output->write($table->render());
    }

    /**
     * Show progress bar | 显示进度条
     *
     * @param int $total Total steps | 总步数
     * @return \think\console\output\ProgressBar Progress bar instance | 进度条实例
     */
    protected function createProgressBar(int $total): \think\console\output\ProgressBar
    {
        return $this->output->createProgressBar($total);
    }

    /**
     * Parse field definitions from string | 从字符串解析字段定义
     *
     * Format: "name:type,email:string,age:integer"
     * 格式: "name:type,email:string,age:integer"
     *
     * @param string $fieldsStr Fields string | 字段字符串
     * @return array Parsed fields | 解析后的字段
     */
    protected function parseFields(string $fieldsStr): array
    {
        $fields = [];
        $fieldPairs = explode(',', $fieldsStr);

        foreach ($fieldPairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            $parts = explode(':', $pair);
            if (count($parts) !== 2) {
                $this->warning("Invalid field format: {$pair}, expected 'name:type'");
                continue;
            }

            [$name, $type] = $parts;
            $name = trim($name);
            $type = trim($type);

            if (empty($name) || empty($type)) {
                $this->warning("Empty field name or type in: {$pair}");
                continue;
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
            ];
        }

        return $fields;
    }

    /**
     * Validate field type | 验证字段类型
     *
     * @param string $type Field type | 字段类型
     * @return bool Is valid | 是否有效
     */
    protected function isValidFieldType(string $type): bool
    {
        $validTypes = [
            'string', 'text', 'integer', 'decimal', 'boolean',
            'date', 'datetime', 'timestamp', 'json', 'select',
            'multiselect', 'file', 'image', 'email', 'url',
            'phone', 'color', 'password',
        ];

        return in_array(strtolower($type), $validTypes, true);
    }

    /**
     * Get tenant ID from input or use default | 从输入获取租户ID或使用默认值
     *
     * @return int Tenant ID | 租户ID
     */
    protected function getTenantId(): int
    {
        $tenantId = (int) $this->input->getOption('tenant-id');
        return $tenantId > 0 ? $tenantId : 0;
    }

    /**
     * Get site ID from input or use default | 从输入获取站点ID或使用默认值
     *
     * @return int Site ID | 站点ID
     */
    protected function getSiteId(): int
    {
        $siteId = (int) $this->input->getOption('site-id');
        return $siteId > 0 ? $siteId : 0;
    }

    /**
     * Format field definition for display | 格式化字段定义用于显示
     *
     * @param array $field Field definition | 字段定义
     * @return string Formatted field | 格式化的字段
     */
    protected function formatField(array $field): string
    {
        $str = "{$field['name']}:{$field['type']}";

        if (isset($field['nullable']) && $field['nullable']) {
            $str .= ' (nullable)';
        }

        if (isset($field['default'])) {
            $str .= " [default: {$field['default']}]";
        }

        return $str;
    }

    /**
     * Handle command exception | 处理命令异常
     *
     * @param \Throwable $e Exception | 异常
     * @return int Exit code | 退出码
     */
    protected function handleException(\Throwable $e): int
    {
        $this->error($e->getMessage());

        if ($this->output->isVerbose()) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Stack trace:</comment>');
            $this->output->writeln($e->getTraceAsString());
        }

        return 1;
    }

    /**
     * Validate required options | 验证必需选项
     *
     * @param array $requiredOptions Required option names | 必需的选项名称
     * @return bool All required options present | 所有必需选项都存在
     */
    protected function validateRequiredOptions(array $requiredOptions): bool
    {
        $missing = [];

        foreach ($requiredOptions as $option) {
            if (!$this->input->hasOption($option) || empty($this->input->getOption($option))) {
                $missing[] = $option;
            }
        }

        if (!empty($missing)) {
            $this->error('Missing required options: ' . implode(', ', $missing));
            return false;
        }

        return true;
    }
}
