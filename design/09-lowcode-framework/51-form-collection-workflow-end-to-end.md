# 表单 → 数据建模 → 工作流 端到端集成设计（草案）

> 本文档串联低代码表单、数据建模（Collection）、工作流三大模块，描述一条从「用户提交表单」到「数据入库 + 工作流触发与执行」的完整链路，作为实现阶段的端到端蓝图。
## 🔗 关联设计文档

- [低代码数据建模插件设计](42-lowcode-data-modeling.md)
- [低代码表单设计器插件设计](43-lowcode-form-designer.md)
- [低代码工作流引擎插件设计](44-lowcode-workflow.md)
- [数据库演进与迁移策略](../03-data-layer/11-database-evolution-and-migration-strategy.md)
- [可观测性与运维设计](../04-security-performance/15-observability-and-ops-design.md)



## 1. 整体流程概览

典型端到端流程（以「订单审批」为例）：

1. 用户在前端 **低代码表单页面** 填写并提交表单（绑定某个 Collection，例如 `order`）。
2. 后端 **表单网关接口** 校验表单 Schema，写入对应的 **Collection 实例表 / 业务表**（自动追加 `tenant_id/site_id`）。
3. 写入成功后，表单模块发布领域事件（如 `lowcode.form.submitted` / `lowcode.collection.data.created`）。
4. 工作流引擎的 **触发器（Trigger）** 订阅这些事件，根据配置匹配需要启动的工作流定义。
5. 工作流引擎创建执行上下文（`ExecutionContext`），初始化变量（`trigger.data`等），并基于表达式引擎（Symfony ExpressionLanguage）驱动节点流转。
6. 各个节点（审批、通知、HTTP 请求、数据更新等）执行完成后，必要时回写业务表或发布新的领域事件。

> 多租户约束：整个链路中必须始终携带 `tenant_id` / `site_id` / `user_id`，并在每一层做隔离与权限校验。

## 2. 表单层：Schema、绑定与提交

### 2.1 表单 Schema 与 Collection 绑定

- 每个低代码表单在 `lowcode_forms` 中保存 Schema：
  - `form_key`：表单唯一标识，例如 `order_create_form`
  - `collection_name`：绑定的 Collection 名称，例如 `order`
  - `schema`：前端渲染与验证所用的 JSON Schema（含 `x-component` 等扩展）
- 关联设计：
  - 一个 Collection 可以绑定多个表单（不同业务场景或角色视图）；
  - 表单提交后，后端根据 `collection_name` 决定写入哪一张业务表。

### 2.2 表单提交 API（示意）

- 统一入口：`POST /api/lowcode/forms/{formKey}/submit`
- 核心流程：
  1. 鉴权：从 JWT 中解析 `userId/tenantId/siteId`，写入 Request 上下文；
  2. 加载表单定义：根据 `formKey` 从 `lowcode_forms` 读取 Schema 与 `collection_name`；
  3. 校验输入：使用 `FormValidatorGenerator` 将 Schema 转为 ThinkPHP 验证规则；
  4. 调用 `CollectionService` / `CollectionRepository` 把数据写入目标 Collection 对应业务表；
  5. 发布事件（见 4.1 节）。

## 3. 数据建模层：Collection 与业务表

### 3.1 Collection 元数据

- 由 `lowcode_data_modeling` 插件管理，包括：
  - `Collection`：逻辑表定义（名称、显示名、关联数据库表名等）；
  - `Field`：字段定义（类型、长度、是否必填、默认值等）；
  - 关系定义：一对多、多对多等。

### 3.2 业务表结构与多租户

- 当创建或更新 Collection 时，由 `SchemaBuilder` 负责 DDL：
  - 自动追加 `tenant_id bigint unsigned not null`;
  - 自动追加 `site_id bigint unsigned default 0`;
  - 追加索引 `KEY idx_tenant_site (tenant_id, site_id)`；
- 运行时 DDL 策略：
  - 开发 / 测试环境允许运行时直接执行 `CREATE/ALTER TABLE`；
  - 生产环境优先生成迁移脚本，由运维审核后在维护窗口执行（见数据建模文档）。

