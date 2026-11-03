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

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IExecution;
use Alkaid\Workflow\Engine\interface\nodes\IDecisionModel;
use Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait;
use Alkaid\Workflow\Engine\libs\utils\Expression;
use Alkaid\Workflow\Engine\enums\err\LfErrEnum;

class DecisionModel extends NodeModel implements IDecisionModel
{
    use DynamicPropsTrait;

    private string|null $expr; // 决策表达式
    private string|null $handleClass; // 决策处理类

    public function exec(IExecution $execution)
    {
        // 执行决策节点自定义执行逻辑
        $isFound      = false;
        $nextNodeName = null;

        //判断节点处理
        if (isset($this->expr) && !empty($this->expr)) {
            $obj          = Expression::eval($this->expr, $execution->getArgs());
            $nextNodeName = (string)$obj;
        } elseif (isset($this->handleClass) && !empty($this->handleClass)) {
            $decisionHandler = ServiceContext::find($this->handleClass);
            $nextNodeName    = $decisionHandler->decide($execution);
        }

        //边线表达式处理
        foreach ($this->outputs as $transitionModel) {
            if ($transitionModel->getExpr() !== null && (bool)Expression::eval($transitionModel->getExpr(), $execution->getArgs())) {
                // 决策节点输出边存在表达式，则使用输出边的表达式，true则执行
                $isFound = true;
                $transitionModel->setEnabled(true);
                $transitionModel->execute($execution);
            } elseif ($transitionModel->getTo() === $nextNodeName) {
                // 找到对应的下一个节点
                $isFound = true;
                $transitionModel->setEnabled(true);
                $transitionModel->execute($execution);
            }
        }

        if (!$isFound) {
            // 找不到下一个可执行路线
            throw new LFlowException('Not found next node', LfErrEnum::NOT_FOUND_NEXT_NODE->value);
        }
    }
}
