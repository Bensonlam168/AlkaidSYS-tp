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
     * 触发原因（`REASON_MALFORMED` / `REASON_NEGATIVE`）
     *
     * 供 `app\ExceptionHandle` 决定日志 tag（两类原因的排查方向不同：
     * 格式错多半是前端把 `'0'` 之外的字符串塞进 header；负数多半是把 `-1` 当"未设置"哨兵）。
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
