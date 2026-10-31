<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 子进程执行结果 | Subprocess execution result
 *
 * 只承载「退出码 + 合并后的输出」这两件事，避免调用方各自维护 `exec()` 的引用参数。
 *
 * @package Tests\Support
 */
final class CliProcessResult
{
    /**
     * @param int    $exitCode 进程退出码（0 = 成功）
     * @param string $output   stdout 与 stderr 合并后的输出
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output
    ) {
    }

    /**
     * 进程是否以 0 退出
     */
    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }
}
