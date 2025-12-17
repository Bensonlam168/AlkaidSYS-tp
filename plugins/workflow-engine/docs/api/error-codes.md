# AlkaidSYS 工作流引擎错误码说明

> **版本**: 1.0.0
> **最后更新**: 2025-12-17

## 错误码格式

工作流引擎使用统一的错误码格式：

```json
{
  "code": 40001,
  "message": "错误描述",
  "errors": [
    {
      "field": "字段名",
      "rule": "规则名",
      "message": "详细错误信息"
    }
  ]
}
```

## 错误码分类

| 范围 | 分类 | 描述 |
|------|------|------|
| 0 | 成功 | 请求成功 |
| 400xx | 请求错误 | 客户端请求错误 |
| 401xx | 认证错误 | 身份验证失败 |
| 403xx | 权限错误 | 权限不足 |
| 404xx | 资源错误 | 资源不存在 |
| 409xx | 冲突错误 | 资源状态冲突 |
| 422xx | 验证错误 | 数据验证失败 |
| 500xx | 系统错误 | 服务器内部错误 |

---

## 通用错误码

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 0 | success | 请求成功 | - |
| 400 | Bad Request | 请求格式错误 | 检查请求参数格式 |
| 401 | Unauthorized | 未认证 | 提供有效的 JWT Token |
| 403 | Forbidden | 无权限 | 确认是否有对应的权限 |
| 404 | Not Found | 资源不存在 | 确认资源 ID 是否正确 |
| 422 | Validation Failed | 验证失败 | 根据 errors 字段修正参数 |
| 500 | Internal Server Error | 服务器错误 | 联系管理员 |

---

## 工作流定义错误码 (40100-40199)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40100 | DEFINITION_NOT_FOUND | 工作流定义不存在 | 确认定义 ID 是否正确 |
| 40101 | DEFINITION_KEY_EXISTS | 定义 key 已存在 | 使用唯一的 key |
| 40102 | DEFINITION_NOT_DRAFT | 定义非草稿状态，无法修改 | 创建新版本进行修改 |
| 40103 | DEFINITION_NOT_PUBLISHED | 定义未发布，无法启动实例 | 先发布定义 |
| 40104 | DEFINITION_HAS_RUNNING_INSTANCES | 定义存在运行中实例，无法删除 | 等待实例完成或取消后删除 |
| 40105 | DEFINITION_ARCHIVED | 定义已归档，无法操作 | 克隆创建新定义 |
| 40106 | DEFINITION_VALIDATION_FAILED | 定义验证失败 | 根据验证错误修正配置 |
| 40107 | DEFINITION_INVALID_NODE_CONFIG | 节点配置无效 | 检查节点配置格式 |
| 40108 | DEFINITION_INVALID_TRIGGER_CONFIG | 触发器配置无效 | 检查触发器配置格式 |
| 40109 | DEFINITION_MISSING_START_NODE | 缺少开始节点 | 添加开始节点 |
| 40110 | DEFINITION_MISSING_END_NODE | 缺少结束节点 | 添加结束节点 |
| 40111 | DEFINITION_UNREACHABLE_NODE | 存在不可达节点 | 检查节点连接 |
| 40112 | DEFINITION_CYCLE_DETECTED | 检测到循环引用 | 修正节点连接，避免循环 |

---

## 工作流实例错误码 (40200-40299)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40200 | INSTANCE_NOT_FOUND | 工作流实例不存在 | 确认实例 ID 是否正确 |
| 40201 | INSTANCE_ALREADY_COMPLETED | 实例已完成 | 无法对已完成实例操作 |
| 40202 | INSTANCE_ALREADY_CANCELLED | 实例已取消 | 无法对已取消实例操作 |
| 40203 | INSTANCE_ALREADY_SUSPENDED | 实例已暂停 | 先恢复实例 |
| 40204 | INSTANCE_NOT_SUSPENDED | 实例未暂停，无法恢复 | 实例状态非 suspended |
| 40205 | INSTANCE_CANNOT_CANCEL | 实例无法取消 | 检查实例当前状态 |
| 40206 | INSTANCE_EXECUTION_FAILED | 实例执行失败 | 查看执行历史获取详情 |
| 40207 | INSTANCE_VARIABLE_NOT_FOUND | 实例变量不存在 | 确认变量名称 |
| 40208 | INSTANCE_VARIABLE_TYPE_MISMATCH | 变量类型不匹配 | 检查变量值类型 |
| 40209 | INSTANCE_NODE_NOT_FOUND | 实例节点不存在 | 确认节点 ID |
| 40210 | INSTANCE_TIMEOUT | 实例执行超时 | 检查节点配置和执行逻辑 |

