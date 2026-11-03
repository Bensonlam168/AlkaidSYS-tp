<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Infrastructure\Auth\JwtService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\DatabaseTransactions;

/**
 * 认证中间件 Token 类型校验测试（缺陷 C-6）
 *
 * 背景：`app/middleware/Auth` 原先只调用 `JwtService::validateToken()`（仅校验签名），
 * 因此用 Refresh Token 也能访问受保护接口。本测试确保受保护接口只接受 Access Token。
 *
 * 目标行为：
 * - Access Token 访问受保护接口 → 200
 * - Refresh Token 访问同一接口 → 401 + 业务码 2001
 *
 * ## 数据库隔离（ER-17）
 *
 * 与其余 4 个「写库但无清理」的测试不同，**本文件当前并不写 MySQL**：整条链路
 * （全局中间件 + `Auth` 中间件 + `AuthController::me`）只读不写，access log 落文件、
 * session 走 Redis。实测（连跑 2 次、比对测试库 33 张表的精确行数）**零变化**。
 *
 * 仍然挂 `DatabaseTransactions` 的两个理由：
 *   1. **防回归**：`/v1/auth/me` 这类受保护接口一旦引入任何写库动作（如登录轨迹、最后登录时间），
 *      没有事务就会重新变成「跑一次脏一次」，而这类残渣极难归因；
 *   2. 它顺带钉住一件事：`#[After]` 的「事务是否被隐式提交」检测在 **HTTP 全链路**下也能通过
 *      —— 说明 `http->run()` 没有把开始事务的连接换掉（见 traits/DatabaseTransactions.php 的检测 1）。
 *
 * 为什么**可以**用事务：本文件没有 DDL；`ThinkPHPTestCase::runHttp()` 与继承来的
 * `Db` 用同一个默认连接，事务回滚能覆盖它。
 *
 * 说明：本类当前有 1 个 failure（`testAccessTokenIsAccepted` 得到 400）。
 * 根因是测试库 `users` 表里没有 id=1 的种子用户，`AuthController::me` 返回「用户不存在」——
 * **既有缺陷，与本改造无关，也不要靠改断言或 skip 掩盖**。
 */
class AuthTokenTypeTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // 清除容器中可能被上一个用例污染的 Request 实例
        //
        // 背景：`tests/ThinkPHPTestCase` 复用同一个 App 实例，容器 make() 会缓存实例，
        // 上一个用例的 Request（含其路由规则/租户上下文）可能被复用，导致无关的 401/403。
        // 这是已知的测试隔离缺陷，此处做局部规避。
        try {
            $this->app()->delete(\think\Request::class);
        } catch (\Throwable $e) {
            // 容器未注册时忽略
        }

        // 绑定自定义 Request，确保 tenantId/siteId/userId 行为一致
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载认证路由（/v1/auth/me 带 Auth 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/auth.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }
    }

    /**
     * 使用指定 token 访问受保护接口 GET /v1/auth/me
     *
     * @param string $token 待验证的 JWT
     * @return \think\Response
     */
    private function requestMe(string $token)
    {
        // 每次新建 app\Request 实例，而非从容器 make()。
        // 原因：容器中的 Request 会残留上一个用例的路由规则（rule），
        // 导致请求被套用错误的路由中间件。
        $request = new \app\Request();
        $request->setMethod('GET');
        $request->setPathinfo('v1/auth/me');
        $request = $request->withHeader([
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-ID'   => '1',
            'X-Trace-Id'    => 'trace-token-type-' . uniqid(),
        ]);

        return $this->runHttp($request);
    }

    /**
     * 基线用例：Access Token 必须能正常访问受保护接口
     *
     * 该用例用于证明测试链路本身有效（路由已加载、用户存在、中间件正常放行）。
     */
    public function testAccessTokenIsAccepted(): void
    {
        // 通过测试辅助工具签发合法 Access Token（type=access）
        $token = AuthHelper::generateTestToken(userId: 1, tenantId: 1, siteId: 0);

        $response = $this->requestMe($token);
        $body     = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getCode(), 'Access Token 应能访问 /v1/auth/me');
        $this->assertSame(0, $body['code'] ?? null, '成功响应的业务码应为 0');
    }

    /**
     * 核心用例：Refresh Token 不得访问受保护接口（缺陷 C-6）
     *
     * Refresh Token 签名有效但 type=refresh，认证中间件必须拒绝并返回 401 / 2001。
     */
    public function testRefreshTokenIsRejected(): void
    {
        // 直接用 JwtService 签发 Refresh Token（签名有效，仅类型不是 access）
        $jwtService   = new JwtService();
        $refreshToken = $jwtService->generateRefreshToken(1, 1, 0);

        $response = $this->requestMe($refreshToken);
        $body     = json_decode($response->getContent(), true);

        $this->assertSame(401, $response->getCode(), 'Refresh Token 访问受保护接口必须返回 401');
        $this->assertSame(2001, $body['code'] ?? null, '业务码必须为 2001（Unauthorized）');
    }
}
