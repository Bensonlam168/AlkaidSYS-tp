<?php

declare(strict_types=1);

namespace app\exception;

use RuntimeException;
use app\constant\ErrorCode;

/**
 * 禁止访问异常
 *
 * 用于在服务层显式触发 2002 权限不足错误。
 */
class ForbiddenException extends RuntimeException
{
    public function __construct(string $message = 'Forbidden', int $code = ErrorCode::FORBIDDEN, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
