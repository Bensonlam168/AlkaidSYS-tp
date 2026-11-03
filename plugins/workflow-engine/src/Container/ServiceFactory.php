<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Container;

use Alkaid\Workflow\Engine\cfg\Configuration;
use think\Container as ThinkContainer;

/**
 * 工作流服务工厂
 *
 * 负责创建和配置工作流引擎的依赖注入容器，
 * 提供统一的工厂接口，支持ThinkPHP集成。
 *
 * @package Alkaid\Workflow\Engine\Container
 */
class ServiceFactory
{
    /**
     * @var WorkflowContainer|null 工作流容器实例
     */
    private static ?WorkflowContainer $container = null;

    /**
     * @var array 服务配置
     */
    private static array $serviceConfig = [];

    /**
     * 创建工作流容器
     *
     * @param array $config 配置参数
     * @return WorkflowContainer
     */
    public static function createContainer(array $config = []): WorkflowContainer
    {
        // 合并默认配置
        $config = self::mergeDefaultConfig($config);

        // 创建容器实例
        $container = new WorkflowContainer(
            self::getExternalContainer($config)
        );

        // 初始化容器
        $container->initialize($config);

        // 保存容器实例
        self::$container = $container;

        return $container;
    }

    /**
     * 获取工作流容器实例（单例）
     *
     * @return WorkflowContainer
     */
    public static function getContainer(): WorkflowContainer
    {
        if (self::$container === null) {
            throw new \RuntimeException('工作流容器尚未创建，请先调用 createContainer() 方法');
        }

        return self::$container;
    }

    /**
     * 合并默认配置
     *
     * @param array $config 用户配置
     * @return array 合并后的配置
     */
    private static function mergeDefaultConfig(array $config = []): array
    {
        $defaultConfig = [
            'service_dependencies' => [
                // 核心服务
                'processEngines' => \Alkaid\Workflow\Engine\Core\ProcessEngines::class,
                'configuration' => Configuration::class,

                // 节点解析器
                'startParser' => \Alkaid\Workflow\Engine\parser\handler\StartParser::class,
                'endParser' => \Alkaid\Workflow\Engine\parser\handler\EndParser::class,
                'taskParser' => \Alkaid\Workflow\Engine\parser\handler\TaskParser::class,
                'decisionParser' => \Alkaid\Workflow\Engine\parser\handler\DecisionParser::class,
                'forkParser' => \Alkaid\Workflow\Engine\parser\handler\ForkParser::class,
                'joinParser' => \Alkaid\Workflow\Engine\parser\handler\JoinParser::class,
                'customParser' => \Alkaid\Workflow\Engine\parser\handler\CustomParser::class,
                'wfSubProcessParser' => \Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser::class,

                // 事件服务
                'processEventService' => \Alkaid\Workflow\Engine\event\ProcessEventService::class,
                'schedulerProcessEventListener' => \Alkaid\Workflow\Engine\scheduling\SchedulerIProcessEventListener::class,

                // 数据访问层（需要根据实际实现调整）
                'processDefineRepository' => \Alkaid\Workflow\Engine\Integration\ProcessDefineRepository::class,
                'processInstanceRepository' => \Alkaid\Workflow\Engine\Integration\ProcessInstanceRepository::class,
                'processTaskRepository' => \Alkaid\Workflow\Engine\Integration\ProcessTaskRepository::class,
                'workflowEventRepository' => \Alkaid\Workflow\Engine\Integration\WorkflowEventRepository::class,

                // 节点管理器
                'nodeManager' => \Alkaid\Workflow\Engine\Multitenant\NodeManager::class,

                // 触发器管理器
                'triggerManager' => \Alkaid\Workflow\Engine\Triggers\TriggerManager::class,

                // 表达式引擎
                'expressionEngine' => \Alkaid\Workflow\Engine\Expression\ExpressionEngine::class,
            ],

            'dynamic_dependencies' => [],

            'extension_parameters' => [
                // 这里的 env() 有守卫，原因见 resolveDebugFlag() 的注释
                'debug' => self::resolveDebugFlag(),
                'tenant_id' => null, // 多租户ID，将由请求上下文动态设置
                'database_prefix' => 'alkaid_',
                'cache_driver' => 'redis',
                'logger_channel' => 'workflow',
            ],

            'configuration_class' => Configuration::class,

            // 外部容器集成
            'external_container' => true,
        ];

        // 深度合并配置
        return array_replace_recursive($defaultConfig, $config);
    }

