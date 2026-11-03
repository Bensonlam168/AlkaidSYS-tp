<?php

declare(strict_types=1);

namespace Infrastructure\Auth;

use Infrastructure\Tenant\Repository\TenantRepository;
use Infrastructure\Tenant\Service\TenantUsability;
use Infrastructure\User\Repository\UserRepository;

/**
 * Principal State Checker | 认证主体状态校验器（ER-04）
 *
 * 解决的问题：JWT 只证明「这张令牌在签发时是真的」，**不能证明**「令牌持有者现在还有效」。
 * 历史上 `app\middleware\Auth` 只校验签名/过期/类型，`AuthController::refresh()` 只校验
 * Refresh Token 自身的白名单，于是：
 *   - 管理员把用户置为 `inactive` / `locked`；
 *   - 或把租户置为 `inactive` / `suspended`、或租户 `expires_at` 已过期；
 * 之后该主体**已经签发的** Access/Refresh Token 在有效期内仍然完全可用（最长 2 小时 / 7 天）。
 *
 * 本类把「主体现在是否还允许持有会话」收敛成一处判据，供三条链路共用：
 *   1. `app\middleware\Auth`（每个受保护请求）；
 *   2. `AuthController::refresh()`（续期）；
 *   3. `AuthController::login()`（只用到 `checkTenant()`，用户状态由登录逻辑自身判定）。
 *
 * 判据：
 *   - 用户：在令牌给定的 `tenant_id` 范围内**必须仍存在**（`findById($userId, $tenantId)`），
 *     且 `status` 必须为 `active`；`inactive` / `locked` / 未知取值一律拒绝。
 *     用户被删除、或被改到别的租户，在这里都表现为「查不到」——这是**有意为之**：
 *     跨租户的「这个 id 到底存不存在」查询不参与放行判断（见
 *     `docs/technical-specs/multi-tenant/tenant-parameter-contract.md` 的口径），
 *     所以不额外发一次无租户条件的查询去区分「不存在」与「已换租户」，
 *     两者在日志里都记 `user_not_found`（见类注释末尾的取舍说明）。
 *   - 租户：存在 + `status = active` + `expires_at` 未过期，判据复用
 *     `Infrastructure\Tenant\Service\TenantUsability`（与 `TenantIdentify` 同一份实现）。
 *
 * ## 为什么这里**不做任何缓存**（性能与正确性的取舍）
 *
 * `alkaid-backend` 是 Swoole 常驻进程，`static` 属性会跨请求存活：一旦把 `active` 缓存住，
 * 「停用用户在 worker 重启前一直可用」——等于用一个进程内缓存把安全校验变成安全假象。
 * 共享缓存（Redis）虽然不跨进程，但必须配 TTL + 全部写路径显式失效才成立：
 * 用户/租户状态可被 `UserController::updateStatus`、`TenantController::update|toggleStatus`、
 * 甚至直接改库的运营动作修改，任何一处漏掉失效就会把「停用延迟」无限放大。
 * 因此本类**每次请求都真查两次主键索引**（`users`、`tenants`），换来「停用即生效（延迟 0）」；
 * 实测代价见 `docs/technical-specs/security/security-guidelines.zh-CN.md` §2.5。
 *
 * ## 原因码只进日志，不进响应体
 *
 * 本类的返回值只用于**日志/审计**（`logAuthFailure()` 的 `reason`）与内部判断，
 * 不得拼进对外 message：五个原因码拼起来足以让调用方区分
 * 「这个 user_id 不存在」与「存在但被停用」，属于账号存在性/运营状态泄露。
 * 对外统一返回 401 + 2001 + `error.session_invalid`（详见 `app\middleware\Auth`）。
 *
 * @package Infrastructure\Auth
 */
class PrincipalStateChecker
{
    /**
     * 原因码：令牌中的用户在所属租户内已不存在（被删除，或被改到其它租户）
     */
    public const REASON_USER_NOT_FOUND = 'user_not_found';

    /**
     * 原因码：用户状态为 `inactive`
     */
    public const REASON_USER_INACTIVE = 'user_inactive';

    /**
     * 原因码：用户状态为 `locked`
     */
    public const REASON_USER_LOCKED = 'user_locked';

    /**
     * 用户仓储
     */
    protected UserRepository $userRepository;

    /**
     * 租户仓储
     */
    protected TenantRepository $tenantRepository;

    /**
     * @param UserRepository|null $userRepository 用户仓储（可注入，便于单测）
     * @param TenantRepository|null $tenantRepository 租户仓储（可注入，便于单测）
     */
    public function __construct(?UserRepository $userRepository = null, ?TenantRepository $tenantRepository = null)
    {
        $this->userRepository = $userRepository ?? new UserRepository();
        $this->tenantRepository = $tenantRepository ?? new TenantRepository();
    }

    /**
     * 校验「用户 + 租户」整体可用（Auth 中间件与 refresh 链路使用）
     *
     * 短路顺序：先用户、后租户。用户已不可用时不再查租户 ——
     * 既省一次查询，也避免在日志里暴露「用户被停用时租户是否也停用」。
     *
     * @param int $userId 令牌中的用户 ID
     * @param int $tenantId 令牌中的租户 ID（权威租户范围）
     * @return string|null 原因码；主体可用时返回 null
     */
    public function check(int $userId, int $tenantId): ?string
    {
        return $this->checkUser($userId, $tenantId) ?? $this->checkTenant($tenantId);
    }

    /**
     * 校验用户是否仍存在且为 active
     *
     * @param int $userId 用户 ID
     * @param int $tenantId 租户 ID（必填，`findById()` 的租户隔离条件）
     * @return string|null 原因码；可用时返回 null
     */
    public function checkUser(int $userId, int $tenantId): ?string
    {
        $user = $this->userRepository->findById($userId, $tenantId);

        if ($user === null) {
            return self::REASON_USER_NOT_FOUND;
        }

        return self::statusReason($user->getStatus());
    }

    /**
     * 校验租户是否可用（存在 + active + 未过期）
     *
     * @param int $tenantId 租户 ID
     * @return string|null 原因码（`tenant_not_found` / `tenant_unusable` / `tenant_expired`）；可用时返回 null
     */
    public function checkTenant(int $tenantId): ?string
    {
        return TenantUsability::unusableReason($this->tenantRepository->findById($tenantId));
    }

    /**
     * 用户状态 → 原因码
     *
     * 未知状态（理论上枚举列不会出现，但脏数据/未来扩枚举都可能）一律按不可用处理（fail-closed），
     * 归类为 `user_inactive`：宁可多拒一个，也不要让一个「状态不认识」的账号继续持有会话。
     *
     * @param string $status `users.status` 当前值
     * @return string|null 原因码；`active` 返回 null
     */
    protected static function statusReason(string $status): ?string
    {
        return match ($status) {
            'active' => null,
            'locked' => self::REASON_USER_LOCKED,
            default => self::REASON_USER_INACTIVE,
        };
    }
}
