2025-11-27 更新：

1. Auth / Permission 中间件错误码与响应结构现状
   - Auth 中间件（app/middleware/Auth.php）：
     - 无 Token 或解析失败 → 使用 ResponseHelper::jsonError(code=2001, message=lang('error.token_missing'), http_status=401, trace_id)。
     - Token 解析异常（catch \Exception）→ 同样返回 2001 + 401 + token_missing 文案；不向客户端暴露内部异常信息，trace_id 从 Request/头部提取。
   - Permission 中间件（app/middleware/Permission.php）：
     - 未登录（request.userId() 为空）→ code=2001, message=lang('error.token_missing'), http_status=401, trace_id。
     - 无权限 → code=2002, message=lang('error.permission_denied'), http_status=403, trace_id。
     - 内部异常（catch \Exception）→ Log::error 记录 message/exception/trace_id/user_id/permission/url/ip 等上下文，对客户端仅返回 code=5000, message='Internal server error', http_status=500, trace_id。
   - 两个中间件均通过 LanguageService 做错误消息国际化，trace_id 获取逻辑与 Trace 中间件和 ApiController 设计保持一致。

2. 文档与 Backlog 对 T-044 四条优化项的收敛
   - docs/todo/development-backlog-2025-11-23.md §404-443：
     - 原始描述认为 Permission 中间件在内部异常时还会返回 "Permission check failed: ..."；现已更新为“内部通过 Log::error 记录，外部仅返回 5000 + 'Internal server error'”。
     - 原始描述认为 AuthController 仍在构造函数中手动 new 依赖；现已更新为“通过构造函数参数注入 JwtService/UserRepository/PermissionService，并调用 parent::__construct($app)` 的标准 DI 写法”。
     - 测试命令规范：文档已改为引用 README.local.md §3 和 always-alkaidsys-project-rules §8，明确所有测试必须在 alkaid-backend 容器内通过 `docker exec alkaid-backend ...` 运行，推荐入口为 `php think test` 或等价的 ./vendor/bin/phpunit。
     - PSR-12 统一：说明已通过 .php-cs-fixer.php 作为唯一配置实现，Auth/Permission 中间件整体符合 PSR-12，历史轻微缩进问题可通过 php-cs-fixer 批量修复，并计划在 T-059 中接入 CI 强校验。

3. T-044 子任务 5（统一异常处理与文档）的结论
   - 代码侧：
     - Auth/Permission 中间件错误码与 HTTP 状态码完全符合 Always 规则：
       - 认证失败（未登录/Token 问题）→ HTTP 401 + code=2001；
       - 权限不足 → HTTP 403 + code=2002；
       - 内部异常 → HTTP 500 + code=5000，对外只暴露通用错误消息，详细信息仅记入日志；
       - 所有 4xx/5xx JSON 响应均带 trace_id。
     - AuthController 内部错误通过 handleInternalError() 统一落到 code=5000 + 500，日志中附带 trace_id 与 scene 标识。
   - 文档侧：
     - 2025-11-23 backlog T-044 四条优化项已根据当前实现状态更新，标注 2025-11-27 更新说明；
     - 测试命令规范已在 README.local.md 和 always rules 中形成合一事实来源，本次仅在 backlog 中引用和对齐，不再维持过时的 ./vendor/bin/phpunit 说明；
     - 后续统一测试入口与 CI gate 通过 T-056/T-059 跟踪，避免与 T-044 责任范围交叉。

4. 后续注意事项
   - 新增任何权限相关中间件/控制器错误处理时，应复用 ErrorCode 常量与 ApiController/ResponseHelper 提供的统一响应方法，避免直接 return json([...])。
   - 权限/认证失败错误码（2001/2002）与内部错误（5000）的语义在技术规范中已冻结，如需变更必须先更新设计与规范文档，再回收历史实现。