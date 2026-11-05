<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use think\facade\Log;

/**
 * 工作流引擎引导类
 *
 * 负责在工作流引擎启动时进行初始化配置，
 * 包括依赖注入容器配置、ThinkPHP集成设置等。
 *
 * @package Alkaid\Workflow\Engine\Integration
 */
class WorkflowBootstrap
{
    /**
     * @var ThinkPHPAdapter|null ThinkPHP适配器实例
     */
    private static ?ThinkPHPAdapter $adapter = null;

    /**
     * @var bool 是否已初始化
     */
    private static bool $initialized = false;

    /**
     * 初始化工作流引擎
     *
     * @param array $config 自定义配置
     * @return ThinkPHPAdapter
     */
    public static function initialize(array $config = []): ThinkPHPAdapter
    {
        if (self::$initialized) {
            return self::getAdapter();
        }

        try {
            // 加载配置
            $workflowConfig = self::loadConfig($config);

            // 创建适配器
            self::$adapter = new ThinkPHPAdapter();

            // 初始化适配器
            self::$adapter->initialize($workflowConfig);

            // 设置已初始化标志
            self::$initialized = true;

            // 记录日志（守卫式：插件独立运行时没有宿主日志，降级到 error_log，绝不因此中断引导）
            self::logWorkflow('info', '工作流引擎初始化成功');

            return self::$adapter;

        } catch (\Throwable $e) {
            // 注意：本行位于 catch 内，若日志本身再抛异常，会把真实根因吞掉并逃逸出 initialize()，
            // 因此同样必须走守卫式日志。
            self::logWorkflow('error', '工作流引擎初始化失败: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(
                sprintf('工作流引擎初始化失败: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 加载工作流配置
     *
     * @param array $config 自定义配置
     * @return array
     */
    private static function loadConfig(array $config = []): array
    {
        // 加载默认配置
        $defaultConfig = [];

        // 加载配置文件
        // 守卫式：app() 是 ThinkPHP 的**全局助手函数**，插件独立运行时有两种缺失可能：
        //  1）该函数根本没被载入 —— topthink/framework 的 helper.php 不在本插件 vendor 的
        //     composer "files" 里，实测 function_exists('app') === false；
        //  2）函数在、但应用容器没启动，getAppPath() 拿不到路径。
        // 两种情况都应退化成「没有宿主配置文件」，而不是抛 Error 中断整个引导。
        $configFile = self::hostConfigFile();
        if ($configFile !== null && file_exists($configFile)) {
            $defaultConfig = include $configFile;
        }

        // 合并配置
        return array_merge($defaultConfig, $config);
    }

    /**
     * 解析宿主侧的 workflow.php 配置文件路径
     *
     * @return string|null 宿主应用存在时返回配置路径，否则 null
     */
    private static function hostConfigFile(): ?string
    {
        if (!function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (!is_object($app) || !method_exists($app, 'getAppPath')) {
                return null;
            }

            return $app->getAppPath() . 'config/workflow.php';
        } catch (\Throwable) {
            // 应用未启动或路径不可得：按「无宿主配置」处理
            return null;
        }
    }

    /**
     * 探测宿主是否提供了 ThinkPHP 日志能力
     *
     * ThinkPHP 的 Log 门面（think\facade\Log）并不直接是一个可用的日志器：
     * 它的 getFacadeClass() 返回容器标识 'log'，任何静态调用都会走到
     * think\Container::make('log')。插件被独立运行（单元测试、CLI 脚本）时
     * 应用容器没有启动，容器里没有 'log' 这个绑定，make() 于是抛
     * think\exception\ClassNotFoundException: class not exists: log。
     *
     * 因此这里做两级探测，而不是直接调用门面：
     * 1）class_exists —— 宿主可能压根没装 topthink/framework；
     * 2）Container::has('log') —— has() 只查绑定表（bound()），不会抛异常，
     *    可以安全地用来判断应用容器是否已把日志服务准备好。
     *
     * @return bool 宿主是否可安全使用 ThinkPHP 日志
     */
    private static function hostLogAvailable(): bool
    {
        if (!class_exists(Log::class)) {
            return false;
        }

        try {
            return \think\Container::getInstance()->has('log');
        } catch (\Throwable) {
            // 容器本身不可用（例如框架版本差异）：按「无宿主日志」处理
            return false;
        }
    }

    /**
     * 守卫式记录工作流日志
     *
     * 有宿主日志就走 ThinkPHP 日志通道 'workflow'；没有就降级为 PHP 原生 error_log()。
     * 之所以连「有宿主日志」的分支也用 try/catch 包住：日志属于旁路能力，
     * 任何写入失败都不应该改变引导流程的结果（尤其不能覆盖 initialize() catch 里的真实根因）。
     *
     * 未引入新依赖：降级目标选 error_log() 而不是 PSR-3 的 NullLogger ——
     * psr/log 只是 topthink/framework 的传递依赖，直接依赖它会在宿主换版本时变脆。
     *
     * 待定项：降级路径只保证「不抛异常、信息不丢失到进程外」，
     * 是否真正落盘取决于宿主的 error_log 配置，不做额外保证。
     *
     * @param string $level   日志级别（info/error/warning...）
     * @param string $message 日志内容
     * @param array  $context 附加上下文
     * @return void
     */
    private static function logWorkflow(string $level, string $message, array $context = []): void
    {
        if (self::hostLogAvailable()) {
            try {
                Log::channel('workflow')->{$level}($message, $context);
                return;
            } catch (\Throwable) {
                // 落不到宿主日志就继续走下面的降级分支，绝不上抛
            }
        }

        error_log(sprintf(
            '[workflow-engine][%s] %s%s',
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        ));
    }

    /**
     * 获取适配器实例
     *
     * @return ThinkPHPAdapter
     */
    public static function getAdapter(): ThinkPHPAdapter
    {
        if (!self::$adapter) {
            throw new \RuntimeException('工作流引擎尚未初始化，请先调用 initialize() 方法');
        }

        return self::$adapter;
    }

    /**
     * 获取工作流容器
     *
     * @return WorkflowContainer
     */
    public static function getContainer(): WorkflowContainer
    {
        return self::getAdapter()->getContainer();
    }

    /**
     * 获取工作流引擎实例
     *
     * @return \Alkaid\Workflow\Engine\interface\IProcessEngines
     */
    public static function getEngine(): \Alkaid\Workflow\Engine\interface\IProcessEngines
    {
        return self::getAdapter()->getEngine();
    }

    /**
     * 获取工作流定义服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
     */
    public static function getDefineService(): \Alkaid\Workflow\Engine\interface\services\IProcessDefineService
    {
        return self::getAdapter()->getDefineService();
    }

    /**
     * 获取工作流实例服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
     */
    public static function getInstanceService(): \Alkaid\Workflow\Engine\interface\services\IProcessInstanceService
    {
        return self::getAdapter()->getInstanceService();
    }

    /**
     * 获取工作流任务服务
     *
     * @return \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
     */
    public static function getTaskService(): \Alkaid\Workflow\Engine\interface\services\IProcessTaskService
    {
        return self::getAdapter()->getTaskService();
    }

    /**
     * 快速启动工作流实例
     *
     * @param string $definitionId 工作流定义ID
     * @param array $params 启动参数
     * @param int $operator 操作人ID
     * @return \Alkaid\Workflow\Engine\interface\model\IProcessInstance
     */
    public static function startWorkflow(string $definitionId, array $params, int $operator): \Alkaid\Workflow\Engine\interface\model\IProcessInstance
    {
        $engine = self::getEngine();

        return $engine->startProcessInstanceById($definitionId, (string)$operator, self::createEngineArgs($params));
    }

    /**
     * 快速执行工作流任务
     *
     * @param string $taskId 任务ID
     * @param array $params 执行参数
     * @param int $operator 操作人ID
     * @return array
     */
    public static function executeTask(string $taskId, array $params, int $operator): array
    {
        $engine = self::getEngine();

        return $engine->executeProcessTask($taskId, (string)$operator, self::createEngineArgs($params));
    }

    /**
     * 构造引擎要的参数字典
     *
     * 为什么不能直接用 `src/libs/utils/Dict.php`：
     * 引擎签名要求的是 `madong\interface\IDict`
     * （例如 `IProcessEngines::startProcessInstanceById(string, string, IDict $args)`），
     * 而插件自带的 `libs\utils\Dict` 实现的是**插件自己的** `interface\IDict`，
     * 两者不是同一个接口 —— 传进去会直接抛
     * `TypeError: Argument #3 ($args) must be of type madong\interface\IDict`。
     * 这正是改造前 startWorkflow()/executeTask() 的真实失败原因
     * （容器修好后它才暴露出来，之前被 TypeError: string returned 盖住了）。
     *
     * `madong\helper\Dict::__construct()` 不接收初始数据，参数必须用 putAll() 注入。
     *
     * @param array $params 参数
     * @return \madong\interface\IDict
     */
    private static function createEngineArgs(array $params): object
    {
        if (!class_exists(\madong\helper\Dict::class)) {
            throw new \RuntimeException(
                '工作流引擎参数类型缺失：需要 madong/helper（提供 madong\interface\IDict）。'
                . '宿主未安装该依赖或未加载插件的 vendor 目录，引擎无法被调用。'
            );
        }

        $args = new \madong\helper\Dict();
        $args->putAll($params);

        return $args;
    }

    /**
     * 检查是否已初始化
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized && self::$adapter !== null;
    }

    /**
     * 获取工作流统计信息
     *
     * @return array
     */
    public static function getStatistics(): array
    {
        if (!self::isInitialized()) {
            return [];
        }

        $container = self::getContainer();
        $config = $container->getConfig('extension_parameters', []);

        return [
            'initialized' => self::$initialized,
            'tenant_id' => $config['tenant_id'] ?? null,
            'debug_mode' => $config['debug'] ?? false,
            'container_ready' => $container->isInitialized(),
        ];
    }

    /**
     * 重置工作流引擎
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::$adapter) {
            self::$adapter->cleanup();
            self::$adapter = null;
        }

        // 重置服务工厂
        ServiceFactory::reset();

        self::$initialized = false;

        // 记录日志（同为守卫式：reset() 常被 tearDown 调用，不能因宿主无日志而抛异常）
        self::logWorkflow('info', '工作流引擎已重置');
    }

    /**
     * 健康检查
     *
     * @return array
     */
    public static function healthCheck(): array
    {
        $health = [
            'status' => 'ok',
            'timestamp' => time(),
            'checks' => [],
        ];

        try {
            // 检查容器状态
            $health['checks']['container'] = [
                'status' => self::isInitialized() ? 'ok' : 'fail',
                'message' => self::isInitialized() ? '容器已初始化' : '容器未初始化',
            ];

            // 检查核心服务
            if (self::isInitialized()) {
                $container = self::getContainer();
                $health['checks']['engine'] = [
                    'status' => $container->has('processEngines') ? 'ok' : 'fail',
                    'message' => $container->has('processEngines') ? '引擎服务可用' : '引擎服务不可用',
                ];

                $health['checks']['define_service'] = [
                    'status' => $container->has('processDefineService') ? 'ok' : 'fail',
                    'message' => $container->has('processDefineService') ? '定义服务可用' : '定义服务不可用',
                ];

                $health['checks']['instance_service'] = [
                    'status' => $container->has('processInstanceService') ? 'ok' : 'fail',
                    'message' => $container->has('processInstanceService') ? '实例服务可用' : '实例服务不可用',
                ];

                $health['checks']['task_service'] = [
                    'status' => $container->has('processTaskService') ? 'ok' : 'fail',
                    'message' => $container->has('processTaskService') ? '任务服务可用' : '任务服务不可用',
                ];
            }

            // 检查是否有失败的检查项
            foreach ($health['checks'] as $check) {
                if ($check['status'] === 'fail') {
                    $health['status'] = 'fail';
                    break;
                }
            }

        } catch (\Throwable $e) {
            $health['status'] = 'error';
            $health['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        // 清理资源
        self::reset();
    }
}
