# AlkaidSYS 工作流引擎 API 文档

> **版本**: 1.0.0
> **最后更新**: 2025-12-17
> **基础路径**: `/api/v1`

## 概述

工作流引擎提供完整的流程管理能力，包括：
- **流程定义管理** - 创建、编辑、发布工作流定义
- **流程实例管理** - 启动、监控、控制工作流实例
- **任务处理** - 待办任务、审批、转办、委托
- **触发器管理** - 配置多种触发方式

## 认证

除 Webhook 端点外，所有接口需要 JWT Bearer Token 认证。

```http
Authorization: Bearer <token>
```

## 多租户

系统自动根据认证信息识别租户，所有数据完全隔离。

## 权限

使用 Casbin RBAC 权限模型，权限码格式：`workflow.{resource}.{action}`

| 权限码 | 描述 |
|--------|------|
| `workflow.definition.read` | 读取工作流定义 |
| `workflow.definition.write` | 创建/更新工作流定义 |
| `workflow.definition.delete` | 删除工作流定义 |
| `workflow.instance.read` | 读取工作流实例 |
| `workflow.instance.write` | 创建/管理工作流实例 |
| `workflow.task.read` | 读取工作流任务 |
| `workflow.task.write` | 处理工作流任务 |
| `workflow.trigger.read` | 读取触发器 |
| `workflow.trigger.write` | 管理触发器 |
| `workflow.trigger.delete` | 删除触发器 |

## 响应格式

### 成功响应

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

### 错误响应

```json
{
  "code": 400,
  "message": "Validation failed",
  "errors": [
    {
      "field": "name",
      "rule": "required",
      "message": "名称不能为空"
    }
  ]
}
```

---

## API 列表

### 工作流定义 API

| 方法 | 路径 | 描述 | 权限 |
|------|------|------|------|
| GET | `/workflow/definitions` | 列出工作流定义 | `workflow.definition.read` |
| POST | `/workflow/definitions` | 创建工作流定义 | `workflow.definition.write` |
| GET | `/workflow/definitions/{id}` | 获取定义详情 | `workflow.definition.read` |
| PUT | `/workflow/definitions/{id}` | 更新定义 | `workflow.definition.write` |
| DELETE | `/workflow/definitions/{id}` | 删除定义 | `workflow.definition.delete` |
| POST | `/workflow/definitions/{id}/publish` | 发布定义 | `workflow.definition.write` |
| POST | `/workflow/definitions/{id}/archive` | 归档定义 | `workflow.definition.write` |
| POST | `/workflow/definitions/{id}/validate` | 验证定义 | `workflow.definition.read` |
| POST | `/workflow/definitions/{id}/clone` | 克隆定义 | `workflow.definition.write` |

### 工作流实例 API

| 方法 | 路径 | 描述 | 权限 |
|------|------|------|------|
| GET | `/workflow/instances` | 列出实例 | `workflow.instance.read` |
| POST | `/workflow/instances` | 启动实例 | `workflow.instance.write` |
| GET | `/workflow/instances/{id}` | 获取实例详情 | `workflow.instance.read` |
| POST | `/workflow/instances/{id}/cancel` | 取消实例 | `workflow.instance.write` |
| POST | `/workflow/instances/{id}/suspend` | 暂停实例 | `workflow.instance.write` |
| POST | `/workflow/instances/{id}/resume` | 恢复实例 | `workflow.instance.write` |
| GET | `/workflow/instances/{id}/history` | 获取执行历史 | `workflow.instance.read` |
| GET | `/workflow/instances/{id}/variables` | 获取变量 | `workflow.instance.read` |
| PUT | `/workflow/instances/{id}/variables` | 更新变量 | `workflow.instance.write` |

### 工作流任务 API

