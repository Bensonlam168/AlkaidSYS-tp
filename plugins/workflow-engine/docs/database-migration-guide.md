# 工作流引擎数据库迁移指南

## 概述

工作流引擎数据库迁移系统基于ThinkPHP的迁移机制构建，提供了完整的数据库表创建、外键约束、初始数据和性能优化功能。

## 迁移文件结构

```
migrations/
├── 20251216000001_create_workflow_tables.php      # 创建基础表结构
├── 20251216000002_add_foreign_keys.php            # 添加外键约束
├── 20251216000003_insert_initial_data.php         # 插入初始数据
├── 20251216000004_create_partitioned_tables.php   # 创建分区表（大数据量优化）
└── 20251216000005_optimize_indexes.php            # 优化索引
```

## 迁移文件详情

### 1. 创建基础表结构 (20251216000001)

**功能**: 创建工作流引擎核心数据表

**包含表**:
- `workflow_definitions` - 工作流定义表
- `workflow_instances` - 工作流实例表
- `workflow_tasks` - 工作流任务表
- `workflow_triggers` - 工作流触发器表
- `workflow_event_logs` - 工作流事件日志表

**关键特性**:
- 多租户设计（所有表包含`tenant_id`字段）
- 支持BPMN 2.0 XML存储
- JSON字段存储复杂配置
- 完整的索引优化
- 时间戳自动管理

### 2. 添加外键约束 (20251216000002)

**功能**: 建立表间关系约束

**外键关系**:
- `workflow_instances.definition_id` → `workflow_definitions.id`
- `workflow_instances.created_by` → `users.id`（可选）
- `workflow_tasks.instance_id` → `workflow_instances.id`
- `workflow_tasks.assignee` → `users.id`（可选）
- `workflow_triggers.definition_id` → `workflow_definitions.id`
- `workflow_event_logs.instance_id` → `workflow_instances.id`
- `workflow_event_logs.task_id` → `workflow_tasks.id`

**删除策略**:
- `CASCADE`: 级联删除
- `SET NULL`: 设置为NULL

### 3. 插入初始数据 (20251216000003)

**功能**: 插入示例工作流和系统配置

**包含数据**:
- 费用报销工作流定义
- 请假申请工作流定义
- 手动触发器和Webhook触发器
- 系统初始化事件日志

**BPMN 2.0支持**:
- 完整的BPMN XML示例
- 标准流程元素：开始事件、用户任务、结束事件
- 流程序列连接

### 4. 创建分区表 (20251216000004)

**功能**: 为大数据量场景创建分区表

**分区策略**:
- `workflow_event_logs_partitioned` - 按月分区（基于`created_at`）
- `workflow_instance_history_partitioned` - 按月分区（基于`completed_at`）
- `workflow_task_history_partitioned` - 按月分区（基于`completed_at`）
- `workflow_instances_active_partitioned` - 按状态分区

**优势**:
- 提高大数据量查询性能
- 便于历史数据归档
- 支持分区级备份和恢复

### 5. 优化索引 (20251216000005)

**功能**: 添加复合索引和优化查询性能

**索引类型**:
- 复合索引：`tenant_id + status + created_at`
- 部分索引：仅索引活跃记录（MySQL 8.0+）
- 全文索引：搜索优化（如果支持）
- 性能监控索引：统计和监控优化

## 执行迁移

### 方法一：使用迁移脚本（推荐）

```bash
# 进入工作流引擎目录
cd /path/to/plugins/workflow-engine

# 执行所有迁移
./scripts/run-migrations.sh run

# 查看迁移状态
./scripts/run-migrations.sh status

# 回滚最后一个迁移
./scripts/run-migrations.sh rollback

# 创建新迁移文件
./scripts/run-migrations.sh create add_new_feature
```

### 方法二：使用ThinkPHP命令

```bash
# 进入项目根目录
cd /path/to/project

# 执行迁移
php think migrate:run

# 查看迁移状态
php think migrate:status

# 回滚迁移
php think migrate:rollback

# 重置数据库（危险操作）
php think migrate:reset
```

### 方法三：在代码中执行

