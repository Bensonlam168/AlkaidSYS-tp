<?php

declare(strict_types=1);

namespace app\exception;

use app\constant\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * 站点标识非法异常（输入校验失败的显式失败出口）
 *
 * ## 与 `TenantContextMissingException` 的关键差异（**不要照抄**）
 *
 * 租户与站点在本仓**不同构**，历史实现把两者写成同一种形状（`tenantId(?int $default = 1)`
 * 与 `siteId(?int $default = 0)`），是缺陷来源之一：
 *
 *   - **租户**：解析不出 = 没有上下文 = 必须失败（fail-closed）。因为「拿不到租户」
 *     静默变成「按租户 1 读写」是一次跨租户数据越界（见 `TenantContextMissingException`）。
 *   - **站点**：`site_id = 0` 是本仓**有语义的「全局 / 无站点」桶**，不是「缺失标记」：
 *     `infrastructure/Lowcode/Collection/Service/TableNameGenerator.php` 与
 *     `infrastructure/Lowcode/Schema/SchemaVersionService.php` 把 0 当业务默认值；
 *     `app\model\BaseModel::scopeSite()` 是 `where('site_id', $siteId)`（真过滤，0 也照过滤）；
 *     且绝大多数请求本来就不带 `X-Site-ID`。
 *
 * 因此「无站点」**不是错误**，默认回落到 0 即可（见 `app\Request::siteId()`）。
 * 本异常只处理「**调用方提供了站点标识，但标识本身非法**」这一种情况：
 *
 *   1. `REASON_MALFORMED`（格式错）：`X-Site-ID: abc`、`1.5`、`1e3`、超长串等，
 *      历史实现里 `(int) 'abc' === 0`，会被**静默当成全局桶 0** —— 与"显式传 0"不可区分。
 *   2. `REASON_NEGATIVE`（负数）：`X-Site-ID: -5`。历史实现连校验都没有，
 *      `-5` 会被原样 `setSiteId(-5)`，随后落进 `where('site_id', -5)` 这种永远匹配不到行的条件
 *      （查询静默变空）—— 这是一个从未被校验过的外部输入。
 *
 * ## 第二类：标识格式合法，但站点在库里的归属/状态不允许继续（归属校验）
 *
 * 历史实现只校验格式，`X-Site-ID: 999999`（不存在）、`X-Site-ID: <别的租户的站点>`
 * 都能一路放行到业务层，靠后续仓储的 `where('site_id', ...)` 撞空结果来「兜底」—— 那不是隔离，
 * 只是查不到数据。现在这几种情况在入口就拒绝：
 *
 *   3. `REASON_SITE_NOT_FOUND`       —— `sites` 表里没有这个 id（纯猜测 / 已删站点）；
 *   4. `REASON_SITE_TENANT_MISMATCH` —— 站点存在，但 `sites.tenant_id` ≠ 当前请求租户（跨租户探测）；
 *   5. `REASON_SITE_UNUSABLE`        —— 站点属于本租户，但 `status` 不是 `active`
 *      （`inactive` / `maintenance`，含枚举外取值，一律 fail-closed）；
 *   6. `REASON_SITE_ID_OUT_OF_RANGE` —— 数值超出 `sites.id` 的 `int unsigned` 上界（4294967295），
 *      不可能命中任何行，直接拒绝而不发一次注定失败的查询；
 *   7. `REASON_TENANT_CONTEXT_MISSING` —— 走到站点归属校验时租户上下文仍为空
 *      （正常链路上 `TenantIdentify` 在前，理论上不会发生；空了就**拒绝**，不静默放过）。
 *
 * ### 为什么「不存在」与「属于别的租户」对外文案完全一致
 *
 * `tenant-parameter-contract.md` §3.3 的结论：对外区分「不存在(404)」与「不是你的(403)」
 * 等于给攻击者一个**站点存在性探测器**。`SiteIdentify` 在 `Auth` 之前执行（此时调用方甚至
 * 还没认证），所以这里更严格：`REASON_SITE_NOT_FOUND` 与 `REASON_SITE_TENANT_MISMATCH`
 * 走**同一句**响应文案（「站点不存在，或不属于当前租户」），HTTP 状态码与业务码也完全一致
 * （403 / 2002）。两者的区别只写在服务端日志的 reason 与日志 tag 里，用于排障。
 *
 * 对外映射：HTTP 403 + 业务码 2002（`ErrorCode::FORBIDDEN`），与 `TenantIdentify` 对
 * `invalid_tenant_header` 的处理**保持一致**（不新增错误码）。
 * 理由：本仓 `X-Tenant-ID` / `X-Site-ID` 都属于「调用方无权以该身份继续」的入口拒绝，
 * 统一走 2002 可让前端/Axios 的既有错误分支继续生效。
 *
 * @see \app\middleware\SiteIdentify 唯一的校验入口（拒绝请求的地方）
 * @see \app\Request::siteId() 无站点时回落 0（全局桶），不抛本异常
 * @see \app\Request::siteIdOrNull() 「显式 0」与「未提供」的区分入口
 * @see \app\exception\TenantContextMissingException 租户侧的 fail-closed（语义不同）
 * @package app\exception
 */
