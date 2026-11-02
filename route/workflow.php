<?php

/**
 * Workflow Engine API Routes | 工作流引擎 API 路由
 *
 * This file defines all routes for the workflow engine module.
 * 此文件定义工作流引擎模块的所有路由。
 *
 * Permission Codes (permission_code format):
 * 权限编码（permission_code 格式）：
 * - workflow_definition.read   - 读取工作流定义
 * - workflow_definition.write  - 创建/更新工作流定义
 * - workflow_definition.delete - 删除工作流定义
 * - workflow_instance.read     - 读取工作流实例
 * - workflow_instance.write    - 创建/管理工作流实例
 * - workflow_task.read         - 读取工作流任务
 * - workflow_task.write        - 处理工作流任务
 * - workflow_trigger.read      - 读取触发器
 * - workflow_trigger.write     - 管理触发器
 * - workflow_trigger.delete    - 删除触发器
 *
 * @package route
 */

use think\facade\Route;

// ===========================================================================
// ⚠️ 为什么本文件每条带参数的路由都带 `->completeMatch()`
// ===========================================================================
// `config/route.php` 的 `route_complete_match = false` 让规则变成**前缀匹配**，
// 于是「先注册的 `:id`」会吃掉「后注册的 `:id/xxx`」——不是 404，而是**静默走错动作**：
//
//   GET v1/workflow/instances/<id>/history    → 命中 read()        （本该 history()）
//   GET v1/workflow/instances/<id>/variables  → 命中 read()        （本该 getVariables()）
//   GET v1/workflow/tasks/<id>/form           → 命中 read()        （本该 getForm()）
//   GET v1/workflow/triggers/<id>/logs        → 命中 read()        （本该 logs()）
//   GET v1/workflow/triggers/webhook/<key>    → 命中 read()        （webhook 回调 100% 失效）
//
// 修法（与 ER-05 修 `route/admin.php` 时一致）：**父级 `:id` 与更具体的子路由都加**。
// 只给子路由加不够 —— 例如 `GET instances/<id>/history/`（尾斜杠）匹配不上子路由时
// 会退化回父级 `:id` 的前缀匹配，再次走错动作。
//
// 回归防护：`tests/Feature/Route/WorkflowLowcodeRouteReachabilityTest.php`
// 用框架自己的匹配逻辑断言「每条规则都命中它自己注册的动作」，新增路由若忘了加
// `completeMatch()`，该用例会立刻红。

