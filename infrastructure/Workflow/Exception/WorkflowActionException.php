<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Exception;

use Alkaid\Workflow\Engine\enums\err\LfErrEnum;
use RuntimeException;
use think\facade\Log;
use Throwable;

/**
 * 工作流动作失败异常（宿主侧统一出口）
 *
 * ## 为什么需要它
 *
 * 工作流实例/任务控制器的动作端点会经历三层失败来源：
 *  1. **宿主校验**（参数非法、状态冲突、越权）；
 *  2. **引擎异常**（`LFlowException` 等，消息是引擎内部文案，甚至可能是错误码数字）；
 *  3. **引擎未实现**（`BadMethodCallException`，说明宿主调错了 API）。
 *
 * 如果把它们混成一个 `RuntimeException` 丢给框架，就会出现两类事故：
 *  - 越权被渲染成 500（看起来像系统故障，掩盖了真实的安全含义）；
 *  - 引擎内部消息（表名、SQL、类名、节点名、状态机取值）原样吐给客户端 —— 信息泄漏。
 *
 * 因此这里把「**对外的** message / 业务码 / HTTP 状态 / 字段级错误」与「**对内的** 原始异常」
 * 分开：原始异常只进日志（`[WORKFLOW_ACTION_FAILED]`），对外只给一句宿主自己写的话。
 *
 * ## 业务码口径（与既有控制器保持一致，不新造枚举）
 *
 * | 场景 | 业务码 | HTTP | 出处 |
 * | ---- | ------ | ---- | ---- |
 * | 记录不存在 | 404 | 404 | `ApiController::notFound()` |
 * | 跨租户 / 非参与者 | 403 | 403 | `ApiController::forbidden()`（区别于权限中间件的 2002） |
 * | 参数非法 | 422 | 422 | `ApiController::validationError()` |
 * | 状态冲突（已结束 / 已挂起 / 已被处理） | 400 | 400 | 既有 `cannot_*_task` 分支 |
 * | 引擎/存储内部故障 | 5000 | 500 | `ErrorCode::SERVER_ERROR` |
 *
 * @package Infrastructure\Workflow\Exception
 */
final class WorkflowActionException extends RuntimeException
{
    /**
     * 内部故障业务码（与 app\constant\ErrorCode::SERVER_ERROR 同值）
     */
    public const CODE_SERVER_ERROR = 5000;

    /**
     * 对外的 HTTP 状态
     */
    private int $httpStatus;

    /**
     * 对外的业务码（响应体 `code`）
     */
    private int $businessCode;

    /**
     * 字段级错误（响应体 `data.errors`）
     *
     * @var array<string, string>
     */
    private array $errors;

    /**
     * 对外消息（**唯一**允许出现在响应里的文案）
     */
    private string $publicMessage;

    /**
     * @param string                $publicMessage 对外消息（不得包含内部结构）
     * @param int                   $businessCode  响应体业务码
     * @param int                   $httpStatus    HTTP 状态
     * @param array<string, string> $errors        字段级错误
     * @param Throwable|null        $previous      原始异常（仅用于日志链路）
     */
    private function __construct(
        string $publicMessage,
        int $businessCode,
        int $httpStatus,
        array $errors = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($publicMessage, $businessCode, $previous);

        $this->publicMessage = $publicMessage;
        $this->businessCode  = $businessCode;
        $this->httpStatus    = $httpStatus;
        $this->errors        = $errors;
    }

    /**
     * 404：记录不存在（非数字主键、查无此行）
     */
    public static function notFound(string $message): self
    {
        return new self($message, 404, 404);
    }

    /**
     * 403：跨租户、非参与者（业务码 403 用于区别于权限中间件的 2002）
     */
    public static function forbidden(string $message): self
    {
        return new self($message, 403, 403);
    }

