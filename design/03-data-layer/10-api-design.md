# AlkaidSYS API 设计规范

> ⚠️ **命名空间声明**：本文示例代码使用**设计阶段的命名**（如 `app\common\*`、`alkaid\lowcode\*`），
> 与最终实现结构不同。**请勿直接照抄**，实际命名映射见
> [`../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md`](../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md) §5.3。

## 📋 文档信息

| 项目 | 内容 |
|------|------|
| **文档名称** | AlkaidSYS API 设计规范 |
| **文档版本** | v1.0 |
| **创建日期** | 2025-01-19 |

## 🎯 API 设计目标

1. **RESTful 规范** - 遵循 REST 架构风格
2. **统一格式** - 统一的请求/响应格式
3. **版本管理** - 支持 API 版本演进
4. **文档自动化** - 自动生成 API 文档
5. **安全防护** - 限流、防刷、签名验证

## 🏗️ RESTful API 规范

### 1. HTTP 方法使用

| 方法 | 用途 | 示例 |
|------|------|------|
| **GET** | 获取资源 | `GET /api/users` - 获取用户列表 |
| **POST** | 创建资源 | `POST /api/users` - 创建用户 |
| **PUT** | 完整更新资源 | `PUT /api/users/1` - 更新用户 |
| **PATCH** | 部分更新资源 | `PATCH /api/users/1` - 部分更新用户 |
| **DELETE** | 删除资源 | `DELETE /api/users/1` - 删除用户 |

### 2. URL 设计规范

```
✅ 好的 URL 设计：
GET    /api/v1/users                    # 获取用户列表
GET    /api/v1/users/123                # 获取用户详情
POST   /api/v1/users                    # 创建用户
PUT    /api/v1/users/123                # 更新用户
DELETE /api/v1/users/123                # 删除用户
GET    /api/v1/users/123/orders         # 获取用户的订单列表
POST   /api/v1/users/123/orders         # 为用户创建订单

❌ 不好的 URL 设计：
GET    /api/getUserList                 # 不要在 URL 中使用动词
POST   /api/createUser                  # 不要在 URL 中使用动词
GET    /api/user?action=delete&id=123   # 不要使用查询参数表示操作
```

### 3. 查询参数规范

```
# 分页
GET /api/users?page=1&page_size=20

# 过滤
GET /api/users?status=1&role=admin

# 排序
GET /api/users?sort=-created_at,+name  # - 表示降序，+ 表示升序

# 字段筛选
GET /api/users?fields=id,name,email

# 搜索
GET /api/users?search=john

# 关联查询
GET /api/users?include=roles,permissions
```

## 📦 统一响应格式

### 统一响应规范（最终版）

统一响应结构与语义如下（与《前端错误与权限处理规范》保持一致）：

- 顶层字段固定为：`code`、`message`、`data`、`timestamp`、`trace_id?`；
- `code`: 业务状态码，`0` 表示成功，非 `0` 表示失败（具体码值在错误码表中维护）；
- `message`: 人类可读的提示文案；
- `data`: 业务数据对象或 `null`；
- `timestamp`: 服务器时间戳（秒）；
- `trace_id`: 可选的请求追踪 ID，由中间件/基础设施注入，用于日志与排错。

> 说明：HTTP 状态码用于表达传输语义（200/4xx/5xx），业务状态采用 `code` 字段；
> 只要 `code != 0`，前端即可视为失败，结合 HTTP 状态与业务 code 决定具体展示逻辑。

