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

use Alkaid\Workflow\Engine\model\DecisionModel;
use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\parser\AbstractINodeParser;
use Alkaid\Workflow\Engine\parser\INodeParser;

class DecisionParser extends AbstractINodeParser
{
    public function parseNode(LfNode $lfNode): void
    {
        $model      = $this->nodeModel; // 假设 nodeModel 是类的一个属性
        $properties = $lfNode->getProperties(); // 假设 getProperties 是 LfNode 类的一个方法
        $model->setExpr($properties->get(INodeParser::EXPR_KEY));
        $model->setHandleClass($properties->get(INodeParser::HANDLE_CLASS_KEY));
    }

    public function newModel(): NodeModel
    {
        return new DecisionModel();
    }

}