```php
use think\migration\Migrator;

class WorkflowInstaller
{
    public static function install()
    {
        // 执行基础表创建
        (new \CreateWorkflowTables())->change();

        // 添加外键约束
        (new \AddForeignKeys())->change();

        // 插入初始数据
        (new \InsertInitialData())->change();

        // 创建分区表
        (new \CreatePartitionedTables())->change();

        // 优化索引
        (new \OptimizeIndexes())->change();
    }
}
```

## 数据库配置

### ThinkPHP数据库配置

在 `config/database.php` 中添加工作流引擎数据表前缀：

```php
return [
    // 数据库配置信息
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type' => 'mysql',
            // 服务器地址
            'hostname' => '127.0.0.1',
            // 数据库名
            'database' => 'your_database',
            // 用户名
            'username' => 'root',
            // 密码
            'password' => '',
            // 端口
            'hostport' => '3306',
            // 数据库连接参数
            'params' => [],
            // 数据库编码默认采用utf8mb4
            'charset' => 'utf8mb4',
            // 数据库表前缀
            'prefix' => 'alkaid_',
            // 数据库调试模式
            'debug' => false,
        ],
    ],
];
```

### 环境变量配置

创建 `.env` 文件：

```env
# 数据库配置
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=alkaidsys
DB_USER=root
DB_PASS=your_password

# 工作流引擎配置
WORKFLOW_DB_PREFIX=alkaid_
WORKFLOW_TENANT_ENABLED=true
WORKFLOW_PARTITION_ENABLED=false
WORKFLOW_INDEX_OPTIMIZATION=true
```

## 多租户支持

### 数据隔离策略

所有工作流表都包含 `tenant_id` 字段，实现数据隔离：

```sql
-- 查询特定租户的工作流定义
SELECT * FROM workflow_definitions
WHERE tenant_id = 1;

-- 复合唯一约束确保租户内数据唯一性
ALTER TABLE workflow_definitions
ADD CONSTRAINT uk_tenant_name_version
UNIQUE KEY (tenant_id, name, version);
```

### 租户配置

```php
// 在配置文件中设置默认租户
return [
    'tenant' => [
        'enabled' => true,
        'default_tenant_id' => 1,
        'isolation_strategy' => 'column', // column, schema, database
    ],
];
```

## 性能优化

### 索引优化

1. **租户索引**: 所有表都有 `tenant_id` 索引
2. **状态索引**: 用于快速状态查询
3. **时间索引**: 用于时间范围查询
4. **复合索引**: 多条件查询优化

### 分区策略

```sql
-- 按时间分区（推荐用于事件日志）
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202501 VALUES LESS THAN (TO_DAYS('2025-02-01')),
  PARTITION p202502 VALUES LESS THAN (TO_DAYS('2025-03-01')),
  -- ... 更多分区
  PARTITION pmax VALUES LESS THAN MAXVALUE
);

-- 按状态分区（推荐用于活跃实例）
PARTITION BY LIST COLUMNS(status) (
  PARTITION p_pending VALUES IN ('pending'),
  PARTITION p_running VALUES IN ('running'),
  PARTITION p_suspended VALUES IN ('suspended')
);
```

### 查询优化

```sql
-- 高效的待办任务查询
SELECT t.*, i.title as instance_title
FROM workflow_tasks t
JOIN workflow_instances i ON t.instance_id = i.id
WHERE t.tenant_id = 1
  AND t.assignee = 123
  AND t.status = 'pending'
ORDER BY t.priority DESC, t.due_date ASC;

-- 高效的历史记录查询
SELECT *
FROM workflow_event_logs
WHERE tenant_id = 1
  AND instance_id = 'instance_123'
  AND created_at >= '2025-01-01'
  AND created_at < '2025-02-01'
ORDER BY created_at DESC
LIMIT 100;
```

## 监控和维护

### 性能监控

```sql
-- 检查表大小
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'DB Size (MB)'
FROM information_schema.tables
WHERE table_schema = 'your_database'
  AND table_name LIKE 'alkaid_workflow_%'
ORDER BY (data_length + index_length) DESC;

-- 检查索引使用情况
SELECT
    table_name,
    index_name,
    cardinality,
    sub_part
FROM information_schema.statistics
WHERE table_schema = 'your_database'
  AND table_name LIKE 'alkaid_workflow_%'
ORDER BY table_name, index_name;
```

