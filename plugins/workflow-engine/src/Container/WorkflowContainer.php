<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Container;

use Alkaid\Workflow\Engine\Core\ProcessEngines;
use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Services\ProcessDefineService;
use Alkaid\Workflow\Engine\Services\ProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\Services\ProcessInstanceService;
use Alkaid\Workflow\Engine\Services\ProcessTaskService;
use Alkaid\Workflow\Engine\Services\DefaultTaskActorDirectory;
use Alkaid\Workflow\Engine\Services\DefaultTaskActorResolver;
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
use Alkaid\Workflow\Engine\interface\services\ITaskActorDirectory;
use Alkaid\Workflow\Engine\interface\services\ITaskActorResolver;
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
     * @var array 构建核心服务时使用的原始配置
     */
    private array $coreConfig = [];

    /**
     * @var string 工作流引擎实现类
     */
    private string $processEnginesClass = ProcessEngines::class;

    /**
     * @var array 已解析的服务实例缓存
     *
     * 为什么必须缓存：`ServiceContext::$container` 是**静态**的，
     * 而 `ProcessEngines` 构造时 `Configuration` 会调用 `ServiceContext::setContext()`
     * 用 `service_dependencies` **整体重建**这个静态容器。
     * 重建之后，`ServiceContext::find($name)` 查的是新容器，
     * 原先登记在那份表里的条目（例如首次解析过的核心服务）会查不到，静默返回 null ——
     * 表现为「第一次取引擎正常，第二次取服务变成 null」。
     * 因此在容器内部保留已解析结果，使 get()/has() 的语义不受静态容器被替换的影响。
     *
     * @var array<string, mixed>
     */
    private array $resolvedServices = [];

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
        // 三个核心服务的实现类：宿主可通过 config 覆盖，未覆盖时用插件自带的真实实现。
        // 这里**不能**再登记接口名：PHP-DI 会把裸字符串归一化成字面值，
        // 接口既无法实例化、取回时又是字符串，调用方按接口接收必然 TypeError。
        $processDefineService = $config['process_define_service'] ?? ProcessDefineService::class;
        $processInstanceService = $config['process_instance_service'] ?? ProcessInstanceService::class;
        $processTaskService = $config['process_task_service'] ?? ProcessTaskService::class;

        // 实例历史服务（= 流程轨迹）。默认用插件自带的真实实现，宿主可用
        // `process_instance_history_service` 覆盖成自己的实现（覆盖能力保留）。
        //
        // 为什么以前登记 null、现在能登记类名：`IProcessInstanceHistoryService` 此前是**空接口**
        // （30 多个声明全被注释），插件没有实现类，历史表也没建。
        // 若登记接口名，ServiceContext::normalizeDefinitions() 只把**存在的类**包成 DI\create()，
        // 接口名会作为字面值留在 PHP-DI 里，find() 于是返回一个**字符串**；而
        // ProcessServiceTrait::processInstanceHistoryService() 的返回类型是
        // ?IProcessInstanceHistoryService，字符串不匹配会直接抛 TypeError，
        // 比「返回 null、由调用方守卫」更难排查（守卫见 ProcessEngines::startProcessInstanceById）。
        //
        // 现在接口有了真契约（5 个方法）与真实现（落 workflow_event_logs），
        // 登记真实类名即可让引擎侧按接口 FQCN 取到对象。
        $processInstanceHistoryService = $config['process_instance_history_service'] ?? ProcessInstanceHistoryService::class;

        // 任务历史服务仍保持「未配置即 null」：IProcessTaskHistoryService 全仓库零调用方，
        // 实现它的 4 个方法就是死代码（理由见 IProcessInstanceHistoryService 的类注释）。
        $processTaskHistoryService = $config['process_task_history_service'] ?? null;

        // 任务参与者解析器：把节点上配的「谁有权办」（assignee / candidate_ext_users /
        // candidate_ext_groups）解析成可落库的用户 id。默认用插件自带的实现，
        // 宿主可用 `task_actor_resolver` 换成自己的（例如接组织架构服务）。
        //
        // 与历史服务同理：**不能**登记接口名本身 —— normalizeDefinitions() 只把
        // 「存在的类」包成 DI\create()，接口名会作为字面值留下，取回时是字符串，
        // 调用方按接口接收必然 TypeError。
        $taskActorResolver = $config['task_actor_resolver'] ?? DefaultTaskActorResolver::class;

        // 人员目录（查角色成员 / 用户 / 部门）：解析器的数据来源，同样可被宿主替换。
        // 单独登记的理由：解析规则与「怎么查人」是两件事，前者值得脱离数据库测试。
        $taskActorDirectory = $config['task_actor_directory'] ?? DefaultTaskActorDirectory::class;

        $this->coreConfig = $config;

        $this->coreServices = [
            // 核心引擎服务（占位值，随后被改写为「带配置的构造定义」，见下方 makeProcessEngines）
            'processEngines' => IProcessEngines::class,
            'configuration' => $config['configuration_class'] ?? IConfiguration::class,

            // 工作流定义服务
            'processDefineService' => $processDefineService,

            // 工作流实例服务
            'processInstanceService' => $processInstanceService,

            // 工作流任务服务
            'processTaskService' => $processTaskService,

            // 按接口 FQCN 登记同一实现：
            // 引擎内部（src/libs/traits/ProcessServiceTrait.php）取服务用的是
            // ServiceContext::find(IProcessXxxService::class)，只登记短名会让引擎侧全部取到 null。
            IProcessDefineService::class => $processDefineService,
            IProcessInstanceService::class => $processInstanceService,
            IProcessTaskService::class => $processTaskService,

            // 任务参与者服务
            'processTaskActorService' => $config['process_task_actor_service'] ?? IProcessTaskActorService::class,

            // 工作流设计服务
            'processDesignService' => $config['process_design_service'] ?? IProcessDesignService::class,

            // 工作流表单服务
            'processFormService' => $config['process_form_service'] ?? IProcessFormService::class,

            // 工作流类型服务
            'processTypeService' => $config['process_type_service'] ?? IProcessTypeService::class,

            // 工作流实例历史服务
            'processInstanceHistoryService' => $processInstanceHistoryService,
            // 按接口 FQCN 再登记一次：引擎侧 ProcessServiceTrait::processInstanceHistoryService()
            // 查的是 `ServiceContext::find(IProcessInstanceHistoryService::class)`，
            // 只登记短名会让引擎侧永远取到 null（历史记录被静默跳过）。
            IProcessInstanceHistoryService::class => $processInstanceHistoryService,

            // 工作流任务历史服务
            'processTaskHistoryService' => $processTaskHistoryService,
            IProcessTaskHistoryService::class => $processTaskHistoryService,

            // 任务参与者解析器（短名 + 接口 FQCN 同时登记：
            // 引擎侧 ProcessTaskService 取的是 `ServiceContext::find(ITaskActorResolver::class)`，
            // 宿主覆盖时可能写短名 `taskActorResolver`，两者必须指向同一个实现）
            'taskActorResolver' => $taskActorResolver,
            ITaskActorResolver::class => $taskActorResolver,
            'taskActorDirectory' => $taskActorDirectory,
            ITaskActorDirectory::class => $taskActorDirectory,

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

            // 节点类型键（start/end/task/...）
            //
            // 这两个列表看起来重复，但查的是**不同的键**：
            // - 上面的 xxxParser 是 `getNodeParser('start')` 用的（拼接 type.'Parser'）；
            // - 下面这组是引擎 `ModelParser::parse()` 用的 —— 它按节点类型直接查
            //   `ServiceContext::find('start')`（src/parser/ModelParser.php:81-84），
            //   键名就是去掉 `ingenious:` 前缀的节点类型。
            // 改造前这组键只由 `Configuration::defaultService()` 在**构造引擎实例时**补登记，
            // 于是「不构造引擎、直接用定义服务取 ProcessModel」会静默解析出空模型。
            // 这里提前登记，去掉这层顺序依赖；键名与 Configuration::defaultService() 保持一致。
            'start'        => StartParser::class,
            'end'          => EndParser::class,
            'task'         => TaskParser::class,
            'decision'     => DecisionParser::class,
            'fork'         => ForkParser::class,
            'join'         => JoinParser::class,
            'custom'       => CustomParser::class,
            'wfSubProcess' => WfSubProcessParser::class,

            // 调度器
            'scheduler' => $config['scheduler'] ?? IScheduler::class,

            // 自定义服务注入
            ...($config['service_dependencies'] ?? []),
        ];

        // 动态依赖注入
        if (!empty($config['dynamic_dependencies'] ?? [])) {
            $this->coreServices = array_merge($this->coreServices, $config['dynamic_dependencies']);
        }

        // 把接口别名同步成最终实现：宿主既可能用 `config['process_define_service']`
        // （见 buildCoreServices 开头），也可能用历史写法 `service_dependencies['processDefineService']`
        // 覆盖同一个服务。后者是在上面的 spread 里生效的，此时必须让
        // `IProcessDefineService::class` 指向同一个实现，否则引擎侧（按接口名取）
        // 拿到的仍是默认实现，出现「短名是宿主的、引擎是插件的」这类割裂。
        $this->coreServices[IProcessDefineService::class] = $this->coreServices['processDefineService'];
        $this->coreServices[IProcessInstanceService::class] = $this->coreServices['processInstanceService'];
        $this->coreServices[IProcessTaskService::class] = $this->coreServices['processTaskService'];

        // 历史服务的同步规则与上面三条不同：它**允许为 null**（宿主可显式关掉实例历史服务），
        // 所以不能用「无条件赋值」，否则会把宿主按接口 FQCN 注入的实现覆盖回 null。
        // 用 `??` 表达「两边谁有值就用谁」：宿主无论写短名还是写接口 FQCN，
        // 引擎侧按接口 FQCN 取值都能拿到同一个服务；两边都没值才是 null
        // （实例历史现在有默认实现，null 只会出现在宿主主动覆盖成 null 的场景；
        // 任务历史没有任何实现类，默认就是 null）。
        $this->coreServices[IProcessInstanceHistoryService::class]
            = $this->coreServices[IProcessInstanceHistoryService::class]
            ?? $this->coreServices['processInstanceHistoryService'];
        $this->coreServices[IProcessTaskHistoryService::class]
            = $this->coreServices[IProcessTaskHistoryService::class]
            ?? $this->coreServices['processTaskHistoryService'];

        // 参与者解析器 / 人员目录：宿主可能用 `config['task_actor_resolver']`（见 buildCoreServices 开头）
        // 或 `service_dependencies['taskActorResolver']` 覆盖同一个服务，两边必须指向同一实现，
        // 否则会出现「短名是宿主的、引擎按接口取到的仍是插件默认实现」的割裂。
        $this->coreServices[ITaskActorResolver::class]
            = $this->coreServices[ITaskActorResolver::class]
            ?? $this->coreServices['taskActorResolver'];
        $this->coreServices[ITaskActorDirectory::class]
            = $this->coreServices[ITaskActorDirectory::class]
            ?? $this->coreServices['taskActorDirectory'];

        // 引擎实现类：允许宿主通过 service_dependencies['processEngines'] 覆盖，默认用插件自带实现
        $engineCandidate = $this->coreServices['processEngines'] ?? ProcessEngines::class;
        $this->processEnginesClass = is_string($engineCandidate) && class_exists($engineCandidate)
            ? $engineCandidate
            : ProcessEngines::class;

        // 引擎必须「带配置」构造：ProcessEngines::__construct() 会用
        // Configuration 重建 ServiceContext（Configuration::__construct 里调用 setContext），
        // 空配置会直接抛「Context cannot be empty.」。裸类名交给 PHP-DI 只会被字面值化
        // 或 autowire 成空配置，所以这里显式给出构造参数。
        //
        // 为什么用 **闭包** 而不是 `DI\factory(...)`：
        // `DI\factory()` 是 php-di 的函数（vendor/php-di/php-di/src/functions.php），
        // 宿主若未安装 php-di（本仓库根 composer.json 确实没有该依赖，实测
        // `class_exists('DI\ContainerBuilder')` 为 false），在 buildCoreServices() 阶段调用它
        // 会直接 `Error: Call to undefined function DI\factory()` —— 把一个「有清晰提示的
        // LFlowException（PHP-DI 未安装）」变成难懂的 \Error。
        // 闭包本身就是 PHP-DI 认识的工厂定义（DefinitionNormalizer 会把 Closure 包成
        // FactoryDefinition），既不需要额外函数，也保持了延迟构造。
        $this->coreServices['processEngines'] = function () {
            return $this->makeProcessEngines();
        };
    }

    /**
     * 构造工作流引擎实例
     *
     * 为什么要在容器里构造，而不是让 PHP-DI 自动装配：
     * 1）`ProcessEngines::__construct($config)` 接收的是**整份引擎配置**，
     *    其中 `service_dependencies` 会被 `Configuration` 用于重建 ServiceContext；
     *    自动装配拿不到这份配置（只会给空数组），进而抛 LFlowException；
     * 2）重建 ServiceContext 时使用的 `service_dependencies` 必须是**本容器登记的完整服务表**，
     *    否则引擎侧 `ServiceContext::find(IProcessXxxService::class)` 会取到 null。
     *
     * @return IProcessEngines
     */
    private function makeProcessEngines(): IProcessEngines
    {
        // 内层容器不需要（也不应该）再具备「造出第二个引擎」的能力：
        // 留着会形成 processEngines → Configuration → setContext → processEngines 的递归定义
        $services = $this->coreServices;
        unset($services['processEngines']);

        $engineConfig = array_merge($this->coreConfig, [
            'service_dependencies' => $services,
        ]);

        $engineClass = $this->processEnginesClass;
        $engine = new $engineClass($engineConfig);

        if (!$engine instanceof IProcessEngines) {
            throw new \RuntimeException(sprintf(
                '工作流引擎实现 %s 未实现 %s',
                $engineClass,
                IProcessEngines::class
            ));
        }

        return $engine;
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

        // 已解析过的服务直接返回，避免静态容器被 Configuration 重建后再查已是空
        if (array_key_exists($name, $this->resolvedServices)) {
            return $this->resolvedServices[$name];
        }

        // 先从外部容器获取
        if ($this->externalContainer && $this->externalContainer->has($name)) {
            return $this->resolvedServices[$name] = $this->externalContainer->get($name);
        }

        // 从ServiceContext获取
        return $this->resolvedServices[$name] = ServiceContext::find($name);
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

        // 已解析的服务按解析结果判断
        if (array_key_exists($name, $this->resolvedServices)) {
            return $this->resolvedServices[$name] !== null;
        }

        // 核心服务表是「本容器定义了哪些名字」的权威来源：
        // ServiceContext 的静态容器可能已被 Configuration 重建（见 $resolvedServices 注释），
        // 只看 ServiceContext 会把仍然可解析的核心服务误判为不存在。
        if (array_key_exists($name, $this->coreServices)) {
            return true;
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

        // 覆盖登记后，旧的解析结果必须失效
        unset($this->resolvedServices[$name]);

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

        foreach (array_keys($services) as $name) {
            unset($this->resolvedServices[$name]);
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
     * 获取任务参与者解析器
     *
     * 宿主替换解析器后，这里返回的就是宿主的实现（短名与接口 FQCN 指向同一对象）。
     *
     * @return ITaskActorResolver
     */
    public function getTaskActorResolver(): ITaskActorResolver
    {
        return $this->get(ITaskActorResolver::class);
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
        $this->coreConfig = [];
        $this->processEnginesClass = ProcessEngines::class;
        $this->resolvedServices = [];
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
