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

use Alkaid\Workflow\Engine\cfg\Configuration;
use Alkaid\Workflow\Engine\ex\LFlowException;
use madong\interface\IDict;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\interface\model\IProcessInstance;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\libs\traits\ProcessServiceTrait;
use Alkaid\Workflow\Engine\libs\utils\ProcessFlowUtils;
use Alkaid\Workflow\Engine\libs\utils\StringHelper;
use Alkaid\Workflow\Engine\model\EndModel;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\model\TransitionModel;
use Alkaid\Workflow\Engine\parser\ModelParser;
use Alkaid\Workflow\Engine\enums\err\LfErrEnum;
use Alkaid\Workflow\Engine\enums\ProcessConstEnum;
use Alkaid\Workflow\Engine\enums\ProcessInstanceStateEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskStateEnum;

class ProcessEngines implements IProcessEngines
{
    use ProcessServiceTrait;

    public ?Configuration $configuration;

    public function __construct($config = [])
    {
        $this->configure($config);
        return $this;
    }

    public function configure($config): void
    {
        try {
            $this->configuration = new Configuration($config);
        } catch (\Throwable $e) {
            throw new LFlowException($e->getMessage());
        }
    }

    public function startProcessInstanceById(string $id, string $operator, IDict $args, string|null $parentId = null, string|null $parentNodeName = null): ?IProcessInstance
    {
        // 1. 根据流程定义ID查询流程定义文件

        $processDefine = $this->processDefineService()->getById($id);
        if (empty($processDefine)) {
            throw new LFlowException('缺少流程定义');
        }

        // 2. 将流程定义文件转成流程模型
        $processModel = $this->processDefineService()->processDefineToModel($processDefine);

        // 3. 根据流程定义对象创建流程实例
        $processInstance = $this->processInstanceService()->createProcessInstance($processDefine, $operator, $args, $parentId, $parentNodeName);

        // 3.1根据返回实例创建历史流程实例
        //
        // 守卫式调用，而不是 `$this->processInstanceHistoryService()->createHistoryProcessInstance(...)`：
        // 历史表（workflow_instance_history）**尚未建立**，IProcessInstanceHistoryService 也还是
        // 空接口（方法声明全被注释），插件没有它的实现类。这种情况下
        // `processInstanceHistoryService()` 返回 null（或宿主未装配好时返回非对象），
        // 无条件调用会抛 `Error: Call to a member function createHistoryProcessInstance() on null` ——
        // 实例已经落库、状态 running，却连第一个任务都生成不出来，流程死在第一步。
        //
        // 口径：历史记录是**审计旁路**，缺服务时跳过、记一条日志，主流程照常推进。
        // 将来补上真实实现（含建表迁移）后，这里无需改动即可自动恢复历史写入。
        $processInstanceHistoryService = $this->processInstanceHistoryService();
        if (
            is_object($processInstanceHistoryService)
            && method_exists($processInstanceHistoryService, 'createHistoryProcessInstance')
        ) {
            $processInstanceHistoryService->createHistoryProcessInstance($processInstance);
        } else {
            $this->logHistoryServiceSkipped(IProcessInstanceHistoryService::class, $processInstanceHistoryService);
        }

        // 4. 构建执行参数对象
        $execution = new Execution();
        $execution->setProcessModel($processModel);
        $execution->setProcessInstance($processInstance);
        $execution->setProcessInstanceId($processInstance->getData('id'));
        $execution->setEngine($this);
        $execution->setArgs($args);

        // 5. 拿到开始节点模型，调用其execute方法
        $processModel->getStart()->execute($execution);
        return $processInstance;
    }

