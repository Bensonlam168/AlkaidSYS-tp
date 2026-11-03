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

use Alkaid\Workflow\Engine\model\CustomModel;
use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\parser\AbstractINodeParser;
use Alkaid\Workflow\Engine\parser\INodeParser;
use Alkaid\Workflow\Engine\enums\ProcessConstEnum;

class CustomParser extends AbstractINodeParser
{
    public function parseNode(LfNode $lfNode): void
    {
        $model      = $this->nodeModel; // 假设 nodeModel 是类的一个属性
        $properties = $lfNode->getProperties(); // 假设 getProperties 是 LfNode 类的一个方法
        //自定义方法如果有参数进行设置
        $model->setClazz($properties->get(INodeParser::CLASS_KEY)); // 假设 getStr 是 Properties 类的一个方法
        $model->setMethodName($properties->get(INodeParser::METHOD_NAME_KEY));
        $model->setArgs($properties->get(INodeParser::ARGS_KEY));
        $model->setVar($properties->get(INodeParser::RETURN_VAL_KEY, ProcessConstEnum::CUSTOM_RETURN_VAL->value));

    }

    public function newModel(): NodeModel
    {
        return new CustomModel();

    }
}
