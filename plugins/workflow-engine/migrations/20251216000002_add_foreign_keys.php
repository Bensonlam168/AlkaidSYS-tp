<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 添加工作流引擎外键约束
 * Add workflow engine foreign key constraints
 *
 * @package plugins\workflow-engine\migrations
 */
class AddForeignKeys extends Migrator
{
    /**
     * 添加外键约束
     */
    public function change(): void
    {
        // 添加外键约束到工作流实例表
        $this->addForeignKeyToWorkflowInstances();

        // 添加外键约束到工作流任务表
        $this->addForeignKeyToWorkflowTasks();

        // 添加外键约束到工作流触发器表
        $this->addForeignKeyToWorkflowTriggers();

        // 添加外键约束到工作流事件日志表
        $this->addForeignKeyToWorkflowEventLogs();
    }

    /**
     * 为工作流实例表添加外键约束
     */
    protected function addForeignKeyToWorkflowInstances(): void
    {
        // 添加外键约束：workflow_instances.definition_id -> workflow_definitions.id
        $this->table('workflow_instances')
            ->addForeignKey(
                'definition_id',
                'workflow_definitions',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        // 添加外键约束：workflow_instances.created_by -> users.id (如果用户表存在)
        // 注意：如果用户表不存在，请注释掉此行或修改为实际的表名
        try {
            $this->table('workflow_instances')
                ->addForeignKey(
                    'created_by',
                    'users',
                    'id',
                    ['delete' => 'SET NULL', 'update' => 'CASCADE']
                )
                ->save();
        } catch (\Exception $e) {
            // 如果users表不存在，跳过此约束
            $this->output->writeln('Warning: users表不存在，跳过workflow_instances.created_by外键约束');
        }
    }

    /**
     * 为工作流任务表添加外键约束
     */
    protected function addForeignKeyToWorkflowTasks(): void
    {
        // 添加外键约束：workflow_tasks.instance_id -> workflow_instances.id
        $this->table('workflow_tasks')
            ->addForeignKey(
                'instance_id',
                'workflow_instances',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        // 添加外键约束：workflow_tasks.assignee -> users.id (如果用户表存在)
        try {
            $this->table('workflow_tasks')
                ->addForeignKey(
                    'assignee',
                    'users',
                    'id',
                    ['delete' => 'SET NULL', 'update' => 'CASCADE']
                )
                ->save();
        } catch (\Exception $e) {
            // 如果users表不存在，跳过此约束
            $this->output->writeln('Warning: users表不存在，跳过workflow_tasks.assignee外键约束');
        }

        // 添加外键约束：workflow_tasks.created_by -> users.id (如果用户表存在)
        try {
            $this->table('workflow_tasks')
                ->addForeignKey(
                    'created_by',
                    'users',
                    'id',
                    ['delete' => 'SET NULL', 'update' => 'CASCADE']
                )
                ->save();
        } catch (\Exception $e) {
            // 如果users表不存在，跳过此约束
            $this->output->writeln('Warning: users表不存在，跳过workflow_tasks.created_by外键约束');
        }
    }

    /**
     * 为工作流触发器表添加外键约束
     */
    protected function addForeignKeyToWorkflowTriggers(): void
    {
        // 添加外键约束：workflow_triggers.definition_id -> workflow_definitions.id
        $this->table('workflow_triggers')
            ->addForeignKey(
                'definition_id',
                'workflow_definitions',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        // 添加外键约束：workflow_triggers.created_by -> users.id (如果用户表存在)
        try {
            $this->table('workflow_triggers')
                ->addForeignKey(
                    'created_by',
                    'users',
                    'id',
                    ['delete' => 'SET NULL', 'update' => 'CASCADE']
                )
                ->save();
        } catch (\Exception $e) {
            // 如果users表不存在，跳过此约束
            $this->output->writeln('Warning: users表不存在，跳过workflow_triggers.created_by外键约束');
        }
    }

    /**
     * 为工作流事件日志表添加外键约束
     */
    protected function addForeignKeyToWorkflowEventLogs(): void
    {
        // 添加外键约束：workflow_event_logs.instance_id -> workflow_instances.id
        $this->table('workflow_event_logs')
            ->addForeignKey(
                'instance_id',
                'workflow_instances',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->save();

        // 添加外键约束：workflow_event_logs.task_id -> workflow_tasks.id
        $this->table('workflow_event_logs')
            ->addForeignKey(
                'task_id',
                'workflow_tasks',
                'id',
                ['delete' => 'SET NULL', 'update' => 'CASCADE']
            )
            ->save();

        // 添加外键约束：workflow_event_logs.user_id -> users.id (如果用户表存在)
        try {
            $this->table('workflow_event_logs')
                ->addForeignKey(
                    'user_id',
                    'users',
                    'id',
                    ['delete' => 'SET NULL', 'update' => 'CASCADE']
                )
                ->save();
        } catch (\Exception $e) {
            // 如果users表不存在，跳过此约束
            $this->output->writeln('Warning: users表不存在，跳过workflow_event_logs.user_id外键约束');
        }
    }
}