    /**
     * 记录「历史服务缺失，已跳过历史记录」的日志（旁路能力，绝不影响主流程）
     *
     * 为什么不静默跳过：与静默崩溃一样，「这次没有历史记录」如果连一条日志都不留，
     * 事后无法区分「本来就没配」与「配了但没生效」，排查成本极高。
     *
     * 为什么不用插件自带的 libs\utils\Logger：它硬依赖 monolog，而 monolog 不在插件的
     * composer 依赖里（实测独立运行时报 `Class "Monolog\Logger" not found`），
     * 且它读的 `logs_path` 从来没有被任何配置写入 —— 调用它会先 mkdir(null) 再 fatal。
     * 用一个会崩的日志器去记录「跳过」，比不记录更糟。因此沿用
     * WorkflowBootstrap::logWorkflow() 的两级口径：宿主 ThinkPHP 日志优先，否则降级 error_log()。
     *
     * @param string $interface 缺失的服务接口（日志里用于定位）
     * @param mixed  $resolved  实际取到的值（null / 非对象 / 没有目标方法的对象）
     * @return void
     */
    private function logHistoryServiceSkipped(string $interface, mixed $resolved): void
    {
        $message = sprintf(
            '未配置工作流历史服务（%s，实际取到 %s），已跳过历史记录；流程继续推进',
            $interface,
            get_debug_type($resolved)
        );

        try {
            if (class_exists(\think\facade\Log::class)) {
                \think\facade\Log::channel('workflow')->info($message);
                return;
            }
        } catch (\Throwable) {
            // 宿主日志不可用（插件独立运行 / 未配置 log 通道）：继续走下面的降级分支
        }

        error_log('[workflow-engine][INFO] ' . $message);
    }

    public function executeProcessTask(string|int $processTaskId, string|int $operator, IDict $args): array
    {
        $execution = $this->execute($processTaskId, $operator, $args);

        if ($execution == null) {
            return [];
        }

        $processModel = $execution->getProcessModel();

        // 7. 根据流程任务名称获取对应的任务节点模型
        $nodeModel = $processModel->getNode($execution->getProcessTask()->getData('task_name'));
        // 8. 调用节点模型执行方法

        $nodeModel->execute($execution);
        return $execution->getProcessTaskList();
    }

    public function executeAndJumpTask(string $processTaskId, string $operator, IDict $args, string $nodeName = ''): array
    {
        $execution = $this->execute($processTaskId, $operator, $args);
        if ($execution == null) {
            return [];
        }
        $model = $execution->getProcessModel();
        if (empty($nodeName)) {
            $newTask = $this->processTaskService()->rejectTask($model, $execution->getProcessTask());
            $execution->addTask($newTask);
        } else {
            $nodeModel = $model->getNode($nodeName);
            if ($nodeModel == null) {
                throw new LFlowException([99999999, '根据节点名称[' . $nodeName . ']无法找到节点模型']);
            }
            // 判断是否为第一个任务节点
            if ($nodeModel instanceof TaskModel) {
                $taskModel = $nodeModel;
                if (ProcessFlowUtils::isFistTaskName($model, $taskModel->getName())) {
                    // 第一个任务节点为申请节点，处理人等于流程发起人
                    $taskModel->setAssignee($execution->getProcessInstance()->getData('operator'));
                }
            }
            //动态创建转移对象，由转移对象执行execution实例
            $tm = new TransitionModel();
            $tm->setTarget($nodeModel);
            $tm->setEnabled(true);
            $tm->execute($execution);
        }
        return $execution->getProcessTaskList();
    }

    public function executeAndJumpToEnd(string $processTaskId, string $operator, IDict $args): array
    {
        $execution = $this->execute($processTaskId, $operator, $args);
        if ($execution == null) {
            return [];
        }
        $model        = $execution->getProcessModel();
        $endModelList = $model->getModels(EndModel::class);
        foreach ($endModelList as $endModel) {
            $tm = new TransitionModel();
            $tm->setTarget($endModel);
            $tm->setEnabled(true);
            $tm->execute($execution);
        }
        // 将流程状态修改为已拒绝
        $processInstance = new ProcessInstance();
        $processInstance->set('id', $execution->getProcessInstanceId());
        $processInstance->set('state', ProcessInstanceStateEnum::REJECT->value);
        $processInstance->set('update_time', time());
        $processInstance->set('update_user', $operator);
        return $execution->getProcessTaskList();
    }

