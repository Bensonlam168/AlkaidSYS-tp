<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Infrastructure\Auth\JwtService;
use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\ThinkPHPTestCase;
use think\facade\Db;
use think\facade\Env;

/**
 * 会话失效闭环测试（ER-04）
 *
 * 缺陷：`app\middleware\Auth` 只校验 JWT 自身（签名/过期/type），**从不回查**
 * 用户与租户的当前状态；`AuthController::refresh()` 只校验 refresh token 自身。
 * 于是管理员把用户设为 `inactive` / `locked`、或把租户设为 `suspended` / 过期后，
 * 该主体**已签发的**令牌在有效期内仍然完全可用（Access 2 小时、Refresh 7 天）。
 *
 * 本文件覆盖的验收场景（全部真跑，不 skip、不放宽断言）：
 *   1. 用户 `active` → 正常访问（正面对照，证明链路本身有效）
 *   2. 用户改 `inactive` → **同一 token 立刻 401**（核心证据）
 *   3. 用户改 `locked` → 401
 *   4. 用户被删除 → 401；用户被改到其它租户 → 401
 *   5. 租户 `suspended` → 401
 *   6. 租户 `expires_at` 为过去时间 → 401
 *   7. 用户停用后用 refresh token 换 access token → 被拒（且旧 refresh 被彻底作废）
 *   8. 停用用户重新登录 → 被拒；停用默认租户下登录 → 被拒
 *   9. 把状态改回 `active` → **同一 token 恢复可用**（证明不是误杀）
 *
 * ## 为什么这些用例**不带** `X-Tenant-ID`
 *
 * `TenantIdentify` 会对**请求头显式指定**的租户提前做状态校验（不可用 → 403）。
 * 若带着 `X-Tenant-ID` 去测「租户被停用」，请求会在 TenantIdentify 就被拦下，
 * 断言 401 会失败、而改成断言 403 又等于根本没测到 `Auth` 中间件这一层。
 * 因此这里走「不带租户头」的真实路径：TenantIdentify 落到默认租户（租户 1，始终可用），
 * 随后由 `Auth` 按 **token 里的 tenant_id** 做闭环校验 —— 这也正是审计报告里
 * 「省略租户头仍可使用旧会话」的那条攻击路径。
 * 只有登录用例需要请求租户与 fixture 租户一致（登录按请求租户查用户），
 * 其中「停用默认租户」那条通过覆盖 `DEFAULT_TENANT_ID` 走 TenantIdentify 的默认分支。
 *
 * ## 夹具与清理
 *
 * 固定 ID 段取 48xx（避开：seeder 权限 1001..1033、工作流夹具 6000-6999、
 * 既有用例的 99xxxx 与 >=10000 约定），并叠加自然键（`%@er04-session.test`、
 * `er04-session-%` slug）清理，保证：
 *   - 只动本用例的行；
 *   - 即使某次 `setUp()` 中途失败（PHPUnit 不调 `tearDown()`）也不会留下残留。
 *
 * @package Tests\Feature\Auth
 */
class SessionInvalidationTest extends ThinkPHPTestCase
{
    /**
     * 主测试租户（可用）
     */
    private const TENANT_ID = 4801;

    /**
     * 备用租户（用于「用户被改到其它租户」场景）
     */
    private const ALT_TENANT_ID = 4802;

    /**
     * 测试用户
     */
    private const USER_ID = 4811;

    /**
     * 测试用户邮箱（自然键清理用）
     */
    private const USER_EMAIL = 'er04-session-user@er04-session.test';

    /**
     * 测试用户密码（登录链路用）
     */
    private const USER_PASSWORD = 'secret123';

    /**
     * 追踪头（断言 401 响应带 trace_id 用）
     */
    private const TRACE_HEADER = 'trace-er04-session-invalidation';

