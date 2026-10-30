<?php

/**
 * Workflow Engine API Routes | 工作流引擎 API 路由
 *
 * This file defines all routes for the workflow engine module.
 * 此文件定义工作流引擎模块的所有路由。
 *
 * Permission Codes (permission_code format):
 * 权限编码（permission_code 格式）：
 * - workflow.definition.read   - 读取工作流定义
 * - workflow.definition.write  - 创建/更新工作流定义
 * - workflow.definition.delete - 删除工作流定义
 * - workflow.instance.read     - 读取工作流实例
 * - workflow.instance.write    - 创建/管理工作流实例
 * - workflow.task.read         - 读取工作流任务
 * - workflow.task.write        - 处理工作流任务
 * - workflow.trigger.read      - 读取触发器
 * - workflow.trigger.write     - 管理触发器
 * - workflow.trigger.delete    - 删除触发器
 *
 * @package route
 */

use think\facade\Route;

// ===========================================================================
// Workflow Definition API Routes | 工作流定义 API 路由
// ===========================================================================
Route::group('v1/workflow/definitions', function () {
    // List definitions | 列出定义
    Route::get('', 'app\controller\workflow\WorkflowDefinitionController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.read');

    // Get definition by ID | 按 ID 获取定义
    Route::get(':id', 'app\controller\workflow\WorkflowDefinitionController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.read')
        ->pattern(['id' => '[\w\-]+']);

    // Create definition | 创建定义
    Route::post('', 'app\controller\workflow\WorkflowDefinitionController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.write');

    // Update definition | 更新定义
    Route::put(':id', 'app\controller\workflow\WorkflowDefinitionController@update')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.write')
        ->pattern(['id' => '[\w\-]+']);

    // Delete definition | 删除定义
    Route::delete(':id', 'app\controller\workflow\WorkflowDefinitionController@delete')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.delete')
        ->pattern(['id' => '[\w\-]+']);

    // Publish definition | 发布定义
    Route::post(':id/publish', 'app\controller\workflow\WorkflowDefinitionController@publish')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.write')
        ->pattern(['id' => '[\w\-]+']);

    // Archive definition | 归档定义
    Route::post(':id/archive', 'app\controller\workflow\WorkflowDefinitionController@archive')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.write')
        ->pattern(['id' => '[\w\-]+']);

    // Validate definition | 验证定义
    // 注意：动作方法名为 validateAction（不能叫 validate，会与父类 ThinkPHP 数据验证方法同名），
    // 但对外 URL 保持不变，仍是 POST v1/workflow/definitions/:id/validate
    Route::post(':id/validate', 'app\controller\workflow\WorkflowDefinitionController@validateAction')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.read')
        ->pattern(['id' => '[\w\-]+']);

    // Clone definition | 克隆定义
    Route::post(':id/clone', 'app\controller\workflow\WorkflowDefinitionController@clone')
        ->middleware(\app\middleware\Permission::class, 'workflow.definition.write')
        ->pattern(['id' => '[\w\-]+']);
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Instance API Routes | 工作流实例 API 路由
// ===========================================================================
Route::group('v1/workflow/instances', function () {
    // List instances | 列出实例
    Route::get('', 'app\controller\workflow\WorkflowInstanceController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.read');

    // Get instance by ID | 按 ID 获取实例
    Route::get(':id', 'app\controller\workflow\WorkflowInstanceController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.read')
        ->pattern(['id' => '[\w\-]+']);

    // Start new instance | 启动新实例
    Route::post('', 'app\controller\workflow\WorkflowInstanceController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.write');

    // Cancel instance | 取消实例
    Route::post(':id/cancel', 'app\controller\workflow\WorkflowInstanceController@cancel')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.write')
        ->pattern(['id' => '[\w\-]+']);

    // Suspend instance | 暂停实例
    Route::post(':id/suspend', 'app\controller\workflow\WorkflowInstanceController@suspend')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.write')
        ->pattern(['id' => '[\w\-]+']);

    // Resume instance | 恢复实例
    Route::post(':id/resume', 'app\controller\workflow\WorkflowInstanceController@resume')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.write')
        ->pattern(['id' => '[\w\-]+']);

    // Get instance history | 获取实例历史
    Route::get(':id/history', 'app\controller\workflow\WorkflowInstanceController@history')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.read')
        ->pattern(['id' => '[\w\-]+']);

    // Get instance variables | 获取实例变量
    Route::get(':id/variables', 'app\controller\workflow\WorkflowInstanceController@getVariables')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.read')
        ->pattern(['id' => '[\w\-]+']);

    // Update instance variables | 更新实例变量
    Route::put(':id/variables', 'app\controller\workflow\WorkflowInstanceController@updateVariables')
        ->middleware(\app\middleware\Permission::class, 'workflow.instance.write')
        ->pattern(['id' => '[\w\-]+']);
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Task API Routes | 工作流任务 API 路由
// ===========================================================================
Route::group('v1/workflow/tasks', function () {
    // Get pending tasks for current user | 获取当前用户待办任务
    // NOTE: Must be defined before :id route to avoid conflict
    Route::get('pending', 'app\controller\workflow\WorkflowTaskController@pending')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.read');

    // List all tasks | 列出所有任务
    Route::get('', 'app\controller\workflow\WorkflowTaskController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.read');

    // Get task by ID | 按 ID 获取任务
    Route::get(':id', 'app\controller\workflow\WorkflowTaskController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.read')
        ->pattern(['id' => '[\w\-]+']);

    // Approve task | 审批通过
    Route::post(':id/approve', 'app\controller\workflow\WorkflowTaskController@approve')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Reject task | 审批拒绝
    Route::post(':id/reject', 'app\controller\workflow\WorkflowTaskController@reject')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Transfer task | 转办任务
    Route::post(':id/transfer', 'app\controller\workflow\WorkflowTaskController@transfer')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Delegate task | 委托任务
    Route::post(':id/delegate', 'app\controller\workflow\WorkflowTaskController@delegate')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Claim task | 认领任务
    Route::post(':id/claim', 'app\controller\workflow\WorkflowTaskController@claim')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Unclaim task | 取消认领
    Route::post(':id/unclaim', 'app\controller\workflow\WorkflowTaskController@unclaim')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Complete task | 完成任务
    Route::post(':id/complete', 'app\controller\workflow\WorkflowTaskController@complete')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);

    // Get task form | 获取任务表单
    Route::get(':id/form', 'app\controller\workflow\WorkflowTaskController@getForm')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.read')
        ->pattern(['id' => '[\w\-]+']);

    // Submit task form | 提交任务表单
    Route::post(':id/form', 'app\controller\workflow\WorkflowTaskController@submitForm')
        ->middleware(\app\middleware\Permission::class, 'workflow.task.write')
        ->pattern(['id' => '[\w\-]+']);
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Workflow Trigger API Routes | 工作流触发器 API 路由
// ===========================================================================
Route::group('v1/workflow/triggers', function () {
    // Get available trigger types | 获取可用触发器类型
    // NOTE: Must be defined before :id route to avoid conflict
    Route::get('types', 'app\controller\workflow\WorkflowTriggerController@types')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.read');

    // List triggers | 列出触发器
    Route::get('', 'app\controller\workflow\WorkflowTriggerController@index')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.read');

    // Get trigger by ID | 按 ID 获取触发器
    Route::get(':id', 'app\controller\workflow\WorkflowTriggerController@read')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.read')
        ->pattern(['id' => '[\w\-]+']);

    // Create trigger | 创建触发器
    Route::post('', 'app\controller\workflow\WorkflowTriggerController@save')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.write');

    // Update trigger | 更新触发器
    Route::put(':id', 'app\controller\workflow\WorkflowTriggerController@update')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.write')
        ->pattern(['id' => '[\w\-]+']);

    // Delete trigger | 删除触发器
    Route::delete(':id', 'app\controller\workflow\WorkflowTriggerController@delete')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.delete')
        ->pattern(['id' => '[\w\-]+']);

    // Enable trigger | 启用触发器
    Route::post(':id/enable', 'app\controller\workflow\WorkflowTriggerController@enable')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.write')
        ->pattern(['id' => '[\w\-]+']);

    // Disable trigger | 禁用触发器
    Route::post(':id/disable', 'app\controller\workflow\WorkflowTriggerController@disable')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.write')
        ->pattern(['id' => '[\w\-]+']);

    // Test trigger | 测试触发器
    Route::post(':id/test', 'app\controller\workflow\WorkflowTriggerController@test')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.write')
        ->pattern(['id' => '[\w\-]+']);

    // Get trigger logs | 获取触发器日志
    Route::get(':id/logs', 'app\controller\workflow\WorkflowTriggerController@logs')
        ->middleware(\app\middleware\Permission::class, 'workflow.trigger.read')
        ->pattern(['id' => '[\w\-]+']);
})->middleware(\app\middleware\Auth::class);

// ===========================================================================
// Webhook Endpoint (No Auth Required) | Webhook 端点（无需认证）
// ===========================================================================
// Webhook receives external HTTP requests and triggers workflows
// Webhook 接收外部 HTTP 请求并触发工作流
Route::any('v1/workflow/triggers/webhook/:key', 'app\controller\workflow\WorkflowTriggerController@webhook')
    ->pattern(['key' => '[a-f0-9]{32}']);
