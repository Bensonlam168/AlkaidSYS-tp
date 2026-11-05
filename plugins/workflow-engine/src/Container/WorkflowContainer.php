<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Container;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\interface\IConfiguration;
use Alkaid\Workflow\Engine\interface\services\IProcessDefineService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskActorService;
use Alkaid\Workflow\Engine\interface\services\IProcessDesignService;
use Alkaid\Workflow\Engine\interface\services\IProcessFormService;
use Alkaid\Workflow\Engine\interface\services\IProcessTypeService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService;
use Alkaid\Workflow\Engine\parser\INodeParser;
use Alkaid\Workflow\Engine\parser\handler\StartParser;
use Alkaid\Workflow\Engine\parser\handler\EndParser;
use Alkaid\Workflow\Engine\parser\handler\TaskParser;
use Alkaid\Workflow\Engine\parser\handler\DecisionParser;
use Alkaid\Workflow\Engine\parser\handler\ForkParser;
use Alkaid\Workflow\Engine\parser\handler\JoinParser;
use Alkaid\Workflow\Engine\parser\handler\CustomParser;
use Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser;
use Alkaid\Workflow\Engine\event\ProcessEventService;
use Alkaid\Workflow\Engine\scheduling\IScheduler;
use Alkaid\Workflow\Engine\scheduling\SchedulerIProcessEventListener;
use Psr\Container\ContainerInterface;

/**
 * 工作流引擎依赖注入容器
 *
 * 负责管理工作流引擎的核心服务依赖注入配置，
 * 支持与ThinkPHP容器集成，提供统一的服务访问接口。
 *
 * @package Alkaid\Workflow\Engine\Container
 */
class WorkflowContainer
{
    /**
     * @var ContainerInterface|null 外部容器引用（ThinkPHP）
     */
    private ?ContainerInterface $externalContainer = null;

    /**
     * @var bool 容器是否已初始化
     */
    private bool $initialized = false;

    /**
     * @var array 核心服务配置
     */
    private array $coreServices = [];

    /**
     * 构造函数
     *
     * @param ContainerInterface|null $externalContainer 外部容器（ThinkPHP）
     */
    public function __construct(?ContainerInterface $externalContainer = null)
    {
        $this->externalContainer = $externalContainer;
    }

    /**
     * 初始化依赖注入容器
     *
     * @param array $config 配置参数
     * @return void
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            // 构建核心服务配置
            $this->buildCoreServices($config);

            // 设置ServiceContext
            ServiceContext::setContext($this->coreServices);

            // 注册扩展参数
            if (!empty($config['extension_parameters'] ?? [])) {
                ServiceContext::extensions($config['extension_parameters']);
            }

            // 备份配置
            ServiceContext::setBackupConfig($config);

            $this->initialized = true;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('工作流容器初始化失败: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 构建核心服务配置
     *
     * @param array $config 配置参数
     * @return void
     */
    private function buildCoreServices(array $config = []): void
    {
        $this->coreServices = [
            // 核心引擎服务
            'processEngines' => IProcessEngines::class,
            'configuration' => $config['configuration_class'] ?? IConfiguration::class,

            // 工作流定义服务
            'processDefineService' => $config['process_define_service'] ?? IProcessDefineService::class,

            // 工作流实例服务
            'processInstanceService' => $config['process_instance_service'] ?? IProcessInstanceService::class,

            // 工作流任务服务
            'processTaskService' => $config['process_task_service'] ?? IProcessTaskService::class,

            // 任务参与者服务
            'processTaskActorService' => $config['process_task_actor_service'] ?? IProcessTaskActorService::class,

            // 工作流设计服务
            'processDesignService' => $config['process_design_service'] ?? IProcessDesignService::class,

            // 工作流表单服务
            'processFormService' => $config['process_form_service'] ?? IProcessFormService::class,

            // 工作流类型服务
            'processTypeService' => $config['process_type_service'] ?? IProcessTypeService::class,

            // 工作流实例历史服务
            'processInstanceHistoryService' => $config['process_instance_history_service'] ?? IProcessInstanceHistoryService::class,

            // 工作流任务历史服务
            'processTaskHistoryService' => $config['process_task_history_service'] ?? IProcessTaskHistoryService::class,

            // 节点解析器
            'startParser' => StartParser::class,
            'endParser' => EndParser::class,
            'taskParser' => TaskParser::class,
            'decisionParser' => DecisionParser::class,
            'forkParser' => ForkParser::class,
            'joinParser' => JoinParser::class,
            'customParser' => CustomParser::class,
            'wfSubProcessParser' => WfSubProcessParser::class,

            // 事件服务
            'processEventService' => ProcessEventService::class,
            'schedulerProcessEventListener' => SchedulerIProcessEventListener::class,

            // 调度器
            'scheduler' => $config['scheduler'] ?? IScheduler::class,

            // 自定义服务注入
            ...($config['service_dependencies'] ?? []),
        ];

        // 动态依赖注入
        if (!empty($config['dynamic_dependencies'] ?? [])) {
            $this->coreServices = array_merge($this->coreServices, $config['dynamic_dependencies']);
        }
    }