### 成功响应

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com"
  },
  "timestamp": 1705651200
}
```

### 列表响应

```json
{
  "code": 0,
  "message": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "username": "admin"
      },
      {
        "id": 2,
        "username": "user"
      }
    ],
    "total": 100,
    "page": 1,
    "page_size": 20,
    "total_pages": 5
  },
  "timestamp": 1705651200
}
```

### 错误响应

```json
{
  "code": 422,
  "message": "验证失败",
  "data": {
    "errors": {
      "email": ["邮箱格式不正确"],
      "password": ["密码长度至少 6 位"]
    }
  },
  "timestamp": 1705651200
}
```

### 统一 API 响应规范（AlkaidSYS-tp 实现约定）

> 本小节针对当前 AlkaidSYS-tp 后端代码实现，对统一响应格式做**落地级约定**，与 `app/controller/ApiController.php` 保持一一对应。

#### 1. 统一响应结构（实现口径）

- 顶层字段固定为：`code`、`message`、`data`、`timestamp`、`trace_id?`；
- `code`：业务状态码，**`0` 表示成功**，非 `0` 表示失败；
  - 认证与授权相关错误码统一在 `2001~2007` 区间（详见《安全架构设计》错误码矩阵）；
  - 通用服务器内部错误统一使用 `5000`；
- `message`：人类可读的提示文案（英文为主，可按需在前端本地化）；
- `data`：业务数据对象或 `null`；
- `timestamp`：服务器时间戳（秒）；
- `trace_id`：可选请求追踪 ID，由 Trace 中间件注入，用于日志与排错。

> 规则：
> - HTTP 状态码用于表达传输/语义（200/201/4xx/5xx）；
> - 业务状态使用 `code` 字段，只要 `code != 0` 前端即视为失败；
> - 具体错误码、含义与 HTTP 状态映射以 `design/04-security-performance/11-security-design.md` 中的错误码矩阵为准。

#### 2. ApiController 标准方法清单

当前项目中，对外 API 控制器统一继承自 `app\controller\ApiController`，该类封装了标准响应方法（仅列出关键方法）：

- `success(mixed $data = null, string $message = 'Success', int $code = 0, int $httpCode = 200)`
  - 语义：业务成功；
  - 默认：`code = 0`，`httpCode = 200`。
- `paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success')`
  - 语义：统一分页响应封装；
  - `data` 结构固定为 `{ list, total, page, page_size, total_pages }`（**2026-10-30 校正**：原写 `pageSize`，与实现及本文 `:308` 不一致，已统一为 snake_case 并补 `total_pages`）。
- `error(string $message, int $code = 400, array $errors = [], int $httpCode = 400)`
  - 语义：业务失败；
  - 当传入 `$errors` 时，`data` 字段为 `{ errors: {...} }`，否则为 `null`。
- `validationError(array $errors, string $message = 'Validation failed')`
  - 语义：参数/表单验证失败；
  - 默认：`code = 422`，`httpCode = 422`，`data.errors` 中承载字段级错误信息。
- `notFound(string $message = 'Resource not found')`
  - 语义：资源不存在；默认 `code = 404`，`httpCode = 404`。
- `unauthorized(string $message = 'Unauthorized')`
  - 语义：未认证；默认 `code = 401`，`httpCode = 401`。
- `forbidden(string $message = 'Forbidden')`
  - 语义：已登录但权限不足；默认 `code = 403`，`httpCode = 403`。

> 说明：认证/授权相关中间件（`Auth`、`Permission`）在返回 401/403 时，也必须复用相同的顶层字段约定，以便前端统一处理。

#### 3. HTTP 状态码与业务错误码映射（摘要）

- 成功场景：
  - `HTTP 200/201` + `code = 0`；
- 客户端错误：
  - 参数/验证错误：`HTTP 422` + `code = 422`；
  - 未认证：`HTTP 401` + `code = 2001`；
  - 权限不足：`HTTP 403` + `code = 2002`；
  - Refresh Token 相关错误：`HTTP 401` + `code = 2003~2007`；
- 服务器内部错误：
  - `HTTP 500` + `code = 5000`（统一由全局异常处理或中间件包装）。

> 完整错误码清单与说明请参考：
> - `design/04-security-performance/11-security-design.md` 中的“认证与授权错误码矩阵”；
> - 其他业务模块的错误码可在各自子文档中扩展，但必须遵守顶层响应结构。

#### 4. 控制器使用示例（基于 ApiController）

以下示例展示业务控制器如何通过 `ApiController` 返回统一格式：

```php
<?php
namespace app\controller;

use think\Response;

class UserController extends ApiController
{
    public function index(): Response
    {
        $users = [/* ... 从服务层获取用户列表 ... */];
        return $this->success($users, 'Get users successfully');
    }

    public function show(int $id): Response
    {
        $user = /* ... 查找用户 ... */ null;
        if (!$user) {
            return $this->notFound('User not found');
        }
        return $this->success($user);
    }
}
```

#### 5. 强制要求（项目级约束）

