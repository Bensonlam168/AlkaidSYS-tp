# AlkaidSYS 工作流后端统一引擎详细设计

> **文档版本**：v2.0  
> **创建日期**：2025-01-20  
> **最后更新**：2026-10-30（§7 数据库表设计按 `database/migrations/` 与 `alkaid_sys` 实测结构重写）  
> **作者**：AlkaidSYS 架构团队

> 🔶 **实现状态**：**部分实现** —— 表结构已落地（5 张表的定义在 `database/migrations/`，已在 `alkaid_sys` 库建成，**实测外键 18 条**：指向 `workflow_definitions.id` 2 条、`workflow_instances.id` 2 条、`workflow_tasks.id` 1 条、`users.id` 8 条、`tenants.id` 5 条）；**API 层未落库**（`app/controller/workflow/*` 仍为静态数组内存态，插件内 `ProcessDefineRepository`、`ProcessInstanceRepository`、`ProcessTaskRepository`、`WorkflowEventRepository` 这 4 个仓储为 TODO）。详见 §7「数据库设计」的「设计 vs 实际」对照。详见 §7「数据库设计」的「设计 vs 实际」对照。

> ⚠️ **命名空间声明**：本文示例使用**设计阶段的子层划分**（`Execution/`、`Models/`、`Services/`），与实际实现不同（实现为 `Swoole/Execution/`、`model/`（小写）、`Expression/`、`Core/`）。
> **请勿直接照抄**，逐类映射见 [`../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md`](../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md) §5.3.3。

---

## 📋 目录

