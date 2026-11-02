<?php

declare(strict_types=1);

namespace Domain\Event;

use think\facade\Log;

/**
 * Event failure logger | 事件失败日志器（ER-13）
 *
 * 统一「同步监听器 / 异步任务 / 监控降级」三条链路的失败日志字段，避免各处各写一套：
 *   event / listener / trace_id / tenant_id / site_id / user_id / exception / message / mode
 *
 * 为什么需要「最后兜底」：
 *   事件系统原本存在「监听器崩了 → catch 吞掉 → 日志也没有」的静默路径。
 *   现在失败会写 error 日志，但如果日志系统本身也故障（通道未配置、磁盘满、日志驱动异常），
 *   `Log::error()` 会抛异常，事件就会再次变成「失败且无痕迹」。因此本类在 catch 到
 *   日志异常时降级到 error_log()，保证至少有一条可被系统级日志采集到的记录。
 *
 * 为什么把 `error` 与 `message` 同时写入：
 *   既有异步日志（event.async.failed）一直用 `error` 字段，线上可能已有按 `error` 检索的告警；
 *   保留同义字段是为了不破坏既有查询，同时让新日志有语义更明确的 `message`。
 *
 * @package Domain\Event
 */
final class EventFailureLogger
{
    /**
     * 记录一次事件失败（默认 error 级）
     *
     * 注意：调用方负责决定「是否继续/是否上抛」，本方法只负责把失败记录下来，不改变控制流。
     *
     * @param string       $key        日志键，如 event.listener.failed
     * @param string       $event      事件名
     * @param string|null  $listener   监听器标识；拿不到时为 null（如 ThinkPHP 事件系统内部监听器）
     * @param EventContext|null $context 事件上下文；异步任务缺少上下文时允许为 null
     * @param \Throwable   $exception  监听器/任务抛出的原始异常
     * @param string       $mode       sync|async
     * @param array        $extra      额外字段（如 phase / attempts / listener_key）
     * @param string       $level      日志级别，默认 error；监控降级类失败可传 warning
     */
    public static function report(
        string $key,
        string $event,
        ?string $listener,
        ?EventContext $context,
        \Throwable $exception,
        string $mode,
        array $extra = [],
        string $level = 'error'
    ): void {
        $data = [
            'event' => $event,
            'listener' => $listener,
            'trace_id' => $context?->getTraceId(),
            'tenant_id' => $context?->getTenantId(),
            'site_id' => $context?->getSiteId(),
            'user_id' => $context?->getUserId(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            // 兼容既有 event.async.failed 日志的检索字段
            'error' => $exception->getMessage(),
            'mode' => $mode,
        ];

        self::write($level, $key, array_merge($data, $extra));
    }

    /**
     * 安全写日志：日志系统自身抛异常时降级 error_log()
     *
     * 绝不让「记录失败」这个动作反过来抛异常 —— 否则调用方的 catch 会把新的日志异常
     * 当成业务异常继续上抛，或再次被吞掉。
     *
     * @param string $level   error|warning|info|debug
     * @param string $key     日志键
     * @param array  $context 结构化字段
     */
    public static function write(string $level, string $key, array $context): void
    {
        try {
            self::writeToLog($level, $key, $context);
        } catch (\Throwable $logError) {
            self::lastResort($key, $context, $logError);
        }
    }

    /**
     * 写入 ThinkPHP 日志通道
     *
     * 关键点：ThinkPHP 的 `Log::error($message, $context)` 里第二个参数是「占位符替换内容」，
     * **不会**把数组原样写进日志（Channel::record 只做 strtr('{key}', value)）。
     * 若直接把结构化数组当 context 传进去，生产日志里只剩 `event.listener.failed` 一行，
     * 所有字段都会丢失 —— 这正是「看起来记了日志、实际查不到现场」的陷阱。
     * 因此这里把字段序列化进 message；同时不传 context，避免嵌套数组触发
     * "Array to string conversion" 告警。
     */
    protected static function writeToLog(string $level, string $key, array $context): void
    {
        $message = self::formatMessage($key, $context);

        switch ($level) {
            case 'error':
                Log::error($message);
                break;
            case 'warning':
                Log::warning($message);
                break;
            case 'info':
                Log::info($message);
                break;
            default:
                Log::debug($message);
                break;
        }
    }

    /**
     * 把日志键与结构化字段拼成单行：`key {"event":"...",...}`
     *
     * 保留 key 作为前缀：既有的按 `event.listener.failed` / `event.dlq` 检索的订阅不受影响。
     */
    public static function formatMessage(string $key, array $context): string
    {
        if ($context === []) {
            return $key;
        }

        $encoded = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if (!is_string($encoded)) {
            // json_encode 仍失败（如资源、循环引用）时用 print_r：它不会抛异常
            $encoded = print_r($context, true);
        }

        return $key . ' ' . $encoded;
    }

    /**
     * 最后一道兜底：即使 Log 抛异常也要留下痕迹
     *
     * 用 error_log() 而不是再次调用 Log：PHP 的系统级日志不依赖框架容器/通道配置，
     * 容器内默认进入 stderr，可被 docker logs / 日志采集器捕获。
     */
    protected static function lastResort(string $key, array $context, \Throwable $logError): void
    {
        @error_log(sprintf(
            '[event.failure] %s | log_error=%s: %s',
            self::formatMessage($key, $context),
            $logError::class,
            $logError->getMessage()
        ));
    }
}