- 所有对外 API 控制器（REST API、低代码 API、认证 API 等）**必须继承** `app\controller\ApiController`；
- 控制器内部**禁止直接使用** `return json([...])` 输出响应，必须通过上述标准方法间接输出；
- 中间件与全局异常处理在特殊情况下可以直接构造 JSON 响应，但必须：
  - 使用相同的顶层字段结构：`code/message/data/timestamp(/trace_id)`；
  - 对认证与授权场景遵守错误码矩阵中的约定（2001~2007、5000 等）。

该约定完成后，前端与第三方客户端只需依赖统一响应结构与错误码表即可稳定集成，无需关心具体控制器实现细节。

## 🔧 Controller 实现

### 基础 Controller

```php
<?php
// /app/common/controller/BaseController.php

namespace app\common\controller;

use think\App;
use think\Response;

abstract class BaseController
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 成功响应
     */
    protected function success($data = null, string $message = '操作成功', int $code = 200): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    /**
     * 失败响应
     */
    protected function error(string $message = '操作失败', int $code = 400, $data = null): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    /**
     * 分页响应
     */
    protected function paginate($list, int $total, int $page, int $pageSize): Response
    {
        return json([
            'code' => 200,
            'message' => '获取成功',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => ceil($total / $pageSize),
            ],
            'timestamp' => time(),
        ]);
    }
}
```

### 用户 Controller 示例

```php
<?php
// /app/api/v1/controller/UserController.php

namespace app\api\v1\controller;

use app\common\controller\BaseController;
use app\common\model\User;
use app\common\validate\UserValidate;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="用户管理",
 *     description="用户相关接口"
 * )
 */
class UserController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/users",
     *     tags={"用户管理"},
     *     summary="获取用户列表",
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="页码",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="page_size",
     *         in="query",
     *         description="每页数量",
     *         required=false,
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="状态",
     *         required=false,
     *         @OA\Schema(type="integer", enum={0, 1})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="获取成功"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="list", type="array", @OA\Items(ref="#/components/schemas/User")),
     *                 @OA\Property(property="total", type="integer", example=100),
     *                 @OA\Property(property="page", type="integer", example=1),
     *                 @OA\Property(property="page_size", type="integer", example=20)
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $page = $this->request->param('page', 1);
        $pageSize = $this->request->param('page_size', 20);
        $status = $this->request->param('status');

        $query = User::order('id', 'desc');

        if ($status !== null) {
            $query->where('status', $status);
        }

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page' => $page,
        ]);

        return $this->paginate(
            $list->items(),
            $list->total(),
            $page,
            $pageSize
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{id}",
     *     tags={"用户管理"},
     *     summary="获取用户详情",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="用户ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=404, description="用户不存在")
     * )
     */
    public function read($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }
        return $this->success($user);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users",
     *     tags={"用户管理"},
     *     summary="创建用户",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username", "email", "password"},
     *             @OA\Property(property="username", type="string", example="john"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="password", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="创建成功",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=422, description="验证失败")
     * )
     */
    public function save()
    {
        $data = $this->request->post();

        try {
            validate(UserValidate::class)->check($data);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError(), 422);
        }

        // 密码加密
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $user = User::create($data);
        return $this->success($user, '创建成功', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{id}",
     *     tags={"用户管理"},
     *     summary="更新用户",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="用户ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="username", type="string"),
     *             @OA\Property(property="email", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="更新成功"),
     *     @OA\Response(response=404, description="用户不存在")
     * )
     */
    public function update($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $data = $this->request->put();

        try {
            validate(UserValidate::class)->scene('update')->check($data);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError(), 422);
        }

        $user->save($data);
        return $this->success($user, '更新成功');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{id}",
     *     tags={"用户管理"},
     *     summary="删除用户",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="用户ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="删除成功"),
     *     @OA\Response(response=404, description="用户不存在")
     * )
     */
    public function delete($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $user->delete();
        return $this->success(null, '删除成功');
    }
}

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="username", type="string", example="john"),
 *     @OA\Property(property="email", type="string", example="john@example.com"),
 *     @OA\Property(property="status", type="integer", example=1),
 *     @OA\Property(property="created_at", type="string", example="2025-01-19 10:00:00")
 * )
 */
```

## 🔄 API 版本管理

### URL 版本管理

```php
<?php
// /route/api.php

use think\facade\Route;

// V1 版本
Route::group('api/v1', function () {
    Route::resource('users', 'api\v1\controller\UserController');
    Route::resource('orders', 'api\v1\controller\OrderController');
});

// V2 版本
Route::group('api/v2', function () {
    Route::resource('users', 'api\v2\controller\UserController');
    Route::resource('orders', 'api\v2\controller\OrderController');
});
```

