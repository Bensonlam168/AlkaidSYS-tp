<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\facade\Cache;
use think\facade\Log;

/**
 * 应用层限流中间件（固定时间窗口 / 令牌桶）。
 *
 * - 支持 user/tenant/ip/route 多维度限流；
 * - 支持 IP/用户/租户白名单；
 * - 使用 Redis/Cache 计数，异常时 fail-open 降级放行；
 * - 命中限流时返回 429，并在 Request 上打 rate_limited 标记，供 AccessLog / Nginx 使用。
 *
 * ## 客户端 IP 的信任边界（ER-09）
 *
 * ip 维度的标识符来自 `resolveClientIp()`，而 `X-Forwarded-For` 是**客户端可任意伪造**的头。
 * 本中间件因此只在「TCP 对端属于可信代理」时才采信 XFF，默认（未配置 `TRUSTED_PROXIES`）
 * **完全不信任 XFF**，直接使用对端地址。历史实现无条件取 XFF 最左值，
 * 攻击者每次换一个伪造 IP 就能无限绕过按 IP 的限流，也能反向「陷害」他人 IP 进限流。
 *
 * ## `user` scope 的可用性边界（ER-09）
 *
 * `RateLimit` 是**全局**中间件，`Auth` 是**路由级**中间件，因此本中间件执行时
 * `$request->userId()` 必然为空 —— `user` scope 在本位置**取不到任何用户标识**。
 * 历史实现在这种情况下返回空串，导致「所有用户（含未认证请求）共用同一个 user 桶」，
 * 语义上退化成一条全局配额（对 `/v1/auth/login` 这类路由尤其危险：一个客户端就能把所有人锁死）。
 * 现在改为返回 null + 告警并跳过该维度，**绝不**创建共享桶。
 */
class RateLimit
{
    /**
     * XFF 头整体长度上限（字节）
     *
     * XFF 由客户端控制，长度可被恶意放大；超限一律视为非法头 → 回退对端地址，
     * 避免把「解析长字符串」变成新的放大面。
     */
    private const MAX_XFF_LENGTH = 1024;

    /**
     * XFF 链最大条目数
     *
     * 正常链路（客户端 → nginx → 后端）最多 1~3 段；20 段足够覆盖多层反代，
     * 再多只可能是伪造/攻击流量。
     */
    private const MAX_XFF_ENTRIES = 20;

    /**
     * `user` scope 告警节流时间戳（秒）
     *
     * Swoole 常驻进程里本类实例会被容器复用，不节流的话「user scope 不可用」
     * 会按请求刷日志，反而把真正的问题淹掉。
     */
    private static int $userScopeWarnedAt = 0;

    /**
     * 非法 XFF 告警节流时间戳（秒）
     *
     * 伪造 XFF 是攻击者可控的日志写入点，必须节流。
     */
    private static int $xffWarnedAt = 0;

    /**
     * 可信代理列表解析缓存：配置内容指纹 => 解析结果
     *
     * 为什么用「配置指纹」而不是实例属性：`think\Container` 会把中间件实例缓存成单例，
     * 实例属性在 Swoole 常驻进程里等价于「首次请求时的配置」，配置变更（reload）后不会更新。
     * 以配置内容作键既能自动失效，又避免每次请求重复解析 CIDR。
     *
     * @var array<string, list<string>>
     */
    private static array $trustedProxyCache = [];

