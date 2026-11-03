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
 * 模型行为
 *
 * @author Mr.April
 * @since  1.0
 */
interface IActionInterface
{
    public function execute(Execution $execution): void;
}