### Header 版本管理

```php
<?php
// /app/middleware/ApiVersion.php

namespace app\middleware;

class ApiVersion
{
    public function handle($request, \Closure $next)
    {
        $version = $request->header('Api-Version', 'v1');

        // 设置版本到请求中
        $request->apiVersion = $version;

        return $next($request);
    }
}
```

## 🔒 API 限流

本节描述 API 限流的阶段性设计路径：

- **Phase 1（当前阶段实现——应用层固定时间窗口）**：
  - 实际中间件 `app/middleware/RateLimit.php` 基于缓存实现固定时间窗口计数，支持 `user` / `tenant` / `ip` / `route` 多维度限流；
  - 命中限流时返回 HTTP `429` + 业务 `code = 429`，并在 JSON `data` 与响应头中携带诊断信息（如 `scope`、`limit`、`period`、`current`、`identifier`、`Retry-After`、`X-Rate-Limited`、`X-RateLimit-Scope` 等）。
- **Phase 2（目标算法——基于 Redis 的令牌桶，必须落地）**：
  - 长期目标是将应用层/网关层限流统一演进为基于 Redis 的令牌桶算法，以便更平滑地处理突发流量并支持更精细的配额控制；
  - 下文 RateLimit 示例代码与 `X-RateLimit-*` 响应头仅作为 Phase 2 目标态参考实现，在真正落地前必须结合安全设计文档与技术规范进行评审与压测。

> 说明：限流能力的真实行为以 `docs/technical-specs/security/security-guidelines*.md` 中的 Phase 约定与当前中间件实现为准；设计文档中的示例代码可能领先于当前实现。

### 基于 Redis 的令牌桶算法

```php
<?php
// /app/middleware/RateLimit.php

namespace app\middleware;

use think\facade\Cache;

class RateLimit
{
    protected int $maxRequests = 60;  // 每分钟最大请求数
    protected int $duration = 60;     // 时间窗口（秒）

    public function handle($request, \Closure $next)
    {
        $key = $this->getKey($request);

        // 获取当前请求数
        $current = Cache::get($key, 0);

        if ($current >= $this->maxRequests) {
            return json([
                'code' => 429,
                'message' => '请求过于频繁，请稍后再试',
                'data' => null,
                'timestamp' => time(),
            ], 429);
        }

        // 增加请求计数
        Cache::inc($key);

        // 设置过期时间
        if ($current == 0) {
            // 注意：ThinkPHP Cache 门面无 expire 方法，需使用底层 handler
            Cache::handler()->expire($key, $this->duration);
        }

        // 添加限流头
        $response = $next($request);
        $response->header([
            'X-RateLimit-Limit' => $this->maxRequests,
            'X-RateLimit-Remaining' => $this->maxRequests - $current - 1,
            'X-RateLimit-Reset' => time() + $this->duration,
        ]);

        return $response;
    }

    protected function getKey($request): string
    {
        $userId = $request->userId() ?? 'guest';
        $route = $request->rule()->getRule();
        return "rate_limit:{$userId}:{$route}";
    }
}
```

## 📚 Swagger 文档生成

### OpenAPI 配置

```php
<?php
// /app/api/OpenApi.php

namespace app\api;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="AlkaidSYS API 文档",
 *     version="1.0.0",
 *     description="AlkaidSYS 系统 API 接口文档",
 *     @OA\Contact(
 *         email="team@alkaid.com",
 *         name="AlkaidSYS Team"
 *     )
 * )
 *
 * @OA\Server(
 *     url="http://localhost:9501",
 *     description="本地开发环境"
 * )
 *
 * @OA\Server(
 *     url="https://api.alkaid.com",
 *     description="生产环境"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class OpenApi
{
}
```

### 生成文档命令

```php
<?php
// /app/command/GenerateApiDoc.php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class GenerateApiDoc extends Command
{
    protected function configure()
    {
        $this->setName('api:doc')
            ->setDescription('生成 API 文档');
    }

    protected function execute(Input $input, Output $output)
    {
        $openapi = \OpenApi\Generator::scan([
            app_path('api'),
        ]);

        $docPath = public_path() . 'api-docs.json';
        file_put_contents($docPath, $openapi->toJson());

        $output->writeln('API 文档生成成功：' . $docPath);
    }
}
```