    /**
     * Handle rate limiting for incoming request | 处理请求的限流逻辑
     *
     * @param Request $request Custom request instance with tenant/user context
     * @param Closure $next Next middleware in the stack
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, Closure $next)
    {
        $config = (array) config('ratelimit', []);
        if (empty($config['enabled'])) {
            return $next($request);
        }

        // 白名单：IP / 用户 / 租户
        if ($this->isWhitelisted($request, $config)) {
            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(false, [
                    'scope'      => 'whitelist',
                    'identifier' => $this->resolveClientIp($request),
                    'reason'     => 'whitelist',
                ]);
            }
            return $next($request);
        }

        $env       = (string) env('APP_ENV', 'dev');
        $algorithm = (string) ($config['algorithm'] ?? 'token_bucket'); // token_bucket | fixed_window
        $store     = $config['store'] ?? null;
        $cache     = $store ? Cache::store($store) : Cache::store();
        $global    = (array) ($config['default'] ?? []);
        $scopesCfg = (array) ($config['scopes'] ?? []);
        $routesCfg = (array) ($config['routes'] ?? []);
        $routeRule = $this->matchRouteConfig($request, $routesCfg);

        $limitHit = false;
        $hitMeta  = [];

        // 选择算法：Token Bucket 或 Fixed Window
        if ($algorithm === 'token_bucket') {
            return $this->handleTokenBucket($request, $next, $env, $store, $global, $scopesCfg, $routeRule);
        }

        // Fallback: Fixed Window 算法（原有实现）
        foreach (['user', 'tenant', 'ip', 'route'] as $scope) {
            $rule = $this->resolveScopeRule($scope, $global, $scopesCfg, $routeRule);
            if ($rule === null) {
                continue;
            }
            if (isset($rule['enabled']) && !$rule['enabled']) {
                continue;
            }

            $identifier = $this->resolveIdentifier($scope, $request);
            if ($identifier === null || $identifier === '') {
                continue;
            }

            $limit  = (int) ($rule['limit'] ?? 0);
            $period = (int) ($rule['period'] ?? 0);
            if ($limit <= 0 || $period <= 0) {
                continue;
            }

            $key = $this->buildCacheKey($env, $scope, (string) $identifier, $period);

            try {
                // 固定时间窗口：每次请求对计数器 +1，首个请求时设置过期时间
                $current = (int) $cache->inc($key);
                if ($current === 1) {
                    $cache->handler()->expire($key, $period);
                }
            } catch (\Throwable $e) {
                return $this->passThroughOnError($request, $next, $e);
            }

            if ($current > $limit) {
                $limitHit = true;
                $hitMeta  = [
                    'scope'      => $scope,
                    'key'        => $key,
                    'limit'      => $limit,
                    'period'     => $period,
                    'current'    => $current,
                    'identifier' => (string) $identifier,
                    'algorithm'  => 'fixed_window',
                ];
                break;
            }
        }

        if ($limitHit) {
            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(true, $hitMeta);
            }
            return $this->buildRateLimitedResponse($hitMeta);
        }

        if (method_exists($request, 'setRateLimited')) {
            $request->setRateLimited(false, []);
        }
        return $next($request);
    }

    /**
     * Handle Token Bucket 算法限流 | Handle Token Bucket rate limiting
     *
     * @param Request $request Request instance
     * @param Closure $next Next middleware
     * @param string $env Environment
     * @param string|null $store Cache store
     * @param array $global Global config
     * @param array $scopesCfg Scopes config
     * @param array $routeRule Route-specific rule
     * @return mixed Response or next middleware result
     */
    protected function handleTokenBucket(
        Request $request,
        Closure $next,
        string $env,
        ?string $store,
        array $global,
        array $scopesCfg,
        array $routeRule
    ) {
        try {
            $service = new \Infrastructure\RateLimit\Service\RateLimitService($store);
            $config  = (array) config('ratelimit');
            $tbCfg   = (array) ($config['token_bucket'] ?? []);

            $limitHit = false;
            $hitMeta  = [];

            foreach (['user', 'tenant', 'ip', 'route'] as $scope) {
                $rule = $this->resolveScopeRule($scope, $global, $scopesCfg, $routeRule);
                if ($rule === null) {
                    continue;
                }
                if (isset($rule['enabled']) && !$rule['enabled']) {
                    continue;
                }

                $identifier = $this->resolveIdentifier($scope, $request);
                if ($identifier === null || $identifier === '') {
                    continue;
                }

                // Token Bucket 参数：capacity 和 rate
                $capacity = (int) ($rule['capacity'] ?? $tbCfg['capacity'] ?? 100);
                $rate     = (float) ($rule['rate'] ?? $tbCfg['rate'] ?? 10.0);
                $cost     = (int) ($tbCfg['cost_per_request'] ?? 1);

                if ($capacity <= 0 || $rate <= 0) {
                    continue;
                }

                $key = \Infrastructure\RateLimit\Service\RateLimitService::buildKey($env, $scope, (string) $identifier);

                if (!$service->allowRequest($key, $capacity, $rate, $cost)) {
                    $info = $service->getRateLimitInfo($key);
                    $limitHit = true;
                    $hitMeta  = [
                        'scope'      => $scope,
                        'key'        => $key,
                        'capacity'   => $capacity,
                        'rate'       => $rate,
                        'tokens'     => $info['tokens'],
                        'identifier' => (string) $identifier,
                        'algorithm'  => 'token_bucket',
                    ];
                    break;
                }
            }

            if ($limitHit) {
                if (method_exists($request, 'setRateLimited')) {
                    $request->setRateLimited(true, $hitMeta);
                }
                return $this->buildRateLimitedResponse($hitMeta);
            }

            if (method_exists($request, 'setRateLimited')) {
                $request->setRateLimited(false, []);
            }
            return $next($request);
        } catch (\Throwable $e) {
            return $this->passThroughOnError($request, $next, $e);
        }
    }