    /**
     * 解析调试开关（全局 env() 的守卫式兜底）
     *
     * 为什么需要这个守卫：
     * ThinkPHP 的全局助手函数 env() 定义在 vendor/topthink/framework/src/helper.php，
     * 而该包的 composer.json 里 autoload.files 为空 —— 它由主应用引导阶段
     * （think\App::initialize() 里的 include_once）载入，composer 并不会自动加载它。
     * 插件被当作独立包运行（自带 vendor 与 autoloader，例如插件自身的 PHPUnit）时，
     * 全局命名空间里没有 env()，直接调用会抛 \Error；而 tests/TestCase.php 里
     * catch (\Exception $e) 接不住 \Error（Error 不继承 Exception），错误会直接冒到 PHPUnit。
     * 所以这里先探测函数是否存在：宿主应用内行为完全不变，独立运行时退化为读环境变量。
     *
     * 注意：不能用 autoload.files 注册全局 env() polyfill —— 那会与 ThinkPHP helper.php
     * 里的同名函数冲突，谁先加载谁生效，属于隐埋的雷。
     *
     * 取值顺序：先 $_ENV 再 getenv()。PHPUnit 的 <env> 注入与 php.ini 里 variables_order
     * 含 E 时（本仓库容器即 EGPCS）都写进 $_ENV，而 putenv() 与容器/Shell 环境只进 getenv()，
     * 两者合起来取才不会漏。
     *
     * @return bool
     */
    private static function resolveDebugFlag(): bool
    {
        // 宿主应用内：与改造前完全一致，仍走 ThinkPHP 的 env()
        if (function_exists('env')) {
            return (bool) env('APP_DEBUG', false);
        }

        // 插件独立运行时：读环境变量，未设置即视为 false
        $value = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');

        if ($value === false || $value === null) {
            return false;
        }

        // 环境变量是字符串，'false' / '0' / '' 直接 (bool) 会被当成 true，必须按布尔语义解析
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * 获取外部容器引用
     *
     * @param array $config 配置
     * @return \Psr\Container\ContainerInterface|null
     */
    private static function getExternalContainer(array $config): ?\Psr\Container\ContainerInterface
    {
        // 如果配置中指定了外部容器
        if (isset($config['container']) && $config['container'] instanceof \Psr\Container\ContainerInterface) {
            return $config['container'];
        }

        // 如果启用了外部容器集成，尝试获取ThinkPHP容器
        if ($config['external_container'] ?? true) {
            try {
                // 检查是否存在ThinkPHP容器
                if (class_exists(ThinkContainer::class)) {
                    $thinkContainer = ThinkContainer::getInstance();
                    if ($thinkContainer instanceof \Psr\Container\ContainerInterface) {
                        return $thinkContainer;
                    }
                }
            } catch (\Throwable $e) {
                // 静默处理，获取失败不影响主要功能
                error_log(sprintf('工作流引擎：无法获取ThinkPHP容器: %s', $e->getMessage()));
            }
        }

        return null;
    }

    /**
     * 设置服务配置
     *
     * @param array $config 配置数组
     * @return void
     */
    public static function setServiceConfig(array $config): void
    {
        self::$serviceConfig = $config;
    }

    /**
     * 获取服务配置
     *
     * @return array
     */
    public static function getServiceConfig(): array
    {
        return self::$serviceConfig;
    }

    /**
     * 获取核心工作流引擎实例
     *
     * @return \Alkaid\Workflow\Engine\interface\IProcessEngines
     */
    public static function getProcessEngines(): \Alkaid\Workflow\Engine\interface\IProcessEngines
    {
        return self::getContainer()->getProcessEngines();
    }

    /**
     * 获取工作流定义服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
     */
    public static function getProcessDefineService(): \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
    {
        return self::getContainer()->getProcessDefineService();
    }

    /**
     * 获取工作流实例服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
     */
    public static function getProcessInstanceService(): \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
    {
        return self::getContainer()->getProcessInstanceService();
    }

    /**
     * 获取工作流任务服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
     */
    public static function getProcessTaskService(): \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
    {
        return self::getContainer()->getProcessTaskService();
    }

    /**
     * 重置工厂状态
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::$container !== null) {
            self::$container->reset();
            self::$container = null;
        }
        self::$serviceConfig = [];
    }

    /**
     * 检查工厂是否已初始化
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$container !== null && self::$container->isInitialized();
    }
}