## 🔐 API 签名中间件（可选安全增强）

为开放给第三方或高价值接口提供的可选安全头部规范，与 JWT/限流并行使用。

### 安全请求头规范

| 请求头 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| X-App-Key | string | 是 | 应用密钥（平台分配） |
| X-Timestamp | integer | 是 | 请求时间戳（秒），默认允许 ±300s 偏移 |
| X-Nonce | string | 是 | 一次性随机串，建议 16 字节以上 CSPRNG |
| X-Signature | string | 是 | 签名值（默认 HMAC-SHA256） |
| X-Signature-Algorithm | string | 否 | 签名算法，默认 hmac-sha256，可选 rsa-sha256、ed25519 |
| X-Key-Id | string | 否 | 开发者公钥标识（用于非对称验签） |
| X-Key-Fingerprint | string | 否 | 公钥指纹（SHA-256，hex），用于密钥轮换/灰度 |

签名串（默认 HMAC-SHA256）建议包含方法、路径、时间戳、nonce 与原始请求体（或规范化 JSON）：

```
plain = method + '|' + path_with_query + '|' + timestamp + '|' + nonce + '|' + body
signature = HMAC_SHA256(plain, app_secret)
```

- 时间窗口：默认 300s；超窗/重复 nonce 均应拒绝
- nonce：服务端在时间窗内落盘/缓存去重（Key: nonce:{appKey}:{nonce}）
- 路由启用方式：在安全敏感路由组开启该中间件

### 市场签名策略增强（非对称验签可选）

- 开发者在“开发者中心”登记公钥（RSA-2048 或 Ed25519），平台保存：`key_id`、`public_key`、`fingerprint_sha256`
- 请求头新增：`X-Signature-Algorithm=ed25519|rsa-sha256`、`X-Key-Id`、`X-Key-Fingerprint`
- 签名串与 HMAC 相同；平台依据 `key_id/fingerprint` 取公钥验签
- 密钥轮换：同一开发者可并存多把公钥，通过 `fingerprint` 做灰度切换

数据库建议：

- developer_keys(id, developer_id, key_id, public_key, fingerprint_sha256 unique, algorithm enum, status, created_at)

### 示例响应头（建议）

- X-Request-ID: 全局请求链路 ID
- X-Server-Time: 服务器时间戳（便于客户端校时）
- X-Signature-Algorithm: 实际验签算法

## 📦 应用市场 API 设计

### 1. 应用浏览 API

#### 获取应用列表

```
GET /api/v1/market/apps
```

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| category | string | 否 | 分类（ecommerce/oa/crm/erp/cms/ai） |
| keyword | string | 否 | 搜索关键词 |
| sort | string | 否 | 排序（latest/popular/rating） |
| page | int | 否 | 页码（默认 1） |
| page_size | int | 否 | 每页数量（默认 20） |

**响应示例**：

```json
{
  "code": 200,
  "message": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "key": "ecommerce",
        "name": "电子商城",
        "category": "ecommerce",
        "version": "1.0.0",
        "description": "功能完整的电子商城应用",
        "icon": "https://cdn.example.com/apps/ecommerce/icon.png",
        "price": 0,
        "rating": 4.8,
        "download_count": 1234
      }
    ],
    "total": 100,
    "page": 1,
    "page_size": 20
  }
}
```

#### 获取应用详情

```
GET /api/v1/market/apps/{id}
```

**响应示例**：

```json
{
  "code": 200,
  "message": "获取成功",
  "data": {
    "id": 1,
    "key": "ecommerce",
    "name": "电子商城",
    "category": "ecommerce",
    "version": "1.0.0",
    "description": "功能完整的电子商城应用",
    "icon": "https://cdn.example.com/apps/ecommerce/icon.png",
    "cover": "https://cdn.example.com/apps/ecommerce/cover.png",
    "screenshots": [
      "https://cdn.example.com/apps/ecommerce/screenshot1.png",
      "https://cdn.example.com/apps/ecommerce/screenshot2.png"
    ],
    "price": 0,
    "rating": 4.8,
    "review_count": 567,
    "download_count": 1234,
    "developer": {
      "id": 1,
      "name": "AlkaidSYS Team",
      "avatar": "https://cdn.example.com/developers/1/avatar.png"
    },
        "latest_package_hash": "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",

    "versions": [
      {
        "version": "1.0.0",
        "package_hash": "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "changelog": "初始版本发布",
        "created_at": 1705651200
      }
    ],
    "reviews": [
      {
        "id": 1,
        "user": {
          "nickname": "张三",
          "avatar": "https://cdn.example.com/users/1/avatar.png"
        },
        "rating": 5,
        "content": "非常好用的应用！",
        "created_at": 1705651200
      }
    ]
  }
}
```