    /**
     * 422：入参非法
     *
     * @param array<string, string> $errors 字段级错误
     */
    public static function invalid(array $errors, ?string $message = null): self
    {
        return new self(
            $message ?? '请求参数校验未通过 | request validation failed',
            422,
            422,
            $errors
        );
    }

    /**
     * 400：状态冲突（实例已结束 / 已挂起、任务已被处理……）
     *
     * @param array<string, mixed> $errors
     */
    public static function conflict(string $message, array $errors = []): self
    {
        return new self($message, 400, 400, $errors);
    }

    /**
     * 500：引擎/存储内部故障（对外只说「执行失败」，细节只进日志）
     */
    public static function internal(string $operation, Throwable $previous): self
    {
        self::logInternal($operation, $previous);

        return new self(
            '工作流引擎执行失败，请稍后重试（若持续失败请联系管理员）| workflow engine failed',
            self::CODE_SERVER_ERROR,
            500,
            [],
            $previous
        );
    }

    /**
     * 引擎异常 → 宿主语义（越权 403 / 任务已处理 400 / 其余 500）
     *
     * `LFlowException` 的消息口径很特殊：引擎有些地方是 `new LFlowException(LfErrEnum::X->value)`，
     * 也就是把**枚举数字**当消息传进来（`getMessage()` 得到 `'20010004'`），中文标签只在
     * `LfErrEnum::label()` 里。因此这里两种形态都要认（数字与中文标签），否则
     * 「当前参与者不能执行该流程任务」会被渲染成 500 —— 那正好把越权伪装成系统故障。
     *
     * @param Throwable $previous  引擎抛出的异常
     * @param string    $operation 操作描述（仅用于日志定位）
     */
    public static function fromEngine(Throwable $previous, string $operation): self
    {
        $raw       = trim($previous->getMessage());
        $notAllowed = (string) LfErrEnum::NOT_ALLOWED_EXECUTE->value;
        $notDoing   = (string) LfErrEnum::NOT_FOUND_DOING_PROCESS_TASK->value;

        if ($raw === $notAllowed || $raw === LfErrEnum::NOT_ALLOWED_EXECUTE->label()) {
            self::logInternal($operation, $previous);

            return new self('您不是该任务的参与者，无权执行该任务 | not allowed to execute this task', 403, 403, [], $previous);
        }

        if ($raw === $notDoing || $raw === LfErrEnum::NOT_FOUND_DOING_PROCESS_TASK->label()) {
            self::logInternal($operation, $previous);

            return new self('任务不存在或已被处理，不能重复操作 | task is not in progress', 400, 400, [], $previous);
        }

        return self::internal($operation, $previous);
    }

    /**
     * 对外的 HTTP 状态
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * 对外的业务码
     */
    public function getBusinessCode(): int
    {
        return $this->businessCode;
    }

    /**
     * 对外的字段级错误
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 对外的消息（**不要**用 getMessage()：那是父类语义，容易被当作可透传的原文）
     */
    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * 控制器统一渲染用的响应片段
     *
     * @return array{message: string, code: int, errors: array<string, string>, http_code: int}
     */
    public function toResponsePayload(): array
    {
        return [
            'message'   => $this->publicMessage,
            'code'      => $this->businessCode,
            'errors'    => $this->errors,
            'http_code' => $this->httpStatus,
        ];
    }

    /**
     * 原始异常只进日志：对外不泄漏表名 / SQL / 类名 / 节点名
     */
    private static function logInternal(string $operation, Throwable $previous): void
    {
        try {
            Log::warning('[WORKFLOW_ACTION_FAILED] 工作流动作执行失败', [
                'operation' => $operation,
                'exception' => get_class($previous),
                'message'   => $previous->getMessage(),
                'file'      => $previous->getFile() . ':' . $previous->getLine(),
            ]);
        } catch (Throwable) {
            // 日志设施不可用时不得让异常映射本身失败（审计旁路，绝不影响主链路）
        }
    }
}