    /**
     * 是否命中白名单。
     */
    protected function isWhitelisted(Request $request, array $config): bool
    {
        $whitelist  = (array) ($config['whitelist'] ?? []);
        $ipList     = (array) ($whitelist['ips'] ?? []);
        $userList   = array_map('strval', (array) ($whitelist['users'] ?? []));
        $tenantList = array_map('strval', (array) ($whitelist['tenants'] ?? []));

        $ip = $this->resolveClientIp($request);
        // 白名单比较改成「按地址比较」而不是字符串严格相等：
        // resolveClientIp() 现在返回归一化后的地址（IPv6 小写零压缩、v4-mapped 折叠成 v4），
        // 若继续用 in_array() 严格比较，白名单里写 `0:0:0:0:0:0:0:1` 或 `::ffff:1.2.3.4`
        // 会在归一化后**静默失配** —— 白名单静默失效意味着本该放行的监控探针被限流。
        foreach ($ipList as $allowedIp) {
            if ($this->isSameIp($ip, trim((string) $allowedIp))) {
                return true;
            }
        }

        $userId = null;
        if (method_exists($request, 'userId')) {
            try {
                $userId = $request->userId();
            } catch (\Throwable) {
                $userId = null;
            }
        }
        if ($userId !== null && in_array((string) $userId, $userList, true)) {
            return true;
        }

        $tenantId = null;
        if (method_exists($request, 'tenantId')) {
            try {
                $tenantId = $request->tenantId();
            } catch (\Throwable) {
                $tenantId = null;
            }
        }
        if ($tenantId !== null && in_array((string) $tenantId, $tenantList, true)) {
            return true;
        }

        return false;
    }