### 2. 应用管理 API

#### 下载应用

```
POST /api/v1/market/apps/{id}/download
```

**响应示例**：

```json
{
  "code": 200,
  "message": "下载成功",
  "data": {
    "download_url": "https://cdn.example.com/apps/ecommerce/1.0.0.zip",
    "expires_at": 1705651200
  }
}
```

#### 安装应用

```
POST /api/v1/apps/install
```

**请求参数**：

```json
{
  "app_id": 1,
  "tenant_id": 1,
  "site_id": 1,
  "config": {
    "default_currency": "CNY",
    "default_language": "zh-cn"
  }
}
```

**响应示例**：

```json
{
  "code": 200,
  "message": "安装成功",
  "data": {
    "installation_id": 1,
    "status": "installed"
  }
}
```

#### 卸载应用

```
DELETE /api/v1/apps/{id}/uninstall
```

**请求参数**：

```json
{
  "tenant_id": 1,
  "site_id": 1,
  "keep_data": false
}
```

#### 启用/禁用应用

```
PATCH /api/v1/apps/{id}/status
```

**请求参数**：

```json
{
  "tenant_id": 1,
  "site_id": 1,
  "status": 1
}
```

### 3. 应用评价 API

#### 提交评价

```
POST /api/v1/market/apps/{id}/reviews
```

**请求参数**：

```json
{
  "rating": 5,
  "content": "非常好用的应用！"
}
```

#### 获取评价列表

```
GET /api/v1/market/apps/{id}/reviews
```

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码（默认 1） |
| page_size | int | 否 | 每页数量（默认 20） |

### 4. 开发者 API

#### 发布应用

```
POST /api/v1/developer/apps
```

**请求参数**：

```json
{
  "file": "应用包文件（multipart/form-data）",
  "package_hash": "sha256:<hex-64>"
}
```

**响应示例**：

```json
{
  "code": 200,
  "message": "应用已提交审核",
  "data": {
    "app_id": 1,
    "status": "pending_review"
  }
}
```

#### 获取开发者应用列表

```
GET /api/v1/developer/apps
```

#### 获取开发者收益

```
GET /api/v1/developer/earnings
```

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| start_date | string | 否 | 开始日期（YYYY-MM-DD） |
| end_date | string | 否 | 结束日期（YYYY-MM-DD） |
| page | int | 否 | 页码（默认 1） |
| page_size | int | 否 | 每页数量（默认 20） |

**响应示例**：

```json
{
  "code": 200,
  "message": "获取成功",
  "data": {
    "total_earnings": 10000.00,
    "settled_earnings": 8000.00,
    "pending_earnings": 2000.00,
    "list": [
      {
        "id": 1,
        "order_no": "APP20250119001",
        "app_name": "电子商城",
        "amount": 100.00,
        "platform_fee": 30.00,
        "developer_fee": 70.00,
        "status": 1,
        "created_at": 1705651200
      }
    ],
    "total": 100,
    "page": 1,
    "page_size": 20
  }
}
```

## 🔌 插件市场 API 设计

### 1. 插件浏览 API

#### 获取插件列表

```
GET /api/v1/market/plugins
```

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| category | string | 否 | 分类（universal/app-specific） |
| app_key | string | 否 | 应用 key（查询应用专属插件） |
| keyword | string | 否 | 搜索关键词 |
| sort | string | 否 | 排序（latest/popular/rating） |
| page | int | 否 | 页码（默认 1） |
| page_size | int | 否 | 每页数量（默认 20） |

**响应示例**：

```json
{
  "code": 200,
  "message": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "key": "payment_wechat",
        "name": "微信支付",
        "category": "universal",
        "version": "1.0.0",
        "description": "微信支付插件",
        "icon": "https://cdn.example.com/plugins/payment_wechat/icon.png",
        "price": 199,
        "rating": 4.9,
        "download_count": 567
      }
    ],
    "total": 50,
    "page": 1,
    "page_size": 20
  }
}
```

#### 获取插件详情

