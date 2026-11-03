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

use Alkaid\Workflow\Engine\model\ForkModel;
use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\NodeModel;
use Alkaid\Workflow\Engine\parser\AbstractINodeParser;

class ForkParser extends AbstractINodeParser
{
    public function parseNode(LfNode $lfNode): void
    {

    }

    public function newModel(): NodeModel
    {
        return new ForkModel();

    }
}