    /**
     * 解析客户端 IP（XFF 信任边界）
     *
     * 判定顺序（任何一步失败都退化为「对端地址」，**绝不**退化为「客户端可控的值」）：
     *   1. TCP 对端**不在**可信代理列表 → 整条 XFF 忽略，返回对端地址；
     *   2. 对端可信但无 XFF / XFF 非法（乱码、空段、超长、段数过多）→ 返回对端地址；
     *   3. 对端可信且 XFF 合法 → **从右往左**扫描，跳过可信代理，返回第一个不可信地址；
     *   4. XFF 全链都是可信代理 → 返回对端地址（没有更左边的「客户端」可用）。
     *
     * 为什么必须「从右往左」：
     *   nginx 的 `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for` 语义是
     *   `$http_x_forwarded_for, $remote_addr` —— **在最右侧追加**。因此最右侧那一段
     *   一定由离后端最近的那台可信代理写入（不可伪造），越往左越接近客户端、越不可信。
     *   取最左值 = 取「客户端自己写的那一段」（完全可伪造）；
     *   取「从右往左第一个非可信地址」= 真正连上第一跳代理的那台机器，这才是唯一可信的结论。
     *
     * @return string 归一化后的客户端 IP；对端地址非法时原样返回 `$request->ip()`
     */
    protected function resolveClientIp(Request $request): string
    {
        $rawPeer = (string) $request->ip();
        $peer    = $this->normalizeIp($rawPeer);

        // 对端地址本身拿不到或不合法（CLI / 测试直调 / 畸形 REMOTE_ADDR）：
        // 无法做任何可信判定，原样返回，绝不采信 XFF。
        if ($peer === null) {
            return $rawPeer;
        }

        // 1) 对端不可信 → XFF 一律忽略
        if (!$this->isTrustedProxy($peer)) {
            return $peer;
        }

        $xff = $request->header('X-Forwarded-For');
        if (!is_string($xff) || trim($xff) === '') {
            // 2) 可信代理没带 XFF（例如直连探针）
            return $peer;
        }

        $chain = $this->parseForwardedFor($xff);
        if ($chain === null) {
            // 2) 非法 XFF → 整条丢弃
            return $peer;
        }

        // 3) 从右往左：跳过可信代理，取第一个不可信地址
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if ($this->isTrustedProxy($chain[$i])) {
                continue;
            }

            return $chain[$i];
        }

