# 工作流引擎使用示例

## 快速开始

### 1. 基本初始化

```php
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;

// 初始化工作流引擎
$adapter = WorkflowBootstrap::initialize();

// 获取工作流引擎实例
$engine = WorkflowBootstrap::getEngine();
```

### 2. 在ThinkPHP中使用

在 `app/middleware/Auth.php` 或应用启动文件中添加：

```php
// 初始化工作流引擎
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;

try {
    WorkflowBootstrap::initialize([
        'debug' => env('APP_DEBUG', false),
        'tenant_id' => session('tenant_id', 1),
    ]);
} catch (\Exception $e) {
    Log::error('工作流引擎初始化失败: ' . $e->getMessage());
}
```

### 3. 启动工作流实例

```php
// 获取工作流定义服务
$defineService = WorkflowBootstrap::getDefineService();

// 根据定义ID启动工作流
$instance = WorkflowBootstrap::startWorkflow(
    'workflow_001', // 工作流定义ID
    [
        'applicant_name' => '张三',
        'amount' => 10000,
        'description' => '申请费用报销',
    ],
    123 // 操作人ID
);

echo "工作流实例ID: " . $instance->getData('id');
```

### 4. 执行工作流任务

```php
// 执行任务
$result = WorkflowBootstrap::executeTask(
    'task_001', // 任务ID
    [
        'approved' => true,
        'comment' => '同意申请',
    ],
    456 // 操作人ID
);

print_r($result);
```

## 高级用法

### 自定义服务配置

```php
use Alkaid\Workflow\Engine\Container\ServiceFactory;

// 创建自定义容器
$container = ServiceFactory::createContainer([
    'service_dependencies' => [
        'processDefineService' => \app\service\CustomWorkflowDefineService::class,
        'processInstanceService' => \app\service\CustomWorkflowInstanceService::class,
    ],
    'extension_parameters' => [
        'tenant_id' => 1,
        'custom_config' => 'value',
    ],
]);
```

### 事务处理

```php
use Alkaid\Workflow\Engine\Integration\ThinkPHPAdapter;

$adapter = WorkflowBootstrap::getAdapter();

// 在事务中执行工作流操作
$result = $adapter->executeInTransaction(function ($adapter) {
    // 启动工作流
    $instance = WorkflowBootstrap::startWorkflow('workflow_001', $params, $userId);

    // 执行其他业务操作
    // ...

    return $instance;
});
```

### 多租户支持

```php
// 从请求上下文自动获取租户ID
$tenantId = WorkflowBootstrap::getAdapter()
    ->getContainer()
    ->getConfig('tenant_id');

// 或者手动设置租户上下文
WorkflowBootstrap::getContainer()
    ->register('current_tenant_id', 1001);
```

### 事件监听

```php
use Alkaid\Workflow\Engine\Integration\WorkflowBootstrap;

$container = WorkflowBootstrap::getContainer();

// 注册自定义事件监听器
$container->register('custom.event.listener', new CustomEventListener());

// 获取事件服务并触发事件
$eventService = WorkflowBootstrap::getAdapter()->getContainer()
    ->get('processEventService');

$eventService->trigger('workflow.completed', [
    'instance_id' => 'instance_001',
    'completed_at' => time(),
]);
```

## API参考

### WorkflowBootstrap类

#### 静态方法

- `initialize(array $config = []): ThinkPHPAdapter` - 初始化工作流引擎
- `getAdapter(): ThinkPHPAdapter` - 获取适配器实例
- `getContainer(): WorkflowContainer` - 获取容器实例
- `getEngine(): IProcessEngines` - 获取工作流引擎
- `getDefineService(): IProcessDefineService` - 获取定义服务
- `getInstanceService(): IProcessInstanceService` - 获取实例服务
- `getTaskService(): IProcessTaskService` - 获取任务服务
- `startWorkflow(string $definitionId, array $params, int $operator): IProcessInstance` - 启动工作流
- `executeTask(string $taskId, array $params, int $operator): array` - 执行任务
- `isInitialized(): bool` - 检查是否已初始化
- `getStatistics(): array` - 获取统计信息
- `healthCheck(): array` - 健康检查
- `reset(): void` - 重置引擎

### ThinkPHPAdapter类

#### 实例方法

