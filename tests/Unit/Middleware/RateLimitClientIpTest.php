<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ThinkPHPTestCase;
use Tests\Unit\Middleware\Support\RateLimitProbe;
use think\facade\Config;
use think\Request;

/**
 * 限流客户端 IP 解析（XFF 信任边界）+ user scope 可用性 单元测试（ER-09）
 *
 * 被测对象：`app/middleware/RateLimit.php`
 * - `resolveClientIp()`：可信代理解析算法
 * - `ipMatchesCidr()`  ：CIDR 匹配（含边界）
 * - `normalizeIp()`    ：IPv4 / IPv6 / v4-mapped 归一化
 * - `resolveIdentifier('user')`：认证前必须返回 null（不得退化成共享桶）
 *
 * 为什么用「探针子类」而不是反射：受测方法都是 protected，子类暴露成 public
 * 既能拿到真实实现（不是复制一份算法），又不必在每个用例里写反射样板。
 * 探针类放在 `tests/Unit/Middleware/Support/` 下，避免 php-cs-fixer 的「一类一文件」噪音。
 *
 * 为什么这些用例不碰 HTTP / Redis：这里验证的是**纯判定逻辑**（输入 = 对端地址 + XFF 头 +
 * 可信列表，输出 = 限流标识）。HTTP 层的「同一个桶被限流」证据在
 * `Tests\Feature\RateLimitMiddlewareTest` 里。
 */
class RateLimitClientIpTest extends ThinkPHPTestCase
{
    /**
     * 测试开始前的原始限流配置，tearDown 时原样写回
     *
     * @var array<string, mixed>
     */
    private array $originalRateLimitConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 直接读配置文件，**不**调用 $this->app()->initialize()：
        // initialize() 会让 ThinkPHP 的 Error 初始化器接管 PHPUnit 的全局 error/exception handler，
        // 而基类只在 runHttp() 之后才把它们还原（本类不发 HTTP 请求），会被 PHPUnit 判为 Risky。
        $configFile = dirname(__DIR__, 3) . '/config/ratelimit.php';