    /**
     * 获取服务实例
     *
     * @param string $name 服务名称
     * @return mixed
     */
    public function get(string $name): mixed
    {
        if (!$this->initialized) {
            throw new \RuntimeException('工作流容器尚未初始化，请先调用 initialize() 方法');
        }

        // 先从外部容器获取
        if ($this->externalContainer && $this->externalContainer->has($name)) {
            return $this->externalContainer->get($name);
        }

        // 从ServiceContext获取
        return ServiceContext::find($name);
    }

    /**
     * 检查服务是否存在
     *
     * @param string $name 服务名称
     * @return bool
     */
    public function has(string $name): bool
    {
        if (!$this->initialized) {
            return false;
        }

        // 检查外部容器
        if ($this->externalContainer && $this->externalContainer->has($name)) {
            return true;
        }

        // 检查ServiceContext
        return ServiceContext::exist($name);
    }

    /**
     * 注册服务
     *
     * @param string $name 服务名称
     * @param mixed $value 服务值（类名或实例）
     * @return void
     */
    public function register(string $name, mixed $value): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('工作流容器尚未初始化，请先调用 initialize() 方法');
        }

        ServiceContext::register($name, $value);
    }

    /**
     * 批量注册服务
     *
     * @param array $services 服务数组
     * @return void
     */
    public function registerBatch(array $services): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('工作流容器尚未初始化，请先调用 initialize() 方法');
        }

        ServiceContext::registerBatch($services);
    }

    /**
     * 获取核心工作流引擎实例
     *
     * @return IProcessEngines
     */
    public function getProcessEngines(): IProcessEngines
    {
        return $this->get('processEngines');
    }

    /**
     * 获取工作流定义服务
     *
     * @return IProcessDefineService
     */
    public function getProcessDefineService(): IProcessDefineService
    {
        return $this->get('processDefineService');
    }

    /**
     * 获取工作流实例服务
     *
     * @return IProcessInstanceService
     */
    public function getProcessInstanceService(): IProcessInstanceService
    {
        return $this->get('processInstanceService');
    }

    /**
     * 获取工作流任务服务
     *
     * @return IProcessTaskService
     */
    public function getProcessTaskService(): IProcessTaskService
    {
        return $this->get('processTaskService');
    }

    /**
     * 获取节点解析器
     *
     * @param string $type 节点类型
     * @return INodeParser|null
     */
    public function getNodeParser(string $type): ?INodeParser
    {
        $parserKey = $type . 'Parser';

        if (!$this->has($parserKey)) {
            return null;
        }

        $parser = $this->get($parserKey);

        // 契约：本方法的返回类型就是 ?INodeParser，够格就直接返回
        if ($parser instanceof INodeParser) {
            return $parser;
        }

        // 为什么还要兜一层实例化：
        // buildCoreServices() 登记的是「名字 => 类名」，而 ServiceContext::setContext()
        // 把这份映射原样交给 PHP-DI；PHP-DI 的 DefinitionNormalizer 会把字符串
        // 归一化成 **字面值**（ValueDefinition），于是 get() 取回的是类名字符串，
        // 而不是解析器对象 —— 直接 return 会违反本方法的返回类型并抛 TypeError。
        // 节点解析器都是无构造依赖的无状态对象（见 AbstractINodeParser），
        // 按登记的类名实例化即可满足契约；实例化不出来就按「缺失」返回 null，
        // 由调用方按既有的 null 口径（has()/get() 判空）处理。
        if (is_string($parser) && class_exists($parser)) {
            $reflection = new \ReflectionClass($parser);

            if ($reflection->isInstantiable()) {
                $instance = $reflection->newInstance();

                if ($instance instanceof INodeParser) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /**
     * 获取事件服务
     *
     * @return ProcessEventService
     */
    public function getProcessEventService(): ProcessEventService
    {
        return $this->get('processEventService');
    }

    /**
     * 获取调度器
     *
     * @return IScheduler
     */
    public function getScheduler(): IScheduler
    {
        return $this->get('scheduler');
    }

    /**
     * 获取配置值
     *
     * @param string $name 配置名称
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getConfig(string $name, mixed $default = null): mixed
    {
        return ServiceContext::getConfig($name, $default);
    }

    /**
     * 检查容器是否已初始化
     *
     * @return bool
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * 重置容器
     *
     * @return void
     */
    public function reset(): void
    {
        $this->coreServices = [];
        $this->initialized = false;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        // 清理资源
        $this->reset();
    }
}