- `initialize(array $config = []): void` - 初始化适配器
- `getContainer(): WorkflowContainer` - 获取容器
- `getEngine(): IProcessEngines` - 获取引擎
- `getDefineService(): IProcessDefineService` - 获取定义服务
- `getInstanceService(): IProcessInstanceService` - 获取实例服务
- `getTaskService(): IProcessTaskService` - 获取任务服务
- `createModel(string $modelClass, array $data = []): Model` - 创建模型实例
- `executeInTransaction(callable $operation, array $options = []): mixed` - 事务处理

## 配置说明

### 基本配置

```php
// config/workflow.php
return [
    'engine' => [
        'enabled' => true,
        'debug' => env('APP_DEBUG', false),
    ],

    'database' => [
        'prefix' => 'alkaid_',
        'tables' => [
            'workflow_definitions' => 'workflow_definitions',
            'workflow_instances' => 'workflow_instances',
            'workflow_tasks' => 'workflow_tasks',
        ],
    ],

    'tenant' => [
        'enabled' => true,
        'default_tenant_id' => 1,
    ],

    'cache' => [
        'driver' => 'redis',
        'prefix' => 'workflow:',
    ],
];
```

### 自定义服务配置

```php
// 在应用配置中覆盖默认服务
ServiceFactory::setServiceConfig([
    'process_define_service' => \app\service\CustomWorkflowDefineService::class,
    'process_instance_service' => \app\service\CustomWorkflowInstanceService::class,
    'process_task_service' => \app\service\CustomWorkflowTaskService::class,
]);
```

## 最佳实践

### 1. 错误处理

```php
try {
    $instance = WorkflowBootstrap::startWorkflow($definitionId, $params, $userId);
} catch (\Exception $e) {
    Log::error('工作流启动失败', [
        'definition_id' => $definitionId,
        'user_id' => $userId,
        'error' => $e->getMessage(),
    ]);

    throw new BusinessException('工作流启动失败');
}
```

### 2. 权限检查

```php
// 在执行任务前检查权限
$task = WorkflowBootstrap::getTaskService()->getById($taskId);

if (!$workflowService->canExecuteTask($task, $userId)) {
    throw new UnauthorizedException('无权执行此任务');
}

$result = WorkflowBootstrap::executeTask($taskId, $params, $userId);
```

### 3. 审计日志

```php
// 记录所有工作流操作
$eventService = WorkflowBootstrap::getAdapter()->getContainer()
    ->get('processEventService');

$eventService->logEvent('task.completed', [
    'task_id' => $taskId,
    'user_id' => $userId,
    'result' => $params,
    'timestamp' => time(),
]);
```

### 4. 性能优化

```php
// 使用缓存
$definition = app()->cache->remember(
    "workflow_definition:{$definitionId}",
    3600,
    function () use ($definitionId) {
        return WorkflowBootstrap::getDefineService()->getById($definitionId);
    }
);

// 批量处理
WorkflowBootstrap::getAdapter()->executeInTransaction(function ($adapter) {
    foreach ($tasks as $task) {
        WorkflowBootstrap::executeTask($task['id'], $task['params'], $task['user_id']);
    }
});
```

## 故障排除

### 常见问题

1. **容器未初始化**
   - 确保调用 `WorkflowBootstrap::initialize()` 在应用启动时执行
   - 检查配置参数是否正确

2. **服务未找到**
   - 确认服务类已正确注册到容器中
   - 检查服务实现类是否存在

3. **多租户数据隔离**
   - 确保所有数据访问层都正确使用租户ID
   - 检查租户上下文是否正确设置

### 健康检查

```php
// 检查引擎状态
$health = WorkflowBootstrap::healthCheck();

if ($health['status'] !== 'ok') {
    Log::warning('工作流引擎健康检查失败', $health);
}
```

## 扩展开发

### 自定义节点类型

```php
use Alkaid\Workflow\Engine\parser\INodeParser;
use Alkaid\Workflow\Engine\parser\AbstractINodeParser;

class CustomNodeParser extends AbstractINodeParser implements INodeParser
{
    public function parse(array $config): array
    {
        // 实现自定义节点解析逻辑
        return [];
    }

    public function execute($execution): void
    {
        // 实现自定义节点执行逻辑
    }
}
```

### 自定义事件监听器

```php
use Alkaid\Workflow\Engine\event\IProcessEventListener;

class CustomEventListener implements IProcessEventListener
{
    public function onWorkflowStarted(array $data): void
    {
        // 处理工作流开始事件
    }

    public function onWorkflowCompleted(array $data): void
    {
        // 处理工作流完成事件
    }
}
```