| 方法 | 路径 | 描述 | 权限 |
|------|------|------|------|
| GET | `/workflow/tasks` | 列出所有任务 | `workflow.task.read` |
| GET | `/workflow/tasks/pending` | 获取待办任务 | `workflow.task.read` |
| GET | `/workflow/tasks/{id}` | 获取任务详情 | `workflow.task.read` |
| POST | `/workflow/tasks/{id}/approve` | 审批通过 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/reject` | 审批拒绝 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/transfer` | 转办 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/delegate` | 委托 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/claim` | 认领 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/unclaim` | 取消认领 | `workflow.task.write` |
| POST | `/workflow/tasks/{id}/complete` | 完成任务 | `workflow.task.write` |
| GET | `/workflow/tasks/{id}/form` | 获取表单 | `workflow.task.read` |
| POST | `/workflow/tasks/{id}/form` | 提交表单 | `workflow.task.write` |

### 触发器 API

| 方法 | 路径 | 描述 | 权限 |
|------|------|------|------|
| GET | `/workflow/triggers` | 列出触发器 | `workflow.trigger.read` |
| GET | `/workflow/triggers/types` | 获取触发器类型 | `workflow.trigger.read` |
| POST | `/workflow/triggers` | 创建触发器 | `workflow.trigger.write` |
| GET | `/workflow/triggers/{id}` | 获取触发器详情 | `workflow.trigger.read` |
| PUT | `/workflow/triggers/{id}` | 更新触发器 | `workflow.trigger.write` |
| DELETE | `/workflow/triggers/{id}` | 删除触发器 | `workflow.trigger.delete` |
| POST | `/workflow/triggers/{id}/enable` | 启用触发器 | `workflow.trigger.write` |
| POST | `/workflow/triggers/{id}/disable` | 禁用触发器 | `workflow.trigger.write` |
| POST | `/workflow/triggers/{id}/test` | 测试触发器 | `workflow.trigger.write` |
| GET | `/workflow/triggers/{id}/logs` | 获取触发日志 | `workflow.trigger.read` |

### Webhook 端点

| 方法 | 路径 | 描述 | 认证 |
|------|------|------|------|
| ANY | `/workflow/triggers/webhook/{key}` | Webhook 接收 | 无需认证 |

---

## 详细接口说明

### 1. 工作流定义

#### 1.1 列出工作流定义

```http
GET /api/v1/workflow/definitions
```

**查询参数**:

| 参数 | 类型 | 必填 | 描述 |
|------|------|------|------|
| page | integer | 否 | 页码，默认 1 |
| limit | integer | 否 | 每页数量，默认 20，最大 100 |
| status | string | 否 | 状态筛选：draft/published/archived |
| category | string | 否 | 分类筛选 |
| keyword | string | 否 | 关键字搜索 |

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "items": [
      {
        "id": "def-001",
        "name": "请假审批流程",
        "key": "leave-approval",
        "version": 1,
        "status": "published",
        "category": "hr",
        "description": "员工请假审批流程",
        "created_at": "2025-12-17T10:00:00Z",
        "updated_at": "2025-12-17T10:00:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "limit": 20
  }
}
```

#### 1.2 创建工作流定义

```http
POST /api/v1/workflow/definitions
```

**请求体**:

```json
{
  "name": "请假审批流程",
  "key": "leave-approval",
  "description": "员工请假审批流程",
  "category": "hr",
  "node_config": [
    {
      "id": "start",
      "type": "start",
      "name": "开始"
    },
    {
      "id": "approval",
      "type": "approval",
      "name": "主管审批",
      "config": {
        "assignee_type": "role",
        "assignee_value": "manager"
      }
    },
    {
      "id": "end",
      "type": "end",
      "name": "结束"
    }
  ],
  "trigger_config": {
    "type": "manual",
    "config": {}
  }
}
```

#### 1.3 发布工作流定义

```http
POST /api/v1/workflow/definitions/{id}/publish
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "def-001",
    "version": 1,
    "status": "published"
  }
}
```

### 2. 工作流实例

#### 2.1 启动工作流实例

```http
POST /api/v1/workflow/instances
```

**请求体**:

```json
{
  "definition_id": "def-001",
  "variables": {
    "leave_type": "annual",
    "days": 3,
    "reason": "家庭事务"
  },
  "business_key": "LEAVE-2025-001"
}
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "inst-001",
    "definition_id": "def-001",
    "status": "running",
    "started_at": "2025-12-17T10:30:00Z",
    "current_node": "approval"
  }
}
```

#### 2.2 获取执行历史

```http
GET /api/v1/workflow/instances/{id}/history
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "event_type": "instance.started",
      "message": "流程实例已启动",
      "timestamp": "2025-12-17T10:30:00Z"
    },
    {
      "event_type": "node.entered",
      "node_id": "start",
      "node_type": "start",
      "message": "进入开始节点",
      "timestamp": "2025-12-17T10:30:00Z"
    },
    {
      "event_type": "node.completed",
      "node_id": "start",
      "node_type": "start",
      "message": "开始节点完成",
      "duration": 0.001,
      "timestamp": "2025-12-17T10:30:00Z"
    },
    {
      "event_type": "task.created",
      "node_id": "approval",
      "message": "创建审批任务",
      "context": {
        "task_id": "task-001",
        "assignees": [102, 103]
      },
      "timestamp": "2025-12-17T10:30:01Z"
    }
  ]
}
```

### 3. 工作流任务

#### 3.1 获取待办任务

```http
GET /api/v1/workflow/tasks/pending
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "items": [
      {
        "id": "task-001",
        "instance_id": "inst-001",
        "node_id": "approval",
        "task_type": "approval",
        "name": "请假审批 - 张三",
        "status": "pending",
        "created_at": "2025-12-17T10:30:01Z",
        "due_date": "2025-12-19T10:30:01Z"
      }
    ],
    "total": 1
  }
}
```

#### 3.2 审批通过

