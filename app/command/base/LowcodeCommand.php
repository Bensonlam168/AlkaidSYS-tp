<?php

declare(strict_types=1);

namespace app\command\base;

use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\Output;
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
     * 测试库名必须包含的标记（与 tests/ThinkPHPTestCase.php 的判据保持一致）
     */
    private const TEST_DB_MARKER = '_test';

    /**
     * 显式测试上下文标记（由 tests/Support/CliProcess 注入子进程）
     */
    private const TESTING_FLAG = 'ALKAID_TESTING';

    /**
     * 命令唯一入口：先做「测试上下文禁止写非测试库」的检查，再交给父类执行
     *
     * 为什么拦在 run() 而不是各命令的 execute()：
     *   run() 是框架调用命令的**唯一入口**（think\Console::doRunCommand() → $command->run()），
     *   在这里挡一道，新增的 lowcode 子命令会自动继承保护，不会因为「忘记加一行」而漏掉。
     *
     * @param Input  $input  Input instance | 输入实例
     * @param Output $output Output instance | 输出实例
     * @return int Exit code | 退出码
     */
    public function run(Input $input, Output $output): int
    {
        $this->guardTestContextDatabase();

        return parent::run($input, $output);
    }

    /**
     * 测试上下文下的数据库 fail-closed 守卫（纵深防御）
     *
     * 背景（实测）：测试用例用 exec() 拉起 `php think lowcode:create-model` 时，子进程是全新 PHP 进程，
     * 会重新 load `.env` → 连到**开发库 alkaid_sys**，把 369 张表的基线写成 371 张。
     * 父进程的守卫（tests/ThinkPHPTestCase）保护不了另一个进程，所以命令侧必须再挡一道：
     * 发现「处于测试上下文」且「实际要连的库名不含 _test」时直接抛异常，绝不下发任何 DDL/DML。
     *
     * 边界：只有显式标记 ALKAID_TESTING=1 才算测试上下文，因此**不影响**正常手工执行
     * `php think lowcode:create-model ...`（没有该标记时直接放行）。
     *
     * @throws RuntimeException 测试上下文下解析出的库名不是测试库
     */
    protected function guardTestContextDatabase(): void
    {
        if (!self::isTestingContext()) {
            // 手工执行（无测试标记）：保持既有行为不变
            return;
        }

        $database = $this->resolveEffectiveDatabaseName();

        if (str_contains($database, self::TEST_DB_MARKER)) {
            return;
        }

        // 刻意抛异常，而不是「打警告 + return 1」：警告会被淹没，进程仍可能继续写库。
        // think\Console::run() 会捕获 Exception、打印下面的文案并以非 0 退出码结束。
        throw new RuntimeException(sprintf(
            "拒绝在非测试库上执行 %s：解析出的数据库名「%s」不包含「%s」。\n"
            . "当前处于测试上下文（%s=1），继续执行会写坏开发库。请按以下顺序处置：\n"
            . "  1) 测试代码请改用 Tests\\Support\\CliProcess::think() 启动子进程（它会生成临时环境文件并指向测试库）；\n"
            . "  2) 不要在测试里手工拼 exec('php think ...')：子进程会重新读 .env 并连到开发库；\n"
            . "  3) 若确需手工验证，请确认没有设置 %s=1；\n"
            . "  4) 冷启动测试库：PHP_ENV_NAME=test php think migrate:run。\n"
            . '参考：ER-15 —— 测试默认库已切到 alkaid_sys_test，但子进程不受父进程守卫保护。',
            $this->getName() ?: '(unknown)',
            $database,
            self::TEST_DB_MARKER,
            self::TESTING_FLAG,
            self::TESTING_FLAG
        ));
    }

    /**
     * 当前进程是否处于测试上下文
     */
    protected static function isTestingContext(): bool
    {
        $flag = getenv(self::TESTING_FLAG);

        if (false === $flag) {
            $flag = $_SERVER[self::TESTING_FLAG] ?? $_ENV[self::TESTING_FLAG] ?? '';
        }

        return in_array(strtolower((string) $flag), ['1', 'true', 'yes'], true);
    }

    /**
     * 解析「实际生效」的数据库名
     *
     * 唯一可信来源是命令真正使用的连接配置（`App::initialize()` 从隔离后的 Env 生成）。
     * 刻意**不优先**读 `getenv('DB_NAME')`：子进程的进程环境可能带着测试库名（助手做了双保险），
     * 而真正生效的可能是被环境文件覆盖过的另一个库 —— 守卫必须看「实际要连的库」。
     */
    protected function resolveEffectiveDatabaseName(): string
    {
        if (null !== $this->app) {
            $database = $this->app->config->get('database.connections.mysql.database');

            if (is_string($database) && '' !== $database) {
                return $database;
            }

            // 配置尚未加载时的兜底：直接问 Env（同样优先读已加载的环境文件数据）
            $database = $this->app->env->get('DB_NAME');

            if (is_string($database) && '' !== $database) {
                return $database;
            }
        }

        return (string) (getenv('DB_NAME') ?: '');
    }

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
        // 与 confirm() 同源问题：Output::ask() 内部会执行
        //   $question = new Question($question, $default);
        // 因此这里只能传「问题文本 + 默认值」，不能自己 new Question 后再塞进去 ——
        // 否则内层 Question 的 question 字段会是一个对象，Ask::writePrompt() 里
        // sprintf(' <info>%s</info>:', $text) 会抛
        //   Error: Object of class think\console\output\Question could not be converted to string
        //
        // 归一化说明：$default 为 null（如 CreateModelCommand 的 ask('Field name')）时，
        // 用户直接回车会让 Question::getDefault() 返回 null，而本方法声明返回 string，
        // 唯一调用点用 empty($name) 判断「输入结束」，故把 null 归一为空串，避免返回类型 TypeError。
        return (string) $this->output->ask($this->input, $question, $default);
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
        // 注意：框架里**没有** think\console\output\Confirm 这个类（历史写法会抛
        // Class "think\console\output\Confirm" not found 致命错误），真正的类是
        // think\console\output\question\Confirmation。
        // 但这里不能再自己 new Confirmation(...) 传进去：Output::confirm() 内部会执行
        // new Confirmation($question, $default)，而 Confirmation::__construct() 的第一个参数
        // 是强类型 string，传对象会抛 TypeError。因此直接把问题文本与默认值交给框架，
        // 由 Output::confirm() 负责实例化 Confirmation（y/n 归一化也由它完成）。
        return $this->output->confirm($this->input, $question, $default);
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
            'phone', 'color', 'password', 'cascader', 'tree-select',
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
        $format = $this->getOutputFormat();

        if ($format === 'json') {
            $this->writeStructuredOutput(
                ['exception' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ]],
                1,
                'Command execution failed'
            );
        } else {
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->output->writeln('');
                $this->output->writeln('<comment>Stack trace:</comment>');
                $this->output->writeln($e->getTraceAsString());
            }
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

    /**
     * Get output format option | 获取输出格式
     */
    protected function getOutputFormat(): string
    {
        $format = (string) ($this->input instanceof Input ? $this->input->getOption('format') : 'text');
        $format = strtolower($format ?: 'text');

        return in_array($format, ['text', 'json'], true) ? $format : 'text';
    }

    /**
     * Get audit output file path | 获取审计输出文件路径
     */
    protected function getAuditOutPath(): ?string
    {
        if (!$this->input instanceof Input) {
            return null;
        }

        $path = (string) $this->input->getOption('audit-out');

        return $path !== '' ? $path : null;
    }

    /**
     * Build CLI envelope | 构建 CLI 输出包裹结构
     *
     * @param array $data    Payload data
     * @param int   $code    Business code (0 = success)
     * @param string $message Human readable message
     */
    protected function buildCliEnvelope(array $data, int $code = 0, string $message = 'OK'): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
            'command' => $this->getName(),
        ];
    }

    /**
     * Write structured output to stdout / audit file | 写入结构化输出
     */
    protected function writeStructuredOutput(array $data, int $code = 0, string $message = 'OK'): void
    {
        $envelope = $this->buildCliEnvelope($data, $code, $message);
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $auditOut = $this->getAuditOutPath();
        if ($auditOut !== null) {
            @file_put_contents($auditOut, $json . PHP_EOL);
        }

        if ($this->getOutputFormat() === 'json') {
            $this->output->writeln($json);
        }
    }
}