```
GET /api/v1/market/plugins/{id}
```

**响应示例**：

```json
{
  "code": 200,
  "message": "获取成功",
  "data": {
    "id": 1,
    "key": "payment_wechat",
    "name": "微信支付",
    "category": "universal",
    "app_key": null,
    "version": "1.0.0",
    "description": "微信支付插件，支持扫码支付、H5 支付等",
    "icon": "https://cdn.example.com/plugins/payment_wechat/icon.png",
    "price": 199,
    "rating": 4.9,
    "review_count": 234,
    "download_count": 567,
    "latest_package_hash": "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
    "hooks": [
      "PaymentCreate",
      "PaymentQuery",
      "PaymentRefund",
      "PaymentNotify"
    ],
    "developer": {
      "id": 1,
      "name": "AlkaidSYS Team",
      "avatar": "https://cdn.example.com/developers/1/avatar.png"
    },
    "versions": [
      {
        "version": "1.0.0",
        "package_hash": "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "changelog": "初始版本发布",
        "created_at": 1705651200
      }
    ]
  }
}
```

### 2. 插件管理 API

#### 下载插件

```
POST /api/v1/market/plugins/{id}/download
```

#### 安装插件

```
POST /api/v1/plugins/install
```

**请求参数**：

```json
{
  "plugin_id": 1,
  "tenant_id": 1,
  "site_id": 1,
  "config": {
    "app_id": "wx1234567890",
    "mch_id": "1234567890",
    "api_key": "your_api_key"
  }
}
```

#### 卸载插件

```
DELETE /api/v1/plugins/{id}/uninstall
```

#### 启用/禁用插件

```
PATCH /api/v1/plugins/{id}/status
```

### 3. 插件评价 API

#### 提交评价

```
POST /api/v1/market/plugins/{id}/reviews
```

#### 获取评价列表

```
GET /api/v1/market/plugins/{id}/reviews
```

### 4. 开发者 API

#### 发布插件

```
POST /api/v1/developer/plugins
```

**请求参数**：

```json
{
  "file": "插件包文件（multipart/form-data）",
  "package_hash": "sha256:<hex-64>"
}
```

#### 获取开发者插件列表

```
GET /api/v1/developer/plugins
```

## 🆚 与 NIUCLOUD API 对比

| 特性 | AlkaidSYS | NIUCLOUD | 优势 |
|------|-----------|----------|------|
| **RESTful 规范** | 严格遵循 | 部分遵循 | ✅ 更标准 |
| **版本管理** | URL + Header | 不支持 | ✅ 更灵活 |
| **API 文档** | Swagger 自动生成 | 手动维护 | ✅ 更高效 |
| **限流机制** | 固定窗口（Phase 1）+ 令牌桶算法（Phase 2 目标） | 基础限流 | ✅ 更精确 |
| **响应格式** | 统一格式 | 不统一 | ✅ 更规范 |
| **应用市场 API** | 完整的 API 设计 | 基础 API | ✅ 更完善 |
| **插件市场 API** | 完整的 API 设计 | 基础 API | ✅ 更完善 |
| **开发者 API** | 完整的开发者生态 API | 无 | ✅ 更完整 |

---

## 🧩 OpenAPI/JSON Schema/SDK 类型生成流水线（新增）

为满足 AI 原生框架“AI 优化文档体系”要求，统一开放接口到类型与文档的生成与校验流程：

### 1. 约定
- OpenAPI 版本：优先使用 3.1（与 JSON Schema 对齐更好）；兼容 3.0 注解。
- 注解生成：使用 swagger-php（zircote/swagger-php）在 Controller 层维护注解。
- 类型生成：使用 openapi-typescript 将 OpenAPI 转 TS 类型；或使用 openapi-generator 生成多语言 SDK（可选）。
- 文档校验：使用 Redocly CLI 进行 lint/validate；CI 必须通过方可合并发布。

### 2. 目录结构建议
```
/alkaid-system
├─ app/                      # PHP 后端
│  ├─ api/                   # 带 OpenAPI 注解的 Controller
│  └─ command/GenerateApiDoc.php
├─ public/api-docs.json      # 生成的 OpenAPI JSON（产物）
├─ admin/src/api/types.d.ts  # 生成的 TS 类型（产物）
└─ admin/src/api/client.ts   # 可选：客户端封装
```

