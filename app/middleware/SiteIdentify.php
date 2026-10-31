<?php

declare(strict_types=1);

namespace app\middleware;

use app\exception\InvalidSiteContextException;
use Closure;
use think\facade\Log;
use think\Request;

/**
 * Site Identify Middleware | 站点识别中间件
 *
 * 职责（与 `TenantIdentify` 对齐形制，但**语义不同**）：
 *   1. 从 `X-Site-ID` 请求头解析站点标识，并**校验它必须是非负整数**
 *      （`0` 合法 = 「全局 / 无站点」桶）；非法值 → 直接拒绝请求；
 *   2. 合法值写入 `Request`（`setSiteId()`），供后续中间件与业务层使用；
 *   3. 请求未带 `X-Site-ID` 时**不写入任何值**，写一条 `[SITE_IDENTIFY_DEFAULT]`
 *      审计日志，站点维度由 `Request::siteId()` 回落到 0（全局桶）。
 *
 * ## 为什么「无站点」不拒绝（与 `TenantIdentify` 的 fail-closed 不同）
 *
 * `site_id = 0` 在本仓是**有语义的分区值**，不是缺失标记：
 *   - `TableNameGenerator` / `SchemaVersionService` 把 0 当业务默认值；
 *   - `BaseModel::scopeSite()` 是 `where('site_id', $siteId)`（0 也照样真过滤）；
 *   - 绝大多数请求本来就不带 `X-Site-ID`。
 * 所以「无站点 → 抛异常」会打断几乎所有低代码请求，是错的。
 *
 * ## 本次收敛的真实缺陷：非法输入从未被校验
 *
 * 历史实现是 `(int) $request->header('X-Site-ID', '0')`，无任何校验：
 *   - `X-Site-ID: -5` → `setSiteId(-5)` → `where('site_id', -5)`，查询静默变空；
 *   - `X-Site-ID: abc` → `(int)'abc' === 0`，被**静默当成全局桶 0**，
 *     与「显式传 0」不可区分（也就不可能审计出「有人在乱传站点」）。
 * 现在两种情况都在入口按 `invalid_tenant_header` 的既有约定拒绝：HTTP 403 + 业务码 2002。
 *
 * 约束：本中间件**不调用** `$request->siteId()` / `siteIdOrNull()`（避免循环依赖 ——
 * 那两个方法本身要读同一份 header 状态），直接读原始请求头。
 *
 * @package app\middleware
 */
class SiteIdentify
{
    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     * @throws InvalidSiteContextException `X-Site-ID` 存在但不是非负整数时抛出
     */
    public function handle(Request $request, Closure $next)
    {
        // 读取原始请求头（不通过 $request->siteId()，避免循环依赖）
        $rawHeader = $request->header('X-Site-ID');

        // 情况 A：未提供请求头 → 站点维度按「全局桶 0」处理
        //
        // 这里**刻意不调用 setSiteId(0)**：让 Request 保持「未解析」状态，
        // 从而使 `siteIdOrNull()` 能区分「没带 X-Site-ID」（null）与「显式传 0」（0）。
        // `siteId()` 无论如何都会返回 0，所以对业务读写路径（含 `BaseModel::scopeSite()`
        // 的 `where('site_id', 0)`）行为完全不变。
        if ($rawHeader === null || $rawHeader === '') {
            // 审计日志：站点维度同样要可观测，与 [TENANT_IDENTIFY_DEFAULT] 对齐。
            // 注意 site_id 直接写进**日志正文**：ThinkPHP 8 的文件通道只输出
            // time/type/msg，context 数组不会落盘（见 app/middleware/Auth.php 的 M7 说明）。
            Log::info('[SITE_IDENTIFY_DEFAULT] 本次请求未带 X-Site-ID，站点维度按全局桶 site_id=0 处理', [
                'site_id'        => 0,
                'default_source' => 'Request::siteId() 的全局桶兜底（0 是合法业务值，不是缺失标记）',
                'ip'             => $request->ip(),
                'path'           => $request->pathinfo(),
                'method'         => $request->method(),
                'trace_id'       => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
            ]);

            return $next($request);
        }

        // 情况 B：显式提供请求头 → 必须是非负整数
        //
        // 非标量（重复请求头被框架解析成数组等）统一按「格式错误」处理，不做 `(string)` 强转，
        // 避免 "Array to string conversion" 告警；语义上也确实不可能是合法站点 ID。
        $raw = is_scalar($rawHeader) ? (string) $rawHeader : '[' . get_debug_type($rawHeader) . ']';

        // 不是「纯数字」时先区分「负数」与「其它非法格式」，两者排查方向不同
        if (preg_match('/^\d+$/', $raw) !== 1) {
            if (preg_match('/^-\d+$/', $raw) === 1) {
                $this->reject($request, InvalidSiteContextException::forNegativeHeader($raw, (string) $request->pathinfo()));
            }

            $this->reject($request, InvalidSiteContextException::forMalformedHeader($raw, (string) $request->pathinfo()));
        }

        // 校验通过：`0` 与正整数都是合法站点维度（0 = 全局桶）
        if (method_exists($request, 'setSiteId')) {
            $request->setSiteId((int) $raw);
        }

        // 验证站点属于租户的逻辑保留为后续增强点（TENANT-01 收敛范围之外）
        // 如需在后续阶段收紧站点权限控制，请先在设计文档中补充完整方案（含 sites 表归属模型），
        // 再在此处接入站点归属校验逻辑。

        // Continue to next middleware | 继续到下一个中间件
        return $next($request);
    }

    /**
     * 统一拒绝出口（审计日志 + 抛出 403/2002）
     *
     * 与 `TenantIdentify::reject()` 同形制：先记 warning 审计日志，再抛异常。
     * 日志里同时带 reason 与原始取值，便于区分「前端把字符串塞进 header」
     * 与「把 -1 当『未设置』哨兵」这两类调用方缺陷。
     *
     * @param Request                     $request   当前请求
     * @param InvalidSiteContextException $exception 已构造好的异常（含 reason / rawValue）
     * @return never
     * @throws InvalidSiteContextException
     */
    private function reject(Request $request, InvalidSiteContextException $exception): void
    {
        $rawValue = $exception->getRawValue();

        Log::warning('[SITE_IDENTIFY_REJECTED] Site header rejected: reason=' . $exception->getReason()
            . ', X-Site-ID=' . var_export(mb_substr($rawValue, 0, 32), true), [
            'reason'    => $exception->getReason(),
            'raw_value' => mb_substr($rawValue, 0, 32),
            'ip'        => $request->ip(),
            'path'      => $request->pathinfo(),
            'method'    => $request->method(),
            'trace_id'  => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
        ]);

        throw $exception;
    }
}
