<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services\Support;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;

/**
 * 「事件写入是旁路审计」的执行口径
 *
 * ── 为什么需要这层包裹 ────────────────────────────────────────────────────
 * 事件日志回答的是「流程是怎么走到今天的」，它**不是**审批本身。
 * 如果写日志失败会把「审批成功」变成「审批失败」，那这个能力一上线就是负资产：
 * 审计表的一次磁盘满 / 一次外键冲突，就能让业务停摆。所以三处调用点
 * （ProcessEngines / ProcessTaskService / ProcessInstanceService）统一走这里：
 *
 *     $this->recordHistorySafely('记录任务完成事件', fn ($history) => $history->recordTaskCompleted(...));
 *
 * 语义：
 * 1）历史服务未注册 → 记 INFO 并跳过（缺这个能力不等于出错，但也不能静默无声）；
 * 2）写入抛任何 Throwable → 记 **WARNING** 并继续，异常绝不外溢；
 * 3）正常路径不额外开销（闭包只在服务就绪时调用）。
 *
 * ── 为什么 WARNING 必须带上失败原因 ───────────────────────────────────────
 * 「静默吞掉」和「没写」在事后排查时完全等价。因此日志里固定包含
 * 操作名 + 异常类型 + 异常消息，能区分「租户缺失」「实例不存在」「外键冲突」
 * 「磁盘/连接问题」这几类完全不同的故障。
 *
 * ── 为什么日志先试宿主再降级 ──────────────────────────────────────────────
 * 与 WorkflowBootstrap::logWorkflow() 同一口径：宿主 ThinkPHP 日志可用就走
 * `Log::channel('workflow')`（未配置该通道时框架回落到默认 file 通道），
 * 否则降级 `error_log()`。插件不带 monolog（`libs\utils\Logger` 会在独立运行时
 * `Class "Monolog\Logger" not found`），因此不能用它。
 *
 * @package Alkaid\Workflow\Engine\Services\Support
 */
trait RecordsHistorySafely
{
    /**
     * 守卫式记录一个历史事件
     *
     * @param string   $operation 操作描述（写进日志，用于定位是哪一处写入失败）
     * @param callable $write     真正的写入动作：`function (IProcessInstanceHistoryService $history): void`
     */
    protected function recordHistorySafely(string $operation, callable $write): void
    {
        $history = $this->instanceHistoryService();

        if ($history === null) {
            $this->logHistory('info', sprintf(
                '%s：容器未注册 %s，已跳过事件记录；主流程继续',
                $operation,
                IProcessInstanceHistoryService::class
            ));

            return;
        }

        try {
            $write($history);
        } catch (\Throwable $e) {
            // 旁路审计失败：只记 WARNING，绝不改变主流程结果
            $this->logHistory('warning', sprintf(
                '%s：工作流事件写入失败，已忽略并继续主流程。原因：%s: %s',
                $operation,
                get_debug_type($e),
                $e->getMessage()
            ));
        }
    }

    /**
     * 解析历史服务（不抛异常：取不到就当「本宿主没有这个能力」）
     */
    protected function instanceHistoryService(): ?IProcessInstanceHistoryService
    {
        try {
            $service = ServiceContext::find(IProcessInstanceHistoryService::class);
        } catch (\Throwable) {
            // ServiceContext 未初始化 / PHP-DI 缺失：与「未注册」同口径
            return null;
        }

        return $service instanceof IProcessInstanceHistoryService ? $service : null;
    }

    /**
     * 守卫式记录工作流日志（宿主日志优先，否则 error_log 降级）
     *
     * 三级口径，逐级降级（任何一级失败都不上抛）：
     * 1）专用通道 `log.channels.workflow`（宿主配置了就用它，便于单独采集）；
     * 2）**默认通道**：本仓库 `config/log.php` 里并没有声明 `workflow` 通道，
     *    而 `Log::channel('workflow')` 在通道不存在时抛
     *    `InvalidArgumentException: Channel [workflow] not found.`（实测，不是回落默认通道）。
     *    改造前这里只写了第 1 级，于是「宿主日志优先」在本仓库实际从未生效 ——
     *    全部工作流日志都落到了 error_log 上。补上第 2 级后，它们才真的进应用日志文件。
     * 3）`error_log()`：插件独立运行 / 应用容器未启动时（`Log` 门面解析不到 'log' 绑定）。
     *
     * @param string $level   info / warning / error ...
     * @param string $message 日志内容
     */
    protected function logHistory(string $level, string $message): void
    {
        if (class_exists(\think\facade\Log::class)) {
            // 1) 专用通道
            try {
                \think\facade\Log::channel('workflow')->{$level}($message);

                return;
            } catch (\Throwable) {
                // 通道未配置：继续走默认通道
            }

            // 2) 默认通道
            try {
                \think\facade\Log::channel()->{$level}($message);

                return;
            } catch (\Throwable) {
                // 应用容器未启动 / 无日志绑定：降级 error_log
            }
        }

        // 3) 最后兜底：绝不因日志本身失败而影响调用方
        error_log(sprintf('[workflow-engine][%s] %s', strtoupper($level), $message));
    }
}
