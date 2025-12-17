# Workflow Engine Database Design

## 概述

本文档描述了 AlkaidSYS 工作流引擎的数据库表结构设计，支持多租户架构。

## 核心表结构

### 1. workflow_definitions - 工作流定义表

存储工作流的定义信息，包括 BPMN 模型、节点配置等。

```sql
CREATE TABLE `workflow_definitions` (
  `id` varchar(50) NOT NULL COMMENT '工作流定义ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `name` varchar(100) NOT NULL COMMENT '工作流名称（唯一）',
  `display_name` varchar(200) NOT NULL COMMENT '显示名称',
  `description` text COMMENT '描述',
  `type` varchar(20) NOT NULL DEFAULT 'approval' COMMENT '类型：approval/automation/hybrid',
  `category` varchar(50) DEFAULT NULL COMMENT '分类',
  `icon` varchar(100) DEFAULT NULL COMMENT '图标',
  `version` int(11) NOT NULL DEFAULT 1 COMMENT '版本号',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT '状态：draft/published/archived',
  `bpmn_xml` longtext COMMENT 'BPMN 2.0 XML定义',
  `nodes_config` json COMMENT '节点配置JSON',
  `triggers_config` json COMMENT '触发器配置JSON',
  `variables_schema` json COMMENT '变量Schema定义',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '创建用户ID',
  `updated_by` bigint(20) DEFAULT NULL COMMENT '更新用户ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name_version` (`tenant_id`, `name`, `version`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流定义表';
```

### 2. workflow_instances - 工作流实例表

存储工作流实例的执行信息。

```sql
CREATE TABLE `workflow_instances` (
  `id` varchar(50) NOT NULL COMMENT '工作流实例ID',
  `definition_id` varchar(50) NOT NULL COMMENT '工作流定义ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `business_key` varchar(100) DEFAULT NULL COMMENT '业务键',
  `title` varchar(200) NOT NULL COMMENT '实例标题',
  `status` varchar(20) NOT NULL COMMENT '状态：pending/running/completed/failed/cancelled/suspended',
  `priority` int(11) NOT NULL DEFAULT 5 COMMENT '优先级（1-10）',
  `current_node_id` varchar(50) DEFAULT NULL COMMENT '当前节点ID',
  `current_node_name` varchar(200) DEFAULT NULL COMMENT '当前节点名称',
  `context` json COMMENT '执行上下文数据',
  `variables` json COMMENT '变量数据',
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
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_definition` (`definition_id`),
  KEY `idx_status` (`status`),
  KEY `idx_business_key` (`business_key`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_assignee` (`assignee`),
  FOREIGN KEY (`definition_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流实例表';
```

### 3. workflow_tasks - 工作流任务表

存储工作流中的任务信息，包括待办、已完成等任务。

```sql
CREATE TABLE `workflow_tasks` (
  `id` varchar(50) NOT NULL COMMENT '任务ID',
  `instance_id` varchar(50) NOT NULL COMMENT '工作流实例ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `node_id` varchar(50) NOT NULL COMMENT '节点ID',
  `node_name` varchar(200) NOT NULL COMMENT '节点名称',
  `task_type` varchar(20) NOT NULL COMMENT '任务类型：user_task/system_task/service_task',
  `task_sub_type` varchar(20) DEFAULT NULL COMMENT '任务子类型：approval/countersign/notify',
  `title` varchar(200) NOT NULL COMMENT '任务标题',
  `description` text COMMENT '任务描述',
  `status` varchar(20) NOT NULL COMMENT '状态：pending/claimed/completed/rejected/cancelled/escalated',
  `priority` int(11) NOT NULL DEFAULT 5 COMMENT '优先级（1-10）',
  `assignee` bigint(20) DEFAULT NULL COMMENT '处理人ID',
  `assignee_name` varchar(100) DEFAULT NULL COMMENT '处理人姓名',
  `candidate_users` json COMMENT '候选用户列表',
  `candidate_groups` json COMMENT '候选组列表',
  `form_data` json COMMENT '表单数据',
  `result` json COMMENT '处理结果',
  `comment` text COMMENT '审批意见',
  `due_date` timestamp NULL DEFAULT NULL COMMENT '截止时间',
  `reminder_times` json COMMENT '提醒时间列表',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '完成时间',
  `rejected_at` timestamp NULL DEFAULT NULL COMMENT '拒绝时间',
  `cancelled_at` timestamp NULL DEFAULT NULL COMMENT '取消时间',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '创建人ID',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assignee` (`assignee`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_node` (`node_id`),
  FOREIGN KEY (`instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流任务表';
```

### 4. workflow_triggers - 工作流触发器表

存储工作流的触发器配置。

```sql
CREATE TABLE `workflow_triggers` (
  `id` varchar(50) NOT NULL COMMENT '触发器ID',
  `definition_id` varchar(50) NOT NULL COMMENT '工作流定义ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `name` varchar(100) NOT NULL COMMENT '触发器名称',
  `type` varchar(20) NOT NULL COMMENT '触发器类型：manual/webhook/schedule/data_event/message_event',
  `config` json NOT NULL COMMENT '触发器配置JSON',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` bigint(20) DEFAULT NULL COMMENT '创建人ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`, `name`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_definition` (`definition_id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_active` (`is_active`),
  FOREIGN KEY (`definition_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流触发器表';
```

### 5. workflow_event_logs - 工作流事件日志表

记录工作流执行过程中的所有事件。

```sql
CREATE TABLE `workflow_event_logs` (
  `id` varchar(50) NOT NULL COMMENT '事件ID',
  `instance_id` varchar(50) DEFAULT NULL COMMENT '工作流实例ID',
  `task_id` varchar(50) DEFAULT NULL COMMENT '任务ID',
  `tenant_id` bigint(20) NOT NULL COMMENT '租户ID',
  `event_type` varchar(50) NOT NULL COMMENT '事件类型',
  `event_name` varchar(100) NOT NULL COMMENT '事件名称',
  `event_data` json COMMENT '事件数据',
  `user_id` bigint(20) DEFAULT NULL COMMENT '操作用户ID',
  `user_name` varchar(100) DEFAULT NULL COMMENT '操作用户姓名',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP地址',
  `user_agent` text COMMENT '用户代理',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`task_id`) REFERENCES `workflow_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流事件日志表';
```

## 多租户设计

### 租户隔离策略

1. **数据隔离**：所有表都包含 `tenant_id` 字段
2. **查询过滤**：应用层自动添加 `tenant_id` 条件
3. **唯一性约束**：`tenant_id` + 业务唯一键组成复合唯一约束

### 索引设计

1. **租户索引**：`idx_tenant` - 所有表都有，用于快速租户过滤
2. **业务索引**：根据查询模式设计复合索引
3. **状态索引**：`idx_status` - 用于状态查询优化
4. **时间索引**：`idx_created_at` - 用于时间范围查询

## 性能优化

### 1. 分区策略

对于大数据量的表（如 `workflow_event_logs`），建议按时间分区：

```sql
-- 按月分区
ALTER TABLE workflow_event_logs
PARTITION BY RANGE (YEAR(created_at)*100 + MONTH(created_at)) (
  PARTITION p202501 VALUES LESS THAN (202502),
  PARTITION p202502 VALUES LESS THAN (202503),
  ...
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
```

### 2. 缓存策略

- **Redis 缓存**：活跃实例状态、任务分配信息
- **应用缓存**：工作流定义、BPMN 模型
- **查询缓存**：常用统计数据

### 3. 归档策略

- **实例归档**：完成超过 1 年的实例数据归档到历史表
- **日志归档**：事件日志定期归档或清理
- **数据保留**：根据业务需求设置数据保留期限

## 数据一致性

### 1. 外键约束

- 所有表都设置必要的外键约束
- 使用 `ON DELETE CASCADE` 或 `ON DELETE SET NULL`
- 避免循环依赖

### 2. 事务保证

- 工作流实例创建、任务分配等操作使用事务
- 确保数据一致性
- 记录详细的操作日志

### 3. 幂等性设计

- 所有业务操作支持幂等性
- 使用唯一业务键防止重复操作
- 失败重试机制

## 扩展性考虑

### 1. 水平扩展

- 支持读写分离
- 分库分表准备（预留分区键）
- 微服务架构支持

### 2. 垂直扩展

- JSON 字段使用灵活的 schema
- 支持动态字段添加
- 向后兼容的 schema 演进

### 3. 监控指标

- 实例数量、任务数量趋势
- 执行时长分布
- 异常率监控
- 性能指标追踪
