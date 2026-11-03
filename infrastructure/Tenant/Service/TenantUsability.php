<?php

declare(strict_types=1);

namespace Infrastructure\Tenant\Service;

use Domain\Tenant\Model\Tenant;

/**
 * Tenant Usability Criteria | 租户可用性判据（全项目唯一实现）
 *
 * 背景（ER-04）：
 *   「租户是否可用」原先只在 `app\middleware\TenantIdentify::isUsable()` 里实现过一次，
 *   判据是「`status` 必须为 `active` 且 `expires_at` 未过期」。本次要把它同时用于：
 *     1. `TenantIdentify`（请求头指向的租户，未认证场景的弱信号）；
 *     2. `app\middleware\Auth`（JWT 中 `tenant_id` 指向的租户，已认证场景的权威来源）；
 *     3. `AuthController::login()` / `AuthController::refresh()`（会话起点与续期）。
 *   三处各写一份必然漂移（典型后果：某处补了 `expires_at`、另一处没补，
 *   于是「已过期租户」在一条链路上被拦住、在另一条链路上照常签发令牌）。
 *   因此把判据**提取到本类**，其余调用点一律委托，禁止就地重写。
 *
 * 判据（与 `TenantIdentify` 原实现逐字等价，行为零变化）：
 *   1. 租户行必须存在；
 *   2. `status` 必须是 `active`（`inactive` / `suspended` 均不可用）；
 *   3. `expires_at` 非空时，解析失败视为「不阻塞」（与历史实现一致），解析成功且早于当前时间即视为已过期。
 *
 * 为什么 `expires_at` 从 `toArray()` 取：
 *   `Domain\Tenant\Model\Tenant` 只有 `fromArray()` 写入 `expiresAt`、没有 getter，
 *   这是既有模型形状；此处沿用 `TenantIdentify` 的原取值方式，避免为了一个只读字段改动领域模型。
 *
 * 为什么是 `static`（与「禁止 static 缓存」不矛盾）：
 *   本类**不保存任何状态**，每次调用都基于传入的 `Tenant` 现算，
 *   不会像进程内缓存那样在 Swoole 常驻 worker 里跨请求存活。
 *   禁止的是「把 active 结果缓存住」，不是「用静态方法做纯函数判断」。
 *
 * @package Infrastructure\Tenant\Service
 */
final class TenantUsability
{
    /**
     * 原因码：租户行不存在
     */
    public const REASON_TENANT_NOT_FOUND = 'tenant_not_found';

    /**
     * 原因码：租户状态不可用（inactive / suspended）
     */
    public const REASON_TENANT_UNUSABLE = 'tenant_unusable';

    /**
     * 原因码：租户已过期（`expires_at` 早于当前时间）
     */
    public const REASON_TENANT_EXPIRED = 'tenant_expired';

    /**
     * 租户是否可用（存在 + active + 未过期）
     *
     * @param Tenant $tenant 已查出的租户实体
     * @return bool 可用返回 true
     */
    public static function isUsable(Tenant $tenant): bool
    {
        return self::unusableReason($tenant) === null;
    }

    /**
     * 判定租户不可用的原因（可用时返回 null）
     *
     * 返回 null 表示**可以放行**；返回字符串表示**必须拒绝**，且字符串只用于日志/审计，
     * 不直接对外暴露（避免泄露「某租户是否续费/被停用」这类运营信息）。
     *
     * 判定顺序（先状态、后时间）：「已停用 + 已过期」时以 `tenant_unusable` 归类，
     * 因为停用是人工动作、是主因；过期只是时间自然到期。两者对外文案完全一致。
     *
     * @param Tenant|null $tenant 租户实体（null = 查不到该租户行）
     * @return string|null 原因码；可用时返回 null
     */
    public static function unusableReason(?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return self::REASON_TENANT_NOT_FOUND;
        }

        if (!$tenant->isActive()) {
            return self::REASON_TENANT_UNUSABLE;
        }

        if (self::isExpired($tenant)) {
            return self::REASON_TENANT_EXPIRED;
        }

        return null;
    }

    /**
     * 租户是否已过期
     *
     * 与历史实现保持一致的边界语义：
     *   - `expires_at` 为空（null / 空串）→ 视为「不限期」；
     *   - `expires_at` 无法解析成时间戳 → 视为「不阻塞」（历史行为，避免脏数据把整个租户锁死）；
     *   - 解析成功且早于当前时间 → 已过期。
     *
     * @param Tenant $tenant 租户实体
     * @return bool 已过期返回 true
     */
    private static function isExpired(Tenant $tenant): bool
    {
        $expiresAt = $tenant->toArray()['expires_at'] ?? null;

        if (empty($expiresAt)) {
            return false;
        }

        $timestamp = strtotime((string) $expiresAt);

        return $timestamp !== false && $timestamp < time();
    }
}