```http
POST /api/v1/workflow/tasks/{id}/approve
```

**请求体**:

```json
{
  "comment": "同意，请假理由充分",
  "variables": {
    "approved_days": 3
  }
}
```

#### 3.3 审批拒绝

```http
POST /api/v1/workflow/tasks/{id}/reject
```

**请求体**:

```json
{
  "comment": "请假时间与重要会议冲突，建议调整"
}
```

#### 3.4 转办任务

```http
POST /api/v1/workflow/tasks/{id}/transfer
```

**请求体**:

```json
{
  "target_user_id": 102,
  "comment": "请协助审批"
}
```

### 4. 触发器

#### 4.1 获取触发器类型

```http
GET /api/v1/workflow/triggers/types
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "type": "manual",
      "name": "手动触发",
      "description": "用户主动触发工作流"
    },
    {
      "type": "webhook",
      "name": "Webhook 触发",
      "description": "通过 HTTP 请求触发工作流"
    },
    {
      "type": "schedule",
      "name": "定时触发",
      "description": "按 Cron 表达式定时触发"
    },
    {
      "type": "data_event",
      "name": "数据事件触发",
      "description": "数据变更时触发"
    },
    {
      "type": "form_submit",
      "name": "表单提交触发",
      "description": "表单提交时触发"
    },
    {
      "type": "message",
      "name": "消息触发",
      "description": "接收到消息时触发"
    },
    {
      "type": "signal",
      "name": "信号触发",
      "description": "BPMN 2.0 信号事件"
    },
    {
      "type": "timer_event",
      "name": "定时器事件",
      "description": "ISO 8601 定时器"
    },
    {
      "type": "system_event",
      "name": "系统事件触发",
      "description": "系统业务事件触发"
    }
  ]
}
```

#### 4.2 创建 Webhook 触发器

```http
POST /api/v1/workflow/triggers
```

**请求体**:

```json
{
  "name": "外部系统 Webhook",
  "type": "webhook",
  "definition_id": "def-001",
  "config": {
    "secret": "my-secret-key",
    "ip_whitelist": ["192.168.1.0/24"]
  }
}
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "trigger-001",
    "name": "外部系统 Webhook",
    "type": "webhook",
    "enabled": true,
    "webhook_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "webhook_url": "https://api.example.com/api/v1/workflow/triggers/webhook/a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
  }
}
```

#### 4.3 创建定时触发器

```http
POST /api/v1/workflow/triggers
```

**请求体**:

```json
{
  "name": "每日报表生成",
  "type": "schedule",
  "definition_id": "def-002",
  "config": {
    "cron": "0 9 * * *",
    "timezone": "Asia/Shanghai"
  }
}
```

#### 4.4 Webhook 接收

```http
POST /api/v1/workflow/triggers/webhook/{key}
```

**签名验证**:

如果触发器配置了 secret，请求需要包含签名头：

```http
X-Webhook-Signature: <hmac-sha256-signature>
```

签名计算方法：
```
signature = HMAC-SHA256(secret, request_body)
```

**请求体示例**:

```json
{
  "event": "order.created",
  "data": {
    "order_id": "ORD-2025-001",
    "amount": 15000,
    "customer_id": 123
  }
}
```

**响应示例**:

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "triggered": true,
    "instance_id": "inst-002"
  }
}
```

---

## 节点类型

| 类型 | 名称 | 描述 |
|------|------|------|
| `start` | 开始节点 | 流程入口 |
| `end` | 结束节点 | 流程出口 |
| `approval` | 审批节点 | 单人/多人审批 |
| `countersign` | 会签节点 | 多人会签，支持多种策略 |
| `condition` | 条件网关 | 排他/并行/包容网关 |
| `http_request` | HTTP 请求 | 调用外部接口 |
| `delay` | 延时节点 | 固定时长/指定时间/Cron |
| `notify` | 通知节点 | 邮件/短信/站内信等 |
| `script` | 脚本节点 | 执行表达式 |
| `data_operation` | 数据操作 | CRUD/聚合操作 |

---

## 实例状态

| 状态 | 描述 |
|------|------|
| `pending` | 待启动 |
| `running` | 运行中 |
| `suspended` | 已暂停 |
| `completed` | 已完成 |
| `failed` | 失败 |
| `cancelled` | 已取消 |

---

## 任务状态

| 状态 | 描述 |
|------|------|
| `pending` | 待处理 |
| `claimed` | 已认领 |
| `completed` | 已完成 |
| `delegated` | 已委托 |
| `transferred` | 已转办 |

---

## 相关文档

- [OpenAPI 规范](./workflow-api.yaml) - 完整的 OpenAPI 3.0 规范文件
- [错误码说明](./error-codes.md) - 详细的错误码列表和处理建议
- [数据库设计](../database-design.md) - 数据库表结构设计
- [使用示例](../usage-example.md) - API 使用示例
