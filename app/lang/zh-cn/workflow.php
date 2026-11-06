<?php

declare(strict_types=1);

/**
 * Workflow Messages - Chinese | 工作流模块消息 - 中文
 *
 * 供 app/controller/workflow/* 通过 `workflow.<key>` 引用（R22 补齐，
 * 此前语言包缺失，响应 message 会回退为原始 key 字符串）。
 *
 * @package app\lang\zh-cn
 */
return [
    // Common | 通用
    'access_denied' => '无权访问该资源',
    'invalid_tenant_context' => '租户上下文无效，无法校验资源归属',

    // Workflow definition | 工作流定义
    'definition_not_found' => '工作流定义不存在',
    'definition_created' => '工作流定义创建成功',
    'creation_failed' => '工作流定义创建失败',
    'definition_updated' => '工作流定义更新成功',
    'update_failed' => '工作流定义更新失败',
    'definition_deleted' => '工作流定义删除成功',
    'definition_published' => '工作流定义发布成功',
    'definition_archived' => '工作流定义归档成功',
    'definition_cloned' => '工作流定义克隆成功',
    'validation_failed' => '工作流定义校验未通过',
    'cannot_update_published' => '已发布的工作流定义不允许修改',
    'cannot_delete_published' => '已发布的工作流定义不允许删除，请先归档',
    'definition_id_required' => '工作流定义 ID 不能为空',
    'name_required' => '工作流名称不能为空',
    'invalid_name_format' => '工作流名称格式不正确：必须以字母开头，且只能包含字母、数字和下划线',
    'invalid_type' => '工作流类型无效',

    // Workflow instance | 工作流实例
    'instance_not_found' => '工作流实例不存在',
    'instance_started' => '工作流实例启动成功',
    'instance_cancelled' => '工作流实例已取消',
    'instance_suspended' => '工作流实例已挂起',
    'instance_resumed' => '工作流实例已恢复',
    'cannot_cancel_instance' => '当前状态的工作流实例不能取消',
    'cannot_suspend_instance' => '只有运行中的工作流实例才能挂起',
    'cannot_resume_instance' => '只有已挂起的工作流实例才能恢复',
    'cannot_modify_instance' => '已结束的工作流实例不允许修改',
    'variables_required' => '变量不能为空，且必须是对象',
    'variables_updated' => '工作流实例变量更新成功',

    // Workflow task | 工作流任务
    'task_not_found' => '工作流任务不存在',
    'task_transferred' => '任务转交成功',
    'task_delegated' => '任务委派成功',
    'task_claimed' => '任务签收成功',
    'task_unclaimed' => '任务已取消签收',
    'task_completed' => '任务已完成',
    'task_approved' => '任务审批通过',
    'task_rejected' => '任务已驳回',
    'form_submitted' => '表单提交成功',
    'cannot_transfer_task' => '当前状态的任务不能转交',
    'cannot_delegate_task' => '当前状态的任务不能委派',
    'cannot_claim_task' => '只有待处理的任务才能签收',
    'cannot_unclaim_task' => '当前状态的任务不能取消签收',
    'cannot_complete_task' => '当前状态的任务不能完成',
    'cannot_submit_form' => '当前状态的任务不能提交表单',
    'cannot_process_approval' => '当前状态的任务不能审批',
    'task_already_assigned' => '任务已被签收，不能重复签收',
    'not_eligible_to_claim' => '您不在该任务的候选范围内，无法签收',
    'not_task_assignee' => '您不是该任务的受理人，无权操作',
    'target_user_required' => '目标用户不能为空',
    'delegate_user_required' => '被委派人不能为空',
    'form_data_required' => '表单数据不能为空',
    'form_validation_failed' => '表单数据校验未通过，请按提示修改后重新提交',
    'form_validation_skipped_form_not_found' => '节点配置的表单「:form_name」在本租户下不存在，本次提交已跳过表单校验，请联系管理员修复流程定义',
    'form_validation_skipped' => '任务所属流程定义数据不一致（原因：:reason），本次提交已跳过表单校验，请联系管理员核查',

    // Workflow trigger | 工作流触发器
    'trigger_not_found' => '触发器不存在',
    'trigger_created' => '触发器创建成功',
    'trigger_updated' => '触发器更新成功',
    'trigger_deleted' => '触发器删除成功',
    'trigger_enabled' => '触发器已启用',
    'trigger_disabled' => '触发器已禁用',
    'trigger_name_required' => '触发器名称不能为空',
    'trigger_type_required' => '触发器类型不能为空',
    'invalid_trigger_type' => '触发器类型无效',
    'unknown_trigger_type' => '未知的触发器类型',
    'trigger_config_invalid' => '触发器配置校验未通过',
    'trigger_test_failed' => '触发器测试失败',
    'trigger_test_fired' => '触发器测试执行成功，已生成实例',
    'trigger_test_would_fire' => '满足触发条件，将触发工作流',
    'trigger_test_would_not_fire' => '未满足触发条件，不会触发工作流',
    'cron_expression_required' => 'Cron 表达式不能为空',
    'collection_required' => '数据集合不能为空',
    'event_type_required' => '事件类型不能为空',

    // Workflow trigger types | 触发器类型
    'trigger_type_manual' => '手动触发',
    'trigger_type_manual_desc' => '由用户手动调用接口触发工作流',
    'trigger_type_webhook' => 'Webhook 触发',
    'trigger_type_webhook_desc' => '通过 HTTP 回调（Webhook）触发工作流',
    'trigger_type_schedule' => '定时触发',
    'trigger_type_schedule_desc' => '按 Cron 表达式定时触发工作流',
    'trigger_type_data_event' => '数据事件触发',
    'trigger_type_data_event_desc' => '数据集合发生新增、更新或删除时触发工作流',

    // Webhook | Webhook 回调
    'webhook_not_found' => 'Webhook 触发器不存在',
    'webhook_processed' => 'Webhook 已接收，工作流已启动',
    'webhook_processing_failed' => 'Webhook 处理失败',
    'method_not_allowed' => 'Webhook 不允许使用当前 HTTP 方法',
    'ip_not_allowed' => '调用方 IP 不在该 Webhook 的允许列表中',
    'invalid_signature' => 'Webhook 签名校验失败',
];