        // 4) 全链可信 → 没有更左侧的地址可用
        return $peer;
    }

    /**
     * 解析 XFF 头为「已归一化的 IP 链」
     *
     * 返回 null 表示整条头不可用（超长 / 段数过多 / 含非法段）。
     *
     * 为什么**不**「跳过非法段继续解析」：合法链上每一段都该是 IP 字面量，
     * 出现非法段说明这条头是伪造或已损坏。此时若「跳过它继续往左取」，
     * 攻击者只要在伪造值后面塞一段乱码，就能把解析结果推向更左边的可控值 ——
     * 因此唯一安全的做法是整条丢弃、回退对端地址。
     *
     * @return list<string>|null
     */
    protected function parseForwardedFor(string $raw): ?array
    {
        if (strlen($raw) > self::MAX_XFF_LENGTH) {
            $this->warnInvalidXff('xff_too_long', strlen($raw));

            return null;
        }

        $parts = explode(',', $raw);
        if (count($parts) > self::MAX_XFF_ENTRIES) {
            $this->warnInvalidXff('xff_too_many_entries', count($parts));

            return null;
        }

        $chain = [];
        foreach ($parts as $part) {
            $normalized = $this->normalizeIp($part);
            if ($normalized === null) {
                $this->warnInvalidXff('xff_invalid_entry', $part);

                return null;
            }

            $chain[] = $normalized;
        }

        return $chain;
    }

    /**
     * 归一化单个 IP 字面量（IPv4 / IPv6）
     *
     * - 去掉首尾空白（`1.2.3.4 , 5.6.7.8` 这种带空格的写法很常见）；
     * - 只接受纯 IP 字面量：带端口（`1.2.3.4:80`）、带方括号（`[::1]`）、
     *   带 zone id（`fe80::1%eth0`）一律视为非法 —— XFF 规范里不该出现这些形态；
     * - `::ffff:1.2.3.4`（IPv4-mapped IPv6）折叠成 `1.2.3.4`：同一台机器既可能以
     *   v4 也可能以 v4-mapped v6 出现，不折叠会让它同时占两个桶、拿到双倍配额；
     * - 其余交给 `inet_ntop()` 输出规范形式（IPv6 小写 + 零压缩），
     *   保证 `2001:DB8::1` 与 `2001:db8::1` 落到同一个桶。
     *
     * @return string|null 归一化后的地址；非法时返回 null
     */
    protected function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip);

        // 空串 / 明显超长的垃圾（IPv6 字面量最长 45 字符，含 IPv4-mapped 形态）
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        // FILTER_VALIDATE_IP 会拒绝 1.2.3.4:80、[::1]、fe80::1%eth0 等非纯字面量形态
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = @inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        // IPv4-mapped IPv6（::ffff:a.b.c.d）→ 折叠为点分 IPv4
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\x00", 10) . "\xff\xff")) {
            $ipv4 = @inet_ntop(substr($binary, 12));

            return $ipv4 === false ? null : $ipv4;
        }

        $canonical = @inet_ntop($binary);

        return $canonical === false ? null : $canonical;
    }

    /**
     * 判定某个地址是否属于可信代理
     *
     * 列表项支持「单个 IP」与「CIDR」两种写法，比较一律走二进制，规避文本差异
     * （IPv6 大小写、零压缩、v4-mapped 形态）。
     */
    protected function isTrustedProxy(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach ($this->trustedProxies() as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipMatchesCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if ($this->isSameIp($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 两个 IP 字面量是否表示同一地址（按二进制比较）
     */
    protected function isSameIp(string $a, string $b): bool
    {
        $binaryA = @inet_pton($a);
        $binaryB = @inet_pton($b);

        return $binaryA !== false && $binaryB !== false && $binaryA === $binaryB;
    }

    /**
     * 判定 IP 是否落在某个 CIDR 网段内
     *
     * 实现说明：按「整字节比较 + 末字节掩码比较」做，不依赖 gmp/bcmath 扩展；
     * 两端的地址族必须一致（IPv4 不会匹配 IPv6 网段），前缀长度超出地址族位宽视为非法。
     *
     * @param string $ip    待判定的地址（可为任意文本形式，内部自行比较二进制）
     * @param string $cidr  形如 `10.0.0.0/8`、`2001:db8::/32`
     */
    protected function ipMatchesCidr(string $ip, string $cidr): bool
    {
        $segments = explode('/', $cidr, 2);
        if (count($segments) !== 2) {
            return false;
        }

        $baseIp = $this->normalizeIp($segments[0]);
        $bits   = $segments[1];

        if ($baseIp === null || preg_match('/^\d{1,3}$/', $bits) !== 1) {
            return false;
        }

        $binaryIp   = @inet_pton($ip);
        $binaryBase = @inet_pton($baseIp);

        // 非法地址或地址族不一致（IPv4 长度 4、IPv6 长度 16）
        if ($binaryIp === false || $binaryBase === false || strlen($binaryIp) !== strlen($binaryBase)) {
            return false;
        }

        $prefix = (int) $bits;
        $max    = strlen($binaryIp) * 8;

        if ($prefix > $max) {
            return false;
        }

        // /0 表示「该地址族全部命中」
        if ($prefix === 0) {
            return true;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($binaryIp, 0, $wholeBytes) !== substr($binaryBase, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($binaryIp[$wholeBytes]) & $mask) === (ord($binaryBase[$wholeBytes]) & $mask);
    }

    /**
     * 读取并解析可信代理列表
     *
     * **默认值必须安全：未配置 = 不信任任何 XFF。**
     *
     * 非法条目「忽略 + 告警」而不是整份作废：一条写错的 CIDR 不该让其余正确配置一起失效
     * （否则运维会把「XFF 完全不生效」误判成代码缺陷）；而「被跳过」的条目本身仍然安全 ——
     * 「不在列表里」的语义就是「不可信」，最终只是回退到对端地址。
     *
     * @return list<string> 归一化后的条目（单 IP 或 `base/prefix`）
     */
    protected function trustedProxies(): array
    {
        $raw   = config('ratelimit.trusted_proxies', []);
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        $cacheKey = md5((string) json_encode($items));
        if (isset(self::$trustedProxyCache[$cacheKey])) {
            return self::$trustedProxyCache[$cacheKey];
        }

        $list = [];
        foreach ($items as $item) {
            $entry = trim((string) $item);
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '/')) {
                $segments = explode('/', $entry, 2);
                $base     = $this->normalizeIp($segments[0]);
                $bits     = $segments[1];

                if (
                    $base === null
                    || preg_match('/^\d{1,3}$/', $bits) !== 1
                    || (int) $bits > (strlen((string) @inet_pton($base)) * 8)
                ) {
                    $this->warnInvalidTrustedProxy($entry);
                    continue;
                }

                $list[] = $base . '/' . (int) $bits;
                continue;
            }

            $normalized = $this->normalizeIp($entry);
            if ($normalized === null) {
                $this->warnInvalidTrustedProxy($entry);
                continue;
            }

            $list[] = $normalized;
        }

        // 只保留最近 8 份配置的解析结果：常驻进程里不应无界增长
        if (count(self::$trustedProxyCache) >= 8) {
            self::$trustedProxyCache = [];
        }

        return self::$trustedProxyCache[$cacheKey] = $list;
    }

    /**
     * 非法可信代理条目的告警（只在配置变化时触发一次，无需节流）
     */
    protected function warnInvalidTrustedProxy(string $entry): void
    {
        try {
            Log::warning('RateLimit: invalid TRUSTED_PROXIES entry ignored', [
                'entry' => $entry,
                'hint'  => 'expects single IP or CIDR, e.g. 172.18.0.6 or 172.18.0.0/16',
            ]);
        } catch (\Throwable) {
            // 日志不可用不能影响限流主流程
        }
    }

    /**
     * 非法 XFF 的告警（按进程节流，避免伪造头变成日志放大器）
     *
     * @param string $reason 原因标记
     * @param mixed  $detail 诊断信息（长度 / 段数 / 非法原文片段）
     */
    protected function warnInvalidXff(string $reason, mixed $detail = null): void
    {
        $now = time();
        if ($now - self::$xffWarnedAt < 60) {
            return;
        }
        self::$xffWarnedAt = $now;

        try {
            Log::warning('RateLimit: invalid X-Forwarded-For ignored, falling back to peer address', [
                'reason' => $reason,
                'detail' => is_scalar($detail) ? $detail : gettype($detail),
            ]);
        } catch (\Throwable) {
            // 日志不可用不能影响限流主流程
        }
    }

    /**
     * 根据路由前缀匹配最具体的配置。
     */
    protected function matchRouteConfig(Request $request, array $routes): array
    {
        $path = '/' . ltrim($request->pathinfo(), '/');
        $best = [];
        $len  = 0;

        foreach ($routes as $prefix => $cfg) {
            $prefix = (string) $prefix;
            if ($prefix === '') {
                continue;
            }
            if ($prefix[0] !== '/') {
                $prefix = '/' . $prefix;
            }
            if (str_starts_with($path, $prefix) && strlen($prefix) > $len) {
                $len  = strlen($prefix);
                $best = (array) $cfg;
            }
        }

        return $best;
    }

    /**
     * 解析某个 scope 的具体规则：路由覆盖 > scopes > 全局默认。
     */
    protected function resolveScopeRule(string $scope, array $global, array $scopesCfg, array $routeRule): ?array
    {
        if (isset($routeRule['scopes'][$scope])) {
            return (array) $routeRule['scopes'][$scope];
        }
        if (isset($routeRule[$scope])) {
            return (array) $routeRule[$scope];
        }
        if (isset($scopesCfg[$scope])) {
            return (array) $scopesCfg[$scope];
        }
        if (!empty($global)) {
            return (array) $global;
        }
        return null;
    }

    /**
     * 根据 scope 解析唯一标识符。
     *
     * 返回 null 表示「本维度在当前上下文里无法解析」，调用方必须**跳过**该维度
     * （而不是用一个空串把它折成一个共享桶）。
     */
    protected function resolveIdentifier(string $scope, Request $request): ?string
    {
        switch ($scope) {
            case 'user':
                return $this->resolveAuthenticatedUserId($request);
            case 'tenant':
                // 限流标识符本身容忍「无租户」（缺失时统一记为 ''，退化为不按租户细分），
                // 因此用可空版本 tenantIdOrNull()：
                // 原来写成 `$request->tenantId() ?? ''`，其中 `?? ''` 是死代码
                // （tenantId() 返回 int），一旦租户上下文缺失还会抛异常把限流中间件打成 500 ——
                // 限流不该因为「算不出 key」而阻断请求。
                if (method_exists($request, 'tenantIdOrNull')) {
                    return (string) ($request->tenantIdOrNull() ?? '');
                }

                return method_exists($request, 'tenantId') ? (string) ($request->tenantId() ?? '') : '';
            case 'ip':
                return $this->resolveClientIp($request);
            case 'route':
                return '/' . ltrim($request->pathinfo(), '/');
            default:
                return null;
        }
    }

    /**
     * 解析 user scope 的用户标识
     *
     * ⚠️ **本中间件位置取不到用户身份**：`RateLimit` 是全局中间件，`Auth` 是路由级中间件，
     * 执行到本类时 `$request->userId()` 必然为空。历史实现把这种「取不到」写成 `''`，
     * 于是所有请求（含未登录）挤进同一个 `rl:{env}:user:{md5('')}` 桶 ——
     * 这不是「按用户限流」，而是**一条伪装成用户维度的全局配额**：
     * 任何客户端先打满，其他用户与未登录请求就一起 429（`/v1/auth/login` 尤其致命）。
     *
     * 因此这里显式返回 null（→ 调用方跳过该维度）+ 告警，不创建任何桶。
     * 一旦将来把「按用户的限流」挪到 `Auth` 之后的**路由级**中间件（见
     * `docs/technical-specs/security/security-guidelines.zh-CN.md` §5.1.1 的方案 B），
     * 本方法无需改动即可自动生效 —— 那时 `userId()` 已经被 `Auth` 写入。
     */
    protected function resolveAuthenticatedUserId(Request $request): ?string
    {
        $userId = null;

        if (method_exists($request, 'userId')) {
            try {
                $userId = $request->userId();
            } catch (\Throwable) {
                $userId = null;
            }
        }

        if ($userId !== null && (string) $userId !== '') {
            return (string) $userId;
        }

        $this->warnUserScopeUnavailable();

        return null;
    }

    /**
     * 「user scope 在本位置不可用」的告警（按进程节流 60 秒）
     *
     * 必须显式告警而不是静默跳过：静默跳过同样会让运维误以为「按用户限流已生效」，
     * 而实际保护完全来自 ip 维度。节流是为了避免每个请求都写一行日志。
     */
    protected function warnUserScopeUnavailable(): void
    {
        $now = time();
        if ($now - self::$userScopeWarnedAt < 60) {
            return;
        }
        self::$userScopeWarnedAt = $now;

        try {
            Log::warning('RateLimit: user scope skipped because no authenticated user is available', [
                'reason' => 'rate_limit_middleware_runs_before_auth',
                'hint'   => 'user scope is unavailable here; use ip/tenant/route scopes, or a route-level limiter after Auth',
            ]);
        } catch (\Throwable) {
            // 日志不可用不能影响限流主流程
        }
    }

    /**
     * 生成 Redis key。
     */
    protected function buildCacheKey(string $env, string $scope, string $identifier, int $period): string
    {
        return sprintf('rl:%s:%s:%s:%ds', $env, $scope, md5($identifier), $period);
    }

    /**
     * 构造 429 响应。
     */
    protected function buildRateLimitedResponse(array $meta)
    {
        $algorithm = (string) ($meta['algorithm'] ?? 'fixed_window');

        // Get trace_id from request | 从请求获取 trace_id
        $traceId = null;
        try {
            $request = request();
            if ($request && method_exists($request, 'getTraceId')) {
                $traceId = $request->getTraceId();
            } elseif ($request && method_exists($request, 'header')) {
                $traceId = $request->header('X-Trace-Id');
            }
        } catch (\Throwable $e) {
            // Ignore trace ID retrieval errors
        }

        if ($algorithm === 'token_bucket') {
            // Token Bucket 响应
            $body = [
                'code'      => 429,
                'message'   => 'Too Many Requests',
                'data'      => [
                    'scope'      => $meta['scope'] ?? null,
                    'capacity'   => $meta['capacity'] ?? null,
                    'rate'       => $meta['rate'] ?? null,
                    'tokens'     => $meta['tokens'] ?? null,
                    'identifier' => $meta['identifier'] ?? null,
                    'algorithm'  => 'token_bucket',
                ],
                'timestamp' => time(),
            ];

            // Add trace_id if available | 添加 trace_id（如果可用）
            if ($traceId) {
                $body['trace_id'] = $traceId;
            }

            $response = json($body, 429);
            $response->header([
                'Retry-After'           => '1',
                'X-Rate-Limited'        => '1',
                'X-RateLimit-Scope'     => (string) ($meta['scope'] ?? ''),
                'X-RateLimit-Limit'     => (string) ($meta['capacity'] ?? 0),
                'X-RateLimit-Remaining' => (string) max(0, floor($meta['tokens'] ?? 0)),
                'X-RateLimit-Reset'     => (string) (time() + 1),
            ]);
        } else {
            // Fixed Window 响应
            $ttl = (int) ($meta['period'] ?? 0);
            if ($ttl <= 0) {
                $ttl = 1;
            }

            $body = [
                'code'      => 429,
                'message'   => 'Too Many Requests',
                'data'      => [
                    'scope'      => $meta['scope'] ?? null,
                    'limit'      => $meta['limit'] ?? null,
                    'period'     => $meta['period'] ?? null,
                    'current'    => $meta['current'] ?? null,
                    'identifier' => $meta['identifier'] ?? null,
                    'algorithm'  => 'fixed_window',
                ],
                'timestamp' => time(),
            ];

            // Add trace_id if available | 添加 trace_id（如果可用）
            if ($traceId) {
                $body['trace_id'] = $traceId;
            }

            $response = json($body, 429);
            $response->header([
                'Retry-After'       => (string) $ttl,
                'X-Rate-Limited'    => '1',
                'X-RateLimit-Scope' => (string) ($meta['scope'] ?? ''),
            ]);
        }

        return $response;
    }

    /**
     * Cache/Redis 故障时降级放行。
     */
    protected function passThroughOnError(Request $request, Closure $next, \Throwable $e)
    {
        try {
            $traceId  = null;
            $tenantId = null;
            $siteId   = null;
            $userId   = null;

            try {
                if (method_exists($request, 'getTraceId')) {
                    $traceId = $request->getTraceId();
                } elseif (method_exists($request, 'header')) {
                    $traceId = $request->header('X-Trace-Id');
                }

                if (method_exists($request, 'getTenantContext')) {
                    $context  = $request->getTenantContext();
                    $tenantId = $context['tenant_id'] ?? null;
                    $siteId   = $context['site_id'] ?? null;
                    $userId   = $context['user_id'] ?? null;
                } else {
                    if (method_exists($request, 'tenantId')) {
                        $tenantId = $request->tenantId();
                    }
                    if (method_exists($request, 'siteId')) {
                        $siteId = $request->siteId();
                    }
                    if (method_exists($request, 'userId')) {
                        $userId = $request->userId();
                    }
                }
            } catch (\Throwable $inner) {
                // Ignore context resolution errors
            }

            Log::warning('RateLimit cache error, fallback to pass-through', [
                'exception' => $e->getMessage(),
                'trace_id'  => $traceId,
                'tenant_id' => $tenantId,
                'site_id'   => $siteId,
                'user_id'   => $userId,
            ]);
        } catch (\Throwable) {
            // ignore
        }

        if (method_exists($request, 'setRateLimited')) {
            $request->setRateLimited(false, [
                'scope'  => 'error',
                'reason' => 'cache_error',
            ]);
        }

        return $next($request);
    }
}