### 3. 生成命令
- 生成 OpenAPI JSON（已存在示例）：
```bash
php think api:doc               # 触发 /app/command/GenerateApiDoc.php 生成 public/api-docs.json
```
- 生成 TypeScript 类型（OpenAPI 3.0/3.1 皆可）：
```bash
# 安装一次
npm i -D openapi-typescript

# 生成 TS 类型
npx openapi-typescript public/api-docs.json -o admin/src/api/types.d.ts
```
- 可选：使用 OpenAPI Generator 生成多语言 SDK：
```bash
# 以 TypeScript-Fetch 客户端为例
npx @openapitools/openapi-generator-cli generate \
  -i public/api-docs.json \
  -g typescript-fetch \
  -o admin/src/api/sdk
```

### 4. 文档质量校验（Redocly CLI）
```bash
# 安装
npm i -D @redocly/cli

# Lint & Validate
npx redocly lint public/api-docs.json
npx redocly bundle public/api-docs.json -o public/api-docs.bundle.json
```

### 5. JSON Schema 对齐
- OpenAPI 3.1 原生使用 JSON Schema 关键字；建议迁移到 3.1 以统一校验口径。
- 如需单独产出 JSON Schema，可从 components.schemas 导出或使用开源转换工具链按需生成。

### 6. CI 集成
```yaml
# /.github/workflows/api-docs.yml
name: API Docs & Types
on: [push, pull_request]
jobs:
  api:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: swoole, redis, pdo_mysql
      - run: composer install --no-interaction --no-progress
      - name: Generate OpenAPI JSON
        run: php think api:doc
      - name: Validate OpenAPI
        run: |
          npm i -D @redocly/cli
          npx redocly lint public/api-docs.json
      - name: Generate TS Types
        run: |
          npm i -D openapi-typescript
          npx openapi-typescript public/api-docs.json -o admin/src/api/types.d.ts
      - name: Upload artifacts
        uses: actions/upload-artifact@v4
        with:
          name: api-artifacts
          path: |
            public/api-docs.json
            admin/src/api/types.d.ts
```

## 🧾 模板驱动的 OpenAPI 注解示例（新增）

使用模板：docs/prompt-templates/api/restful-template.yaml

示例参数：
- resource_name=Product
- base_path=/api/v1/products
- fields={"id":"integer","name":"string","price":"number","status":"integer"}
- operations=[list, detail, create, update, delete]

控制器骨架：
```php
<?php
namespace app\api\v1\controller;

use app\common\controller\BaseController;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(name="产品管理", description="产品资源接口")
 */
class ProductController extends BaseController
{
    /**
     * @OA\Get(
     *   path="/api/v1/products",
     *   tags={"产品管理"}, summary="获取产品列表",
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *   @OA\Parameter(name="page_size", in="query", @OA\Schema(type="integer", default=20)),
     *   @OA\Response(response=200, description="成功")
     * )
     */
    public function index() { /* ... */ }

    /**
     * @OA\Get(
     *   path="/api/v1/products/{id}", tags={"产品管理"}, summary="获取产品详情",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="成功")
     * )
     */
    public function read($id) { /* ... */ }

    /**
     * @OA\Post(
     *   path="/api/v1/products", tags={"产品管理"}, summary="创建产品",
     *   @OA\RequestBody(required=true,
     *     @OA\JsonContent(
     *       required={"name","price"},
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="price", type="number"),
     *       @OA\Property(property="status", type="integer")
     *     )
     *   ),
     *   @OA\Response(response=201, description="创建成功")
     * )
     */
    public function save() { /* ... */ }

    /**
     * @OA\Put(
     *   path="/api/v1/products/{id}", tags={"产品管理"}, summary="更新产品",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(@OA\JsonContent(
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="price", type="number"),
     *       @OA\Property(property="status", type="integer")
     *   )),
     *   @OA\Response(response=200, description="更新成功")
     * )
     */
    public function update($id) { /* ... */ }

    /**
     * @OA\Delete(
     *   path="/api/v1/products/{id}", tags={"产品管理"}, summary="删除产品",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="删除成功")
     * )
     */
    public function delete($id) { /* ... */ }
}
```

字段 Schema 与响应体可在 components.schemas 中集中维护，并由 openapi-typescript 产出 TS 类型。

---

**最后更新**: 2025-01-19
**文档版本**: v1.0
**维护者**: AlkaidSYS 架构团队