---

## 工作流任务错误码 (40300-40399)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40300 | TASK_NOT_FOUND | 任务不存在 | 确认任务 ID 是否正确 |
| 40301 | TASK_ALREADY_COMPLETED | 任务已完成 | 无法对已完成任务操作 |
| 40302 | TASK_ALREADY_CLAIMED | 任务已被认领 | 任务已分配给其他用户 |
| 40303 | TASK_NOT_CLAIMED | 任务未认领 | 需要先认领任务 |
| 40304 | TASK_NOT_ASSIGNEE | 非任务处理人 | 确认是否是任务的处理人或候选人 |
| 40305 | TASK_CANNOT_APPROVE | 任务无法审批 | 检查任务类型和状态 |
| 40306 | TASK_CANNOT_REJECT | 任务无法拒绝 | 检查任务类型和状态 |
| 40307 | TASK_CANNOT_TRANSFER | 任务无法转办 | 检查任务状态 |
| 40308 | TASK_CANNOT_DELEGATE | 任务无法委托 | 检查任务状态 |
| 40309 | TASK_TRANSFER_TO_SELF | 不能转办给自己 | 选择其他用户 |
| 40310 | TASK_DELEGATE_TO_SELF | 不能委托给自己 | 选择其他用户 |
| 40311 | TASK_TARGET_USER_NOT_FOUND | 目标用户不存在 | 确认用户 ID |
| 40312 | TASK_FORM_VALIDATION_FAILED | 表单验证失败 | 根据验证错误修正表单数据 |
| 40313 | TASK_COMMENT_REQUIRED | 需要填写意见 | 拒绝操作必须填写意见 |
| 40314 | TASK_TIMEOUT | 任务已超时 | 任务超过截止时间 |

---

## 触发器错误码 (40400-40499)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40400 | TRIGGER_NOT_FOUND | 触发器不存在 | 确认触发器 ID 是否正确 |
| 40401 | TRIGGER_DISABLED | 触发器已禁用 | 启用触发器后重试 |
| 40402 | TRIGGER_TYPE_INVALID | 触发器类型无效 | 使用有效的触发器类型 |
| 40403 | TRIGGER_CONFIG_INVALID | 触发器配置无效 | 检查配置格式 |
| 40404 | TRIGGER_CRON_INVALID | Cron 表达式无效 | 检查 Cron 表达式格式 |
| 40405 | TRIGGER_WEBHOOK_KEY_INVALID | Webhook 密钥无效 | 使用正确的 Webhook 密钥 |
| 40406 | TRIGGER_WEBHOOK_SIGNATURE_INVALID | Webhook 签名验证失败 | 检查签名计算方法 |
| 40407 | TRIGGER_WEBHOOK_IP_BLOCKED | IP 不在白名单内 | 将 IP 添加到白名单 |
| 40408 | TRIGGER_CONDITION_FAILED | 触发条件不满足 | 数据不满足触发条件 |
| 40409 | TRIGGER_RATE_LIMITED | 触发频率超限 | 稍后重试 |
| 40410 | TRIGGER_DEFINITION_NOT_PUBLISHED | 关联定义未发布 | 先发布工作流定义 |

---

