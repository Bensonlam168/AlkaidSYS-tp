<?php

declare(strict_types=1);

namespace app;

use app\exception\TenantContextMissingException;
use Infrastructure\User\Repository\UserRepository;
use think\facade\Db;
use think\facade\Log;
use think\Request as ThinkRequest;

/**
 * Custom Request Class | 自定义请求类
 *
 * Extends ThinkPHP Request to provide multi-tenant context methods.
 * 扩展ThinkPHP Request提供多租户上下文方法。
 *
 * @package app
 */
class Request extends ThinkRequest
{
    /**
     * Current tenant ID | 当前租户ID
     * @var int|null
     */
    protected $tenantId;

    /**
     * Current site ID | 当前站点ID
     * @var int|null
     */
    protected $siteId;

    /**
     * Current user ID | 当前用户ID
     * @var int|null
     */
    protected $userId;

    /**
     * Current trace ID | 当前请求追踪ID
     * @var string|null
     */
    protected $traceId;

    /**
     * Rate limit flag | 当前请求是否被限流
     * @var bool
     */
    protected bool $rateLimited = false;

    /**
     * Rate limit metadata | 限流元信息（命中 scope、limit、period 等）
     * @var array|null
     */
    protected ?array $rateLimitInfo = null;

    /**
     * Current user groups | 当前用户的用户组
     *
     * 本项目「用户组」映射为当前用户在当前租户下的角色 slug（如 admin / user），
     * 系统没有独立的 groups / user_groups 表。null 表示尚未解析。
     *
     * @var array|null
     */
    protected ?array $userGroups = null;



    /**
     * Get tenant ID (strict / fail-closed) | 获取租户ID（严格模式，失败即抛异常）
     *
     * Resolves tenant ID from:
     * 1. Previously set tenant ID (from TenantIdentify / Auth middleware)
     * 2. X-Tenant-ID header (must be a positive integer)
     *
     * 解析租户ID来源（严格按优先级，**没有第三档兜底**）：
     * 1. 已被 `setTenantId()` 写入的租户ID（正常来自 `TenantIdentify` / `Auth` 中间件）
     * 2. `X-Tenant-ID` 请求头（必须是正整数）
     *
     * 解析不出来时**抛异常**而不是回落到默认租户 —— 历史实现带 `?int $default = 1`
     * 兜底，会让「拿不到租户」静默变成「按租户 1 读写」，在多租户下属于数据越界隐患。
     * 「默认租户」现在只由 `app\middleware\TenantIdentify::getDefaultTenantId()` 一处决定，
     * 且必须由 `DEFAULT_TENANT_ID` 显式配置。
     *
     * 确实允许「无租户」的调用点（例如只写日志/审计维度）请改用 `tenantIdOrNull()`。
     *
     * @return int 当前租户ID（可能为 0 或负数，仅当调用方自行 `setTenantId()` 写入时）
     * @throws TenantContextMissingException 既无已设值、也无有效请求头时
     */
    public function tenantId(): int
    {
        $tenantId = $this->tenantIdOrNull();

        if ($tenantId === null) {
            throw TenantContextMissingException::forRequest($this->pathinfo());
        }

        return $tenantId;
    }

    /**
     * Get tenant ID or null | 获取租户ID（解析不出时返回 null，不抛异常）
     *
     * 仅「确实能处理无租户上下文」的调用点可以使用本方法（例如日志/审计字段、
     * 限流标识符兜底）。业务读写路径一律用 `tenantId()`，让缺失显式失败。
     *
     * @return int|null 解析出的租户ID；既无已设值、也无有效 `X-Tenant-ID` 时返回 null
     */
    public function tenantIdOrNull(): ?int
    {
        // 1) 已被中间件写入 → 直接返回（含调用方刻意写入的 0 / 负数）
        if ($this->tenantId !== null) {
            return $this->tenantId;
        }

        // 2) 尝试从 header 获取 | Try to get from header
        $rawHeader = $this->header('X-Tenant-ID');

        // 头缺失 / 空串 / 非正整数 → 视为「没有租户上下文」，不写入实例属性
        if ($rawHeader === null || $rawHeader === '') {
            return null;
        }

        $tenantId = (int) $rawHeader;

        if ($tenantId <= 0) {
            return null;
        }

        $this->tenantId = $tenantId;

        return $tenantId;
    }