### 数据归档

```sql
-- 归档6个月前的事件日志（删除分区）
ALTER TABLE workflow_event_logs_partitioned
DROP PARTITION p202507;

-- 归档1年前的已完成实例
INSERT INTO workflow_instance_history_partitioned
SELECT * FROM workflow_instances
WHERE status = 'completed'
  AND completed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

DELETE FROM workflow_instances
WHERE status = 'completed'
  AND completed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

## 故障排除

### 常见问题

1. **外键约束失败**
   ```
   错误: Cannot add foreign key constraint
   解决: 检查引用的表和列是否存在，确保数据类型匹配
   ```

2. **分区表创建失败**
   ```
   错误: Table partitioning requires the transactional storage engine
   解决: 确保使用InnoDB存储引擎
   ```

3. **索引重复**
   ```
   错误: Duplicate key name
   解决: 删除现有索引或使用不同的索引名称
   ```

### 调试方法

```bash
# 验证迁移文件语法
php -l migrations/*.php

# 试运行迁移（不实际执行）
./scripts/run-migrations.sh --dry-run run

# 查看详细错误信息
php think migrate:run -vvv
```

### 恢复操作

```bash
# 备份数据库
mysqldump -u root -p alkaidsys > backup_$(date +%Y%m%d_%H%M%S).sql

# 恢复数据库
mysql -u root -p alkaidsys < backup_20251216_120000.sql

# 回滚迁移
./scripts/run-migrations.sh rollback
```

## 最佳实践

### 1. 开发环境

```bash
# 在开发环境中重置数据库
./scripts/run-migrations.sh reset
./scripts/run-migrations.sh run
```

### 2. 测试环境

```bash
# 在测试环境中执行迁移
./scripts/run-migrations.sh run

# 检查迁移状态
./scripts/run-migrations.sh status
```

### 3. 生产环境

```bash
# 在生产环境中执行迁移
./scripts/run-migrations.sh run

# 验证迁移结果
./scripts/run-migrations.sh status

# 备份数据库
mysqldump -u root -p alkaidsys > pre_migration_backup.sql
```

### 4. 迁移文件命名规范

```
格式: YYYYMMDDHHMMSS_action_description.php
示例: 20251216000001_create_workflow_tables.php
      20251217000001_add_workflow_versioning.php
```

### 5. 迁移脚本要求

- 每个迁移文件必须实现 `change()` 方法
- 使用 `declare(strict_types=1)` 严格类型检查
- 添加详细的中文注释
- 提供回滚逻辑（如果可能）
- 包含错误处理

## 扩展开发

### 创建新迁移文件

```bash
./scripts/run-migrations.sh create add_workflow_notifications
```

### 迁移模板

```php
<?php

declare(strict_types=1);

use think\migration\db\Column;
use think\migration\Migrator;

/**
 * 添加工作流通知功能
 *
 * @package plugins\\workflow-engine\\migrations
 */
class AddWorkflowNotifications extends Migrator
{
    /**
     * 执行迁移
     */
    public function change(): void
    {
        // 创建通知表
        $this->createNotificationsTable();

        // 添加外键约束
        $this->addForeignKeys();

        // 插入初始数据
        $this->insertInitialData();
    }

    protected function createNotificationsTable(): void
    {
        // 实现表创建逻辑
    }

    protected function addForeignKeys(): void
    {
        // 实现外键添加逻辑
    }

    protected function insertInitialData(): void
    {
        // 实现初始数据插入逻辑
    }
}
```

## 总结

工作流引擎数据库迁移系统提供了：

1. **完整的数据库表结构** - 支持多租户和BPMN 2.0
2. **数据完整性约束** - 外键和唯一约束
3. **示例数据** - 快速上手和测试
4. **性能优化** - 分区和索引优化
5. **易于使用的工具** - 自动化迁移脚本
6. **详细的文档** - 完整的使用指南

遵循本指南，您可以成功部署和维护工作流引擎的数据库。
