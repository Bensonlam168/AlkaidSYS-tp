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

namespace Alkaid\Workflow\Engine\interface;

use Alkaid\Workflow\Engine\Core\Execution;

/**
 * 流程节点-拦截器
 *
 * @author Mr.April
 * @since  1.0
 */
interface IProcessNodeInterceptor
{
    /**
     * 拦截方法，参数为执行对象
     *
     * @param \Alkaid\Workflow\Engine\Core\Execution $execution
     */
    public function intercept(Execution $execution);
}
