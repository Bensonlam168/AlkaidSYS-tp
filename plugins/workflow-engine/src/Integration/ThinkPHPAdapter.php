<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use think\Container;
use think\facade\Request;
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
     * @var WorkflowContainer|null 工作流容器实例
     */
    private ?WorkflowContainer $container = null;

    /**
     * @var array 服务绑定配置
     */
    private array $serviceBindings = [];

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
     * @return void
     */
    private function setupRequestContext(): void
    {
        if (!Request::instance()) {
            return;
        }

        // 获取请求相关信息
        $request = Request::instance();
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
     * 设置多租户上下文
     *
     * @return void
     */
    private function setupTenantContext(): void
    {
        // 从请求中获取租户ID
        $tenantId = $this->getCurrentTenantId();

        if ($tenantId) {
            // 设置租户上下文
            $this->container->getConfig('tenant_id', $tenantId);
        }
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
            $request = $request ?: Request::instance();

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
     * @return void
     */
    public function cleanup(): void
    {
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
