<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Integration;

use Alkaid\Workflow\Engine\Container\ServiceFactory;
use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use think\facade\Config;
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

            // 记录日志
            Log::channel('workflow')->info('工作流引擎初始化成功');

            return self::$adapter;

        } catch (\Throwable $e) {
            Log::channel('workflow')->error('工作流引擎初始化失败: ' . $e->getMessage(), [
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
        $configFile = app()->getAppPath() . 'config/workflow.php';
        if (file_exists($configFile)) {
            $defaultConfig = include $configFile;
        }

        // 合并配置
        return array_merge($defaultConfig, $config);
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
        $args = new \madong\ingenious\libs\utils\Dict($params);

        return $engine->startProcessInstanceById($definitionId, (string)$operator, $args);
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
        $args = new \madong\ingenious\libs\utils\Dict($params);

        return $engine->executeProcessTask($taskId, (string)$operator, $args);
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

        Log::channel('workflow')->info('工作流引擎已重置');
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