class InvalidSiteContextException extends RuntimeException
{
    /**
     * 格式错误：不是非负整数（`abc` / `1.5` / ` 5` / `0x10` 等）
     */
    public const REASON_MALFORMED = 'malformed';

    /**
     * 负数：`-5` / `-1` 等（历史上会被原样写进 `where('site_id', -5)`）
     */
    public const REASON_NEGATIVE = 'negative';

    /**
     * 站点不存在：`sites` 表里没有这个 id
     */
    public const REASON_SITE_NOT_FOUND = 'site_not_found';

    /**
     * 站点属于别的租户：`sites.tenant_id` ≠ 当前请求租户
     */
    public const REASON_SITE_TENANT_MISMATCH = 'site_tenant_mismatch';

    /**
     * 站点不可用：`sites.status` 不是 `active`（`inactive` / `maintenance` / 枚举外取值）
     */
    public const REASON_SITE_UNUSABLE = 'site_unusable';

    /**
     * 站点 ID 超出 `sites.id` 的 `int unsigned` 上界
     */
    public const REASON_SITE_ID_OUT_OF_RANGE = 'site_id_out_of_range';

    /**
     * 到达站点归属校验时租户上下文为空（正常链路不该发生，发生即拒绝）
     */
    public const REASON_TENANT_CONTEXT_MISSING = 'tenant_context_missing';

    /**
     * 触发原因（取 `REASON_*` 常量）
     *
     * 供 `app\ExceptionHandle` 决定日志 tag（各类原因的排查方向不同：
     * 格式错多半是前端把 `'0'` 之外的字符串塞进 header；负数多半是把 `-1` 当"未设置"哨兵；
     * `site_not_found` 多半是调用方写死了一个不存在的站点 id；
     * `site_tenant_mismatch` 是跨租户探测或租户切换后遗留的旧站点 id；
     * `site_unusable` 是站点被停用 / 维护，属于运营动作而非调用方缺陷）。
     */
    private string $reason;

    /**
     * 原始请求头取值（已截断，仅用于日志与响应提示）
     */
    private string $rawValue;

    /**
     * @param string         $message  可操作的中文说明（写明合法取值与怎么改）
     * @param int            $code     业务错误码（对外统一 2002）
     * @param string         $reason   触发原因，取 REASON_* 常量
     * @param string         $rawValue 原始请求头取值
     * @param Throwable|null $previous 原始异常
     */
    public function __construct(
        string $message,
        int $code = ErrorCode::FORBIDDEN,
        string $reason = self::REASON_MALFORMED,
        string $rawValue = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->reason   = $reason;
        $this->rawValue = $rawValue;
    }

    /**
     * 获取触发原因 | Get failure reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 获取原始请求头取值 | Get raw header value
     */
    public function getRawValue(): string
    {
        return $this->rawValue;
    }

    /**
     * 格式错误：`X-Site-ID` 不是非负整数
     *
     * @param string         $rawValue 原始请求头取值
     * @param string         $path     当前请求路径（便于日志里直接定位链路）
     * @param Throwable|null $previous 原始异常
     */
    public static function forMalformedHeader(string $rawValue, string $path = '', ?Throwable $previous = null): self
    {
        $message = '站点标识非法（invalid site context）：请求头 `X-Site-ID` 必须是 **0 或正整数**'
            . '（0 表示「全局 / 无站点」桶，是合法取值），当前取值 '
            . self::describeRaw($rawValue) . ' 不是合法整数。'
            . '说明：「不带 X-Site-ID」是允许的（按 0 处理）；只有**带了但不合法**才会被拒绝。'
            . '修复方式：调用方要么去掉该请求头，要么传纯数字（如 `0`、`7`）。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::FORBIDDEN, self::REASON_MALFORMED, $rawValue, $previous);
    }