    public function executeAndJumpToFirstTaskNode(string $processTaskId, string $operator, IDict $args): array
    {
        $execution = $this->execute($processTaskId, $operator, $args);
        if ($execution == null) {
            return [];
        }
        $model      = $execution->getProcessModel();
        $startModel = $model->getStart();
        foreach ($startModel->getOutputs() as $transitionModel) {
            $transitionModel->setEnabled(true);
            // 调整参与者为流程发起人
            if ($transitionModel->getTarget() instanceof TaskModel) {
                $taskModel = $transitionModel->getTarget();
                $taskModel->setAssignee($execution->getProcessInstance()->getData('operator'));
            }
            $transitionModel->execute($execution);
        }
        return $execution->getProcessTaskList();
    }

    private function execute(string $processTaskId, string $operator, IDict $args): ?IExecution
    {
        // 1.1 根据id查询正在进行中的流程任务
        $processTask = $this->processTaskService()->getById($processTaskId);

        if ($processTask == null || !StringHelper::equalsIgnoreCase((string)ProcessTaskStateEnum::DOING->value, (string)$processTask->getData('task_state'))) {
            throw new LFlowException(LfErrEnum::NOT_FOUND_DOING_PROCESS_TASK->value);
        }

        // 1.2 判断是否可以执行任务
        if (!$this->processTaskService()->isAllowed($processTask, $operator)) {
            // 当前参与者不能执行该流程任务
            throw new LFlowException(LfErrEnum::NOT_ALLOWED_EXECUTE->value);
        }
        // 2. 根据流程任务查询流程实例
        $processInstance = $this->processInstanceService()->getById($processTask->getData('process_instance_id'));
        // 3. 根据流程实例查询流程定义
        $processDefine = $this->processDefineService()->getById($processInstance->getData('process_define_id'));
        // 4. 将流程定义文件转成流程模型
        $processModel = ModelParser::parse($processDefine->getData('content'));
        // 5. 将流程任务状态修改为已完成
        $this->processTaskService()->finishProcessTask($processTaskId, $operator, $args);
        $processTask->set('task_state', ProcessTaskStateEnum::FINISHED->value);
        // 6. 根据流程定义、实例、任务构建执行参数对象
        $execution = new Execution();
        $execution->setProcessModel($processModel);
        $execution->setProcessInstance($processInstance);
        $execution->setProcessInstanceId($processInstance->getData('id'));
        $execution->setProcessTask($processTask);
        $execution->setProcessTaskId($processTaskId);
        $execution->setOperator($operator);
        $execution->setEngine($this);

        $processInstanceVariable = $processInstance->getData('variable');
        $newArgs                 = ProcessFlowUtils::variableToDict($processInstanceVariable);
        $newArgs->putAll($args->getAll());
        $execution->setArgs($newArgs);
        // 如果提交参数中存在f_前辍参数，则更新到流程实例变量中
        $prefixArgs = array_filter($args->toArray(), function ($value, $key) {
            if (str_starts_with($key, ProcessConstEnum::FORM_DATA_PREFIX->value)) {
                return true; // 返回 false 以便从过滤结果中排除这些键值对
            }
            return false; // 其他键值会被包含在过滤结果中（但在这个上下文中我们不关心它们）
        }, ARRAY_FILTER_USE_BOTH); // 使用 ARRAY_FILTER_USE_BOTH 同时传递键和值给回调函数

        $addsArgs = ProcessFlowUtils::variableToDict($prefixArgs);
        if (!empty($addsArgs->getAll())) {
            $this->processInstanceService()->addVariable($processInstance->getData('id'), $addsArgs);
        }
        return $execution;
    }

}
