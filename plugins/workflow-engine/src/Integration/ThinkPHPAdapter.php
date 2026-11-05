<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use think\Container;
use think\Model;

/**
 * ThinkPHP集成适配器
 *
 * 提供工作流引擎与ThinkPHP框架的集成支持，
 * 包括服务注册、请求上下文注入、多租户支持等。
 *
 * @package Alkaid\Workflow\Engine\Integration
 */
class ThinkPHPAdapter
{
    /**
     * CLI 场景（队列 worker / cron / 控制台命令）使用的系统身份标识
     *
     * 0 表示「非人类用户发起」，用于区分「真实终端用户」与「调度器/命令触发」。
     */
    private const CONSOLE_SYSTEM_USER_ID = 0;

    /**
     * CLI 场景下 IP / User-Agent 的显式标记
     *
     * 不用空串：空串与「真实请求但取值为空」无法区分，
     * 标记为 cli 才能让审计侧一眼看出这条记录来自非 HTTP 来源。
     */
    private const CLI_CONTEXT_MARKER = 'cli';

    /**
     * @var WorkflowContainer|null 工作流容器实例
     */
    private ?WorkflowContainer $container = null;

    /**
     * @var array 服务绑定配置
     */
    private array $serviceBindings = [];

    /**
     * @var int|null 本适配器写入 TenantContext 的租户ID（cleanup 时按需撤销）
     */
    private ?int $appliedTenantId = null;