// ===========================================================================
// Workflow Definition API Routes | 工作流定义 API 路由
// ===========================================================================
Route::group('v1/workflow/definitions', function () {
    // List definitions | 列出定义
    Route::get('', 'app\controller\workflow\WorkflowDefinitionController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.read');

    // Get definition by ID | 按 ID 获取定义
    Route::get(':id', 'app\controller\workflow\WorkflowDefinitionController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Create definition | 创建定义
    Route::post('', 'app\controller\workflow\WorkflowDefinitionController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.write');

    // Update definition | 更新定义
    Route::put(':id', 'app\controller\workflow\WorkflowDefinitionController@update')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Delete definition | 删除定义
    Route::delete(':id', 'app\controller\workflow\WorkflowDefinitionController@delete')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.delete')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Publish definition | 发布定义
    Route::post(':id/publish', 'app\controller\workflow\WorkflowDefinitionController@publish')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Archive definition | 归档定义
    Route::post(':id/archive', 'app\controller\workflow\WorkflowDefinitionController@archive')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Validate definition | 验证定义
    // 注意：动作方法名为 validateAction（不能叫 validate，会与父类 ThinkPHP 数据验证方法同名），
    // 但对外 URL 保持不变，仍是 POST v1/workflow/definitions/:id/validate
    Route::post(':id/validate', 'app\controller\workflow\WorkflowDefinitionController@validateAction')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Clone definition | 克隆定义
    Route::post(':id/clone', 'app\controller\workflow\WorkflowDefinitionController@clone')
        ->middleware(\app\middleware\Permission::class, 'workflow_definition.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Instance API Routes | 工作流实例 API 路由
// ===========================================================================
Route::group('v1/workflow/instances', function () {
    // List instances | 列出实例
    Route::get('', 'app\controller\workflow\WorkflowInstanceController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.read');

    // Get instance by ID | 按 ID 获取实例
    // ⚠️ `completeMatch()` 必须加：不加时这条前缀匹配会吃掉下面的
    //    `GET :id/history` 与 `GET :id/variables`（详见文件头说明）。
    Route::get(':id', 'app\controller\workflow\WorkflowInstanceController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Start new instance | 启动新实例
    Route::post('', 'app\controller\workflow\WorkflowInstanceController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.write');

    // Cancel instance | 取消实例
    Route::post(':id/cancel', 'app\controller\workflow\WorkflowInstanceController@cancel')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Suspend instance | 暂停实例
    Route::post(':id/suspend', 'app\controller\workflow\WorkflowInstanceController@suspend')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Resume instance | 恢复实例
    Route::post(':id/resume', 'app\controller\workflow\WorkflowInstanceController@resume')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Get instance history | 获取实例历史
    Route::get(':id/history', 'app\controller\workflow\WorkflowInstanceController@history')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Get instance variables | 获取实例变量
    Route::get(':id/variables', 'app\controller\workflow\WorkflowInstanceController@getVariables')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Update instance variables | 更新实例变量
    Route::put(':id/variables', 'app\controller\workflow\WorkflowInstanceController@updateVariables')
        ->middleware(\app\middleware\Permission::class, 'workflow_instance.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Task API Routes | 工作流任务 API 路由
// ===========================================================================
Route::group('v1/workflow/tasks', function () {
    // Get pending tasks for current user | 获取当前用户待办任务
    // NOTE: Must be defined before :id route to avoid conflict
    Route::get('pending', 'app\controller\workflow\WorkflowTaskController@pending')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.read');

    // List all tasks | 列出所有任务
    Route::get('', 'app\controller\workflow\WorkflowTaskController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.read');

    // Get task by ID | 按 ID 获取任务
    // ⚠️ `completeMatch()` 必须加：不加时这条前缀匹配会吃掉下面的 `GET :id/form`（详见文件头说明）。
    Route::get(':id', 'app\controller\workflow\WorkflowTaskController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Approve task | 审批通过
    Route::post(':id/approve', 'app\controller\workflow\WorkflowTaskController@approve')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Reject task | 审批拒绝
    Route::post(':id/reject', 'app\controller\workflow\WorkflowTaskController@reject')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Transfer task | 转办任务
    Route::post(':id/transfer', 'app\controller\workflow\WorkflowTaskController@transfer')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Delegate task | 委托任务
    Route::post(':id/delegate', 'app\controller\workflow\WorkflowTaskController@delegate')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Claim task | 认领任务
    Route::post(':id/claim', 'app\controller\workflow\WorkflowTaskController@claim')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Unclaim task | 取消认领
    Route::post(':id/unclaim', 'app\controller\workflow\WorkflowTaskController@unclaim')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Complete task | 完成任务
    Route::post(':id/complete', 'app\controller\workflow\WorkflowTaskController@complete')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Get task form | 获取任务表单
    Route::get(':id/form', 'app\controller\workflow\WorkflowTaskController@getForm')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Submit task form | 提交任务表单
    Route::post(':id/form', 'app\controller\workflow\WorkflowTaskController@submitForm')
        ->middleware(\app\middleware\Permission::class, 'workflow_task.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Trigger API Routes | 工作流触发器 API 路由
// ===========================================================================
Route::group('v1/workflow/triggers', function () {
    // Get available trigger types | 获取可用触发器类型
    // NOTE: Must be defined before :id route to avoid conflict
    Route::get('types', 'app\controller\workflow\WorkflowTriggerController@types')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.read');

    // List triggers | 列出触发器
    Route::get('', 'app\controller\workflow\WorkflowTriggerController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.read');

    // Get trigger by ID | 按 ID 获取触发器
    // ⚠️ `completeMatch()` 必须加：不加时这条前缀匹配会吃掉下面的 `GET :id/logs`
    //    以及文件末尾的 `GET v1/workflow/triggers/webhook/:key`（webhook 回调 100% 失效）。
    Route::get(':id', 'app\controller\workflow\WorkflowTriggerController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Create trigger | 创建触发器
    Route::post('', 'app\controller\workflow\WorkflowTriggerController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.write');

    // Update trigger | 更新触发器
    Route::put(':id', 'app\controller\workflow\WorkflowTriggerController@update')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Delete trigger | 删除触发器
    Route::delete(':id', 'app\controller\workflow\WorkflowTriggerController@delete')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.delete')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Enable trigger | 启用触发器
    Route::post(':id/enable', 'app\controller\workflow\WorkflowTriggerController@enable')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Disable trigger | 禁用触发器
    Route::post(':id/disable', 'app\controller\workflow\WorkflowTriggerController@disable')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Test trigger | 测试触发器
    Route::post(':id/test', 'app\controller\workflow\WorkflowTriggerController@test')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.write')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();

    // Get trigger logs | 获取触发器日志
    Route::get(':id/logs', 'app\controller\workflow\WorkflowTriggerController@logs')
        ->middleware(\app\middleware\Permission::class, 'workflow_trigger.read')
        ->pattern(['id' => '[\w\-]+'])
        ->completeMatch();
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Webhook Endpoint (No Auth Required) | Webhook 端点（无需认证）
// ===========================================================================
// Webhook receives external HTTP requests and triggers workflows
// Webhook 接收外部 HTTP 请求并触发工作流
//
// ⚠️ 鉴权现状（本次核实）：本端点**不挂 Auth / Permission 中间件**（外部回调不可能带 JWT），
//    鉴权靠 ① 路径里的 `webhook_key`（32 位十六进制，创建触发器时随机生成）；
//    ② 触发器 config 里可选 `secret`（校验 `X-Webhook-Signature`，HMAC-SHA256）；
//    ③ 可选 `allowed_ips` / `http_methods` 白名单。
//    唯一的全局中间件依赖是 `TenantIdentify`：不带 `X-Tenant-ID` 时回落
//    `DEFAULT_TENANT_ID`，因此该环境变量未配置时本端点会直接失败（部署要求，见报告）。
Route::any('v1/workflow/triggers/webhook/:key', 'app\controller\workflow\WorkflowTriggerController@webhook')
    ->pattern(['key' => '[a-f0-9]{32}'])
    ->completeMatch();
