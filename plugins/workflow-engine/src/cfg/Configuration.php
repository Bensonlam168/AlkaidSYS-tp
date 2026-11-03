<?php

/**
 *+------------------
 * ingenious
 *+------------------
 * Copyright (c) https://gitcode.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Software Registration Number: 2024SR0694589
 * Official Website: https://madong.tech
 */

namespace Alkaid\Workflow\Engine\cfg;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\event\ProcessEventService;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IConfiguration;
use Alkaid\Workflow\Engine\libs\utils\Logger;
use Alkaid\Workflow\Engine\parser\handler\CustomParser;
use Alkaid\Workflow\Engine\parser\handler\DecisionParser;
use Alkaid\Workflow\Engine\parser\handler\EndParser;
use Alkaid\Workflow\Engine\parser\handler\ForkParser;
use Alkaid\Workflow\Engine\parser\handler\JoinParser;
use Alkaid\Workflow\Engine\parser\handler\StartParser;
use Alkaid\Workflow\Engine\parser\handler\TaskParser;
use Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser;
use Alkaid\Workflow\Engine\scheduling\SchedulerIProcessEventListener;

/**
 *
 * 流程引擎配置类
 * @author Mr.April
 * @since  1.0
 */
class Configuration implements IConfiguration
{
    public function __construct($config = [])
    {
        try {
            $dependencies = $config['service_dependencies'] ?? [];//依赖注入
            $dependency   = $config['dynamic_dependencies'] ?? [];//动态依赖
            $extensions   = $config['extension_parameters'] ?? [];//扩展参数
            ServiceContext::setContext($dependencies);//注册服务
            ServiceContext::registerBatch($dependency);
            ServiceContext::extensions($extensions);
            ServiceContext::setBackupConfig($config);
            $this->defaultService();
        } catch (\Throwable $e) {
            Logger::debug($e->getMessage());
            throw new LFlowException($e->getMessage(), [], $e->getCode());
        }
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        // 获取当前脚本的绝对路径
        $scriptPath = $_SERVER['SCRIPT_FILENAME'];
        // 获取当前脚本所在的目录路径
        $scriptDir = dirname($scriptPath);
        // 获取根目录的路径
        $rootPath = realpath($scriptDir . '/../');
        //根目录下的config目录
        $file = $rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ingenious.php';
        if (!file_exists($file)) {
            throw new LFlowException('Add the engine configuration file first!');
        }
        $ret = require($file);
        return $ret[$key] ?? $default;
    }

    /**
     * 默认引擎服务注册
     */
    private function defaultService(): void
    {
        $parse = [
            'decision'     => DecisionParser::class,
            'end'          => EndParser::class,
            'fork'         => ForkParser::class,
            'join'         => JoinParser::class,
            'start'        => StartParser::class,
            'task'         => TaskParser::class,
            'custom'       => CustomParser::class,
            'wfSubProcess' => WfSubProcessParser::class,
        ];
        $event = [
            'processEventService'           => ProcessEventService::class,
            'schedulerProcessEventListener' => SchedulerIProcessEventListener::class,
        ];
        ServiceContext::registerBatch(array_merge($parse, $event));
    }
}
