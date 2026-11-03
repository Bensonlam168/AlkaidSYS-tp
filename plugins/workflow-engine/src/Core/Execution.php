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

namespace Alkaid\Workflow\Engine\Core;

use madong\interface\IDict;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\interface\model\IProcessInstance;
use Alkaid\Workflow\Engine\interface\model\IProcessTask;
use Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\model\ProcessModel;
use Alkaid\Workflow\Engine\enums\ProcessTaskStateEnum;

/**
 * @method setNodeModel(\Alkaid\Workflow\Engine\model\NodeModel $param)
 * @method setEngine($getEngine)
 * @method setProcessModel($pm)
 * @method setProcessInstance($parentInstance)
 * @method setProcessInstanceId($getData)
 * @method getProcessTaskList()
 * @method setProcessTask(\Alkaid\Workflow\Engine\interface\model\IProcessTask $processTask)
 * @method setProcessTaskId(string $processTaskId)
 * @method setOperator(string $operator)
 */
class Execution implements IExecution
{
    use DynamicPropsTrait;

    /**
     * @var string  流程实例ID
     */
    private string $processInstanceId;

    /**
     * @var string|int 当前流程任务ID
     */
    private string|int $processTaskId = 0;

    /**
     * 执行对象扩展参数
     *
     * @var \Alkaid\Workflow\Engine\interface\IDict|null
     */
    private ?IDict $args;

    /**
     *  当前流程模型
     */
    private ?ProcessModel $processModel;

    /**
     * 当前任务
     */
    private ?IProcessTask $processTask;

    /**
     * @var \Alkaid\Workflow\Engine\interface\model\IProcessInstance|null
     */
    private ?IProcessInstance $processInstance; //当前流程实例

    /**
     * @var array 所有任务集合
     */
    private array $processTaskList = [];

    /**
     * @var bool  是否可合并
     */
    private bool $isMerged;

    /**
     * @var \Alkaid\Workflow\Engine\interface\IProcessEngines|null  引擎实例
     */
    private ?IProcessEngines $engine;

    /**
     * @var string|null  操作人
     */
    private ?string $operator;

    /**
     * @var \Alkaid\Workflow\Engine\model\NodeModel|null  当前任务节点
     */
    private ?NodeModel $nodeModel;

    public function __construct()
    {
    }

    /**
     * 添加任务到任务集合
     *
     * @param IProcessTask $processTask
     */
    public function addTask(IProcessTask $processTask): void
    {
        $this->processTaskList[] = $processTask;
    }

    /**
     * 添加任务集合
     *
     * @param array $processTasks
     */
    public function addTasks(array $processTasks): void
    {
        $this->processTaskList = array_merge($this->processTaskList, $processTasks);
    }

    /**
     * 获取正在进行中的任务列表
     *
     * @return array
     */
    public function getDoingTaskList(): array
    {
        return array_filter($this->processTaskList, function (IProcessTask $task) {
            return $task->getData('task_state') === ProcessTaskStateEnum::DOING->value;
        });
    }

    public function setMerged(bool $isMerged): void
    {
        $this->isMerged = $isMerged;
    }

    public function getMerged(): bool
    {
        return $this->isMerged ?? false;
    }

    public function setArgs(IDict $args): void
    {
        $this->args = $args;
    }

    public function getArgs(): ?IDict
    {
        return $this->args ?? null;
    }


}
