<?php

declare(strict_types=1);

// ⚠️ 历史留档，勿执行：database/migrations 无对应文件；若要采纳分区表，先同步进 database/migrations
use think\migration\Migrator;

/**
 * 创建分区表用于大数据量优化
 * Create partitioned tables for large data optimization
 *
 * @package plugins\workflow-engine\migrations
 */
class CreatePartitionedTables extends Migrator
{
    /**
     * 创建分区表
     */
    public function change(): void
    {
        // 创建分区的工作流事件日志表
        $this->createPartitionedEventLogsTable();

        // 创建分区的历史表（可选）
        $this->createPartitionedHistoryTables();

        // 创建分区的工作流实例表（可选）
        $this->createPartitionedInstanceTable();
    }

    /**
     * 创建分区的工作流事件日志表
     */
    protected function createPartitionedEventLogsTable(): void
    {
        // 检查MySQL版本是否支持分区
        $db = $this->getDb();
        $version = $db->query('SELECT VERSION() as version')[0]['version'];

        if (version_compare($version, '5.1.0', '<')) {
            $this->output->writeln('MySQL版本不支持分区，跳过事件日志表分区');
            return;
        }

        // 创建按月分区的事件日志表
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `workflow_event_logs_partitioned` (
  `id` varchar(50) NOT NULL COMMENT '事件ID',
  `instance_id` varchar(50) DEFAULT NULL COMMENT '工作流实例ID',
  `task_id` varchar(50) DEFAULT NULL COMMENT '任务ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `event_type` varchar(50) NOT NULL COMMENT '事件类型',
  `event_name` varchar(100) NOT NULL COMMENT '事件名称',
  `event_data` json DEFAULT NULL COMMENT '事件数据',
  `user_id` bigint(20) DEFAULT NULL COMMENT '操作用户ID',
  `user_name` varchar(100) DEFAULT NULL COMMENT '操作用户姓名',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP地址',
  `user_agent` text COMMENT '用户代理',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`, `created_at`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流事件日志表（分区）'
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202501 VALUES LESS THAN (TO_DAYS('2025-02-01')),
  PARTITION p202502 VALUES LESS THAN (TO_DAYS('2025-03-01')),
  PARTITION p202503 VALUES LESS THAN (TO_DAYS('2025-04-01')),
  PARTITION p202504 VALUES LESS THAN (TO_DAYS('2025-05-01')),
  PARTITION p202505 VALUES LESS THAN (TO_DAYS('2025-06-01')),
  PARTITION p202506 VALUES LESS THAN (TO_DAYS('2025-07-01')),
  PARTITION p202507 VALUES LESS THAN (TO_DAYS('2025-08-01')),
  PARTITION p202508 VALUES LESS THAN (TO_DAYS('2025-09-01')),
  PARTITION p202509 VALUES LESS THAN (TO_DAYS('2025-10-01')),
  PARTITION p202510 VALUES LESS THAN (TO_DAYS('2025-11-01')),
  PARTITION p202511 VALUES LESS THAN (TO_DAYS('2025-12-01')),
  PARTITION p202512 VALUES LESS THAN (TO_DAYS('2026-01-01')),
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
SQL;

        try {
            $db->execute($sql);
            $this->output->writeln('成功创建分区工作流事件日志表');
        } catch (\Exception $e) {
            $this->output->writeln('创建分区表失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建分区的工作流实例历史表
     */
    protected function createPartitionedHistoryTables(): void
    {
        $db = $this->getDb();

        // 创建工作流实例历史表（分区）
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `workflow_instance_history_partitioned` (
  `id` varchar(50) NOT NULL COMMENT '历史记录ID',
  `instance_id` varchar(50) NOT NULL COMMENT '工作流实例ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `business_key` varchar(100) DEFAULT NULL COMMENT '业务键',
  `title` varchar(200) NOT NULL COMMENT '实例标题',
  `status` varchar(20) NOT NULL COMMENT '状态',
  `priority` int(11) NOT NULL DEFAULT 5 COMMENT '优先级',
  `current_node_id` varchar(50) DEFAULT NULL COMMENT '当前节点ID',
  `current_node_name` varchar(200) DEFAULT NULL COMMENT '当前节点名称',
  `context` json DEFAULT NULL COMMENT '执行上下文数据',
  `variables` json DEFAULT NULL COMMENT '变量数据',
  `started_at` timestamp NULL DEFAULT NULL COMMENT '开始时间',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '完成时间',
  `suspended_at` timestamp NULL DEFAULT NULL COMMENT '暂停时间',
  `duration` bigint(20) DEFAULT NULL COMMENT '执行时长（毫秒）',
  `error_message` text COMMENT '错误信息',
  `error_stack` longtext COMMENT '错误堆栈',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '发起用户ID',
  `assignee` bigint(20) DEFAULT NULL COMMENT '当前处理人ID',
  PRIMARY KEY (`id`, `completed_at`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_business_key` (`business_key`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_completed_at` (`completed_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_assignee` (`assignee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流实例历史表（分区）'
PARTITION BY RANGE (TO_DAYS(completed_at)) (
  PARTITION p202501 VALUES LESS THAN (TO_DAYS('2025-02-01')),
  PARTITION p202502 VALUES LESS THAN (TO_DAYS('2025-03-01')),
  PARTITION p202503 VALUES LESS THAN (TO_DAYS('2025-04-01')),
  PARTITION p202504 VALUES LESS THAN (TO_DAYS('2025-05-01')),
  PARTITION p202505 VALUES LESS THAN (TO_DAYS('2025-06-01')),
  PARTITION p202506 VALUES LESS THAN (TO_DAYS('2025-07-01')),
  PARTITION p202507 VALUES LESS THAN (TO_DAYS('2025-08-01')),
  PARTITION p202508 VALUES LESS THAN (TO_DAYS('2025-09-01')),
  PARTITION p202509 VALUES LESS THAN (TO_DAYS('2025-10-01')),
  PARTITION p202510 VALUES LESS THAN (TO_DAYS('2025-11-01')),
  PARTITION p202511 VALUES LESS THAN (TO_DAYS('2025-12-01')),
  PARTITION p202512 VALUES LESS THAN (TO_DAYS('2026-01-01')),
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
SQL;

        try {
            $db->execute($sql);
            $this->output->writeln('成功创建分区工作流实例历史表');
        } catch (\Exception $e) {
            $this->output->writeln('创建分区实例历史表失败: ' . $e->getMessage());
        }

        // 创建工作流任务历史表（分区）
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `workflow_task_history_partitioned` (
  `id` varchar(50) NOT NULL COMMENT '任务历史ID',
  `task_id` varchar(50) NOT NULL COMMENT '原始任务ID',
  `instance_id` varchar(50) NOT NULL COMMENT '工作流实例ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `node_id` varchar(50) NOT NULL COMMENT '节点ID',
  `node_name` varchar(200) NOT NULL COMMENT '节点名称',
  `task_type` varchar(20) NOT NULL COMMENT '任务类型',
  `task_sub_type` varchar(20) DEFAULT NULL COMMENT '任务子类型',
  `title` varchar(200) NOT NULL COMMENT '任务标题',
  `description` text COMMENT '任务描述',
  `status` varchar(20) NOT NULL COMMENT '状态',
  `priority` int(11) NOT NULL DEFAULT 5 COMMENT '优先级',
  `assignee` bigint(20) DEFAULT NULL COMMENT '处理人ID',
  `assignee_name` varchar(100) DEFAULT NULL COMMENT '处理人姓名',
  `candidate_users` json DEFAULT NULL COMMENT '候选用户列表',
  `candidate_groups` json DEFAULT NULL COMMENT '候选组列表',
  `form_data` json DEFAULT NULL COMMENT '表单数据',
  `result` json DEFAULT NULL COMMENT '处理结果',
  `comment` text COMMENT '审批意见',
  `due_date` timestamp NULL DEFAULT NULL COMMENT '截止时间',
  `reminder_times` json DEFAULT NULL COMMENT '提醒时间列表',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '完成时间',
  `rejected_at` timestamp NULL DEFAULT NULL COMMENT '拒绝时间',
  `cancelled_at` timestamp NULL DEFAULT NULL COMMENT '取消时间',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '创建人ID',
  PRIMARY KEY (`id`, `completed_at`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assignee` (`assignee`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_node` (`node_id`),
  KEY `idx_completed_at` (`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流任务历史表（分区）'
PARTITION BY RANGE (TO_DAYS(completed_at)) (
  PARTITION p202501 VALUES LESS THAN (TO_DAYS('2025-02-01')),
  PARTITION p202502 VALUES LESS THAN (TO_DAYS('2025-03-01')),
  PARTITION p202503 VALUES LESS THAN (TO_DAYS('2025-04-01')),
  PARTITION p202504 VALUES LESS THAN (TO_DAYS('2025-05-01')),
  PARTITION p202505 VALUES LESS THAN (TO_DAYS('2025-06-01')),
  PARTITION p202506 VALUES LESS THAN (TO_DAYS('2025-07-01')),
  PARTITION p202507 VALUES LESS THAN (TO_DAYS('2025-08-01')),
  PARTITION p202508 VALUES LESS THAN (TO_DAYS('2025-09-01')),
  PARTITION p202509 VALUES LESS THAN (TO_DAYS('2025-10-01')),
  PARTITION p202510 VALUES LESS THAN (TO_DAYS('2025-11-01')),
  PARTITION p202511 VALUES LESS THAN (TO_DAYS('2025-12-01')),
  PARTITION p202512 VALUES LESS THAN (TO_DAYS('2026-01-01')),
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
SQL;

        try {
            $db->execute($sql);
            $this->output->writeln('成功创建分区工作流任务历史表');
        } catch (\Exception $e) {
            $this->output->writeln('创建分区任务历史表失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建分区的工作流实例表
     */
    protected function createPartitionedInstanceTable(): void
    {
        $db = $this->getDb();

        // 创建活跃实例的分区表（按状态分区）
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `workflow_instances_active_partitioned` (
  `id` varchar(50) NOT NULL COMMENT '工作流实例ID',
  `definition_id` varchar(50) NOT NULL COMMENT '工作流定义ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `business_key` varchar(100) DEFAULT NULL COMMENT '业务键',
  `title` varchar(200) NOT NULL COMMENT '实例标题',
  `status` varchar(20) NOT NULL COMMENT '状态',
  `priority` int(11) NOT NULL DEFAULT 5 COMMENT '优先级',
  `current_node_id` varchar(50) DEFAULT NULL COMMENT '当前节点ID',
  `current_node_name` varchar(200) DEFAULT NULL COMMENT '当前节点名称',
  `context` json DEFAULT NULL COMMENT '执行上下文数据',
  `variables` json DEFAULT NULL COMMENT '变量数据',
  `started_at` timestamp NULL DEFAULT NULL COMMENT '开始时间',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '完成时间',
  `suspended_at` timestamp NULL DEFAULT NULL COMMENT '暂停时间',
  `duration` bigint(20) DEFAULT NULL COMMENT '执行时长（毫秒）',
  `error_message` text COMMENT '错误信息',
  `error_stack` longtext COMMENT '错误堆栈',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '发起用户ID',
  `assignee` bigint(20) DEFAULT NULL COMMENT '当前处理人ID',
  PRIMARY KEY (`id`, `status`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_definition` (`definition_id`),
  KEY `idx_status` (`status`),
  KEY `idx_business_key` (`business_key`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_assignee` (`assignee`),
  CONSTRAINT `chk_status` CHECK (`status` IN ('pending', 'running', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活跃工作流实例表（分区）'
PARTITION BY LIST COLUMNS(status) (
  PARTITION p_pending VALUES IN ('pending'),
  PARTITION p_running VALUES IN ('running'),
  PARTITION p_suspended VALUES IN ('suspended')
);
SQL;

        try {
            $db->execute($sql);
            $this->output->writeln('成功创建分区活跃工作流实例表');
        } catch (\Exception $e) {
            $this->output->writeln('创建分区实例表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取数据库连接
     *
     * @return \think\db\Connection
     */
    protected function getDb()
    {
        return $this->getAdapter()->getConnection();
    }
}