    /**
     * 覆盖前的 DEFAULT_TENANT_ID（用例结束后恢复）
     */
    private ?string $originalDefaultTenantId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 每次新建 app\Request（容器复用的 Request 会带上一个用例的路由规则/trace_id）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载认证路由（/v1/auth/me、/v1/auth/codes 挂着 Auth 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其它测试类可能把 Auth 绑定成 no-op（匿名类），
        // 而容器会缓存首次解析结果，导致本类拿到假中间件、断言变成「假绿」。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());

        // 复位路由/控制器中间件队列：ThinkPHP 的 `think\Middleware::$queue` 是进程级累加、
        // 框架从不清空。前序用例请求过 `v1/auth/me` 之后，`Auth` 会留在队列里，
        // 于是本类的 `POST v1/auth/login`（本不该挂 Auth）会被套上一层鉴权并 401。
        // 这里同 WorkflowTenantIsolationTest 的做法，按类型清空 route/controller 队列。
        $this->resetRouteMiddlewareQueue();

        $this->originalDefaultTenantId = Env::get('DEFAULT_TENANT_ID');

        $this->purgeFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        // 恢复 DEFAULT_TENANT_ID（登录用例会临时改写）
        if ($this->originalDefaultTenantId !== null) {
            Env::set('DEFAULT_TENANT_ID', $this->originalDefaultTenantId);
        }

        $this->purgeFixtures();

        // 同样在结束前清空队列，避免把 `Auth` 留给后续测试类
        $this->resetRouteMiddlewareQueue();

        parent::tearDown();
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     *
     * 与 `WorkflowTenantIsolationTest::resetRouteMiddlewareQueue()` 同源：
     * `think\Middleware::$queue` 是受保护属性、没有公开 API，只能反射。
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    // =====================================================================
    // 1. 正面对照：用户 active → 正常访问
    // =====================================================================