        // 先钉一份「配置文件原值」，再用它覆盖运行时配置：
        // 用例内可以精确改 trusted_proxies，tearDown 时原样写回，不污染同进程内的后续测试类。
        $this->originalRateLimitConfig = (array) require $configFile;
        Config::set($this->originalRateLimitConfig, 'ratelimit');
    }

    protected function tearDown(): void
    {
        if ($this->originalRateLimitConfig !== []) {
            Config::set($this->originalRateLimitConfig, 'ratelimit');

            $this->assertSame(
                $this->originalRateLimitConfig,
                (array) config('ratelimit'),
                '限流配置未正确恢复，可能污染同进程内的后续测试'
            );
        }
        $this->originalRateLimitConfig = [];

        parent::tearDown();
    }

    /**
     * 覆盖可信代理配置（其余 ratelimit 配置保持不变）
     *
     * @param list<string> $trustedProxies 单个 IP 或 CIDR
     */
    private function useTrustedProxies(array $trustedProxies): void
    {
        Config::set(['trusted_proxies' => $trustedProxies], 'ratelimit');
    }

    /**
     * 构造一个请求：TCP 对端 = $peer，可选携带 X-Forwarded-For
     */
    private function makeRequest(string $peer, ?string $xff = null): Request
    {
        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo('unit/ratelimit-ip');

        // REMOTE_ADDR 才是「TCP 对端」。think\Request::ip() 读的就是它
        // （框架自带的 proxyServerIp 默认为空，因此不会自己采信 XFF）。
        $request->withServer(['REMOTE_ADDR' => $peer]);

        if ($xff !== null) {
            $request->withHeader(['X-Forwarded-For' => $xff]);
        }

        return $request;
    }

    /**
     * 用例 1：对端不可信时，伪造 XFF **完全不参与**判断
     *
     * 未配置 TRUSTED_PROXIES 是默认状态，此时任何 XFF 都必须被忽略：
     * 攻击者换一个伪造 IP 就能换一个桶的时代必须结束。
     */
    public function test_forged_xff_is_ignored_when_peer_is_not_trusted(): void
    {
        $this->useTrustedProxies([]);

        $probe = new RateLimitProbe();

        // 无论伪造多少段、伪造什么网段，结论都必须是「对端地址」
        foreach (['9.9.9.9', '9.9.9.1, 9.9.9.2', '127.0.0.1', '10.0.0.1, 8.8.8.8'] as $forged) {
            $this->assertSame(
                '203.0.113.5',
                $probe->clientIpForTest($this->makeRequest('203.0.113.5', $forged)),
                '对端不在可信列表时，XFF 必须被整条忽略：' . $forged
            );
        }

        // 没有 XFF 时同样返回对端地址
        $this->assertSame('203.0.113.5', $probe->clientIpForTest($this->makeRequest('203.0.113.5')));
    }

    /**
     * 用例 2：对端可信时，从右往左取第一个**不可信**地址
     *
     * 场景即 nginx 的默认行为：`X-Forwarded-For: <客户端原样带的值>, <nginx 追加的真实客户端 IP>`。
     * 最左侧的 9.9.9.9 是客户端自己写的（可伪造），必须被跳过；
     * 正确结果是 nginx 追加的那一段（1.2.3.4）。
     */
    public function test_trusted_proxy_takes_rightmost_untrusted_hop(): void
    {
        $this->useTrustedProxies(['10.0.0.1']);

        $probe = new RateLimitProbe();

        // 典型链路：客户端伪造 9.9.9.9 → nginx 追加真实客户端 1.2.3.4 → nginx 自身 10.0.0.1
        $this->assertSame(
            '1.2.3.4',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', '9.9.9.9, 1.2.3.4, 10.0.0.1')),
            '最左值是客户端伪造的，必须取从右往左第一个不可信地址'
        );

        // 只有一跳时（客户端 → nginx → 后端）
        $this->assertSame(
            '1.2.3.4',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', '1.2.3.4, 10.0.0.1'))
        );

        // 可信代理没追加任何东西（例如直连探针）：没有 XFF 可用 → 对端地址
        $this->assertSame('10.0.0.1', $probe->clientIpForTest($this->makeRequest('10.0.0.1')));
    }

    /**
     * 用例 3：XFF 全链都是可信代理 → 回退对端地址
     *
     * 此时没有任何「客户端侧」地址可用；返回最左侧的可信代理地址是错误的
     * （那会让所有内部流量共用一个桶），必须回退到 TCP 对端。
     */
    public function test_all_trusted_hops_fall_back_to_peer(): void
    {
        $this->useTrustedProxies(['10.0.0.0/8']);

        $probe = new RateLimitProbe();

        $this->assertSame(
            '10.0.0.1',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', '10.1.1.1, 10.2.2.2')),
            '全部可信时必须回退对端地址，而不是返回最左侧的代理地址'
        );
    }

    /**
     * 用例 4：非法 XFF（乱码 / 空段 / 带端口 / 超长 / 段数过多）→ 不崩、回退对端
     *
     * 设计要点：任何一段非法都**整条丢弃**。若「跳过非法段继续往左取」，
     * 攻击者只要在伪造值右侧塞一段乱码，就能把解析结果推到更左边的可控值。
     */
    #[DataProvider('invalidXffProvider')]
    public function test_invalid_xff_falls_back_to_peer(string $invalidXff, string $case): void
    {
        $this->useTrustedProxies(['10.0.0.1']);

        $probe = new RateLimitProbe();

        $this->assertSame(
            '10.0.0.1',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', $invalidXff)),
            '非法 XFF 必须回退对端地址：' . $case
        );
    }

    /**
     * 非法 XFF 样本
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidXffProvider(): array
    {
        return [
            'garbage'          => ['not-an-ip', '乱码'],
            'empty segment'    => ['1.2.3.4, , 5.6.7.8', '空段'],
            'trailing comma'   => ['1.2.3.4,', '尾随逗号产生的空段'],
            'with port'        => ['1.2.3.4:8080', '带端口的写法不属于 XFF 规范'],
            'bracketed ipv6'   => ['[::1]', '方括号包裹的 IPv6'],
            'zone id'          => ['fe80::1%eth0', '带 zone id 的 IPv6'],
            'too long'         => [str_repeat('9', 1100), '超过长度上限'],
            'too many entries' => [implode(',', array_fill(0, 25, '1.2.3.4')), '段数超过上限'],
        ];
    }

    /**
     * 用例 5：CIDR 匹配（含边界值）
     */
    public function test_cidr_matching_boundaries(): void
    {
        $probe = new RateLimitProbe();

        // /8 的边界
        $this->assertTrue($probe->cidrForTest('10.0.0.0', '10.0.0.0/8'));
        $this->assertTrue($probe->cidrForTest('10.255.255.255', '10.0.0.0/8'), '10.0.0.0/8 必须包含 10.255.255.255');
        $this->assertFalse($probe->cidrForTest('11.0.0.1', '10.0.0.0/8'), '10.0.0.0/8 不得包含 11.0.0.1');
        $this->assertFalse($probe->cidrForTest('9.255.255.255', '10.0.0.0/8'));

        // 非 8 位对齐的前缀（/12、/25）走掩码分支
        $this->assertTrue($probe->cidrForTest('172.31.255.255', '172.16.0.0/12'));
        $this->assertFalse($probe->cidrForTest('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue($probe->cidrForTest('192.168.1.127', '192.168.1.0/25'));
        $this->assertFalse($probe->cidrForTest('192.168.1.128', '192.168.1.0/25'));

        // /32 精确匹配、/0 全匹配
        $this->assertTrue($probe->cidrForTest('203.0.113.9', '203.0.113.9/32'));
        $this->assertFalse($probe->cidrForTest('203.0.113.10', '203.0.113.9/32'));
        $this->assertTrue($probe->cidrForTest('8.8.8.8', '0.0.0.0/0'));

        // IPv6
        $this->assertTrue($probe->cidrForTest('2001:db8:ffff::1', '2001:db8::/32'));
        $this->assertFalse($probe->cidrForTest('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue($probe->cidrForTest('::1', '::1/128'));

        // 地址族不一致 / 前缀越界：一律 false（不得抛异常）
        $this->assertFalse($probe->cidrForTest('::1', '10.0.0.0/8'));
        $this->assertFalse($probe->cidrForTest('10.0.0.1', '10.0.0.0/33'));
        $this->assertFalse($probe->cidrForTest('10.0.0.1', '2001:db8::/129'));
        $this->assertFalse($probe->cidrForTest('10.0.0.1', '10.0.0.0/abc'));
    }

    /**
     * 用例 6：IPv6 与 `::ffff:1.2.3.4`（IPv4-mapped）必须折叠成同一个标识
     *
     * 若不折叠，同一台机器可以同时占用 `::ffff:1.2.3.4` 与 `1.2.3.4` 两个桶，
     * 等于把配额翻倍；可信列表里写 v4、实际连接走 v4-mapped 时也会「信任失效」。
     */
    public function test_ipv4_mapped_ipv6_is_folded(): void
    {
        $this->useTrustedProxies(['::ffff:10.0.0.1']);

        $probe = new RateLimitProbe();

        // 可信列表里写 v4-mapped、对端是 v4 → 仍应命中可信
        $this->assertSame(
            '1.2.3.4',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', '9.9.9.9, ::ffff:1.2.3.4')),
            'v4-mapped 的 XFF 段必须折叠为点分 IPv4'
        );

        // IPv6 文本形式不同、地址相同 → 归一化后一致（不会被拆成两个桶）
        // 可信网段刻意选 2001:db8:ffff::/48，把「代理」和「客户端」分到不同 /32
        $this->useTrustedProxies(['2001:db8:ffff::/48']);
        $this->assertSame(
            '2001:db8::1',
            $probe->clientIpForTest($this->makeRequest('2001:db8:ffff::ff', '2001:DB8:0:0:0:0:0:1, 2001:db8:ffff::ff')),
            'IPv6 必须归一化为规范小写零压缩形式'
        );
    }

    /**
     * 用例 7：可信代理列表里的非法条目「忽略 + 继续」而不是整份作废
     *
     * 理由：一条写错的 CIDR 不该让其余正确条目一起失效（否则会被误判成代码缺陷），
     * 而「被跳过」本身仍然安全 —— 不在列表里就等于不可信。
     */
    public function test_invalid_trusted_proxy_entries_are_ignored(): void
    {
        $this->useTrustedProxies(['not-an-ip', '10.0.0.0/99', ' 10.0.0.1 ', '']);

        $probe = new RateLimitProbe();

        // 合法的 10.0.0.1（带首尾空格）仍然生效
        $this->assertSame(
            '1.2.3.4',
            $probe->clientIpForTest($this->makeRequest('10.0.0.1', '1.2.3.4, 10.0.0.1')),
            '合法条目不应被邻居的非法条目拖累'
        );

        // 非法条目不会把任何地址变成可信
        $this->assertSame(
            '198.51.100.9',
            $probe->clientIpForTest($this->makeRequest('198.51.100.9', '1.2.3.4')),
            '非法条目不得产生「意外信任」'
        );
    }

    /**
     * 用例 8：对端地址缺失（CLI / 测试直调 / 畸形 REMOTE_ADDR）时，绝不采信 XFF
     *
     * 这条是「默认安全」的兜底：解析不出 TCP 对端就没有任何可信依据，
     * 此时若去读 XFF，等于把「无对端」场景变成「客户端完全可控」。
     *
     * 注：think\Request::ip() 会把非法 REMOTE_ADDR 归一成 0.0.0.0，
     * 因此这里用一个覆写 ip() 的匿名子类，直接构造「对端不可解析」的边界。
     */
    public function test_missing_peer_address_never_uses_xff(): void
    {
        // 即便把「全世界」都设为可信，缺失对端时也不允许回落到 XFF
        $this->useTrustedProxies(['0.0.0.0/0', '::/0']);

        $probe = new RateLimitProbe();

        $request = new class () extends \app\Request {
            /**
             * 模拟「拿不到 TCP 对端」的场景
             */
            public function ip(): string
            {
                return '';
            }
        };
        $request->setMethod('GET');
        $request->setPathinfo('unit/ratelimit-ip');
        $request->withHeader(['X-Forwarded-For' => '1.2.3.4']);

        $this->assertSame(
            '',
            $probe->clientIpForTest($request),
            '对端地址缺失时必须原样返回，不得回落到 XFF'
        );
    }

    /**
     * 用例 9：user scope 在认证之前返回 null（不得退化成共享桶）
     *
     * 这是「静默共用桶」这个语义陷阱的**直接**断言：
     *   - 无 userId → null（调用方跳过该维度，不建桶）；
     *   - 有 userId（Auth 写入后）→ 该用户 id（证明算法本身没坏，
     *     只是当前中间件位置拿不到身份）。
     */
    public function test_user_scope_returns_null_before_authentication(): void
    {
        $probe = new RateLimitProbe();

        // 1) 认证前：必须 null，而不是 ''
        $this->assertNull(
            $probe->identifierForTest('user', $this->makeRequest('203.0.113.5')),
            '认证前 user scope 必须返回 null（跳过），返回空串会退化成全局共享桶'
        );

        // 2) Auth 写入 userId 之后：正常返回该用户标识
        $authenticated = $this->makeRequest('203.0.113.5');
        $authenticated->setUserId(7);

        $this->assertSame('7', $probe->identifierForTest('user', $authenticated));

        // 3) 其余维度的行为不受影响（ip 维度仍按对端地址）
        $this->assertSame('203.0.113.5', $probe->identifierForTest('ip', $this->makeRequest('203.0.113.5')));
    }

    /**
     * 用例 10：解析失败不得抛异常（返回 null 即可）
     *
     * 限流不该因为「算不出 key」把请求打成 500；调用方对 null 的处理是「跳过该维度」。
     */
    public function test_unknown_scope_returns_null(): void
    {
        $probe = new RateLimitProbe();

        $this->assertNull($probe->identifierForTest('not-a-scope', $this->makeRequest('203.0.113.5')));
    }
}