## 节点执行错误码 (40500-40599)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40500 | NODE_EXECUTION_FAILED | 节点执行失败 | 查看节点执行日志 |
| 40501 | NODE_CONFIG_INVALID | 节点配置无效 | 检查节点配置 |
| 40502 | NODE_ASSIGNEE_NOT_FOUND | 未找到审批人 | 配置有效的审批人 |
| 40503 | NODE_CONDITION_EVALUATION_FAILED | 条件评估失败 | 检查条件表达式 |
| 40504 | NODE_HTTP_REQUEST_FAILED | HTTP 请求失败 | 检查目标地址和网络 |
| 40505 | NODE_HTTP_TIMEOUT | HTTP 请求超时 | 增加超时时间或检查目标服务 |
| 40506 | NODE_SCRIPT_ERROR | 脚本执行错误 | 检查脚本语法 |
| 40507 | NODE_DATA_OPERATION_FAILED | 数据操作失败 | 检查数据操作配置 |
| 40508 | NODE_NOTIFY_FAILED | 通知发送失败 | 检查通知配置和接收人 |
| 40509 | NODE_DELAY_CONFIG_INVALID | 延时配置无效 | 检查延时时间配置 |
| 40510 | NODE_COUNTERSIGN_INCOMPLETE | 会签未完成 | 等待所有会签人处理 |
| 40511 | NODE_EXPRESSION_ERROR | 表达式错误 | 检查表达式语法 |

---

## 多租户错误码 (40600-40699)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 40600 | TENANT_NOT_FOUND | 租户不存在 | 确认租户 ID |
| 40601 | TENANT_ACCESS_DENIED | 无权访问该租户数据 | 检查租户权限 |
| 40602 | TENANT_QUOTA_EXCEEDED | 租户配额超限 | 联系管理员增加配额 |
| 40603 | TENANT_SUSPENDED | 租户已暂停 | 联系管理员 |

---

## 系统错误码 (50000-50099)

| 错误码 | 消息 | 描述 | 处理建议 |
|--------|------|------|----------|
| 50000 | INTERNAL_SERVER_ERROR | 服务器内部错误 | 联系管理员 |
| 50001 | DATABASE_ERROR | 数据库错误 | 联系管理员 |
| 50002 | CACHE_ERROR | 缓存错误 | 联系管理员 |
| 50003 | QUEUE_ERROR | 队列错误 | 联系管理员 |
| 50004 | SERVICE_UNAVAILABLE | 服务不可用 | 稍后重试 |
| 50005 | EXTERNAL_SERVICE_ERROR | 外部服务错误 | 检查外部服务状态 |
| 50006 | EXECUTION_ENGINE_ERROR | 执行引擎错误 | 联系管理员 |
| 50007 | EXPRESSION_ENGINE_ERROR | 表达式引擎错误 | 检查表达式配置 |

---

## 错误处理最佳实践

### 1. 客户端错误处理

```javascript
async function handleWorkflowAPI(response) {
  const data = await response.json();

  if (data.code === 0) {
    return data.data;
  }

  switch (data.code) {
    case 401:
      // 重新登录
      redirectToLogin();
      break;
    case 403:
      // 显示权限不足提示
      showPermissionDenied();
      break;
    case 40106:
      // 定义验证失败，显示具体错误
      showValidationErrors(data.errors);
      break;
    case 40301:
      // 任务已完成，刷新列表
      refreshTaskList();
      break;
    default:
      // 显示通用错误
      showError(data.message);
  }
}
```

### 2. 重试策略

对于临时性错误（如 50004 服务不可用），建议使用指数退避重试：

```javascript
async function retryWithBackoff(fn, maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      return await fn();
    } catch (error) {
      if (error.code === 50004 && i < maxRetries - 1) {
        // 等待 2^i 秒后重试
        await sleep(Math.pow(2, i) * 1000);
        continue;
      }
      throw error;
    }
  }
}
```

### 3. 错误日志记录

```javascript
function logWorkflowError(error, context) {
  console.error('[Workflow Error]', {
    code: error.code,
    message: error.message,
    errors: error.errors,
    context: context,
    timestamp: new Date().toISOString()
  });
}
```

---

## 相关文档

- [API 文档](./README.md) - 完整的 API 接口文档
- [OpenAPI 规范](./workflow-api.yaml) - OpenAPI 3.0 规范文件
