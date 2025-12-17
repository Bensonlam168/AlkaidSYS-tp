【状态更新（2025-12-14）】本计划已完成，实现与测试结果见 `trace-id-coverage-commit-message`、`trace-id-coverage-final-review` 与 `TraceIdCoverageTest` 相关记忆。

# Trace ID JSON 覆盖任务规划

**任务**：[P1] Trace ID JSON 覆盖与可观测性增强

**日期**：2025-01-24

## 任务背景

**来源**：`docs/todo/development-backlog-2025-11-23.md` 第 114-122 行

**当前状态**：
- ✅ Trace 中间件在响应头中写入 `X-Trace-Id`
- ✅ ExceptionHandle 渲染的错误 JSON 包含 `trace_id`
- ❌ ApiController 直接返回的成功/错误响应缺少 `trace_id`
- ❌ 部分中间件直接 `json()` 返回的错误缺少 `trace_id`

**预估工时**：2-3 天

## 问题分析

### 1. ApiController 响应缺少 trace_id

**影响的方法**：
- `success()` - 第 35-45 行
- `error()` - 第 82-96 行
- `validationError()` - 第 105-108 行
- `notFound()` - 第 116-119 行
- `unauthorized()` - 第 121-124 行
- `forbidden()` - 第 126-129 行
- 其他所有响应方法

**问题**：
所有方法都直接构造响应数组，没有包含 `trace_id` 字段。

### 2. 中间件直接 json() 返回缺少 trace_id

**影响的中间件**：

1. **app/middleware/Auth.php**
   - 第 46-51 行：Token 缺失时返回 401
   - 第 71-76 行：Token 验证失败时返回 401

2. **app/middleware/Permission.php**
   - 第 51-56 行：未授权时返回 401
   - 第 69-74 行：权限不足时返回 403
   - 第 93 行：异常时返回 500（已包含 trace_id，但不一致）

**问题**：
中间件直接使用 `json()` 函数返回响应，没有统一的 trace_id 注入机制。

## 实施方案

### 方案 1：在 ApiController 中注入 trace_id（推荐）

**优点**：
- 集中管理，易于维护
- 所有继承 ApiController 的控制器自动获得 trace_id
- 符合 DRY 原则

**实现**：
1. 在 ApiController 中添加 `getTraceId()` 方法
2. 在所有响应方法中调用 `getTraceId()` 并添加到响应数组
3. 从 Request 对象中获取 trace_id（Trace 中间件已注入）

### 方案 2：创建统一的响应构造器

**优点**：
- 更灵活，可以在中间件中使用
- 可以添加更多统一的响应处理逻辑

**缺点**：
- 需要修改更多代码
- 增加复杂度

**决定**：采用方案 1，并为中间件提供辅助方法

## 详细实施计划

### 步骤 1：修改 ApiController

1. **添加 getTraceId() 方法**
   ```php
   protected function getTraceId(): ?string
   {
       $request = $this->request;
       
       // 优先从 Request 对象获取（Trace 中间件注入）
       if (method_exists($request, 'getTraceId')) {
           return $request->getTraceId();
       }
       
       // 降级：从请求头获取
       $traceId = $request->header('X-Trace-Id');
       if (is_string($traceId) && $traceId !== '') {
           return $traceId;
       }
       
       return null;
   }
   ```

2. **修改所有响应方法**
   - `success()` - 添加 trace_id
   - `error()` - 添加 trace_id
   - `validationError()` - 继承自 error()，自动获得
   - `notFound()` - 继承自 error()，自动获得
   - `unauthorized()` - 继承自 error()，自动获得
   - `forbidden()` - 继承自 error()，自动获得

### 步骤 2：修改中间件

1. **创建统一的错误响应辅助方法**
   
   在 `app/middleware/` 下创建 `ResponseHelper.php`：
   ```php
   class ResponseHelper
   {
       public static function jsonError(
           int $code,
           string $message,
           int $httpCode,
           ?string $traceId = null,
           ?array $data = null
       ): Response {
           $response = [
               'code' => $code,
               'message' => $message,
               'data' => $data,
               'timestamp' => time(),
           ];
           
           if ($traceId !== null) {
               $response['trace_id'] = $traceId;
           }
           
           return json($response, $httpCode);
       }
   }
   ```

2. **修改 Auth 中间件**
   - 替换两处 `json()` 调用为 `ResponseHelper::jsonError()`
   - 传入 trace_id

3. **修改 Permission 中间件**
   - 替换三处 `json()` 调用为 `ResponseHelper::jsonError()`
   - 传入 trace_id

### 步骤 3：添加测试

创建 `tests/Feature/TraceIdCoverageTest.php`：

1. **测试 ApiController 响应包含 trace_id**
   - 测试 success() 响应
   - 测试 error() 响应
   - 测试 validationError() 响应
   - 测试 notFound() 响应

2. **测试中间件响应包含 trace_id**
   - 测试 Auth 中间件 401 响应
   - 测试 Permission 中间件 403 响应

3. **测试 trace_id 的传递**
   - 测试客户端传入的 trace_id 被正确使用
   - 测试自动生成的 trace_id

### 步骤 4：更新文档

更新技术规范，明确要求所有错误响应必须包含 trace_id。

## 验收标准

- [ ] ApiController 所有响应方法返回的 JSON 包含 `trace_id`
- [ ] Auth 中间件的错误响应包含 `trace_id`
- [ ] Permission 中间件的错误响应包含 `trace_id`
- [ ] trace_id 与响应头 `X-Trace-Id` 一致
- [ ] 所有测试通过
- [ ] 代码审查通过

## 风险评估

- ✅ **低风险**：只是添加字段，不影响现有功能
- ✅ **向后兼容**：添加可选字段，不破坏现有客户端
- ✅ **影响范围小**：只修改响应构造逻辑

## 参考文档

- `docs/todo/development-backlog-2025-11-23.md`
- `docs/technical-specs/api/api-specification*.md` §6
- `app/middleware/Trace.php`
- `app/ExceptionHandle.php`
