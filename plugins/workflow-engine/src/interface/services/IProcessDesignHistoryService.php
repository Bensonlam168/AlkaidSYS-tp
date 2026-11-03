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

namespace Alkaid\Workflow\Engine\interface\services;

use Alkaid\Workflow\Engine\interface\model\IProcessDesignHistory;

interface IProcessDesignHistoryService
{
    public function created(object $param): ?IProcessDesignHistory;

    public function list(object $param): array;

    public function findById(string $id): ?IProcessDesignHistory;

}
