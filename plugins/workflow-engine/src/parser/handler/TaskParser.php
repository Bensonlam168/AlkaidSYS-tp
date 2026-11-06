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

namespace Alkaid\Workflow\Engine\parser\handler;

use madong\helper\Dict;
use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\parser\AbstractINodeParser;
use Alkaid\Workflow\Engine\parser\INodeParser;
use Alkaid\Workflow\Engine\enums\CountersignTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskPerformTypeEnum;
use Alkaid\Workflow\Engine\enums\ProcessTaskTypeEnum;

class TaskParser extends AbstractINodeParser
{
    public function parseNode(LfNode $lfNode): void
    {
        $model      = $this->nodeModel;
        $properties = $lfNode->getProperties();
        if (!empty($properties)) {
            $model->setForm($properties->get(INodeParser::FORM_KEY));
            // 参与者配置统一经 normalize*() 归一化，见方法注释（LogicFlow 里的数组会变成 stdClass）
            $model->setAssignee($this->normalizeScalarConfig($properties->get(INodeParser::ASSIGNEE_KEY)));
            $model->setAssigneeFormKey($properties->get(INodeParser::ASSIGNEE_FORM_KEY));
            $model->setGroupKey($properties->get(INodeParser::GROUP_KEY));
            $model->setAssignmentHandler($properties->get(INodeParser::ASSIGNMENT_HANDLE_KEY));
            $model->setTaskType(ProcessTaskTypeEnum::codeOf($properties->get(INodeParser::TASK_TYPE_KEY), ProcessTaskTypeEnum::MAJOR->value));
            $model->setPerformType(ProcessTaskPerformTypeEnum::codeOf($properties->get(INodeParser::PERFORM_TYPE_KEY) ?? 0, ProcessTaskPerformTypeEnum::NORMAL->value));
            $model->setReminderTime($properties->get(INodeParser::REMINDER_TIME_KEY));
            $model->setReminderRepeat($properties->get(INodeParser::REMINDER_REPEAT_KEY));
            $model->setExpireTime($this->normalizeScalarConfig($properties->get(INodeParser::EXPIRE_TIME_KEY)));
            $model->setAutoExecute($properties->get(INodeParser::AUTH_EXECUTE_KEY));
            $model->setCallback($properties->get(INodeParser::CALLBACK_KEY));
            // 解析候选人属性
            $model->setCandidateUsers($this->normalizeListConfig($properties->get(INodeParser::EXT_FIELD_CANDIDATE_USERS_KET)));
            $model->setCandidateGroups($this->normalizeListConfig($properties->get(INodeParser::EXT_FIELD_CANDIDATE_GROUPS_KEY)));
            $model->setCandidateHandler($properties->get(INodeParser::EXT_FIELD_CANDIDATE_HANDLER_KEY));
            // 解析会签属性
            $model->setCountersignType(CountersignTypeEnum::codeOf($properties->get(INodeParser::EXT_FIELD_COUNTERSIGN_TYPE_KEY), CountersignTypeEnum::PARALLEL->value));
            $model->setCountersignCompletionCondition($properties->get(INodeParser::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY));
            // 自定义扩展属性
            $field = $properties->get(INodeParser::EXT_FIELD_KEY);
            if ($field != null) {
                $ext = new Dict();
                $ext->putAll($field);
                $model->setExt($ext);
                // 解析候选人属性
                $model->setCandidateUsers($this->normalizeListConfig($properties->get(INodeParser::EXT_FIELD_CANDIDATE_USERS_KET, $ext->get(INodeParser::EXT_FIELD_CANDIDATE_USERS_KET, ''))));
                $model->setCandidateGroups($this->normalizeListConfig($properties->get(INodeParser::EXT_FIELD_CANDIDATE_GROUPS_KEY, $ext->get(INodeParser::EXT_FIELD_CANDIDATE_GROUPS_KEY, ''))));
                $model->setCandidateHandler($properties->get(INodeParser::EXT_FIELD_CANDIDATE_HANDLER_KEY, $ext->get(INodeParser::EXT_FIELD_CANDIDATE_HANDLER_KEY, '')));
                // 解析会签属性
                $model->setCountersignType(CountersignTypeEnum::codeOf($properties->get(INodeParser::EXT_FIELD_COUNTERSIGN_TYPE_KEY, $ext->get(INodeParser::EXT_FIELD_COUNTERSIGN_TYPE_KEY, ''))));
                $model->setCountersignCompletionCondition($properties->get(INodeParser::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY, $ext->get(INodeParser::EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY, '')));
            }
        }
    }

    public function newModel(): NodeModel
    {
        return new TaskModel();
    }

    /**
     * 归一化「单值」参与者配置（assignee / expire_time）
     *
     * ## 为什么必须在解析器里做这一步
     *
     * 节点的 `properties` 不是从 JSON 直接数组解出来的：`ModelParser::parse()` 走的是
     * `ArrayHelper::jsonToArray()` → `ArrayHelper::arrayToObject()`，
     * 于是 JSON 里的**数组会变成 `stdClass`**（键名是数字字符串）。
     *
     * 而 `TaskModel::$assignee` 声明为 `string|null`，给它塞 stdClass 会直接抛
     * `TypeError: Cannot assign stdClass to property ... of type string|null`
     * —— 换句话说，改造前**只要节点上配了数组形态的参与者，流程定义一解析就崩**。
     * 这就是「参与者配置形同虚设」的第一层原因（第二层是 buildTaskColumns() 丢字段）。
     *
     * 单值口径：数组/对象按顺序用 `,` 连接 —— 连出来的串恰好是解析器
     * （`DefaultTaskActorResolver`）认可的「多人配置」写法：首个当处理人，其余进候选集。
     *
     * @param mixed $value LogicFlow 属性原值（可能是 string / int / array / stdClass / null）
     */
    private function normalizeScalarConfig(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $tokens = [];

            foreach ($value as $item) {
                $token = $this->scalarToken($item);

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }

            return $tokens === [] ? null : implode(',', $tokens);
        }

        $token = $this->scalarToken($value);

        return $token === '' ? null : $token;
    }

    /**
     * 归一化「多值」参与者配置（candidate_ext_users / candidate_ext_groups）
     *
     * 与 normalizeScalarConfig() 同因：LogicFlow 的数组会以 `stdClass` 形态到达，
     * 而 `TaskModel::$candidateUsers` / `$candidateGroups` 声明为 `string|array|null`，
     * 塞 stdClass 同样抛 TypeError —— 也就是节点上配的候选人/候选人组**存不进模型**。
     *
     * 返回数组（顺序保持）或原样的字符串（兼容老配置里的「逗号分隔串」写法）；
     * 空集合返回 null，让解析器走「没配」的分支。
     *
     * @param mixed $value LogicFlow 属性原值
     * @return string|array|null
     */
    private function normalizeListConfig(mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            $token = $this->scalarToken($value);

            return $token === '' ? null : $token;
        }

        $tokens = [];

        foreach ($value as $item) {
            $token = $this->scalarToken($item);

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens === [] ? null : $tokens;
    }

    /**
     * 把单个配置项转成字符串 token（非标量用 JSON 编码保留原貌）
     *
     * 非标量**不做静默丢弃**：编码成 JSON 串交给下游解析器，
     * 它会因为「没有合法前缀」而报错/告警，而不是悄悄变成某个用户的 ID。
     */
    private function scalarToken(mixed $item): string
    {
        if ($item === null) {
            return '';
        }

        if (is_bool($item)) {
            return $item ? '1' : '0';
        }

        if (is_scalar($item)) {
            return trim((string) $item);
        }

        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

}