    /**
     * Set tenant ID | 设置租户ID
     *
     * @param int $tenantId
     * @return self
     */
    public function setTenantId(int $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Get site ID | 获取站点ID（无站点时返回 0 = 全局桶，**刻意不抛异常**）
     *
     * 解析优先级（**没有 `-1` 哨兵、也没有 `$default` 参数**）：
     * 1. 已被 `setSiteId()` 写入的值（正常来自 `SiteIdentify` 中间件；含刻意写入的 0）
     * 2. `X-Site-ID` 请求头（必须是非负整数，即 `0` 或正整数）
     * 3. 两者都没有 → `0`
     *
     * 为什么**不**照抄 `tenantId()` 的 fail-closed —— 这是语义差异，不是遗漏：
     *   - **租户**：`tenant_id = 0` 不是租户（`tenants.id` 从 1 开始）。解析不出租户若静默回落到
     *     租户 1，等于按别人的租户读写，属于跨租户越界 → 必须抛
     *     `TenantContextMissingException`。
     *   - **站点**：`site_id = 0` 是**有语义的「全局 / 无站点」桶**，不是缺失标记：
     *     `infrastructure/Lowcode/Collection/Service/TableNameGenerator.php` 与
     *     `infrastructure/Lowcode/Schema/SchemaVersionService.php` 把 0 当业务默认值；
     *     `app\model\BaseModel::scopeSite()` 是 `where('site_id', $siteId)`（0 也照样真过滤）；
     *     而且绝大多数请求本来就不带 `X-Site-ID`。
     *     「无站点 → 抛异常」会打断几乎所有低代码请求，因此这里回落 0。
     *
     * 真正的缺陷在**输入校验**（历史实现把 `abc` 静默当 0、把 `-5` 原样写进
     * `where('site_id', -5)`），已由 `app\middleware\SiteIdentify` 收敛为入口 403。
     *
     * 需要区分「显式 0」与「本次请求没有站点维度」时，用 `siteIdOrNull()`。
     *
     * @return int 当前站点ID；0 表示「全局 / 无站点」桶
     */
    public function siteId(): int
    {
        return $this->siteIdOrNull() ?? 0;
    }

    /**
     * Get site ID or null | 获取站点ID（**区分「显式 0」与「未提供」**）
     *
     * 这是 `siteId()` 的可空版本，两种返回值语义不同，不要混用：
     *   - `0`   ：调用方/请求头**明确**指定了「全局 / 无站点」桶；
     *   - `null`：本次请求**根本没有**提供站点维度（无 `X-Site-ID`，且无人调用过
     *             `setSiteId()`）。
     *
     * 业务读写路径请继续用 `siteId()`（返回 int，0 会真的参与 `where('site_id', 0)` 过滤）。
     * 只有「确实要如实区分『没给站点』」的地方才用本方法，例如：
     *   - `getTenantContext()` 的日志/诊断字段 —— 缺失就是缺失，不该伪造一个 0；
     *   - 事件 payload 里的可选 `site_id`（见 `FieldController` / `RelationshipController`），
     *     其下游 `FieldManager` / `RelationshipManager` 只把该值原样写进事件，不做过滤。
     *
     * ⚠️ **不要**把 `null` 喂给「`null` = 不过滤」的仓储方法（例如
     * `FormSchemaRepository::findByName($name, $tenantId, $siteId)` 里的
     * `if ($siteId !== null) where('site_id', $siteId)`）—— 那会把「本站点」放宽成
     * 「本租户内任意站点」，属于站点维度越权。这类位置必须传 `siteId()`。
     *
     * @return int|null 显式站点ID（含 0）；既无已设值、也无合法 `X-Site-ID` 时返回 null
     */
    public function siteIdOrNull(): ?int
    {
        // 1) 已被中间件 / 调用方写入 → 直接返回（含刻意写入的 0）
        if ($this->siteId !== null) {
            return $this->siteId;
        }

        // 2) 尝试从 header 获取 | Try to get from header
        $rawHeader = $this->header('X-Site-ID');

        // 头缺失 / 空串 → 「本次请求没有提供站点维度」，不写入实例属性
        if ($rawHeader === null || $rawHeader === '') {
            return null;
        }

        // 非标量（例如重复请求头被框架解析成数组）直接视为「未提供」，
        // 不做 `(string)` 强制转换 —— 那会在数组上触发 "Array to string conversion" 告警。
        $raw = is_scalar($rawHeader) ? (string) $rawHeader : '';

        // 只接受非负整数：`abc` / `-5` / ` 7` / `1.5` 一律视为「未提供」。
        //
        // 这里是**最后一道兜底** —— 正常 HTTP 链路上 `SiteIdentify` 已在入口 403 拒绝
        // 非法 header；但调用链也可能绕过中间件（CLI / 队列 / 测试直调控制器），
        // 此时绝不能把脏值塞进 `where('site_id', ...)`，宁可回落到全局桶 0。
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $siteId = (int) $raw;

        $this->siteId = $siteId;

        return $siteId;
    }

    /**
     * Set site ID | 设置站点ID
     *
     * @param int $siteId
     * @return self
     */
    public function setSiteId(int $siteId): self
    {
        $this->siteId = $siteId;
        return $this;
    }

    /**
     * Get user ID | 获取用户ID
     *
     * Resolves user ID from:
     * 1. Previously set user ID (from middleware)
     *
     * 解析用户ID来源：
     * 1. 之前设置的用户ID（来自中间件）
     *
     * 说明：JWT 解析由 Auth 中间件完成，此处不再重复解析。
     *
     * @return int|null
     */
    public function userId(): ?int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }

