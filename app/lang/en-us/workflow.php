<?php

declare(strict_types=1);

/**
 * Workflow Messages - English | 工作流模块消息 - 英文
 *
 * Referenced by app/controller/workflow/* as `workflow.<key>` (added in R22; the
 * package was missing before, so response messages fell back to the raw key).
 *
 * @package app\lang\en-us
 */
return [
    // Common | 通用
    'access_denied' => 'Access to this resource is denied',
    'invalid_tenant_context' => 'Invalid tenant context; resource ownership cannot be verified',

    // Workflow definition | 工作流定义
    'definition_not_found' => 'Workflow definition not found',
    'definition_created' => 'Workflow definition created',
    'creation_failed' => 'Failed to create the workflow definition',
    'definition_updated' => 'Workflow definition updated',
    'update_failed' => 'Failed to update the workflow definition',
    'definition_deleted' => 'Workflow definition deleted',
    'definition_published' => 'Workflow definition published',
    'definition_archived' => 'Workflow definition archived',
    'definition_cloned' => 'Workflow definition cloned',
    'validation_failed' => 'Workflow definition validation failed',
    'cannot_update_published' => 'A published workflow definition cannot be modified',
    'cannot_delete_published' => 'A published workflow definition cannot be deleted; archive it first',
    'definition_id_required' => 'Workflow definition ID is required',
    'name_required' => 'Workflow name is required',
    'invalid_name_format' => 'Invalid workflow name: it must start with a letter and contain only letters, digits, and underscores',
    'invalid_type' => 'Invalid workflow type',

    // Workflow instance | 工作流实例
    'instance_not_found' => 'Workflow instance not found',
    'instance_started' => 'Workflow instance started',
    'instance_cancelled' => 'Workflow instance cancelled',
    'instance_suspended' => 'Workflow instance suspended',
    'instance_resumed' => 'Workflow instance resumed',
    'cannot_cancel_instance' => 'A workflow instance in its current status cannot be cancelled',
    'cannot_suspend_instance' => 'Only a running workflow instance can be suspended',
    'cannot_resume_instance' => 'Only a suspended workflow instance can be resumed',
    'cannot_modify_instance' => 'A workflow instance that has already ended cannot be modified',
    'variables_required' => 'Variables are required and must be an object',
    'variables_updated' => 'Workflow instance variables updated',

    // Workflow task | 工作流任务
    'task_not_found' => 'Workflow task not found',
    'task_transferred' => 'Task transferred',
    'task_delegated' => 'Task delegated',
    'task_claimed' => 'Task claimed',
    'task_unclaimed' => 'Task claim released',
    'task_completed' => 'Task completed',
    'task_approved' => 'Task approved',
    'task_rejected' => 'Task rejected',
    'form_submitted' => 'Form submitted',
    'cannot_transfer_task' => 'A task in its current status cannot be transferred',
    'cannot_delegate_task' => 'A task in its current status cannot be delegated',
    'cannot_claim_task' => 'Only a pending task can be claimed',
    'cannot_unclaim_task' => 'A task in its current status cannot be unclaimed',
    'cannot_complete_task' => 'A task in its current status cannot be completed',
    'cannot_submit_form' => 'A task in its current status cannot submit a form',
    'cannot_process_approval' => 'A task in its current status cannot be approved or rejected',
    'task_already_assigned' => 'The task has already been claimed',
    'not_eligible_to_claim' => 'You are not a candidate for this task and cannot claim it',
    'not_task_assignee' => 'You are not the assignee of this task and cannot operate on it',
    'target_user_required' => 'Target user is required',
    'delegate_user_required' => 'Delegate user is required',
    'form_data_required' => 'Form data is required',

    // Workflow trigger | 工作流触发器
    'trigger_not_found' => 'Workflow trigger not found',
    'trigger_created' => 'Workflow trigger created',
    'trigger_updated' => 'Workflow trigger updated',
    'trigger_deleted' => 'Workflow trigger deleted',
    'trigger_enabled' => 'Workflow trigger enabled',
    'trigger_disabled' => 'Workflow trigger disabled',
    'trigger_name_required' => 'Trigger name is required',
    'trigger_type_required' => 'Trigger type is required',
    'invalid_trigger_type' => 'Invalid trigger type',
    'unknown_trigger_type' => 'Unknown trigger type',
    'trigger_config_invalid' => 'Trigger configuration validation failed',
    'trigger_test_failed' => 'Failed to test the trigger',
    'trigger_test_fired' => 'Test fire succeeded; an instance has been created',
    'trigger_test_would_fire' => 'Conditions are met; the workflow would be triggered',
    'trigger_test_would_not_fire' => 'Conditions are not met; the workflow would not be triggered',
    'cron_expression_required' => 'Cron expression is required',
    'collection_required' => 'Data collection is required',
    'event_type_required' => 'Event type is required',

    // Workflow trigger types | 触发器类型
    'trigger_type_manual' => 'Manual',
    'trigger_type_manual_desc' => 'Trigger the workflow manually through the API',
    'trigger_type_webhook' => 'Webhook',
    'trigger_type_webhook_desc' => 'Trigger the workflow through an HTTP webhook callback',
    'trigger_type_schedule' => 'Schedule',
    'trigger_type_schedule_desc' => 'Trigger the workflow on a cron schedule',
    'trigger_type_data_event' => 'Data event',
    'trigger_type_data_event_desc' => 'Trigger the workflow when data in a collection is created, updated, or deleted',

    // Webhook | Webhook 回调
    'webhook_not_found' => 'Webhook trigger not found',
    'webhook_processed' => 'Webhook received; the workflow has been started',
    'webhook_processing_failed' => 'Webhook processing failed',
    'method_not_allowed' => 'This HTTP method is not allowed for the webhook',
    'ip_not_allowed' => 'The caller IP is not in the allowed list of this webhook',
    'invalid_signature' => 'Webhook signature verification failed',
];
