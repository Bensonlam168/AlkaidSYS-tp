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

namespace Alkaid\Workflow\Engine\processor\handler;

use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\IProcessNodeInterceptor;
use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\event\ProcessEventService;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\processor\IHandler;
use Alkaid\Workflow\Engine\enums\ProcessEventTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskPerformTypeEnum;

/**
 * 创建任务处理器
 *
 * @author Mr.April
 * @since  1.0
 */
class CreateTaskHandler implements IHandler
{
    private TaskModel $taskModel;

    public function __construct(TaskModel $taskModel)
    {
        $this->taskModel = $taskModel;
    }

    /**
     * handle
     * @param \Alkaid\Workflow\Engine\interface\IExecution $execution
     */
    public function handle(IExecution $execution): void
    {
        $processTaskList = [];
        // 根据任务类型创建任务
        if (ProcessTaskPerformTypeEnum::COUNTERSIGN->value === $this->taskModel->getPerformType() ?? '') {
            $processTaskList = $execution->getEngine()->processTaskService()->createCountersignTask($this->taskModel, $execution);
            //会签任务
        } else {
            //创建普通任务
            $processTaskList = $execution->getEngine()->processTaskService()->createTask($this->taskModel, $execution);
        }
        // 将任务添加到执行对象中
        $execution->addTasks($processTaskList);

        // 从服务容器中获取拦截器并执行
        $interceptors = ServiceContext::findAll(IProcessNodeInterceptor::class);
        foreach ($interceptors as $interceptor) {
            $interceptor->intercept($execution);
        }

        // 发布流程任务开始事件
        foreach ($processTaskList as $processTask) {
            ProcessEventService::publishNotification(ProcessEventTypeEnum::PROCESS_TASK_START->value, $processTask ->getData('id'));
        }
    }
}