### 3.3 Collection 数据写入与事件

- 表单模块不直接操作底层数据表，而是调用 `CollectionService::createRecord($collectionName, $payload, $context)`：
  - `context` 中包含 `tenant_id/site_id/user_id` 等信息；
  - Service 内部侧重业务规则与审计字段（`created_by`、`created_at` 等）；
- 写入成功后，由数据建模层统一发布数据事件（示意）：

```text
lowcode.collection.data.created
lowcode.collection.data.updated
lowcode.collection.data.deleted
```

事件 payload 应包含：

```json
{
  "tenantId": 1,
  "siteId": 0,
  "userId": 1001,
  "collection": "order",
  "recordId": "202501010001",
  "data": { "total": 1234.56, "status": "PENDING" }
}
```

## 4. 事件与工作流触发器

### 4.1 事件规范

- 表单层事件（示例）：
  - `lowcode.form.submitted`：表单提交成功后发布；
- 数据层事件（示例）：
  - `lowcode.collection.data.created`
  - `lowcode.collection.data.updated`
- 所有事件均要求：
  - 必含 `tenantId/siteId/userId`；
  - 指明 `formKey` 或 `collection`；
  - 提供 `data` 快照用于工作流表达式判断。

### 4.2 工作流触发器配置模型

- 在 `lowcode_workflows` 中为每个工作流定义一个或多个 Trigger：
  - `type`: `form_submitted` / `collection_event` / `manual` 等；
  - `event`: 绑定的事件名，如 `lowcode.collection.data.created`；
  - `filters`: 表达式列表，用于筛选特定数据（如订单金额 > 1000）；
- 示例（订单审批）：

```json
{
  "type": "collection_event",
  "event": "lowcode.collection.data.created",
  "collection": "order",
  "filters": [
    "trigger.data.total > 1000",
    "trigger.data.status == 'PENDING'"
  ]
}
```

### 4.3 触发流程

1. 消息总线（如 RabbitMQ）将 `lowcode.collection.data.created` 推送给工作流引擎；
2. 工作流引擎根据 `tenantId/siteId` 找到对应租户下已启用的工作流；
3. 逐个评估 Trigger 的 `filters` 表达式（基于 ExpressionLanguage）：
   - 通过则创建新的 `WorkflowExecution`；
   - 未通过则忽略。

## 5. 工作流执行与上下文

### 5.1 执行上下文结构

- `ExecutionContext` 至少包含：
  - `tenantId` / `siteId` / `userId`
  - `trigger`: 来自事件的原始数据（`trigger.data`）
  - `workflow`: 工作流定义（节点、连线、变量等）
  - `nodes`: 节点执行结果缓存，如 `nodes.node_001.response`

### 5.2 节点执行与表达式

- 条件节点示例：
  - 配置中的表达式：`{{ trigger.data.total > 1000 }}`
  - 存储时去掉花括号，运行时由 `ExpressionEvaluator` 统一解析：

```php
$result = $expressionEvaluator->evaluate(
    'trigger.data.total > 1000',
    ['trigger' => $context->getTrigger(), 'nodes' => $context->getNodes()]
);
```

- HTTP 请求节点 / 通知节点等执行完成后，将结果写入 `nodes`，供后续节点表达式引用。

## 6. 回写与后续集成

### 6.1 回写 Collection / 业务表

- 某些节点（如“审批通过”）需要回写业务字段：
  - 通过 `CollectionService::updateRecord()` 更新记录；
  - 保持与初始写入一致的多租户与审计规则。

### 6.2 与其他系统的集成

- 支持在节点中调用 HTTP / MQ / Webhook 等方式与外部系统集成；
- 建议：节点执行结果统一抽象为 `nodes.{nodeId}.response`，并可在后续节点表达式中使用。

## 7. 多租户与安全性贯穿

1. 所有 API 必须在网关层完成鉴权与租户解析；
2. 表单 → Collection → 工作流的每一跳都禁止丢失 `tenantId/siteId/userId`；
3. 工作流的所有查询与写入必须在当前租户范围内执行；
4. 关键操作（启动/终止工作流、审批决策等）应通过 Casbin 等权限系统做细粒度授权。