    /**
     * 初始化ThinkPHP集成
     *
     * @param array $config 集成配置
     * @return void
     */
    public function initialize(array $config = []): void
    {
        try {
            // 获取ThinkPHP容器实例
            $thinkContainer = Container::getInstance();

            // 创建工作流容器
            $this->container = ServiceFactory::createContainer([
                'container' => $thinkContainer,
                ...$config,
            ]);

            // 注册ThinkPHP服务
            $this->registerThinkPHPServices($thinkContainer);

            // 设置请求上下文
            $this->setupRequestContext();

            // 设置多租户上下文
            $this->setupTenantContext();

            // 注册事件监听器
            $this->registerEventListeners();

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('ThinkPHP集成初始化失败: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 注册ThinkPHP服务
     *
     * @param Container $thinkContainer ThinkPHP容器
     * @return void
     */
    private function registerThinkPHPServices(Container $thinkContainer): void
    {
        // 注册工作流容器服务
        $thinkContainer->bind('workflow.container', function () {
            return $this->container;
        });

        // 注册核心工作流服务
        $this->serviceBindings = [
            'workflow.engine' => function () {
                return $this->container->getProcessEngines();
            },
            'workflow.define.service' => function () {
                return $this->container->getProcessDefineService();
            },
            'workflow.instance.service' => function () {
                return $this->container->getProcessInstanceService();
            },
            'workflow.task.service' => function () {
                return $this->container->getProcessTaskService();
            },
            'workflow.event.service' => function () {
                return $this->container->getProcessEventService();
            },
            'workflow.scheduler' => function () {
                return $this->container->getScheduler();
            },
        ];

        // 将服务绑定注册到ThinkPHP容器
        foreach ($this->serviceBindings as $name => $callback) {
            $thinkContainer->bind($name, $callback);
        }
    }

    /**
     * 设置请求上下文
     *
     * 两条路径：有 HTTP 请求走原逻辑；没有 HTTP 请求（CLI）走降级逻辑。
     * CLI 为什么必须能安全工作，见 resolveHttpRequest() 的注释。
     *
     * @return void
     */
    private function setupRequestContext(): void
    {
        $request = $this->resolveHttpRequest();

        // ── CLI / 队列 worker / cron 分支：降级，不抛异常 ──────────────
        if ($request === null) {
            $this->applyCliRequestContext();
            return;
        }

        // ── HTTP 分支：审计字段取值逻辑与改造前完全一致 ────────────────
        // 获取请求相关信息
        $userId = $this->getCurrentUserId($request);
        $tenantId = $this->getCurrentTenantId($request);
        $userAgent = $request->header('user-agent', '');
        $ipAddress = $request->ip();

        // 设置工作流引擎的请求上下文
        $this->container->registerBatch([
            'current_user_id' => $userId,
            'current_tenant_id' => $tenantId,
            'request_user_agent' => $userAgent,
            'request_ip_address' => $ipAddress,
            'request_id' => uniqid('wf_', true),
            'request_time' => time(),
        ]);
    }

    /**
     * 解析当前 HTTP 请求；没有 HTTP 请求上下文时返回 null
     *
     * 为什么不能直接 Request::instance()：
     * think\facade\Request 的底层是容器标识 'request'，任何静态调用都会走到
     * think\Container::make('request')。只有真正处理 HTTP 请求时，
     * think\Http::runWithRequest() 才会执行 $app->instance('request', $request)
     * 把请求对象放进容器。而在 **队列 worker（think-queue）、定时任务（cron）、
     * php think 自定义命令** 里容器中没有 'request'，make() 会去反射一个名叫
     * "request" 的类，抛 `ClassNotFoundException: class not exists: request`，
     * 进而让整个 WorkflowBootstrap::initialize() 失败 ——
     * 表现为「生产上 CLI 侧一条工作流都跑不起来」，这不只是测试环境的问题。
     *
     * ⚠️ 判定必须用 `exists()`（实例表）而不是 `has()`（绑定表或实例表）：
     * `think\App` 把 `'request' => Request::class` 登记进了**绑定表**（App::$bind），
     * 所以引导过的应用里 `has('request')` **恒为 true**，`make('request')` 会现造一个空请求。
     * 实测（容器内 `php -r` 引导 App 后）：`has('request') = true`、`exists('request') = false`。
     * 用 has() 的后果是「CLI 降级分支永远走不到」：审计字段拿到的是空请求而不是 console 身份，
     * 多租户上下文也就永远拿不到配置里的 tenant_id（本轮修复的正是这条路径）。
     *
     * @return \think\Request|null 有 HTTP 上下文返回请求对象，否则 null
     */
    private function resolveHttpRequest(): ?\think\Request
    {
        try {
            $thinkContainer = Container::getInstance();

            // 只有真实 HTTP 请求处理期间，框架才会把 request **实例**放进容器
            if (!$thinkContainer->exists('request')) {
                return null;
            }

            $request = $thinkContainer->make('request');

            // 容器里可能存着不符合预期的绑定，按类型收口后再交给调用方
            return $request instanceof \think\Request ? $request : null;
        } catch (\Throwable) {
            // 宿主容器未启动或框架版本差异：一律按「没有 HTTP 请求上下文」处理
            return null;
        }
    }

    /**
     * 应用 CLI 场景的降级上下文
     *
     * 没有 HTTP 请求时，审计字段没有真实来源，因此显式降级而不是留空或抛异常：
     * - 用户身份：CONSOLE_SYSTEM_USER_ID（0，系统/控制台身份，表示由调度器或命令触发）；
     * - 租户：取容器配置（extension_parameters.tenant_id），CLI 场景没有请求头可读；
     * - IP / User-Agent：CLI_CONTEXT_MARKER（'cli'），明确标记「非 HTTP 来源」，
     *   避免被误读成「取值为空的真实请求」。
     *
     * @return void
     */
    private function applyCliRequestContext(): void
    {
        $this->container->registerBatch([
            'current_user_id' => self::CONSOLE_SYSTEM_USER_ID,
            'current_tenant_id' => $this->resolveCliTenantId(),
            'request_user_agent' => self::CLI_CONTEXT_MARKER,
            'request_ip_address' => self::CLI_CONTEXT_MARKER,
            'request_id' => uniqid('wf_cli_', true),
            'request_time' => time(),
        ]);
    }

    /**
     * 解析 CLI 场景的租户ID
     *
     * CLI 没有请求头/请求参数可读，只能取配置项 tenant_id
     * （即 extension_parameters.tenant_id），取不到则为 null。
     *
     * @return int|null
     */
    private function resolveCliTenantId(): ?int
    {
        $tenantId = $this->container->getConfig('tenant_id');

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return (int) $tenantId;
    }

    /**
     * 设置多租户上下文
     *
     * 改造前后差异（原实现是**空操作**）：
     * 原代码是 `$this->container->getConfig('tenant_id', $tenantId);` —— 只读配置、返回值被丢弃，
     * 租户信息从未写进任何地方，于是引擎侧 `TenantContext::getTenantId()` 永远是 null，
     * TenantScopeManager 的任何租户作用域查询都会以「缺少租户上下文」失败。
     * 现改为真正写入 `TenantContext`（协程安全，见 src/Multitenant/TenantContext.php），
     * 与 TenantMiddleware 处理 HTTP 请求时的写入口径一致。
     *
     * 有 HTTP 请求就用请求里的租户；CLI（队列 worker / cron / php think）没有请求头可读，
     * 退化为配置项 `extension_parameters.tenant_id`（与 setupRequestContext 同口径）。
     *
     * @return void
     */
    private function setupTenantContext(): void
    {
        $request = $this->resolveHttpRequest();
        $tenantId = $request === null
            ? $this->resolveCliTenantId()
            : $this->getCurrentTenantId($request);

        if ($tenantId === null || $tenantId <= 0) {
            // 没有租户就**不写**上下文：宁可让后续作用域查询 fail-closed 报错，
            // 也不要把 0 或猜测值写进去，那会让隔离形同虚设。
            return;
        }

        TenantContext::setTenantId($tenantId);
        $this->appliedTenantId = $tenantId;
    }

    /**
     * 注册事件监听器
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        // 注册ThinkPHP事件监听器
        if (class_exists('\think\facade\Event')) {
            // 工作流事件监听器可以在这里注册
            // 例如：流程开始、任务创建、流程完成等事件的监听
        }
    }

    /**
     * 获取当前用户ID
     *
     * @param \think\Request|null $request 请求对象
     * @return int|null
     */
    private function getCurrentUserId(?\think\Request $request = null): ?int
    {
        try {
            // 尝试从认证中间件获取用户信息
            if (class_exists('\app\middleware\Auth')) {
                $auth = app()->make(\app\middleware\Auth::class);
                if (method_exists($auth, 'getUserId')) {
                    return $auth->getUserId();
                }
            }

            // 尝试从会话中获取用户ID
            if (session('user_id')) {
                return (int) session('user_id');
            }

            // 尝试从JWT中获取用户ID
            if (class_exists('\app\service\JwtService')) {
                $jwtService = app()->make(\app\service\JwtService::class);
                $token = $jwtService->getTokenFromRequest();
                if ($token) {
                    $payload = $jwtService->decode($token);
                    return $payload['user_id'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            // 静默处理
        }

        return null;
    }

    /**
     * 获取当前租户ID
     *
     * @param \think\Request|null $request 请求对象
     * @return int|null
     */
    private function getCurrentTenantId(?\think\Request $request = null): ?int
    {
        try {
            // CLI 安全：没有 HTTP 请求时不再走 Request::instance()（那会抛
            // ClassNotFoundException: class not exists: request），
            // 统一交给 resolveHttpRequest() 判空后返回 null，
            // 由调用方（setupTenantContext）按配置兜底。
            $request = $request ?: $this->resolveHttpRequest();

            if ($request === null) {
                return null;
            }

            // 优先级1：从请求头获取租户ID
            $tenantId = $request->header('x-tenant-id');
            if ($tenantId) {
                return (int) $tenantId;
            }

            // 优先级2：从请求参数获取租户ID
            $tenantId = $request->param('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }

            // 优先级3：从会话获取租户ID
            if (session('tenant_id')) {
                return (int) session('tenant_id');
            }

            // 优先级4：从JWT中获取租户ID
            if (class_exists('\app\service\JwtService')) {
                $jwtService = app()->make(\app\service\JwtService::class);
                $token = $jwtService->getTokenFromRequest();
                if ($token) {
                    $payload = $jwtService->decode($token);
                    return $payload['tenant_id'] ?? null;
                }
            }

            // 优先级5：从用户信息中获取租户ID
            $userId = $this->getCurrentUserId($request);
            if ($userId && class_exists('\app\model\User')) {
                $user = \app\model\User::find($userId);
                if ($user && isset($user->tenant_id)) {
                    return (int) $user->tenant_id;
                }
            }
        } catch (\Throwable $e) {
            // 静默处理
        }

        return null;
    }

    /**
     * 获取工作流容器实例
     *
     * @return WorkflowContainer
     */
    public function getContainer(): WorkflowContainer
    {
        if (!$this->container) {
            throw new \RuntimeException('工作流容器尚未初始化');
        }

        return $this->container;
    }

    /**
     * 获取工作流引擎实例
     *
     * @return \Alkaid\Workflow\Engine\interface\IProcessEngines
     */
    public function getEngine(): \Alkaid\Workflow\Engine\interface\IProcessEngines
    {
        return $this->getContainer()->getProcessEngines();
    }

    /**
     * 获取工作流定义服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
     */
    public function getDefineService(): \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
    {
        return $this->getContainer()->getProcessDefineService();
    }

    /**
     * 获取工作流实例服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
     */
    public function getInstanceService(): \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
    {
        return $this->getContainer()->getProcessInstanceService();
    }

    /**
     * 获取工作流任务服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
     */
    public function getTaskService(): \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
    {
        return $this->getContainer()->getProcessTaskService();
    }

    /**
     * 创建工作流模型实例
     *
     * @param string $modelClass 模型类名
     * @param array $data 模型数据
     * @return Model
     */
    public function createModel(string $modelClass, array $data = []): Model
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException(sprintf('模型类 %s 不存在', $modelClass));
        }

        return new $modelClass($data);
    }

    /**
     * 执行工作流操作（带事务）
     *
     * @param callable $operation 操作回调
     * @param array $options 选项
     * @return mixed
     */
    public function executeInTransaction(callable $operation, array $options = [])
    {
        $db = app()->db;
        $transactionLevel = $options['level'] ?? null;

        try {
            if ($transactionLevel !== null) {
                $db->startTrans($transactionLevel);
            } else {
                $db->startTrans();
            }

            $result = $operation($this);

            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($transactionLevel !== null) {
                $db->rollback($transactionLevel);
            } else {
                $db->rollback();
            }
            throw $e;
        }
    }

    /**
     * 清理资源
     *
     * 租户上下文是**进程级静态状态**（TenantContext 按协程/默认槽位保存），
     * 适配器初始化时写入的租户必须在清理时撤销，否则同一个 CLI 进程里
     * 后续任务会继承上一个任务的租户 —— 那正是多租户最危险的串租路径。
     * 只撤销「自己写进去的」值：宿主自己设置的上下文不归适配器管。
     *
     * @return void
     */
    public function cleanup(): void
    {
        if ($this->appliedTenantId !== null) {
            TenantContext::clear();
            $this->appliedTenantId = null;
        }

        if ($this->container) {
            $this->container->reset();
            $this->container = null;
        }
        $this->serviceBindings = [];
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
