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
use Alkaid\Workflow\Engine\interface\nodes\ITaskModel;
use Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait;
use Alkaid\Workflow\Engine\libs\utils\Logger;
use Alkaid\Workflow\Engine\libs\utils\StringHelper;
use Alkaid\Workflow\Engine\processor\handler\CountersignHandler;
use Alkaid\Workflow\Engine\enums\CountersignTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskPerformTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskTypeEnum;

/**
 * @method getName()
 * @method getDisplayName()
 * @method getExpireTime()
 * @method getAssignee()
 * @method getAssigneeFormKey()
 * @method getAssignmentHandler()
 * @method getGroupKey()
 * @method getPerformType()
 * @method getCountersignType()
 * @method getTaskType()
 * @method getExt()
 * @method getCandidateHandler()
 * @method getReminderTime()
 * @method getReminderRepeat()
 * @method getAutoExecute()
 */
class TaskModel extends NodeModel implements ITaskModel
{
    use DynamicPropsTrait;

    private string|null $form; // 表单标识
    private string|null $assignee; // 参与人
    private string|null $assigneeFormKey; // 参与人表单key
    private string|null $groupKey; // 参与组用户标识
    private string|null $assignmentHandler; // 参与人处理类
    private string|int|ProcessTaskTypeEnum $taskType; // 任务类型(主办/协办)
    private string|int|ProcessTaskPerformTypeEnum $performType; // 参与类型(普通参与/会签参与)
    private string|null $reminderTime; // 提醒时间
    private string|null $reminderRepeat; // 重复提醒间隔
    private string|null $expireTime; // 期待任务完成时间变量key
    private string|null $autoExecute; // 到期是否自动执行Y/N
    private string|null $callback; // 自动执行回调类
    private mixed $ext; // 自定义扩展属性
    // 候选用户标识
    //
    // 为什么类型是 `string|array|null`：LogicFlow 的 properties 里这两项**两种形态都有人写** ——
    // 设计器导出的是 `["role:admin"]`（数组），手写/历史数据常是 `"5,7"`（逗号串）。
    // 声明成 `string|null` 时，一旦配置是数组，`DynamicPropsTrait::handleSet()` 就会抛
    // `TypeError: Cannot assign array to property ... of type ?string` —— 即「配了数组的节点
    // 一执行就崩」。解析器（DefaultTaskActorResolver）两种形态都吃，这里如实放开类型。
    private string|array|null $candidateUsers; // ext.getStr("candidateUsers");
    // 候选用户组标识
    private string|array|null $candidateGroups; // ext.getStr("candidateGroups");
    // 候选用户处理类字符串
    private string|null $candidateHandler; // ext.getStr("candidateHandler");
    // 会签类型 PARALLEL表示并行会签，SEQUENTIAL表示串行会签
    private string|int|CountersignTypeEnum $countersignType;
    // 会签完成条件
    /**
     * ● 全部完成：为空
     * ● 按数量通过：#nrOfCompletedInstances==n，这里表示n人完成任务，会签结束。
     * ● 按比例通过：#nrOfCompletedInstances/nrOfInstances==n，这里表示已完成会签数与总实例数达到一定比例时，会签结束
     * ● 一票通过：#nrOfCompletedInstances==1，这里表示1人完成任务，会签结束。
     * ● 一票否决：ONE_VOTE_VETO
     */
    private string|null $countersignCompletionCondition;

    public function exec(IExecution $execution): void
    {
        $sourceId = $execution->getProcessInstanceId() ?? '';
        Logger::debug('taskModel:' . ' 执行流程实例ID=' . $sourceId . '节点=' . $this->getName() ?? '');
        $performType = $this->getPerformType() ?? ProcessTaskPerformTypeEnum::NORMAL->value;
        if (StringHelper::equalsIgnoreCase(ProcessTaskPerformTypeEnum::COUNTERSIGN->value, $performType)) {
            // 会签任务处理
            $this->fire(new CountersignHandler($this), $execution);
            if ($execution->getMerged()) {
                $this->runOutTransition($execution);
            }
        } else {
            // 普通任务处理
            $this->runOutTransition($execution);
        }
    }
}
