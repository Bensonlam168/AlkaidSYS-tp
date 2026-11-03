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

namespace Alkaid\Workflow\Engine\libs\traits;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\interface\services\IProcessCcInstanceService;
use Alkaid\Workflow\Engine\interface\services\IProcessDefineService;
use Alkaid\Workflow\Engine\interface\services\IProcessDesignService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\interface\services\IProcessTypeService;

trait ProcessServiceTrait
{
    public function processDefineService(): ?IProcessDefineService
    {
        return ServiceContext::find(IProcessDefineService::class);
    }

    public function processInstanceService(): ?IProcessInstanceService
    {
        return ServiceContext::find(IProcessInstanceService::class);
    }

    public function processInstanceHistoryService(): ?IProcessInstanceHistoryService
    {
        return ServiceContext::find(IProcessInstanceHistoryService::class);
    }

    public function processTaskService(): ?IProcessTaskService
    {
        return ServiceContext::find(IProcessTaskService::class);
    }

    public function processTaskHistoryService(): ?IProcessTaskHistoryService
    {
        return ServiceContext::find(IProcessTaskHistoryService::class);
    }

    public function processTypeService(): ?IProcessTypeService
    {
        return ServiceContext::find(IProcessTypeService::class);
    }

    public function processCcInstanceService(): ?IProcessCcInstanceService
    {
        return ServiceContext::find(IProcessCcInstanceService::class);
    }

    public function processDesignService(): ?IProcessDesignService
    {
        return ServiceContext::find(IProcessDesignService::class);
    }
}
