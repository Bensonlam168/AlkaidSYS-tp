<?php

declare(strict_types=1);

namespace app\middleware;

use app\exception\InvalidSiteContextException;
use Closure;
use Infrastructure\Tenant\Repository\SiteRepository;
use think\facade\Log;
use think\Request;

/**
 * Site Identify Middleware | 站点识别中间件
 *
 * 职责（与 `TenantIdentify` 对齐形制，但**语义不同**）：
 *   1. 从 `X-Site-ID` 请求头解析站点标识，并**校验它必须是非负整数**
 *      （`0` 合法 = 「全局 / 无站点」桶）；非法值 → 直接拒绝请求；
 *   2. **归属校验**（本次新增）：`site_id > 0` 时查 `sites` 表，校验
 *      「存在 + 属于当前租户 + 状态可用」三项，任一不满足即拒绝；
 *   3. 合法值写入 `Request`（`setSiteId()`），供后续中间件与业务层使用；
 *   4. 请求未带 `X-Site-ID` 时**不写入任何值**，写一条 `[SITE_IDENTIFY_DEFAULT]`
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
 * ## 三档处理（`site_id` 决定走哪一档）
 *
 * | 输入 | 行为 |
 * | --- | --- |
 * | 无 `X-Site-ID` | 不写值 + `[SITE_IDENTIFY_DEFAULT]` 审计日志（`siteIdOrNull()` 仍返回 null） |
 * | `site_id === 0` | 放行，**不查库**（0 是「全局 / 无站点」桶，不是站点行） |
 * | `site_id > 0` | 查 `sites` 表，三项校验任一不过即 403 |
 *
 * ## 为什么归属校验是「先租户内查、再跨租户诊断查」两次
 *
 * 见 `Infrastructure\Tenant\Repository\SiteRepository` 的类注释与
 * `docs/technical-specs/multi-tenant/tenant-parameter-contract.md` §2.4：
 * **参与放行判断的查询必须带租户条件**；「这个 id 到底存不存在」这类信息只能用于
 * 选择拒绝原因（日志 reason），不能参与放行。因此：
 *   - `findContextById($siteId, $tenantId)` 命中且 `status = active` → 放行；
 *   - 未命中 → 再用 `findOwnershipHintUnscoped($siteId)` 区分 `site_not_found`
 *     与 `site_tenant_mismatch`（**两者对外文案与状态码完全一致**，只差日志里的 reason）。
 *
 * ## 性能结论：不缓存
 *
 * 带 `X-Site-ID` 的请求会多一次（或失败时两次）按主键的查询，`sites` 的 `id` 是主键、
 * 查询条件是常量等值，属于最便宜的索引查找；不带该请求头的请求**零额外开销**。
 * **刻意不做进程内 / 静态缓存**：`alkaid-backend` 是 Swoole 常驻进程，`static` 缓存会跨请求
 * 存活，而站点数据会变（停用 / 改名 / 维护），一旦缓存住 `active` 就等于让「停用站点」
 * 在 worker 重启前一直可用 —— 那是把一个安全校验变成一次安全假象。
 * 若将来确实要加缓存，必须走带 TTL + 站点写路径显式失效的共享缓存（Redis），且 TTL 足够短，
 * 不能是 `static`。
 *
 * 约束：本中间件**不调用** `$request->siteId()` / `siteIdOrNull()`（避免循环依赖 ——
 * 那两个方法本身要读同一份 header 状态），直接读原始请求头。
 *
 * @package app\middleware
 */
class SiteIdentify
{
    /**
     * 站点 ID 上界（与 `sites.id int unsigned` 对齐）
     *
     * 超出这个值不可能命中任何站点行；单独判出来是为了「不发注定失败的查询」
     * 并且避免超长数字串 `(int)` 溢出成 `PHP_INT_MAX` 后变成一个「看似正常的巨大 id」。
     */
    private const MAX_SITE_ID = 4294967295;

