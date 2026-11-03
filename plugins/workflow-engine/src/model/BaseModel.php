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

use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait;
use Alkaid\Workflow\Engine\processor\IHandler;

class BaseModel
{
    use DynamicPropsTrait;

    /**
     * 元素名称
     */
    protected string $name = '';

    /**
     * 显示名称
     */
    protected string $display_name = '';

    /**
     * 将执行对象execution交给具体的处理器处理
     *
     * @param \Alkaid\Workflow\Engine\processor\IHandler   $handler
     * @param \Alkaid\Workflow\Engine\interface\IExecution $execution
     */
    protected function fire(IHandler $handler, IExecution $execution): void
    {
        $handler->handle($execution);
    }

}