    /**
     * 负数：`X-Site-ID` 是负整数
     *
     * 负数不是「未设置」的合法写法：`site_id` 在库里是无符号语义的分区值，
     * `where('site_id', -5)` 永远匹配不到有效行，只会让查询静默变空。
     * 「未设置」请直接**不发送**该请求头（服务端按 0 处理）。
     *
     * @param string         $rawValue 原始请求头取值
     * @param string         $path     当前请求路径
     * @param Throwable|null $previous 原始异常
     */
    public static function forNegativeHeader(string $rawValue, string $path = '', ?Throwable $previous = null): self
    {
        $message = '站点标识非法（invalid site context）：请求头 `X-Site-ID` 不能为负数，当前取值 '
            . self::describeRaw($rawValue) . '。'
            . '站点 ID 只接受 0（「全局 / 无站点」桶）或正整数。'
            . '若想表达「没有指定站点」，请**不要发送**该请求头（服务端按 0 处理），'
            . '不要用 `-1` 之类的哨兵值 —— 那会让 `where(\'site_id\', -1)` 静默查不到任何数据。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::FORBIDDEN, self::REASON_NEGATIVE, $rawValue, $previous);
    }

    /**
     * 站点不存在：`sites` 表里没有这个 id
     *
     * ⚠️ 对外文案与 `forForeignSite()` **完全一致**（见类注释「为什么不存在与属于别的租户
     * 对外文案完全一致」）—— 不要在这里加「不存在」字样，否则调用方就能用响应正文枚举站点 id。
     * 区别只体现在 `getReason()`（服务端日志 / `ExceptionHandle` 的日志 tag）上。
     *
     * @param string         $rawValue 原始请求头取值
     * @param int            $siteId   解析出的站点 ID
     * @param string         $path     当前请求路径
     * @param Throwable|null $previous 原始异常
     */
    public static function forUnknownSite(string $rawValue, int $siteId, string $path = '', ?Throwable $previous = null): self
    {
        return new self(
            self::unresolvedSiteMessage($siteId, $path),
            ErrorCode::FORBIDDEN,
            self::REASON_SITE_NOT_FOUND,
            $rawValue,
            $previous
        );
    }

    /**
     * 站点属于别的租户：`sites.tenant_id` ≠ 当前请求租户
     *
     * 属于「跨租户探测」这一类：租户 A 拿着租户 B 的 `site_id` 访问。对外文案与
     * `forUnknownSite()` 一致（不暴露该 id 真实存在），reason 不同以便排障。
     *
     * @param string         $rawValue 原始请求头取值
     * @param int            $siteId   解析出的站点 ID
     * @param string         $path     当前请求路径
     * @param Throwable|null $previous 原始异常
     */
    public static function forForeignSite(string $rawValue, int $siteId, string $path = '', ?Throwable $previous = null): self
    {
        return new self(
            self::unresolvedSiteMessage($siteId, $path),
            ErrorCode::FORBIDDEN,
            self::REASON_SITE_TENANT_MISMATCH,
            $rawValue,
            $previous
        );
    }

    /**
     * 站点不可用：属于本租户，但 `status` 不是 `active`
     *
     * 这里可以如实告知状态：地址里的 `site_id` 本来就是调用方自己租户的站点，
     * 不存在「泄露他人存在性」的问题，运营也能据此判断是等维护结束还是改用其它站点。
     *
     * @param string         $rawValue 原始请求头取值
     * @param int            $siteId   解析出的站点 ID
     * @param string         $status   站点当前状态（`inactive` / `maintenance` / 空串等）
     * @param string         $path     当前请求路径
     * @param Throwable|null $previous 原始异常
     */
    public static function forUnusableSite(
        string $rawValue,
        int $siteId,
        string $status,
        string $path = '',
        ?Throwable $previous = null
    ): self {
        $message = '站点当前不可用（invalid site context）：站点 ' . $siteId . ' 属于当前租户，但状态为 '
            . ($status === '' ? '（未知）' : '`' . $status . '`') . '，只有 `active` 的站点可以接收请求。'
            . '修复方式：请站点管理员把该站点状态改回 `active`；'
            . '或在 `X-Site-ID` 中改用本租户内其它 `active` 的站点。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::FORBIDDEN, self::REASON_SITE_UNUSABLE, $rawValue, $previous);
    }