    /**
     * 站点仓储（惰性初始化，避免不带 `X-Site-ID` 的请求也构造一次）
     */
    private ?SiteRepository $siteRepository = null;

    /**
     * Handle request | 处理请求
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     * @throws InvalidSiteContextException `X-Site-ID` 非法：不是非负整数、超出范围，
     *                                     或站点不存在 / 不属于当前租户 / 不可用 / 租户上下文缺失
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

        // 情况 B1：数值超出 `sites.id int unsigned` 上界 → 直接拒绝，不发注定失败的查询。
        //
        // 先去掉前导零再判长度：`0000000001` 与 `1` 是同一个站点，不应因为多写了几个 0 被拒。
        // 长度判据（而不是先 `(int)` 再比较）是必需的：十万位的数字串 `(int)` 会溢出成
        // `PHP_INT_MAX`，把一个明显的垃圾输入伪装成一个「正常的巨大 id」。
        $digits = ltrim($raw, '0');
        if ($digits === '') {
            $digits = '0';
        }
        if (strlen($digits) > strlen((string) self::MAX_SITE_ID) || (int) $digits > self::MAX_SITE_ID) {
            $this->reject(
                $request,
                InvalidSiteContextException::forOutOfRangeSiteId($raw, self::MAX_SITE_ID, (string) $request->pathinfo())
            );
        }

        $siteId = (int) $digits;

        // 情况 B2：`0` = 「全局 / 无站点」桶 —— 有语义的合法取值，**不查库**
        //
        // 这里必须显式 `setSiteId(0)`（而不是不写）：调用方**明确**指定了全局桶，
        // `siteIdOrNull()` 应返回 0 而不是 null，与「没带请求头」区分开。
        if ($siteId === 0) {
            if (method_exists($request, 'setSiteId')) {
                $request->setSiteId(0);
            }

            return $next($request);
        }

        // 情况 B3：`site_id > 0` → 归属校验（存在 + 属于当前租户 + 状态可用）
        $this->assertSiteUsable($request, $siteId, $raw);

        // 校验通过：写入站点维度
        if (method_exists($request, 'setSiteId')) {
            $request->setSiteId($siteId);
        }

        // Continue to next middleware | 继续到下一个中间件
        return $next($request);
    }

    /**
     * 站点归属校验：存在 + 属于当前租户 + 状态可用
     *
     * 任一不满足即抛 `InvalidSiteContextException`（403 / 2002）；**全部满足才返回**，
     * 调用方拿到返回值即代表「可以 `setSiteId()`」。
     *
     * 顺序与理由：
     *   1. 先取租户上下文。为空（理论上不该发生：`TenantIdentify` 在前）→ 拒绝，
     *      **不静默放过** —— 「带了 site_id 但不校验」正是本次要消灭的老行为；
     *   2. 租户内查站点（带 `tenant_id` 条件）。命中后判 `status`；
     *   3. 未命中 → 再查一次归属（无租户条件，**仅用于选择 reason**）：
     *      查到 = 属于别的租户（`site_tenant_mismatch`），查不到 = 不存在（`site_not_found`）。
     *
     * @param Request $request 当前请求
     * @param int     $siteId  解析出的站点 ID（> 0）
     * @param string  $raw     原始请求头取值（仅用于异常文案与日志）
     * @return void
     * @throws InvalidSiteContextException 站点不存在 / 不属于当前租户 / 不可用 / 租户上下文缺失
     */
    private function assertSiteUsable(Request $request, int $siteId, string $raw): void
    {
        $path = (string) $request->pathinfo();

        // 1) 租户上下文（由 `TenantIdentify` 写入；这里只读，不写）
        $tenantId = method_exists($request, 'tenantIdOrNull') ? $request->tenantIdOrNull() : null;

        if ($tenantId === null || $tenantId <= 0) {
            $this->reject(
                $request,
                InvalidSiteContextException::forMissingTenantContext($raw, $path),
                $siteId,
                null
            );
        }

        $repository = $this->getSiteRepository();

        // 2) 租户内读取 —— **唯一参与放行判断**的查询
        $context = $repository->findContextById($siteId, $tenantId);

        if ($context === null) {
            // 3) 诊断读（无租户条件）：只决定拒绝原因，不参与放行
            $hint = $repository->findOwnershipHintUnscoped($siteId);

            if ($hint === null) {
                $this->reject(
                    $request,
                    InvalidSiteContextException::forUnknownSite($raw, $siteId, $path),
                    $siteId,
                    $tenantId
                );
            }

            $this->reject(
                $request,
                InvalidSiteContextException::forForeignSite($raw, $siteId, $path),
                $siteId,
                $tenantId
            );
        }

        // 4) 状态可用性：只有 `active` 放行（枚举外取值一律当「不可用」，fail-closed）
        if ($context['status'] !== SiteRepository::STATUS_ACTIVE) {
            $this->reject(
                $request,
                InvalidSiteContextException::forUnusableSite($raw, $siteId, $context['status'], $path),
                $siteId,
                $tenantId,
                $context['status']
            );
        }
    }