- [1. 引擎概述](#1-引擎概述)
- [2. 节点模型体系设计](#2-节点模型体系设计)
- [3. 触发器系统设计](#3-触发器系统设计)
- [4. 执行引擎设计](#4-执行引擎设计)
- [5. 变量系统设计](#5-变量系统设计)
- [6. 表达式引擎设计](#6-表达式引擎设计)
- [7. 数据库设计](#7-数据库设计)
- [8. API 接口设计](#8-api-接口设计)

---

## 1. 引擎概述

### 1.1 插件信息

- **插件名称**：lowcode-workflow-engine
- **插件标识**：`alkaid/lowcode-workflow-engine`
- **版本**：v2.0.0
- **依赖**：
  - `alkaid/framework` >= 1.0.0
  - `alkaid/lowcode-data-modeling` >= 1.0.0
  - `topthink/think-orm` >= 3.0
  - `topthink/think-queue` >= 3.0
  - `symfony/expression-language` >= 6.0

### 1.2 核心功能

1. **统一的节点模型体系**：支持审批节点 + 自动化节点
2. **触发器系统**：支持 10+ 种触发器类型
3. **执行引擎**：基于 Swoole 协程的异步执行
4. **变量系统**：支持上下文变量和表达式计算
5. **表达式引擎**：支持 `{{expression}}` 语法
6. **数据库设计**：流程定义 + 流程实例 + 执行记录

### 1.3 架构设计

```mermaid
graph TB
    subgraph "触发层"
        T1[事件驱动触发器]
        T2[时间驱动触发器]
        T3[外部驱动触发器]
    end
    
    subgraph "执行引擎"
        WE[Workflow Engine<br/>工作流引擎]
        EQ[Execution Queue<br/>执行队列]
        EC[Execution Context<br/>执行上下文]
    end
    
    subgraph "节点模型体系"
        N1[自动化节点]
        N2[人工任务节点]
    end
    
    subgraph "支持系统"
        VS[Variable System<br/>变量系统]
        EE[Expression Engine<br/>表达式引擎]
    end
    
    subgraph "数据层"
        WR[Workflow Repository<br/>工作流仓储]
        ER[Execution Repository<br/>执行记录仓储]
    end
    
    T1 --> WE
    T2 --> WE
    T3 --> WE
    
    WE --> EQ
    WE --> EC
    
    EQ --> N1
    EQ --> N2
    
    EC --> VS
    VS --> EE
    
    WE --> WR
    WE --> ER
    
    style WE fill:#e1f5ff
    style EQ fill:#fff4e1
    style EC fill:#f0f0f0
```

---

## 2. 节点模型体系设计

### 2.1 核心设计理念

**关键洞察**：审批流可以看作是自动化工作流的一个特例，只是增加了"人工任务节点"。

**节点分类**：
1. **自动化节点**（Auto-Execute Nodes）：execute() 方法立即返回结果（SUCCESS 或 FAILED）
2. **人工任务节点**（Human Task Nodes）：execute() 方法返回 PENDING 状态，等待人工完成后再继续

### 2.2 抽象基类设计

**借鉴 Ingenious 的节点模型设计**：

```php
<?php

namespace Alkaid\Workflow\Engine\Models;

use Alkaid\Workflow\Engine\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Execution\NodeExecutionResult;

/**
 * 节点模型抽象基类
 * 
 * 借鉴 Ingenious 的设计理念：
 * 1. 使用模板方法模式
 * 2. 支持前置/后置拦截器
 * 3. 输入边和输出边的设计
 */
abstract class NodeModel
{
    protected string $id;
    protected string $type;
    protected string $name;
    protected array $config = [];
    protected array $inputs = [];  // 输入边
    protected array $outputs = []; // 输出边
    protected ?string $preInterceptors = null;
    protected ?string $postInterceptors = null;
    
    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->type = $data['type'];
        $this->name = $data['name'] ?? '';
        $this->config = $data['config'] ?? [];
    }
    
    /**
     * 执行节点（模板方法模式）
     */
    public function execute(ExecutionContext $context): NodeExecutionResult
    {
        // 执行前置拦截器
        $this->execPreInterceptors($context);
        
        // 执行节点逻辑
        $result = $this->exec($context);
        
        // 执行后置拦截器
        $this->execPostInterceptors($context);
        
        return $result;
    }
    
    /**
     * 子类必须实现的执行逻辑
     */
    abstract protected function exec(ExecutionContext $context): NodeExecutionResult;
    
    /**
     * 验证节点配置
     */
    abstract public function validate(): bool;
    
    /**
     * 执行前置拦截器
     */
    protected function execPreInterceptors(ExecutionContext $context): void
    {
        if ($this->preInterceptors) {
            // 执行前置拦截器逻辑
        }
    }
    
    /**
     * 执行后置拦截器
     */
    protected function execPostInterceptors(ExecutionContext $context): void
    {
        if ($this->postInterceptors) {
            // 执行后置拦截器逻辑
        }
    }
    
    /**
     * 添加输入边
     */
    public function addInput(string $edgeId): void
    {
        $this->inputs[] = $edgeId;
    }
    
    /**
     * 添加输出边
     */
    public function addOutput(string $edgeId): void
    {
        $this->outputs[] = $edgeId;
    }
    
    /**
     * 获取下一个节点ID
     */
    protected function getNextNodeId(): ?string
    {
        return $this->outputs[0] ?? null;
    }
    
    // Getters
    public function getId(): string { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getName(): string { return $this->name; }
    public function getConfig(): array { return $this->config; }
}
```

### 2.3 节点执行结果

```php
<?php

namespace Alkaid\Workflow\Engine\Execution;

/**
 * 节点执行结果
 */
class NodeExecutionResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_FAILED = 'FAILED';
    
    public function __construct(
        public string $status,
        public array $data = [],
        public ?string $nextNodeId = null,
        public ?string $error = null
    ) {}
    
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
    
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
```

### 2.4 自动化节点示例

**HTTP 请求节点**：

```php
<?php

namespace Alkaid\Workflow\Engine\Nodes\Automation;

use Alkaid\Workflow\Engine\Models\NodeModel;
use Alkaid\Workflow\Engine\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Execution\NodeExecutionResult;
use Alkaid\Workflow\Engine\Services\ExpressionEngine;
use Illuminate\Support\Facades\Http;

/**
 * HTTP 请求节点
 * 
 * 借鉴 n8n 的设计理念：
 * 1. 支持多种 HTTP 方法（GET、POST、PUT、DELETE）
 * 2. 支持变量替换（URL、Headers、Body）
 * 3. 支持表达式计算
 */
class HttpRequestNode extends NodeModel
{
    protected function exec(ExecutionContext $context): NodeExecutionResult
    {
        $expressionEngine = app(ExpressionEngine::class);
        
        // 解析 URL（支持变量）
        $url = $expressionEngine->parseTemplate(
            $this->config['url'], // "https://api.example.com/orders/{{trigger.data.id}}"
            $context->getAllVariables()
        );
        
        // 解析请求方法
        $method = $this->config['method'] ?? 'GET';
        
        // 解析请求头（支持变量）
        $headers = [];
        if (isset($this->config['headers'])) {
            foreach ($this->config['headers'] as $key => $value) {
                $headers[$key] = $expressionEngine->parseTemplate($value, $context->getAllVariables());
            }
        }
        
        // 解析请求体（支持变量）
        $body = null;
        if (isset($this->config['body'])) {
            $body = $expressionEngine->parseTemplate(
                $this->config['body'],
                $context->getAllVariables()
            );
            $body = json_decode($body, true);
        }
        
        try {
            // 发送 HTTP 请求
            $response = Http::withHeaders($headers)->$method($url, $body);
            
            return new NodeExecutionResult(
                status: NodeExecutionResult::STATUS_SUCCESS,
                data: [
                    'status_code' => $response->status(),
                    'headers' => $response->headers(),
                    'body' => $response->json()
                ],
                nextNodeId: $this->getNextNodeId()
            );
        } catch (\Exception $e) {
            return new NodeExecutionResult(
                status: NodeExecutionResult::STATUS_FAILED,
                error: $e->getMessage()
            );
        }
    }
    
    public function validate(): bool
    {
        return isset($this->config['url']) && isset($this->config['method']);
    }
}
```

**条件判断节点**：

```php
<?php

namespace Alkaid\Workflow\Engine\Nodes\Automation;

use Alkaid\Workflow\Engine\Models\NodeModel;
use Alkaid\Workflow\Engine\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Execution\NodeExecutionResult;
use Alkaid\Workflow\Engine\Services\ExpressionEngine;

/**
 * 条件判断节点
 */
class ConditionNode extends NodeModel
{
    protected function exec(ExecutionContext $context): NodeExecutionResult
    {
        $expressionEngine = app(ExpressionEngine::class);
        
        // 获取条件表达式
        $expression = $this->config['expression']; // "trigger.data.total > 1000"
        
        // 计算表达式
        $result = $expressionEngine->evaluate($expression, $context->getAllVariables());
        
        // 根据结果选择下一个节点
        $nextNodeId = $result ? $this->config['true_branch'] : $this->config['false_branch'];
        
        return new NodeExecutionResult(
            status: NodeExecutionResult::STATUS_SUCCESS,
            data: ['condition_result' => $result],
            nextNodeId: $nextNodeId
        );
    }
    
    public function validate(): bool
    {
        return isset($this->config['expression']) 
            && isset($this->config['true_branch']) 
            && isset($this->config['false_branch']);
    }
}
```

### 2.5 人工任务节点示例

**人工任务节点**：

```php
<?php

namespace Alkaid\Workflow\Engine\Nodes\Approval;

use Alkaid\Workflow\Engine\Models\NodeModel;
use Alkaid\Workflow\Engine\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Execution\NodeExecutionResult;
use Alkaid\Workflow\Engine\Models\HumanTask;

/**
 * 人工任务节点
 * 
 * 借鉴 Ingenious 的设计理念：
 * 1. 创建待办任务
 * 2. 返回 PENDING 状态
 * 3. 等待人工完成后再继续
 */
class HumanTaskNode extends NodeModel
{
    protected function exec(ExecutionContext $context): NodeExecutionResult
    {
        // 创建待办任务
        $task = $this->createTask($context);
        
        // 返回 PENDING 状态，等待人工完成
        return new NodeExecutionResult(
            status: NodeExecutionResult::STATUS_PENDING,
            data: ['task_id' => $task->id],
            nextNodeId: null // 等待人工完成后再确定下一个节点
        );
    }
    
    /**
     * 创建待办任务
     */
    protected function createTask(ExecutionContext $context): HumanTask
    {
        return HumanTask::create([
            'instance_id' => $context->getInstance()->id,
            'node_id' => $this->id,
            'task_type' => 'approval',
            'title' => $this->config['title'] ?? $this->name,
            'description' => $this->config['description'] ?? '',
            'form_data' => $this->config['form_data'] ?? [],
            'assignee' => $this->config['assignee'],
            'status' => 'pending'
        ]);
    }
    
    /**
     * 人工完成任务后调用
     */
    public function complete(string $taskId, array $result): void
    {
        // 更新任务状态
        $task = HumanTask::find($taskId);
        $task->update([
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now()
        ]);
        
        // 继续执行后续节点
        $context = $this->getExecutionContext($task->instance_id);
        $context->setData($result);
        
        $nextNodeId = $this->getNextNodeId();
        if ($nextNodeId) {
            app(WorkflowEngine::class)->executeNode($nextNodeId, $context);
        }
    }
    
    public function validate(): bool
    {
        return isset($this->config['assignee']);
    }
}
```

---

## 3. 触发器系统设计

### 3.1 触发器接口

**借鉴 n8n 的触发器系统设计**：

```php
<?php

namespace Alkaid\Workflow\Engine\Triggers;

use Alkaid\Workflow\Engine\Models\Workflow;

/**
 * 触发器接口
 */
interface TriggerInterface
{
    /**
     * 获取触发器类型
     */
    public function getType(): string;
    
    /**
     * 注册触发器
     */
    public function register(Workflow $workflow, array $config): void;
    
    /**
     * 取消注册
     */
    public function unregister(Workflow $workflow): void;
    
    /**
     * 检查是否应该触发
     */
    public function shouldTrigger(array $context): bool;
}
```

### 3.2 触发器分类

**1. 事件驱动触发器**（Event-Driven Triggers）：
- FormSubmittedTrigger（表单提交触发器）
- DataCreatedTrigger（数据创建触发器）
- DataUpdatedTrigger（数据更新触发器）
- DataDeletedTrigger（数据删除触发器）
- FieldChangedTrigger（字段变更触发器）
- UserActionTrigger（用户操作触发器）

**2. 时间驱动触发器**（Time-Driven Triggers）：
- ScheduledTrigger（定时触发器）
- CronTrigger（Cron 表达式触发器）

**3. 外部驱动触发器**（External Triggers）：
- WebhookTrigger（Webhook 触发器）
- ManualTrigger（手动触发器）

### 3.3 触发器实现示例

**数据创建触发器**：

```php
<?php

namespace Alkaid\Workflow\Engine\Triggers\EventDriven;

use Alkaid\Workflow\Engine\Triggers\TriggerInterface;
use Alkaid\Workflow\Engine\Models\Workflow;
use Alkaid\Workflow\Engine\Services\WorkflowEngine;
use Illuminate\Support\Facades\Event;

/**
 * 数据创建触发器
 */
class DataCreatedTrigger implements TriggerInterface
{
    public function getType(): string
    {
        return 'data_created';
    }
    
    public function register(Workflow $workflow, array $config): void
    {
        // 注册事件监听器
        Event::listen('data.created', function($event) use ($workflow, $config) {
            // 检查是否满足触发条件
            if ($this->shouldTrigger($event, $config)) {
                // 触发工作流
                app(WorkflowEngine::class)->execute($workflow, [
                    'trigger' => 'data_created',
                    'data' => $event->data,
                    'collection' => $event->collection
                ]);
            }
        });
    }
    
    public function unregister(Workflow $workflow): void
    {
        // 取消事件监听器
        Event::forget('data.created');
    }
    
    public function shouldTrigger(array $context): bool
    {
        // 实现触发条件检查逻辑
        return true;
    }
    
    protected function evaluateConditions(array $data, array $conditions): bool
    {
        foreach ($conditions as $key => $value) {
            if (!isset($data[$key]) || $data[$key] !== $value) {
                return false;
            }
        }
        return true;
    }
}
```

**Webhook 触发器**：

```php
<?php

namespace Alkaid\Workflow\Engine\Triggers\External;

use Alkaid\Workflow\Engine\Triggers\TriggerInterface;
use Alkaid\Workflow\Engine\Models\Workflow;
use Alkaid\Workflow\Engine\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook 触发器
 */
class WebhookTrigger implements TriggerInterface
{
    public function getType(): string
    {
        return 'webhook';
    }
    
    public function register(Workflow $workflow, array $config): void
    {
        // 生成唯一的 Webhook URL
        $webhookUrl = $this->generateWebhookUrl($workflow->id);
        
        // 保存 Webhook 配置
        $this->saveWebhookConfig($workflow->id, $webhookUrl, $config);
    }
    
    public function unregister(Workflow $workflow): void
    {
        // 删除 Webhook 配置
        $this->deleteWebhookConfig($workflow->id);
    }
    
    public function shouldTrigger(array $context): bool
    {
        return true;
    }
    
    /**
     * Webhook 端点
     */
    public function handleWebhook(Request $request, string $workflowId): Response
    {
        // 验证签名
        if (!$this->verifySignature($request)) {
            return json(['error' => 'Invalid signature'], 401);
        }
        
        // 获取工作流
        $workflow = Workflow::find($workflowId);
        
        // 触发工作流
        app(WorkflowEngine::class)->execute($workflow, [
            'trigger' => 'webhook',
            'data' => $request->all(),
            'headers' => $request->headers->all()
        ]);
        
        return json(['success' => true]);
    }
    
    protected function generateWebhookUrl(string $workflowId): string
    {
        // 使用 ThinkPHP URL 生成器生成绝对地址
        return (string) url('workflow/webhook', ['id' => $workflowId, 'token' => $this->generateToken()], false, true);
    }
    
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    protected function verifySignature(Request $request): bool
    {
        // 实现签名验证逻辑
        return true;
    }
}
```

---

## 4. 执行引擎设计

### 4.1 工作流引擎

**基于 Swoole 协程的异步执行**：

```php
<?php

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Models\Workflow;
use Alkaid\Workflow\Engine\Models\WorkflowInstance;
use Alkaid\Workflow\Engine\Models\NodeModel;
use Alkaid\Workflow\Engine\Execution\ExecutionContext;
use Alkaid\Workflow\Engine\Execution\NodeExecutionResult;

/**
 * 工作流引擎
 */
class WorkflowEngine
{
    /**
     * 执行工作流
     */
    public function execute(Workflow $workflow, array $triggerData): WorkflowInstance
    {
        // 1. 创建执行实例
        $instance = $this->createExecutionInstance($workflow, $triggerData);
        
        // 2. 创建执行上下文
        $context = new ExecutionContext($instance, $triggerData);
        
        // 3. 从开始节点开始执行
        $startNode = $workflow->getStartNode();
        
        // 4. 使用 Swoole 协程异步执行
        go(function() use ($startNode, $context) {
            $this->executeNode($startNode, $context);
        });
        
        return $instance;
    }
    
    /**
     * 执行节点
     */
    public function executeNode(NodeModel $node, ExecutionContext $context): void
    {
        // 记录节点开始执行
        $this->logNodeStart($node, $context);
        
        // 执行节点
        $result = $node->execute($context);
        
        // 记录节点执行结果
        $this->logNodeResult($node, $result);
        
        // 根据执行结果决定下一步
        if ($result->isSuccess()) {
            // 保存节点输出数据
            $context->setNodeOutput($node->getId(), $result->data);
            
            // 继续执行下一个节点
            $nextNode = $this->getNextNode($result->nextNodeId);
            if ($nextNode) {
                $this->executeNode($nextNode, $context);
            } else {
                // 流程结束
                $this->completeExecution($context);
            }
        } elseif ($result->isPending()) {
            // 等待人工处理，暂停执行
            $this->pauseExecution($context, $result);
        } elseif ($result->isFailed()) {
            // 执行失败，记录错误
            $this->failExecution($context, $result->error);
        }
    }
    
    protected function createExecutionInstance(Workflow $workflow, array $triggerData): WorkflowInstance
    {
        // 注：下表字段以 database/migrations/ 为准——实例表只有 definition_id / tenant_id /
        // business_key / context / variables 等列，没有 workflow_id / workflow_name /
        // workflow_version / trigger_type / trigger_data（详见 §7.1「设计 vs 实际」）
        return WorkflowInstance::create([
            'definition_id' => $workflow->id,
            'tenant_id' => $triggerData['tenant_id'] ?? 0,
            'business_key' => $triggerData['business_key'] ?? null,
            'title' => $workflow->name . ' - ' . now(),
            'status' => 'running',
            'context' => $triggerData,
            'started_at' => now(),
            'created_by' => $triggerData['user_id'] ?? null
        ]);
    }
    
    protected function completeExecution(ExecutionContext $context): void
    {
        $context->getInstance()->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);
    }
    
    protected function pauseExecution(ExecutionContext $context, NodeExecutionResult $result): void
    {
        $context->getInstance()->update([
            'status' => 'pending',
            'current_node_id' => $result->data['task_id'] ?? null
        ]);
    }
    
    protected function failExecution(ExecutionContext $context, ?string $error): void
    {
        $context->getInstance()->update([
            'status' => 'failed',
            'error_message' => $error,
            'completed_at' => now()
        ]);
    }
}
```

---

## 5. 变量系统设计

### 5.1 执行上下文

```php
<?php

namespace Alkaid\Workflow\Engine\Execution;

use Alkaid\Workflow\Engine\Models\WorkflowInstance;
use Alkaid\Workflow\Engine\Models\Workflow;

/**
 * 执行上下文
 *
 * 管理工作流执行过程中的所有变量
 */
class ExecutionContext
{
    protected array $variables = [];
    protected Workflow $workflow;
    protected WorkflowInstance $instance;

    public function __construct(WorkflowInstance $instance, array $triggerData)
    {
        $this->instance = $instance;
        $this->workflow = $instance->workflow;

        // 初始化变量
        $this->variables = [
            'trigger' => $triggerData,
            'workflow' => [
                'id' => $this->workflow->id,
                'name' => $this->workflow->name,
                'version' => $this->workflow->version
            ],
            'execution' => [
                'id' => $this->instance->id,
                'started_at' => $this->instance->started_at->toIso8601String()
            ],
            'nodes' => []
        ];
    }

    /**
     * 设置变量
     */
    public function setVariable(string $key, mixed $value): void
    {
        data_set($this->variables, $key, $value);
    }

    /**
     * 获取变量
     */
    public function getVariable(string $key): mixed
    {
        return data_get($this->variables, $key);
    }

    /**
     * 设置节点输出数据
     */
    public function setNodeOutput(string $nodeId, array $data): void
    {
        $this->variables['nodes'][$nodeId] = $data;
    }

    /**
     * 获取节点输出数据
     */
    public function getNodeOutput(string $nodeId): ?array
    {
        return $this->variables['nodes'][$nodeId] ?? null;
    }

    /**
     * 获取所有变量
     */
    public function getAllVariables(): array
    {
        return $this->variables;
    }

    /**
     * 获取工作流实例
     */
    public function getInstance(): WorkflowInstance
    {
        return $this->instance;
    }

    /**
     * 获取工作流定义
     */
    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }
}
```

---

## 6. 表达式引擎设计

### 6.1 表达式引擎

**借鉴 n8n 的表达式引擎设计，使用 Symfony Expression Language**：

```php
<?php

namespace Alkaid\Workflow\Engine\Services;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Alkaid\Workflow\Engine\Exceptions\ExpressionEvaluationException;

/**
 * 表达式引擎
 *
 * 支持 {{expression}} 语法
 */
class ExpressionEngine
{
    protected ExpressionLanguage $expressionLanguage;

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();

        // 注册自定义函数
        $this->registerFunctions();
    }

    /**
     * 计算表达式
     */
    public function evaluate(string $expression, array $variables): mixed
    {
        try {
            return $this->expressionLanguage->evaluate($expression, $variables);
        } catch (\Exception $e) {
            throw new ExpressionEvaluationException(
                "Failed to evaluate expression: {$expression}",
                0,
                $e
            );
        }
    }

    /**
     * 解析模板字符串（支持 {{expression}} 语法）
     */
    public function parseTemplate(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) use ($variables) {
            $expression = trim($matches[1]);
            $result = $this->evaluate($expression, $variables);
            return is_scalar($result) ? (string)$result : json_encode($result);
        }, $template);
    }

    /**
     * 注册自定义函数
     */
    protected function registerFunctions(): void
    {
        // 日期函数
        $this->expressionLanguage->register('now', function() {
            return 'date("Y-m-d H:i:s")';
        }, function() {
            return date('Y-m-d H:i:s');
        });

        $this->expressionLanguage->register('today', function() {
            return 'date("Y-m-d")';
        }, function() {
            return date('Y-m-d');
        });

        // 字符串函数
        $this->expressionLanguage->register('upper', function($str) {
            return "strtoupper({$str})";
        }, function($arguments, $str) {
            return strtoupper($str);
        });

        $this->expressionLanguage->register('lower', function($str) {
            return "strtolower({$str})";
        }, function($arguments, $str) {
            return strtolower($str);
        });

        $this->expressionLanguage->register('trim', function($str) {
            return "trim({$str})";
        }, function($arguments, $str) {
            return trim($str);
        });

        // 数组函数
        $this->expressionLanguage->register('length', function($arr) {
            return "count({$arr})";
        }, function($arguments, $arr) {
            return count($arr);
        });

        $this->expressionLanguage->register('first', function($arr) {
            return "reset({$arr})";
        }, function($arguments, $arr) {
            return reset($arr);
        });

        $this->expressionLanguage->register('last', function($arr) {
            return "end({$arr})";
        }, function($arguments, $arr) {
            return end($arr);
        });

        // 数学函数
        $this->expressionLanguage->register('abs', function($num) {
            return "abs({$num})";
        }, function($arguments, $num) {
            return abs($num);
        });

        $this->expressionLanguage->register('round', function($num, $precision = 0) {
            return "round({$num}, {$precision})";
        }, function($arguments, $num, $precision = 0) {
            return round($num, $precision);
        });
    }
}
```

### 6.2 支持的表达式语法

```javascript
// 1. 变量引用
{{trigger.data.id}}
{{trigger.data.total}}
{{nodes.node_001.response.status}}
{{workflow.name}}
{{execution.id}}

// 2. 条件表达式
{{trigger.data.total > 1000}}
{{trigger.data.status == 'pending'}}
{{trigger.data.total > 1000 and trigger.data.status == 'pending'}}
{{trigger.data.total > 1000 or trigger.data.priority == 'high'}}

// 3. 函数调用
{{upper(trigger.data.name)}}
{{lower(trigger.data.email)}}
{{length(trigger.data.items)}}
{{now()}}
{{today()}}

// 4. 三元表达式
{{trigger.data.total > 1000 ? 'high' : 'low'}}
{{trigger.data.status == 'pending' ? '待处理' : '已处理'}}

// 5. 数组访问
{{trigger.data.items[0].name}}
{{trigger.data.items[0].price}}
{{first(trigger.data.items)}}
{{last(trigger.data.items)}}

// 6. 复杂表达式
{{round(trigger.data.total * 0.1, 2)}}
{{upper(trim(trigger.data.name))}}
```

---

## 7. 数据库设计

### 7.1 设计 vs 实际（必读）

> **本文档下面（§7.2）的 SQL 是设计稿；实际表结构以 `database/migrations/` 为准。**
> 建表：`database/migrations/20251216000001_create_workflow_tables.php`；
> 外键：`database/migrations/20251216000002_add_foreign_keys.php`；
> 组合索引/全文索引：`database/migrations/20251216000005_optimize_indexes.php`。
> 核对方式：`information_schema.columns` / `information_schema.statistics` / `information_schema.referential_constraints`（库 `alkaid_sys`，实测日期 2026-10-30）。

#### 7.1.1 表级对照

| 设计文档（§7.2 SQL 的设计稿） | 实际实现（`alkaid_sys` 实测） | 差异性质 |
| --- | --- | --- |
| `workflow_definitions` | `workflow_definitions` | ✅ 表名一致，列有差异 |
| `workflow_instances` | `workflow_instances` | ✅ 表名一致，列有差异（`workflow_id` → `definition_id`） |
| `workflow_node_executions` | ❌ **未实现（当前无此表）** | 设计有、代码无；代码中无任何读写，保留为后续设计 |
| `workflow_human_tasks`（旧名） | `workflow_tasks` | ⚠️ 表名不一致，**以代码为准**；本文档已全部改名为 `workflow_tasks` |
| `workflow_triggers` | `workflow_triggers` | ✅ 表名一致，列有差异 |
| （设计未列） | `workflow_event_logs` | 🆕 代码新增，设计稿漏列；本文档已补建表 SQL（见 §7.2 表 6） |

#### 7.1.2 列级对照

| 设计稿 | 实际实现 | 说明 |
| --- | --- | --- |
| `workflow_definitions.title` | `workflow_definitions.display_name` | 改名 |
| `workflow_definitions.trigger` / `nodes` / `edges` / `variables` | `triggers_config` / `nodes_config` / `bpmn_xml` / `variables_schema` | 单列拆分 + 增加 BPMN XML |
| `workflow_definitions.is_published` | ❌ 不存在 | 状态由 `status`（draft/published/archived）表达 |
| `workflow_definitions.deleted_at` | ❌ 不存在 | 当前未做软删除 |
| `workflow_instances.workflow_id` | `workflow_instances.definition_id` | **改名**，外键指向 `workflow_definitions.id` |
| `workflow_instances.workflow_name` | ❌ 不存在 | 冗余列未落地；需要名称/版本时 JOIN `workflow_definitions` |
| `workflow_instances.workflow_version` | ❌ 不存在 | 同上 |
| `workflow_instances.trigger_type` / `trigger_data` | ❌ 不存在 | 改由 `business_key` / `context` / `variables` 承载 |
| `workflow_instances.current_node_id`（`varchar(100)`） | `current_node_id`（`varchar(50)`）+ 新增 `current_node_name` | 长度收窄并补名称冗余 |
| `workflow_human_tasks.node_execution_id` | ❌ 不存在 | 依赖未实现的 `workflow_node_executions` |
| `workflow_human_tasks.instance_id`（`bigint unsigned`） | `workflow_tasks.instance_id`（`int unsigned`） | 存放实例主键值；类型已统一为 `int unsigned`，见 §7.1.3 |
| `workflow_triggers.workflow_id` | `workflow_triggers.definition_id` | 同实例表改名逻辑 |
| `workflow_triggers.trigger_type` / `trigger_config` | `workflow_triggers.type` / `config` | 改名 |
| `workflow_triggers.webhook_url` / `webhook_token` | ❌ 不存在 | Webhook 参数统一放在 `config` JSON 内 |
| （设计稿 5 张表全部漏列） | 每表都有 `tenant_id int unsigned NOT NULL` + `fk_{表}_tenant_id → tenants.id` | 多租户隔离必备列；类型已对齐 `tenants.id` |

#### 7.1.3 外键与列类型约定

- **实测外键共 18 条**（全部 `ON UPDATE CASCADE`），完整清单：

| 约束名 | 子表.列 | 被引用 | ON DELETE |
|---|---|---|---|
| `fk_workflow_instances_definition_id` | `workflow_instances.definition_id` | `workflow_definitions.id` | CASCADE |
| `fk_workflow_triggers_definition_id` | `workflow_triggers.definition_id` | `workflow_definitions.id` | CASCADE |
| `fk_workflow_tasks_instance_id` | `workflow_tasks.instance_id` | `workflow_instances.id` | CASCADE |
| `fk_workflow_event_logs_instance_id` | `workflow_event_logs.instance_id` | `workflow_instances.id` | CASCADE |
| `fk_workflow_event_logs_task_id` | `workflow_event_logs.task_id` | `workflow_tasks.id` | SET NULL |
| `fk_workflow_definitions_created_by` | `workflow_definitions.created_by` | `users.id` | SET NULL |
| `fk_workflow_definitions_updated_by` | `workflow_definitions.updated_by` | `users.id` | SET NULL |
| `fk_workflow_instances_created_by` | `workflow_instances.created_by` | `users.id` | SET NULL |
| `fk_workflow_instances_assignee` | `workflow_instances.assignee` | `users.id` | SET NULL |
| `fk_workflow_tasks_assignee` | `workflow_tasks.assignee` | `users.id` | SET NULL |
| `fk_workflow_tasks_created_by` | `workflow_tasks.created_by` | `users.id` | SET NULL |
| `fk_workflow_triggers_created_by` | `workflow_triggers.created_by` | `users.id` | SET NULL |
| `fk_workflow_event_logs_user_id` | `workflow_event_logs.user_id` | `users.id` | SET NULL |
| `fk_workflow_definitions_tenant_id` | `workflow_definitions.tenant_id` | `tenants.id` | CASCADE |
| `fk_workflow_instances_tenant_id` | `workflow_instances.tenant_id` | `tenants.id` | CASCADE |
| `fk_workflow_tasks_tenant_id` | `workflow_tasks.tenant_id` | `tenants.id` | CASCADE |
| `fk_workflow_triggers_tenant_id` | `workflow_triggers.tenant_id` | `tenants.id` | CASCADE |
| `fk_workflow_event_logs_tenant_id` | `workflow_event_logs.tenant_id` | `tenants.id` | CASCADE |

> ⚠️ **注意**：§7.2 的建表 SQL 只在 `workflow_instances`、`workflow_triggers` 两块内联了
> `CONSTRAINT`，其余 16 条是在后续迁移里补加的（外键列类型先对齐才能建约束）。
> **照抄 SQL 建库会缺少外键**，请以本表为准，或直接使用 `database/migrations/`。

- 本小节 DDL 中所有外键列（`created_by`、`updated_by`、`assignee`、`user_id`、`instance_id`、`task_id`、`tenant_id`）
  均为 **`int unsigned`**，与 `users.id`、`tenants.id` 及各表 `id` 主键严格一致。
- 租户外键沿用项目既有惯例（`fk_{表}_tenant_id` + `CASCADE/CASCADE`），与
  `depts`/`menus`/`roles`/`sites`/`users` 的 5 条一致；全库指向 `tenants` 的外键共 10 条。
- 历史沿革（供排查旧环境）：`20251216000002_add_foreign_keys.php` 最初声明 10 条外键，
  只有 2 条能建成 —— 其余 8 条因当时列类型是 `bigint`（用户引用列）与 `varchar(50)`
  （`instance_id`/`task_id`）而被 MySQL 拒绝。类型对齐分两次迁移完成：
  `20251222000001`（11 个外键列）与 `20251223000001`（5 个 `tenant_id`）。
  在此之前，18 条外键中只有 2 条存在。
- ⚠️ **相邻域的两张表**（不属工作流域，但同为 `tenant_id`，易被一起误判）：
  - `alkaid_plugin_installations.tenant_id`：`bigint unsigned` + `DEFAULT 0`，注释写明「0 = 系统模板」，
    `PluginManager::enable/disable/uninstall` 的 `$tenantId` 默认值也是 0 ——
    **属「系统模板空间」，本就不该有租户外键**；全库「有默认值的 213 张表零外键」是零例外的实测结论。
  - `lowcode_forms.tenant_id`：`bigint unsigned NOT NULL` 无默认值，按「无默认值 = 真实租户引用」
    规则本应带外键，但低代码 CLI 默认写 0，归属待决。详见
    [`04-multi-tenant-design.md` §0](../../design/01-architecture-design/04-multi-tenant-design.md) 的两条语义。

### 7.2 核心表设计（SQL 设计稿）

**借鉴 Ingenious 的数据库设计理念**（列名/类型/索引已按 `alkaid_sys` 实测结构校正）：

**1. workflow_definitions（工作流定义表）✅ 已实现**：

```sql
CREATE TABLE `workflow_definitions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `name` varchar(100) NOT NULL COMMENT '工作流名称（租户内唯一）',
  `display_name` varchar(200) NOT NULL COMMENT '显示名称',
  `description` text COMMENT '描述',
  `type` varchar(20) NOT NULL DEFAULT 'approval' COMMENT '类型：approval/automation/hybrid',
  `category` varchar(50) COMMENT '分类',
  `icon` varchar(100) COMMENT '图标',
  `version` int NOT NULL DEFAULT 1 COMMENT '版本号',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT '状态：draft/published/archived',
  `bpmn_xml` text COMMENT 'BPMN 2.0 XML 定义',
  `nodes_config` json COMMENT '节点配置 JSON',
  `triggers_config` json COMMENT '触发器配置 JSON',
  `variables_schema` json COMMENT '变量 Schema 定义',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
  `created_at` datetime COMMENT '创建时间',
  `updated_at` datetime COMMENT '更新时间',
  `created_by` int unsigned COMMENT '创建用户ID',
  `updated_by` int unsigned COMMENT '更新用户ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name_version` (`tenant_id`, `name`, `version`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`),
  KEY `idx_tenant_status_active` (`tenant_id`, `status`, `is_active`),
  KEY `idx_tenant_type_category` (`tenant_id`, `type`, `category`),
  KEY `idx_tenant_updated` (`tenant_id`, `updated_at`),
  FULLTEXT KEY `idx_search` (`name`, `display_name`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流定义表';
```

**2. workflow_instances（工作流实例表）✅ 已实现**：

```sql
CREATE TABLE `workflow_instances` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `definition_id` int unsigned NOT NULL COMMENT '工作流定义ID（FK → workflow_definitions.id）',
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `business_key` varchar(100) COMMENT '业务键（幂等/关联业务单据）',
  `title` varchar(200) NOT NULL COMMENT '实例标题',
  `status` varchar(20) NOT NULL COMMENT '状态：pending/running/completed/failed/cancelled/suspended',
  `priority` int NOT NULL DEFAULT 5 COMMENT '优先级（1-10）',
  `current_node_id` varchar(50) COMMENT '当前节点ID',
  `current_node_name` varchar(200) COMMENT '当前节点名称',
  `context` json COMMENT '执行上下文数据',
  `variables` json COMMENT '执行变量',
  `started_at` datetime COMMENT '开始时间',
  `completed_at` datetime COMMENT '完成时间',
  `suspended_at` datetime COMMENT '暂停时间',
  `duration` bigint COMMENT '执行时长（毫秒）',
  `error_message` text COMMENT '错误信息',
  `error_stack` text COMMENT '错误堆栈',
  `created_at` datetime COMMENT '创建时间',
  `updated_at` datetime COMMENT '更新时间',
  `created_by` int unsigned COMMENT '发起用户ID',
  `assignee` int unsigned COMMENT '当前处理人ID',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_definition` (`definition_id`),
  KEY `idx_status` (`status`),
  KEY `idx_business_key` (`business_key`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_assignee` (`assignee`),
  KEY `idx_tenant_status_time` (`tenant_id`, `status`, `started_at`),
  KEY `idx_tenant_status_user` (`tenant_id`, `status`, `created_by`),
  KEY `idx_tenant_status_assignee` (`tenant_id`, `status`, `assignee`),
  KEY `idx_tenant_business_key` (`tenant_id`, `business_key`),
  KEY `idx_tenant_def_status` (`tenant_id`, `definition_id`, `status`),
  KEY `idx_status_time` (`status`, `started_at`),
  KEY `idx_tenant_completed` (`tenant_id`, `completed_at`),
  KEY `idx_tenant_status_time_range` (`tenant_id`, `status`, `started_at`, `completed_at`),
  CONSTRAINT `fk_workflow_instances_definition_id`
    FOREIGN KEY (`definition_id`) REFERENCES `workflow_definitions` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流实例表';
```

**3. workflow_node_executions（节点执行记录表）❌ 未实现（当前无此表）**：

> ⚠️ 该表**在代码与数据库中都不存在**（`database/migrations/` 未创建，代码中无任何读写）。
> 下面的 SQL 仅作为**后续设计参考**，请勿据此编码；当前节点执行信息通过 `workflow_event_logs`
> 事件流与 `workflow_tasks` 人工任务间接体现。

```sql
CREATE TABLE `workflow_node_executions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` int unsigned NOT NULL COMMENT '工作流实例ID',
  `node_id` varchar(100) NOT NULL COMMENT '节点ID',
  `node_type` varchar(50) NOT NULL COMMENT '节点类型',
  `node_name` varchar(200) COMMENT '节点名称',
  `status` varchar(50) NOT NULL COMMENT '状态：success/pending/failed',
  `input_data` json COMMENT '输入数据',
  `output_data` json COMMENT '输出数据',
  `error_message` text COMMENT '错误信息',
  `started_at` timestamp NULL COMMENT '开始时间',
  `completed_at` timestamp NULL COMMENT '完成时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance_id` (`instance_id`),
  KEY `idx_node_id` (`node_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='节点执行记录表（未实现）';
```

**4. workflow_tasks（工作流任务表，设计稿旧名 `workflow_human_tasks`）✅ 已实现**：

> 表名以代码为准：设计稿的 `workflow_human_tasks` 未落地，实际表为 `workflow_tasks`；
> 列也随之变化（无 `node_execution_id`，新增 `task_sub_type` / `assignee_name` /
> `candidate_users` / `candidate_groups` / `comment` / `due_date` / `reminder_times` / `rejected_at` / `cancelled_at` 等）。

```sql
CREATE TABLE `workflow_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `instance_id` int unsigned NOT NULL COMMENT '工作流实例ID',
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `node_id` varchar(50) NOT NULL COMMENT '节点ID',
  `node_name` varchar(200) NOT NULL COMMENT '节点名称',
  `task_type` varchar(20) NOT NULL COMMENT '任务类型：user_task/system_task/service_task',
  `task_sub_type` varchar(20) COMMENT '任务子类型：approval/countersign/notify',
  `title` varchar(200) NOT NULL COMMENT '任务标题',
  `description` text COMMENT '任务描述',
  `status` varchar(20) NOT NULL COMMENT '状态：pending/claimed/completed/rejected/cancelled/escalated',
  `priority` int NOT NULL DEFAULT 5 COMMENT '优先级（1-10）',
  `assignee` int unsigned COMMENT '处理人ID',
  `assignee_name` varchar(100) COMMENT '处理人姓名',
  `candidate_users` json COMMENT '候选用户列表',
  `candidate_groups` json COMMENT '候选组列表',
  `form_data` json COMMENT '表单数据',
  `result` json COMMENT '处理结果',
  `comment` text COMMENT '审批意见',
  `due_date` datetime COMMENT '截止时间',
  `reminder_times` json COMMENT '提醒时间列表',
  `completed_at` datetime COMMENT '完成时间',
  `rejected_at` datetime COMMENT '拒绝时间',
  `cancelled_at` datetime COMMENT '取消时间',
  `created_at` datetime COMMENT '创建时间',
  `updated_at` datetime COMMENT '更新时间',
  `created_by` int unsigned COMMENT '创建人ID',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assignee` (`assignee`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_node` (`node_id`),
  KEY `idx_tenant_status_assignee` (`tenant_id`, `status`, `assignee`),
  KEY `idx_tenant_status_due` (`tenant_id`, `status`, `due_date`),
  KEY `idx_tenant_assignee_status` (`tenant_id`, `assignee`, `status`),
  KEY `idx_todo_list` (`assignee`, `status`, `priority`, `due_date`),
  KEY `idx_tenant_instance_node` (`tenant_id`, `instance_id`, `node_id`),
  KEY `idx_tenant_type_status` (`tenant_id`, `task_type`, `status`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_tenant_completed` (`tenant_id`, `completed_at`),
  KEY `idx_tenant_status_date_range` (`tenant_id`, `status`, `created_at`, `due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流任务表';
```

**5. workflow_triggers（触发器注册表）✅ 已实现**：

```sql
CREATE TABLE `workflow_triggers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `definition_id` int unsigned NOT NULL COMMENT '工作流定义ID（FK → workflow_definitions.id）',
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `name` varchar(100) NOT NULL COMMENT '触发器名称（租户内唯一）',
  `type` varchar(20) NOT NULL COMMENT '触发器类型：manual/webhook/schedule/data_event/message_event',
  `config` json NOT NULL COMMENT '触发器配置 JSON（webhook 的 url/token 也放这里）',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
  `created_at` datetime COMMENT '创建时间',
  `updated_at` datetime COMMENT '更新时间',
  `created_by` int unsigned COMMENT '创建人ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`, `name`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_definition` (`definition_id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_def_type` (`definition_id`, `type`),
  KEY `idx_tenant_def_active` (`tenant_id`, `definition_id`, `is_active`),
  KEY `idx_tenant_type_active` (`tenant_id`, `type`, `is_active`),
  KEY `idx_tenant_updated` (`tenant_id`, `updated_at`),
  CONSTRAINT `fk_workflow_triggers_definition_id`
    FOREIGN KEY (`definition_id`) REFERENCES `workflow_definitions` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流触发器表';
```

**6. workflow_event_logs（工作流事件日志表）🆕 代码新增（设计稿漏列）**：

> 设计稿没有这张表，迁移已创建（`create_workflow_tables.php` 的 `createWorkflowEventLogs()`），
> 用于按实例/任务维度记录事件流水：`event_type` 区分事件类别，`event_data` 存事件载荷。
> 注意：业务代码目前尚未写入该表（仓储仍为 TODO，仅迁移种子数据有插入）；
> 而 `workflow_node_executions` 不存在，因此**没有节点级「输入/输出/耗时」明细表**，需要时再按 §7.2 表 3 落地。

```sql
CREATE TABLE `workflow_event_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `instance_id` int unsigned COMMENT '工作流实例ID',
  `task_id` int unsigned COMMENT '任务ID',
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `event_type` varchar(50) NOT NULL COMMENT '事件类型',
  `event_name` varchar(100) NOT NULL COMMENT '事件名称',
  `event_data` json COMMENT '事件数据',
  `user_id` int unsigned COMMENT '操作用户ID',
  `user_name` varchar(100) COMMENT '操作用户姓名',
  `ip_address` varchar(45) COMMENT 'IP地址',
  `user_agent` text COMMENT '用户代理',
  `created_at` datetime COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_tenant_instance_time` (`tenant_id`, `instance_id`, `created_at`),
  KEY `idx_tenant_task_time` (`tenant_id`, `task_id`, `created_at`),
  KEY `idx_tenant_type_time` (`tenant_id`, `event_type`, `created_at`),
  KEY `idx_tenant_user_time` (`tenant_id`, `user_id`, `created_at`),
  KEY `idx_type_time` (`event_type`, `created_at`),
  KEY `idx_ip_time` (`ip_address`, `created_at`),
  KEY `idx_multi_condition` (`tenant_id`, `event_type`, `user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流事件日志表';
```

---

## 8. API 接口设计

### 8.1 工作流管理 API

```php
<?php

namespace Alkaid\Workflow\Engine\Controllers;

use Alkaid\Framework\Http\Controller;
use Alkaid\Workflow\Engine\Models\Workflow;
use Alkaid\Workflow\Engine\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * 工作流管理控制器
 */
class WorkflowController extends Controller
{
    /**
     * 创建工作流
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'type' => 'required|in:approval,automation,hybrid',
            'trigger' => 'required|array',
            'nodes' => 'required|array',
            'edges' => 'required|array',
            'variables' => 'nullable|array'
        ]);

        $workflow = Workflow::create($validated);

        return json([
            'success' => true,
            'data' => $workflow
        ]);
    }

    /**
     * 更新工作流
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:100',
            'title' => 'string|max:200',
            'description' => 'nullable|string',
            'type' => 'in:approval,automation,hybrid',
            'trigger' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
            'variables' => 'nullable|array'
        ]);

        $workflow->update($validated);

        return json([
            'success' => true,
            'data' => $workflow
        ]);
    }

    /**
     * 删除工作流
     */
    public function delete(string $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $workflow->delete();

        return json([
            'success' => true
        ]);
    }

    /**
     * 执行工作流
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);

        $triggerData = $request->all();

        $instance = app(WorkflowEngine::class)->execute($workflow, $triggerData);

        return json([
            'success' => true,
            'data' => $instance
        ]);
    }

    /**
     * 查询执行记录
     */
    public function getExecutions(string $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);

        $instances = $workflow->instances()
            ->orderBy('started_at', 'desc')
            ->paginate(20);

        return json([
            'success' => true,
            'data' => $instances
        ]);
    }
}
```

---

**最后更新**：2026-10-30
**文档版本**：v2.0
**维护者**：AlkaidSYS 架构团队

**变更记录**：
- 2026-10-30：§7 数据库表设计整体对齐实际代码——`workflow_human_tasks` → `workflow_tasks`、`workflow_node_executions` 标注「❌ 未实现」、补 `workflow_event_logs` 建表 SQL、`workflow_id` → `definition_id` 并删除 `workflow_name`/`workflow_version` 等未落地列；新增 §7.1「设计 vs 实际」对照表（依据 `alkaid_sys` 的 information_schema 实测）。
