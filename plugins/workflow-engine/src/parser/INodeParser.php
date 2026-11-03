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

namespace Alkaid\Workflow\Engine\parser;

use Alkaid\Workflow\Engine\model\logicflow\LfEdge;
use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\NodeModel;

interface INodeParser
{
    public const NODE_NAME_PREFIX = 'ingenious:'; // 节点名称前辍
    public const TEXT_VALUE_KEY = 'value'; // 文本值
    public const WIDTH_KEY = 'width'; // 节点宽度
    public const HEIGHT_KEY = 'height'; // 节点高度
    public const PRE_INTERCEPTORS_KEY = 'pre_interceptors'; // 前置拦截器
    public const POST_INTERCEPTORS_KEY = 'post_interceptors'; // 后置拦截器
    public const EXPR_KEY = 'expr'; // 表达式key
    public const HANDLE_CLASS_KEY = 'handle_class'; // 表达式处理类
    public const FORM_KEY = 'form'; // 表单标识
    public const ASSIGNEE_KEY = 'assignee'; // 参与人
    public const ASSIGNEE_FORM_KEY = 'assignee_form_key'; // 参与人表单key
    public const GROUP_KEY = 'group_key'; // 参与用户组
    public const ASSIGNMENT_HANDLE_KEY = 'assignment_handler'; // 参与人处理类
    public const TASK_TYPE_KEY = 'task_type'; // 任务类型(主办/协办)
    public const PERFORM_TYPE_KEY = 'perform_type'; // 参与类型(普通参与/会签参与)
    public const REMINDER_TIME_KEY = 'reminder_time'; // 提醒时间
    public const REMINDER_REPEAT_KEY = 'reminder_repeat'; // 重复提醒间隔
    public const EXPIRE_TIME_KEY = 'expire_time'; // 期待任务完成时间变量key
    public const AUTH_EXECUTE_KEY = 'auto_execute'; // 到期是否自动执行Y/N
    public const CALLBACK_KEY = 'callback'; // 自动执行回调类
    public const EXT_FIELD_KEY = 'field'; // 自定义扩展属性
    public const EXT_FIELD_CANDIDATE_USERS_KET = 'candidate_ext_users';//候选人
    public const EXT_FIELD_CANDIDATE_GROUPS_KEY = 'candidate_ext_groups';//候选人组
    public const EXT_FIELD_CANDIDATE_HANDLER_KEY = 'candidate_ext_handler';//候选人处理类
    public const EXT_FIELD_COUNTERSIGN_TYPE_KEY = 'countersign_type'; // 会签类型
    public const EXT_FIELD_COUNTERSIGN_COMPLETION_CONDITION_KEY = 'countersign_completion_condition'; // 会签完成条件
    public const CLASS_KEY = 'clazz'; // 类路径
    public const METHOD_NAME_KEY = 'method_name'; // 方法名
    public const ARGS_KEY = 'args'; // 方法入参
    public const RETURN_VAL_KEY = 'val'; // 返回变量名
    public const VERSION_KEY = 'version'; // 版本号

    /**
     * 节点属性解析方法，由解析类完成解析
     *
     * @param \Alkaid\Workflow\Engine\model\logicflow\LfNode       $lfNode LogicFlow节点对象
     * @param \Alkaid\Workflow\Engine\model\logicflow\LfEdge|array $edges  所有边对象
     */
    public function parse(LfNode $lfNode, LfEdge|array $edges): void;

    /**
     * 解析完成后，提供返回NodeModel对象
     */
    public function getModel(): NodeModel;

}