    /**
     * 惰性获取站点仓储
     */
    private function getSiteRepository(): SiteRepository
    {
        if ($this->siteRepository === null) {
            $this->siteRepository = new SiteRepository();
        }

        return $this->siteRepository;
    }

    /**
     * 统一拒绝出口（审计日志 + 抛出 403/2002）
     *
     * 与 `TenantIdentify::reject()` 同形制：先记 warning 审计日志，再抛异常。
     * 日志里同时带 reason、原始取值与（归属校验阶段才有的）site_id / tenant_id / status，
     * 便于区分这几类调用方缺陷：
     *   - 把字符串塞进 header（`malformed`）、把 `-1` 当「未设置」哨兵（`negative`）；
     *   - 写死了一个不存在的站点 id（`site_not_found`）；
     *   - 租户切换后遗留旧租户的 site_id，或故意跨租户探测（`site_tenant_mismatch`）；
     *   - 站点被停用 / 维护（`site_unusable`，属于运营动作）。
     *
     * 日志正文（`msg`）里**直接拼** reason / site_id / tenant_id：ThinkPHP 8 的文件通道只输出
     * time/type/msg，context 数组不会落盘（见 `app/middleware/Auth.php` 的 M7 说明）。
     *
     * @param Request                     $request   当前请求
     * @param InvalidSiteContextException $exception 已构造好的异常（含 reason / rawValue）
     * @param int|null                    $siteId    解析出的站点 ID（格式校验阶段为 null）
     * @param int|null                    $tenantId  当前请求租户（租户缺失时为 null）
     * @param string|null                 $siteStatus 站点状态（仅 `site_unusable` 时有值）
     * @return never
     * @throws InvalidSiteContextException
     */
    private function reject(
        Request $request,
        InvalidSiteContextException $exception,
        ?int $siteId = null,
        ?int $tenantId = null,
        ?string $siteStatus = null
    ): void {
        $rawValue = $exception->getRawValue();

        $context = [
            'reason'     => $exception->getReason(),
            'site_id'    => $siteId,
            'tenant_id'  => $tenantId,
            'raw_value'  => mb_substr($rawValue, 0, 32),
            'ip'         => $request->ip(),
            'path'       => $request->pathinfo(),
            'method'     => $request->method(),
            'trace_id'   => method_exists($request, 'getTraceId') ? $request->getTraceId() : null,
        ];

        if ($siteStatus !== null) {
            $context['site_status'] = $siteStatus;
        }

        Log::warning(
            '[SITE_IDENTIFY_REJECTED] Site header rejected: reason=' . $exception->getReason()
            . ', site_id=' . ($siteId === null ? 'null' : (string) $siteId)
            . ', tenant_id=' . ($tenantId === null ? 'null' : (string) $tenantId)
            . ', X-Site-ID=' . var_export(mb_substr($rawValue, 0, 32), true),
            $context
        );

        throw $exception;
    }
}