        return null;
    }

    /**
     * Set user ID | 设置用户ID
     *
     * @param int|null $userId
     * @return self
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Get Bearer token from Authorization header | 从Authorization头获取Bearer token
     *
     * @return string|null
     */
    protected function getToken(): ?string
    {
        $authorization = $this->header('Authorization', '');

        if (empty($authorization)) {
            return null;
        }

        // Extract Bearer token | 提取Bearer token
        if (strpos($authorization, 'Bearer ') === 0) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * Get trace ID | 获取 Trace ID
     *
     * @return string|null
     */

    public function traceId(): ?string
    {
        return $this->traceId ?? null;
    }

    /**
     * Get trace ID (alias) | 获取 Trace ID（别名）
     *
     * 为中间件与监听器提供统一的 getTraceId() 接口，兼容现有调用。
     *
     * @return string|null
     */
    public function getTraceId(): ?string
    {
        return $this->traceId();
    }

    /**
     * Set rate limit state | 设置限流状态
     *
     * @param bool  $limited 是否已被限流 | Whether the request has been rate limited
     * @param array $info    限流元信息（命中 scope / limit / period 等）| Rate limit metadata
     * @return self
     */
    public function setRateLimited(bool $limited, array $info = []): self
    {
        $this->rateLimited   = $limited;
        $this->rateLimitInfo = $limited ? $info : null;

        return $this;
    }

    /**
     * Check if current request is rate limited.
     */
    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }

    /**
     * Get rate limit info for current request.
     */
    public function getRateLimitInfo(): ?array
    {
        return $this->rateLimitInfo;
    }

    /**
     * Set trace ID | 设置 Trace ID
     *
     * @param string $traceId
     * @return self
     */
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }


    /**
     * Get current user groups | 获取当前用户的用户组
     *
     * 说明（缺陷 R1）：
     *  - 历史代码用属性方式访问 `userGroups`，会落到 `think\Request::__get()`
     *    → `middleware('userGroups')`，恒为 null，导致「按组匹配候选任务」永远失效；
     *  - 本方法提供真实数据源：本项目「用户组」= 当前用户在当前租户下的角色 slug，
     *    与工作流任务 `candidate_groups`（哪些角色可以处理）的语义一致。
     *
     * 数据来源：UserRepository::getRoleIds()（已内置租户隔离）→ roles.slug。
     * 同一请求内结果会缓存在实例属性上，避免重复查库。
     *
     * 失败安全：未认证、无角色或查库异常时返回空数组（绝不让鉴权链路因查库失败而 500）。
     *
     * @return array 角色 slug 列表
     */
    public function userGroups(): array
    {
        // 实例级缓存：同一请求只解析一次
        if ($this->userGroups !== null) {
            return $this->userGroups;
        }

        $userId = $this->userId();

        // 未认证请求没有用户组概念
        if ($userId === null) {
            $this->userGroups = [];
            return $this->userGroups;
        }

        try {
            // 角色 ID 列表（仓储内部已按用户所属租户做隔离）
            $roleIds = (new UserRepository())->getRoleIds($userId);

            if (empty($roleIds)) {
                $this->userGroups = [];
                return $this->userGroups;
            }

            // 角色 ID → 角色 slug（对外即「用户组」标识）
            $slugs = Db::name('roles')->whereIn('id', $roleIds)->column('slug');

            // 过滤空值并去重，保证返回纯净的字符串列表
            $slugs = array_values(array_unique(array_filter(
                array_map(static fn ($slug) => (string) $slug, (array) $slugs),
                static fn (string $slug) => $slug !== ''
            )));

            $this->userGroups = $slugs;
        } catch (\Throwable $e) {
            // 失败安全：记录告警并退化为「无用户组」，不阻断请求
            Log::warning('Failed to resolve user groups', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            $this->userGroups = [];
        }

        return $this->userGroups;
    }

    /**
     * Set current user groups | 设置当前用户的用户组
     *
     * 便于测试注入，或未来由中间件在认证阶段一次性预置，避免控制器重复查库。
     *
     * @param array $groups 角色 slug 列表
     * @return self
     */
    public function setUserGroups(array $groups): self
    {
        $this->userGroups = array_values($groups);
        return $this;
    }

    /**
     * Check if current user is authenticated | 检查当前用户是否已认证
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Get current tenant context | 获取当前租户上下文
     *
     * 用途：**诊断/日志聚合**（`Auth` / `Permission` / `AccessLog` / `RateLimit` /
     * `SlowQueryListener` 在写日志前一次性取三个维度），不参与任何业务读写决策。
     *
     * 因此 `tenant_id` / `site_id` 都用**可空版本**：
     *   - `tenant_id` 用 `tenantIdOrNull()`：若用严格版 `tenantId()`，一旦租户上下文缺失，
     *     整个聚合会抛异常，调用方 catch 之后连本来能拿到的 `site_id` / `user_id` 也会一起丢掉 ——
     *     排障时最需要的信息反而消失；
     *   - `site_id` 用 `siteIdOrNull()`：这里要的是「**本次请求到底有没有站点维度**」，
     *     用 `siteId()` 会把「没带 X-Site-ID」伪装成「显式站点 0」，让排障时无法区分
     *     「请求没指定站点」与「请求指定了全局桶」。
     *
     * 业务代码要租户ID请直接用 `tenantId()`；要站点ID（参与过滤）请用 `siteId()`。
     *
     * @return array{tenant_id: int|null, site_id: int|null, user_id: int|null}
     */
    public function getTenantContext(): array
    {
        return [
            'tenant_id' => $this->tenantIdOrNull(),
            'site_id' => $this->siteIdOrNull(),
            'user_id' => $this->userId(),
        ];
    }
}