    /**
     * 站点 ID 超出 `sites.id` 的取值范围（`int unsigned`，上界 4294967295）
     *
     * 为什么单独成一种原因，而不是笼统算「不存在」：超出上界的数值**不可能**命中任何行，
     * 发一次注定失败的查询只是浪费一次数据库往返；而且 `(int)` 强转对超长数字串会溢出成
     * `PHP_INT_MAX`，把「十万位的垃圾输入」变成「一个看起来正常的巨大 id」，掩盖真实问题。
     *
     * @param string         $rawValue  原始请求头取值
     * @param int            $maxSiteId 允许的最大站点 ID
     * @param string         $path      当前请求路径
     * @param Throwable|null $previous  原始异常
     */
    public static function forOutOfRangeSiteId(
        string $rawValue,
        int $maxSiteId,
        string $path = '',
        ?Throwable $previous = null
    ): self {
        $message = '站点标识非法（invalid site context）：请求头 `X-Site-ID` 超出站点 ID 的取值范围'
            . '（1 ~ ' . $maxSiteId . '，即无符号 32 位整数上界），当前取值 '
            . self::describeRaw($rawValue) . '。'
            . '该取值不可能对应任何站点，服务端不会为此发起查询。'
            . '修复方式：传真实存在的站点 ID，或**不发送**该请求头（按全局桶 site_id=0 处理）。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::FORBIDDEN, self::REASON_SITE_ID_OUT_OF_RANGE, $rawValue, $previous);
    }

    /**
     * 租户上下文缺失：无法校验站点归属
     *
     * 正常链路上 `TenantIdentify` 在 `SiteIdentify` 之前执行，租户一定已写入；
     * 走到这里说明中间件顺序被改动、或有人绕过了租户识别直接调用本中间件。
     * 此时**不能**跳过归属校验放行（那就是「带了 site_id 却不校验」的老行为），
     * 也不能假装租户是 1 —— 只能拒绝并留下可 grep 的原因。
     *
     * @param string         $rawValue 原始请求头取值
     * @param string         $path     当前请求路径
     * @param Throwable|null $previous 原始异常
     */
    public static function forMissingTenantContext(string $rawValue, string $path = '', ?Throwable $previous = null): self
    {
        $message = '站点归属无法校验（invalid site context）：请求带了 `X-Site-ID`，'
            . '但当前请求没有可用的租户上下文（`TenantIdentify` 未执行或未写入租户）。'
            . '拒绝继续，避免在「不知道该站点属于谁」的情况下放行。'
            . '修复方式：确认全局中间件顺序为 `TenantIdentify` → `SiteIdentify`；'
            . '或**不发送** `X-Site-ID`（按全局桶 site_id=0 处理）。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return new self($message, ErrorCode::FORBIDDEN, self::REASON_TENANT_CONTEXT_MISSING, $rawValue, $previous);
    }

    /**
     * 「站点无法解析」的统一对外文案（`site_not_found` 与 `site_tenant_mismatch` 共用）
     *
     * 刻意**只**出现在这两个工厂方法里：两处文案必须逐字节相同，否则「不存在」与
     * 「属于别的租户」就能被调用方从响应正文里区分出来（站点存在性探测）。
     *
     * @param int    $siteId 解析出的站点 ID
     * @param string $path   当前请求路径
     */
    private static function unresolvedSiteMessage(int $siteId, string $path): string
    {
        $message = '站点不可用（invalid site context）：站点 ' . $siteId
            . ' 不存在，或不属于当前租户。'
            . '站点归属以服务端 `sites` 表为准（`sites.tenant_id` 必须等于当前请求租户），'
            . '调用方无法通过该请求头访问其它租户的站点。'
            . '修复方式：请使用本租户下真实存在的站点 ID；'
            . '若确实不需要站点维度，请**不要发送** `X-Site-ID`（服务端按全局桶 site_id=0 处理）。';

        if ($path !== '') {
            $message .= '（请求路径：' . $path . '）';
        }

        return $message;
    }

    /**
     * 把原始取值渲染成安全、可读的片段
     *
     * - 截断到 32 字符，避免超长 header 把日志/响应撑爆；
     * - 用 `var_export` 加引号，区分 `''`（空串）、`'0'`、`' 0'`（带空格）等情况。
     */
    private static function describeRaw(string $rawValue): string
    {
        if ($rawValue === '') {
            return '（空串）';
        }

        $shown = mb_strlen($rawValue) > 32 ? mb_substr($rawValue, 0, 32) . '…' : $rawValue;

        return var_export($shown, true);
    }
}