    /**
     * 场景 1：用户 `active` 时，合法 Access Token 正常放行
     *
     * 该用例同时证明「测试链路本身有效」（路由已加载、中间件真实生效、夹具用户存在），
     * 否则后面所有 401 断言都无法区分「闭环生效」与「链路本来就坏」。
     */
    public function testActiveUserCanAccessProtectedEndpoint(): void
    {
        $token = $this->issueAccessToken();

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getCode(), 'active 用户应能访问 /v1/auth/me，响应：' . $response->getContent());
        $this->assertSame(0, $body['code'] ?? null);
        $this->assertSame(self::USER_ID, $body['data']['user']['id'] ?? null);
        $this->assertSame(self::TENANT_ID, $body['data']['user']['tenant_id'] ?? null);
    }

    // =====================================================================
    // 2. 用户 inactive → 同一 token 立刻 401（核心证据）
    // =====================================================================

    /**
     * 场景 2：把用户改成 `inactive` 后，**同一张** Access Token 立即失效
     *
     * 这是 ER-04 的核心证据：令牌的签名、`exp`、`type` 全都没变，
     * 只有数据库里的 `users.status` 变了，请求就必须从 200 变成 401。
     */
    public function testInactiveUserTokenIsRejectedImmediately(): void
    {
        $token = $this->issueAccessToken();

        // 停用前：同一 token 可用（正面对照压在同一用例里，避免「夹具建错」导致的假绿）
        $before = $this->getMe($token);
        $this->assertSame(200, $before->getCode(), '停用前应可访问，响应：' . $before->getContent());

        $this->setUserStatus('inactive');

        $after = $this->getMe($token);
        $body = $this->decode($after);

        $this->assertSame(401, $after->getCode(), '停用后同一 token 必须 401，响应：' . $after->getContent());
        $this->assertSame(2001, $body['code'] ?? null, '会话失效必须返回业务码 2001（不是 2002/403）');
        $this->assertArrayHasKey('trace_id', $body, '401 响应必须带 trace_id');
        $this->assertSame(self::TRACE_HEADER, $body['trace_id']);

        // 对外不泄露具体原因：日志里是 user_inactive，响应体里不能出现
        $this->assertStringNotContainsString('user_inactive', $after->getContent());
        $this->assertStringNotContainsString('inactive', strtolower((string) ($body['message'] ?? '')));
    }

    // =====================================================================
    // 3. 用户 locked → 401
    // =====================================================================

    /**
     * 场景 3：用户被 `locked` → 同一 token 401
     */
    public function testLockedUserTokenIsRejected(): void
    {
        $token = $this->issueAccessToken();

        $this->setUserStatus('locked');

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), 'locked 用户必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    // =====================================================================
    // 4. 用户被删除 / 被改租户 → 401
    // =====================================================================

    /**
     * 场景 4a：用户被删除 → 同一 token 401
     *
     * 删除后 `findById($userId, $tenantId)` 查不到 → `user_not_found`。
     */
    public function testDeletedUserTokenIsRejected(): void
    {
        $token = $this->issueAccessToken();

        Db::name('user_roles')->where('user_id', self::USER_ID)->delete();
        Db::name('users')->where('id', self::USER_ID)->delete();

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), '用户被删除后同一 token 必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    /**
     * 场景 4b：用户被改到其它租户 → 旧 token（tenant_id 仍指向原租户）401
     *
     * 语义：令牌声明的 `tenant_id` 是权威范围；用户在**该范围内**已不存在，
     * 因此必须拒绝，不能继续用旧令牌按「用户仍存在」的身份访问原租户数据。
     */
    public function testUserMovedToAnotherTenantIsRejected(): void
    {
        $token = $this->issueAccessToken();

        Db::name('users')->where('id', self::USER_ID)->update(['tenant_id' => self::ALT_TENANT_ID]);

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), '用户被改租户后旧 token 必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    // =====================================================================
    // 5. 租户 suspended → 401
    // =====================================================================

    /**
     * 场景 5：租户被 `suspended` → 其用户手中的 Access Token 401
     */
    public function testSuspendedTenantTokenIsRejected(): void
    {
        $token = $this->issueAccessToken();

        $before = $this->getMe($token);
        $this->assertSame(200, $before->getCode(), '停用租户前应可访问，响应：' . $before->getContent());

        $this->setTenantStatus('suspended');

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), '租户 suspended 后必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    // =====================================================================
    // 6. 租户 expires_at 过去时间 → 401
    // =====================================================================

    /**
     * 场景 6：租户 `expires_at` 设为过去时间（status 仍为 active）→ 401
     *
     * 单独覆盖「过期」维度：status 是 active，唯一变化是订阅时间到期。
     */
    public function testExpiredTenantTokenIsRejected(): void
    {
        $token = $this->issueAccessToken();

        Db::name('tenants')
            ->where('id', self::TENANT_ID)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $response = $this->getMe($token);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), '租户过期后必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    // =====================================================================
    // 7. refresh 链路：停用后不得续期，且旧 refresh token 被彻底作废
    // =====================================================================

    /**
     * 场景 7：用户停用后用 refresh token 换 access token → 被拒
     *
     * 这是「不修就等于没修」的一条：只要 refresh 还能换出新 access token，
     * 中间件的逐请求校验就会被无限续期绕过（每次续 2 小时）。
     *
     * 额外证据：拒绝时同时作废旧 refresh token —— 再次重放应得到 2007（已吊销），
     * 而不是继续按「主体不可用」被拒。
     */
    public function testRefreshIsRejectedAfterUserDeactivated(): void
    {
        $loginBody = $this->login();
        $refreshToken = (string) ($loginBody['data']['refresh_token'] ?? '');
        $this->assertNotSame('', $refreshToken, '登录必须返回 refresh_token');

        // 停用前：refresh 正常工作并轮换出新 refresh token
        // （先证明「链路本身能续期」，后面 401 才可归因于停用而不是链路坏掉）
        $firstRefresh = $this->refresh($refreshToken);
        $firstBody = $this->decode($firstRefresh);
        $this->assertSame(200, $firstRefresh->getCode(), '停用前 refresh 应成功，响应：' . $firstRefresh->getContent());

        $rotatedRefreshToken = (string) ($firstBody['data']['refresh_token'] ?? '');
        $this->assertNotSame('', $rotatedRefreshToken, 'refresh 轮换必须返回新的 refresh_token');

        $this->setUserStatus('inactive');

        $rejected = $this->refresh($rotatedRefreshToken);
        $rejectedBody = $this->decode($rejected);

        $this->assertSame(401, $rejected->getCode(), '停用后 refresh 必须 401，响应：' . $rejected->getContent());
        $this->assertSame(2001, $rejectedBody['code'] ?? null, '会话失效必须 2001');
        $this->assertStringNotContainsString('user_inactive', $rejected->getContent());

        // 旧 refresh token 已作废：重放得到「已吊销」而不是「主体不可用」
        $replay = $this->refresh($rotatedRefreshToken);
        $replayBody = $this->decode($replay);
        $this->assertSame(401, $replay->getCode());
        $this->assertSame(2007, $replayBody['code'] ?? null, '被拒的 refresh token 必须彻底作废（重放应为 2007）');
    }

    /**
     * 场景 7 补充：租户停用后 refresh 同样被拒
     *
     * 只校验用户状态、不校验租户，会让「租户被停用」这一维度从 refresh 侧漏出去。
     */
    public function testRefreshIsRejectedAfterTenantSuspended(): void
    {
        $loginBody = $this->login();
        $refreshToken = (string) ($loginBody['data']['refresh_token'] ?? '');
        $this->assertNotSame('', $refreshToken);

        $this->setTenantStatus('suspended');

        $response = $this->refresh($refreshToken);
        $body = $this->decode($response);

        $this->assertSame(401, $response->getCode(), '租户停用后 refresh 必须 401，响应：' . $response->getContent());
        $this->assertSame(2001, $body['code'] ?? null);
    }

    // =====================================================================
    // 8. 登录链路：停用用户 / 停用租户都不得拿到新令牌
    // =====================================================================

    /**
     * 场景 8a：停用用户重新登录 → 被拒
     *
     * 现状核实：`AuthController::login()` **原本就已拒绝**非 active 用户
     * （`if (!$user->isActive()) return $this->error('User account is not active', 403)`），
     * 因此本条是**回归护栏**（防止将来被删掉），不是本次新增行为。
     *
     * 注意 HTTP 状态：该调用是 `error($msg, 403)`（只传了业务码，未传第 4 个参数），
     * 所以**HTTP 400 + 业务码 403** —— 这是改造前就存在的对外契约（见改造报告「未做项」），
     * 本次不顺手改，避免与 ER-04 无关的客户端行为变更混在一起。
     */
    public function testLoginIsRejectedForInactiveUser(): void
    {
        $this->setUserStatus('inactive');

        $response = $this->post('v1/auth/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
        ], ['X-Tenant-ID' => (string) self::TENANT_ID]);

        $body = $this->decode($response);

        $this->assertSame(403, $body['code'] ?? null, '停用用户登录必须被拒（业务码 403），响应：' . $response->getContent());
        $this->assertNotSame(200, $response->getCode(), '停用用户登录绝不能成功');
        $this->assertArrayNotHasKey('access_token', $body['data'] ?? []);
    }

    /**
     * 场景 8b：默认租户被停用 → 登录被拒（本次新增的登录侧闭环）
     *
     * 为什么这条要覆盖 `DEFAULT_TENANT_ID`：
     *   `TenantIdentify` 只校验**请求头显式指定**的租户；请求不带 `X-Tenant-ID` 时
     *   它落到「配置的默认租户」并**不校验状态**。因此「默认租户被停用」是登录侧
     *   唯一能绕过租户可用性检查的路径 —— 也正是本用例要钉住的缺口。
     *   （若这里带 `X-Tenant-ID`，请求会在 TenantIdentify 就被 403，测不到登录侧逻辑。）
     */
    public function testLoginIsRejectedForSuspendedDefaultTenant(): void
    {
        $this->setTenantStatus('suspended');
        Env::set('DEFAULT_TENANT_ID', (string) self::TENANT_ID);

        $response = $this->post('v1/auth/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
        ], []);

        $body = $this->decode($response);

        $this->assertSame(403, $response->getCode(), '停用租户下登录必须被拒，响应：' . $response->getContent());
        $this->assertSame(2002, $body['code'] ?? null, '租户不可用沿用 2002（与 TenantIdentify 一致）');
        $this->assertArrayNotHasKey('access_token', $body['data'] ?? []);
    }

    // =====================================================================
    // 9. 反向：改回 active → 同一 token 恢复可用
    // =====================================================================

    /**
     * 场景 9：把状态改回 `active` 后，**同一张** Access Token 恢复可用
     *
     * 证明闭环是「按当前状态判定」而不是「一旦拒绝就永久拉黑」：
     * 没有误杀、没有不可逆副作用。
     */
    public function testTokenWorksAgainAfterReactivation(): void
    {
        $token = $this->issueAccessToken();

        $this->setUserStatus('inactive');
        $this->assertSame(401, $this->getMe($token)->getCode(), '停用后应 401');

        $this->setUserStatus('active');
        $restored = $this->getMe($token);
        $body = $this->decode($restored);

        $this->assertSame(200, $restored->getCode(), '恢复 active 后同一 token 应恢复可用，响应：' . $restored->getContent());
        $this->assertSame(0, $body['code'] ?? null);
    }

    /**
     * 场景 9 补充：租户恢复 active 后同一 token 亦恢复可用
     */
    public function testTokenWorksAgainAfterTenantReactivated(): void
    {
        $token = $this->issueAccessToken();

        $this->setTenantStatus('suspended');
        $this->assertSame(401, $this->getMe($token)->getCode(), '租户停用后应 401');

        $this->setTenantStatus('active');
        $restored = $this->getMe($token);

        $this->assertSame(200, $restored->getCode(), '租户恢复后同一 token 应恢复可用，响应：' . $restored->getContent());
    }

    // =====================================================================
    // 请求辅助方法
    // =====================================================================

    /**
     * 访问受保护接口 GET /v1/auth/me
     *
     * 刻意**不**带 `X-Tenant-ID`：租户范围必须由 token 决定，
     * 这样才测得到「审计报告里那条省略租户头的攻击路径」。
     *
     * @param string $token Access Token
     * @return \think\Response
     */
    private function getMe(string $token)
    {
        return $this->get('v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
            'X-Trace-Id' => self::TRACE_HEADER,
        ]);
    }

    /**
     * 走真实 HTTP 链路发 GET 请求
     *
     * @param string $uri 路径
     * @param array $headers 请求头
     * @return \think\Response
     */
    private function get(string $uri, array $headers = [])
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * 走真实 HTTP 链路发 POST 请求
     *
     * @param string $uri 路径
     * @param array $data 表单数据
     * @param array $headers 请求头
     * @return \think\Response
     */
    private function post(string $uri, array $data = [], array $headers = [])
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * 核心请求辅助方法（每次新建 Request，避免容器复用的陈旧路由/上下文）
     *
     * @param string $method HTTP 方法
     * @param string $uri 路径
     * @param array $data 请求数据
     * @param array $headers 请求头
     * @return \think\Response
     */
    private function request(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 每个请求前清一次路由中间件队列：同一用例里的多次请求（如「停用前 200 / 停用后 401」）
        // 若共用累加队列，前一请求挂上的 `Auth` 会被后一请求重复执行，断言就失去可归因性。
        $this->resetRouteMiddlewareQueue();

        $request = new \app\Request();
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } else {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }

    /**
     * 登录并返回响应体（必须成功）
     *
     * @return array
     */
    private function login(): array
    {
        $response = $this->post('v1/auth/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
        ], ['X-Tenant-ID' => (string) self::TENANT_ID]);

        $body = $this->decode($response);
        $this->assertSame(200, $response->getCode(), '夹具用户登录应成功，响应：' . $response->getContent());
        $this->assertSame(0, $body['code'] ?? null);

        return $body;
    }

    /**
     * 用 refresh token 换新令牌
     *
     * @param string $refreshToken Refresh Token
     * @return \think\Response
     */
    private function refresh(string $refreshToken)
    {
        return $this->post('v1/auth/refresh', [], [
            'Authorization' => 'Bearer ' . $refreshToken,
        ]);
    }

    /**
     * 签发夹具用户的 Access Token
     *
     * 用 `JwtService` 真实签发（与登录链路同一套密钥/issuer/claims），
     * 而不是手工拼 JWT —— 后者一旦签名参数漂移，测试就会退化成「自证」。
     *
     * @return string
     */
    private function issueAccessToken(): string
    {
        return (new JwtService())->generateAccessToken(self::USER_ID, self::TENANT_ID, 0);
    }

    /**
     * 解析响应体
     *
     * @param \think\Response $response 响应
     * @return array
     */
    private function decode($response): array
    {
        $body = json_decode($response->getContent(), true);

        return is_array($body) ? $body : [];
    }

    // =====================================================================
    // 夹具
    // =====================================================================

    /**
     * 更新夹具用户状态
     *
     * @param string $status active / inactive / locked
     */
    private function setUserStatus(string $status): void
    {
        Db::name('users')->where('id', self::USER_ID)->update(['status' => $status]);
    }

    /**
     * 更新夹具租户状态
     *
     * @param string $status active / inactive / suspended
     */
    private function setTenantStatus(string $status): void
    {
        Db::name('tenants')->where('id', self::TENANT_ID)->update(['status' => $status]);
    }

    /**
     * 播种夹具：两个租户 + 一个 active 用户
     *
     * 两个租户的理由：`users.tenant_id` 有外键约束，
     * 「把用户改到其它租户」的场景必须先有一个真实可用的目标租户行。
     */
    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        Db::name('tenants')->insertAll([
            [
                'id' => self::TENANT_ID,
                'name' => 'ER-04 Session Tenant',
                'slug' => 'er04-session-tenant',
                'status' => 'active',
                'config' => json_encode([]),
                'max_sites' => 1,
                'max_users' => 10,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::ALT_TENANT_ID,
                'name' => 'ER-04 Session Alt Tenant',
                'slug' => 'er04-session-tenant-alt',
                'status' => 'active',
                'config' => json_encode([]),
                'max_sites' => 1,
                'max_users' => 10,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::name('users')->insert([
            'id' => self::USER_ID,
            'tenant_id' => self::TENANT_ID,
            'username' => 'er04_session_user',
            'email' => self::USER_EMAIL,
            'password' => password_hash(self::USER_PASSWORD, PASSWORD_DEFAULT),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 清理夹具（ID 段 + 自然键双条件，幂等）
     */
    private function purgeFixtures(): void
    {
        try {
            (new PermissionCacheService())->clearUserCache(self::USER_ID, self::TENANT_ID);
        } catch (\Throwable $e) {
            // 缓存清理失败不影响夹具清理结论（测试库是文件缓存，最坏留一个过期键）
        }

        Db::name('user_roles')->where('user_id', self::USER_ID)->delete();
        Db::name('users')->where('id', self::USER_ID)->delete();
        Db::name('users')->where('email', self::USER_EMAIL)->delete();

        Db::name('tenants')->whereIn('id', [self::TENANT_ID, self::ALT_TENANT_ID])->delete();
        Db::name('tenants')->where('slug', 'like', 'er04-session-%')->delete();
    }
}
