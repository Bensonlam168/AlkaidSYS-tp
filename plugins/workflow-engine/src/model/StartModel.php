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

namespace Alkaid\Workflow\Engine\model;

use Alkaid\Workflow\Engine\event\ProcessEventService;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\nodes\IStartModel;
use Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait;
use Alkaid\Workflow\Engine\libs\utils\Logger;
use Alkaid\Workflow\Engine\enums\ProcessEventTypeEnum;

/**
 * 开始-模型
 *
 * @author Mr.April
 * @since  1.0
 * @method getName()
 */
class StartModel extends NodeModel implements IStartModel
{
    use DynamicPropsTrait;

    public function exec(IExecution $execution): void
    {
        //执行开始节点逻辑
        $sourceId = $execution->getProcessInstanceId();
        Logger::debug('starModel:' . ' 执行流程实例ID=' . $sourceId . '节点=' . $this->getName() ?? '');

        //执行开始节点事件
        ProcessEventService::publishNotification(ProcessEventTypeEnum::PROCESS_INSTANCE_START->value, $execution->getProcessInstanceId());
        $this->runOutTransition($execution);
    }

}
