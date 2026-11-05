<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;
use Alkaid\Workflow\Engine\parser\INodeParser;
use DOMDocument;
use DOMElement;

/**
 * BPMN 2.0 子集 ⇄ LogicFlow JSON 双向转换器
 *
 * ## 定位（定案前提，不要推翻）
 *
 * 工作流定义体的**唯一执行格式是 LogicFlow JSON**（`workflow_definitions.nodes_config`），
 * 引擎 `ModelParser::parse()` 只吃 `{nodes, edges}`。`bpmn_xml` 列保留为**导入/导出**用途，
 * **不作执行源**。本类就是把那一列从「只存不读」接上线的转换层：
 *
 * - `fromBpmn(string)` —— BPMN XML → LogicFlow `{nodes, edges}`，
 *   结果可直接 `ModelParser::parse()` 或写进 `nodes_config`
 *   （也能直接喂 {@see LogicFlowSerializer::fromLogicFlow()} 还原成主应用 `WorkflowDefinition`，
 *   因为本类刻意复用了它的保留键 `_alkaid_*` 与 `ingenious:` 类型前缀）；
 * - `toBpmn(array)` —— LogicFlow → BPMN XML，用于与 bpmn.io / 其它 BPMN 工具互操作。
 *
 * ## 支持的 BPMN 子集（其余元素一律抛 {@see BpmnConversionException}，不静默丢弃）
 *
 * | BPMN 元素 | 落到引擎解析器键 |
 * | --- | --- |
 * | `startEvent` | `start` |
 * | `endEvent` | `end` |
 * | `userTask` / `serviceTask` / `scriptTask` / `sendTask` | `task`（原 BPMN 类型记在 `properties._alkaid_bpmn_type`） |
 * | `exclusiveGateway` | `decision` |
 * | `parallelGateway` | 按出入边数：多出边 → `fork`，多入边 → `join` |
 * | `sequenceFlow` | 边（`sourceNodeId` / `targetNodeId`） |
 *
 * 元素名按 **localName + 命名空间 URI** 判定，因此 `bpmn:` / `bpmn2:` / 无前缀 /
 * 用 `xmlns=` 声明为默认命名空间，四种写法都能解析（见 {@see self::isBpmnElement()}）。
 *
 * ## 条件表达式：BPMN 的 EL/FEEL 与引擎的 PHP 是两种语法，桥接规则是显式的
 *
 * 引擎判定分支读的是边的 `properties.expr`，其求值器是
 * `Expression::eval()` → `extract($args)` + `eval("return ($expr);")`
 * （src/libs/utils/Expression.php:30-49），也就是说 **`expr` 必须是 PHP 表达式，变量要写成 `$var`**。
 * 而 BPMN 侧 `<conditionExpression>` 的常见写法是 EL/JUEL（`${amount > 1000}`）或裸变量（`amount > 1000`）。
 * 直接原样塞进 `expr` 的后果不是报错，而是**静默求值为 false**（`Expression::eval()` 捕获
 * `ParseError|Throwable` 后 return false）—— 表现为「条件分支永远走不出去」，最难查的一类故障。
 *
 * 因此导入时做**保守桥接**（{@see self::toEngineExpression()}）：
 * 1. 剥掉整串 `${...}` / `#{...}` 包裹；
 * 2. 裸标识符加 `$` 前缀；PHP 字面量（`true/false/null`）、逻辑关键字（`and/or/xor/instanceof`）、
 *    函数调用（标识符后紧跟 `(`）保持不变；字符串字面量整体跳过；
 * 3. **已知桥接不了的构造直接抛异常**而不是猜：JUEL 点号属性访问（`order.amount`）、
 *    JUEL 单词比较符（`eq/ne/lt/gt/le/ge`）、`not` / `in` / `contains` / `matches` /
 *    `startsWith` / `endsWith`、不带括号的 `empty`、多段 `${...}` 混排。
 *
 * 桥接后的可执行表达式写进 `properties.condition` 与 `properties.expr`（前者是主应用语义键、
 * 后者是引擎读取键，与 {@see LogicFlowSerializer} 的双写约定一致）；**原文**若与桥接结果不同，
 * 另存 `properties._alkaid_bpmn_condition`，导出时优先用它，于是 BPMN 原文不会被这次导入改写。
 *
 * ## 坐标
 *
 * BPMN 的坐标在 DI 段（`bpmndi:BPMNShape` + `dc:Bounds`），是**外接矩形**；
 * LogicFlow 的 `x`/`y` 是**节点中心点**。本类按中心点换算
 * （导入 `x = bounds.x + width/2`，导出按 {@see self::DEFAULT_NODE_WIDTH} 反算），
 * 缺失 DI 时退化为与 {@see LogicFlowSerializer::computeLayout()} 同一口径的 BFS 分层布局
 * （起点坐标与步长直接复用它的公开常量，避免两套画布约定）。
 *
 * ⚠️ 导出只写 `bpmndi:BPMNShape`，**不写边的 `di:waypoint`**：BPMN 工具会自行布线，
 * 而引擎根本不读 BPMN 的连线几何（它读 `pointsList`，由节点坐标推出）。
 *
 * ## 边界
 *
 * - 一次只导入**一个** `<bpmn:process>`（多池/多流程协作不在子集内）；
 * - `definitions` 层的非 BPMN 命名空间子元素（`bpmndi:` / `dc:` / `di:`）视为渲染信息，跳过；
 * - 流程节点/连线内部出现子集之外的元素（如 `timerEventDefinition`、
 *   `multiInstanceLoopCharacteristics`、`extensionElements`）同样抛异常 ——
 *   它们会改变执行语义（定时启动、会签、外部表单绑定），忽略等于改写流程。
 *
 * ## 已知语义落差（导入后仍然存在，不要以为转换器能抹平）
 *
 * 1. **`default` 缺省分支**：BPMN 的 `default="Flow_X"` 语义是「其余条件都不成立时走这条」，
 *    而引擎 `DecisionModel::exec()` 只做「边上的 expr 为真就执行」，**没有**缺省分支概念
 *    （src/model/DecisionModel.php:47-60）。本类把该属性完整保留在
 *    `properties._alkaid_default` 并原样导回，但**不会**替它编一条恒真 expr ——
 *    那会让缺省分支无条件命中，等于把「兜底」改成「必经」。
 *    需要缺省语义时请在设计器里显式写条件表达式。
 * 2. **网关的「只走一条」与「走全部匹配」**：`DecisionModel` 会把**所有** expr 为真的出边都执行
 *    （同上），这与 BPMN 排他网关「只走一条」并不等价。转换器只保证**图结构**忠实，
 *    运行期语义由引擎决定，不在本类职责内。
 * 3. **任务类型落到主应用**：BPMN 的 `userTask` 在 `nodes_config` 里只是 `ingenious:task`，
 *    原类型记在 `_alkaid_bpmn_type`；若再经 {@see LogicFlowSerializer::fromLogicFlow()}
 *    还原成主应用定义，会按缺省类落到 `TaskNode`（service 子类型），
 *    参与人/表单等业务配置需要设计器补。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class BpmnConverter
{
    /**
     * BPMN 2.0 语义模型命名空间
     */
    public const BPMN_NS = 'http://www.omg.org/spec/BPMN/20100524/MODEL';

    /**
     * BPMN 2.0 图形互换命名空间
     */
    public const BPMNDI_NS = 'http://www.omg.org/spec/BPMN/20100524/DI';

    /**
     * DD 图形互换命名空间（`dc:Bounds` 在这里）
     */
    public const DC_NS = 'http://www.omg.org/spec/DD/20100524/DC';

    /**
     * XSI 命名空间（`xsi:type` 用）
     */
    public const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * 导出时的 targetNamespace（仅用于声明本文件出自哪个实现）
     */
    public const TARGET_NAMESPACE = 'http://alkaidsys.com/schema/bpmn';

    /**
     * 节点类型前缀（与引擎解析器同源，避免两处各写一份字符串）
     */
    public const NODE_TYPE_PREFIX = INodeParser::NODE_NAME_PREFIX;

    /**
     * 边类型常量（与 {@see LogicFlowSerializer} 保持一致：没有解析器读边的 type）
     */
    public const EDGE_TYPE = LogicFlowSerializer::EDGE_TYPE;

    /**
     * 边属性键：引擎读取的条件表达式键
     */
    public const EDGE_EXPR_KEY = INodeParser::EXPR_KEY;

    /**
     * 边属性键：主应用语义的条件键
     */
    public const EDGE_CONDITION_KEY = 'condition';

    /**
     * 保留键前缀（与 {@see LogicFlowSerializer} 同源，保证 `_alkaid_*` 不会被当成业务配置摊平）
     */
    public const RESERVED_PREFIX = LogicFlowSerializer::RESERVED_PREFIX;

    /**
     * 边属性键：BPMN 原文条件表达式（桥接结果与原文不同时才写）
     */
    public const BPMN_CONDITION_KEY = self::RESERVED_PREFIX . 'bpmn_condition';

    /**
     * 节点属性键：原 BPMN 元素类型（仅当多个 BPMN 元素塌缩成同一个解析器键时才写）
     */
    public const BPMN_TYPE_KEY = self::RESERVED_PREFIX . 'bpmn_type';

    /**
     * 边属性键：该连线在原 BPMN 里是源节点的 `default`（缺省）分支
     */
    public const BPMN_DEFAULT_KEY = self::RESERVED_PREFIX . 'default';

    /**
     * 导出时回写节点显示名的键（与 {@see LogicFlowSerializer} 一致）
     */
    public const TEXT_VALUE_KEY = INodeParser::TEXT_VALUE_KEY;

    /**
     * 默认节点外接矩形尺寸（用于 LogicFlow 中心点 ⇄ BPMN 外接矩形换算）
     */
    public const DEFAULT_NODE_WIDTH = 100;
    public const DEFAULT_NODE_HEIGHT = 80;

    /**
     * 支持的 BPMN 流节点 ⇒ 引擎解析器键
     *
     * `parallelGateway` 先落到哨兵值，再由 {@see self::resolveParallelGatewayKey()} 按出入度细分。
     */
    public const FLOW_NODE_PARSER_KEYS = [
        'startEvent'        => 'start',
        'endEvent'          => 'end',
        'userTask'          => 'task',
        'serviceTask'       => 'task',
        'scriptTask'        => 'task',
        'sendTask'          => 'task',
        'exclusiveGateway'  => 'decision',
        'parallelGateway'   => self::PARALLEL_GATEWAY_SENTINEL,
    ];

    /**
     * `parallelGateway` 的哨兵值（解析器键里没有它，导入时一定会被替换掉）
     */
    public const PARALLEL_GATEWAY_SENTINEL = '__parallelGateway__';

    /**
     * `parallelGateway` 的 BPMN 元素名
     */
    public const PARALLEL_GATEWAY = 'parallelGateway';

    /**
     * 会塌缩成 `task` 解析器键的 BPMN 元素（导出时优先用 `_alkaid_bpmn_type` 还原）
     */
    public const TASK_BPMN_TYPES = ['userTask', 'serviceTask', 'scriptTask', 'sendTask'];

    /**
     * 解析器键 ⇒ 导出的 BPMN 元素
     *
     * `custom` / `wfSubProcess` 故意缺席：BPMN 子集里没有对应元素，导出时抛异常。
     */
    private const PARSER_KEY_TO_BPMN = [
        'start'    => 'startEvent',
        'end'      => 'endEvent',
        'task'     => 'userTask',
        'decision' => 'exclusiveGateway',
        'fork'     => self::PARALLEL_GATEWAY,
        'join'     => self::PARALLEL_GATEWAY,
    ];

    /**
     * 流节点内部允许出现、但不参与转换的子元素
     *
     * `incoming` / `outgoing` 只是连线的冗余索引（本类按 `sequenceFlow` 的 `sourceRef`/`targetRef`
     * 自行算度数，不信任这份索引，避免两处不一致）；`documentation` 是纯人类可读注解。
     */
    private const FLOW_NODE_CHILD_ALLOWLIST = ['incoming', 'outgoing', 'documentation'];

    /**
     * 连线内部允许出现、但不参与转换的子元素（`conditionExpression` 由专门逻辑读取）
     */
    private const FLOW_CHILD_ALLOWLIST = ['documentation', 'conditionExpression'];

    /**
     * 桥接条件表达式时保持原样的 PHP 字面量/关键字
     */
    private const PHP_KEYWORDS = ['true', 'false', 'null', 'and', 'or', 'xor', 'instanceof'];

    /**
     * 已知无法桥接成 PHP 的 JUEL/EL 单词运算符（出现即抛异常，不猜）
     */
    private const UNTRANSLATABLE_WORD_OPERATORS = [
        'eq', 'ne', 'lt', 'gt', 'le', 'ge', 'in', 'not', 'contains', 'matches', 'startswith', 'endswith',
    ];

    /**
     * BPMN XML → LogicFlow 图
     *
     * 返回结构对齐引擎 `ModelParser::parse()` 的输入契约：
     * `{name, display_name, nodes[], edges[]}`，节点 `type` 带 `ingenious:` 前缀，
     * 边几何只写 `pointsList`（写 `startPoint`/`endPoint` 会让引擎抛
     * `Call to undefined method stdClass::getX()`，见 {@see LogicFlowSerializer::connectionToLogicFlow()} 的注释）。
     *
     * @param string $xml BPMN 2.0 XML（`bpmn_xml` 列的内容）
     * @return array{name: string, display_name: string, nodes: array, edges: array}
     * @throws BpmnConversionException XML 非法、含子集外元素、连线悬空或条件无法桥接时
     */
    public function fromBpmn(string $xml): array
    {
        if (trim($xml) === '') {
            throw new BpmnConversionException('BPMN 导入失败：XML 内容为空。');
        }

        $dom = new DOMDocument();

        // libxml 默认会把解析错误直接打到 PHP 警告里，这里接管成异常信息
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET：解析期间禁止访问网络，挡住 XXE 的外部实体抓取。
            // 刻意**不传** LIBXML_NOENT：实体替换会把外部实体内容嵌进文档，正是 XXE 的入口。
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($loaded === false) {
            $detail = '';
            foreach (array_slice($errors, 0, 3) as $error) {
                $detail .= sprintf(' [第 %d 行] %s;', (int) $error->line, trim((string) $error->message));
            }

            throw new BpmnConversionException('BPMN 导入失败：XML 不是合法文档。' . $detail);
        }

        $root = $dom->documentElement;

        if (!$root instanceof DOMElement) {
            throw new BpmnConversionException('BPMN 导入失败：XML 没有根元素。');
        }

        if (!$this->isBpmnElement($root) || $root->localName !== 'definitions') {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：根元素必须是 BPMN 2.0 的 <definitions>，实际是 <%s>（命名空间：%s）。',
                (string) $root->localName,
                $root->namespaceURI !== null && $root->namespaceURI !== '' ? $root->namespaceURI : '无'
            ));
        }

        return $this->importProcess($root, $this->findSingleProcess($root));
    }

    /**
     * LogicFlow 图 → BPMN XML
     *
     * 输出是一份自洽的 BPMN 2.0 文档：`process` + 流节点 + `sequenceFlow` + `BPMNDiagram`。
     * `isExecutable` 固定 `false` —— 真正的执行源是 LogicFlow JSON，
     * 标成 `true` 会诱导别人拿 BPMN 引擎去跑这份文件，而两边语义并不完全等价（例如 `default` 分支）。
     *
     * @param array $logicFlow LogicFlow 图（`nodes_config` 列的内容）
     * @return string BPMN 2.0 XML
     * @throws BpmnConversionException 节点类型无 BPMN 等价物、缺 id、连线悬空时
     */
    public function toBpmn(array $logicFlow): string
    {
        $nodes = $this->normalizeExportNodes($logicFlow);
        $flows = $this->normalizeExportFlows($logicFlow, $nodes);
        $positions = $this->resolveExportPositions($nodes, $flows);

        $processId = $this->sanitizeId((string) ($logicFlow['name'] ?? ''), 'Process_1');
        $processName = trim((string) ($logicFlow['display_name'] ?? ''));
        if ($processName === '') {
            $processName = $processId;
        }

        // 每个节点的入/出连线，按边的声明顺序（BPMN 侧习惯：先出后入都合法，这里统一先入后出）
        $incoming = [];
        $outgoing = [];
        $defaultFlowByNode = [];
        foreach ($flows as $flow) {
            $incoming[$flow['target']][] = $flow['id'];
            $outgoing[$flow['source']][] = $flow['id'];
            if ($flow['is_default']) {
                $defaultFlowByNode[$flow['source']] = $flow['id'];
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // 先解析一份「只有根元素」的骨架，再往里挂子元素。
        // 为什么不 createElementNS 建根再 setAttributeNS('…/xmlns/', …) 补声明：
        // 那样 libxml 只把 xmlns 当普通属性记下，**不会**把它登记进前缀上下文，
        // 于是每个 dc:Bounds / bpmndi:BPMNShape 都会重复声明一遍 xmlns:dc。
        // 走 loadXML 建立的前缀上下文是真正的命名空间声明，子元素按前缀复用，输出与 bpmn.io 一致。
        // 骨架必须带 encoding 声明：少了它 libxml 不记录文档编码，saveXML() 会把中文
        // 全部转成数字字符引用（`&#x8D39;`），文件虽然合法但人看不出内容。
        $dom->loadXML(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<bpmn:definitions xmlns:bpmn="%s" xmlns:bpmndi="%s" xmlns:dc="%s" xmlns:xsi="%s"'
            . ' id="" targetNamespace="%s"/>',
            self::BPMN_NS,
            self::BPMNDI_NS,
            self::DC_NS,
            self::XSI_NS,
            self::TARGET_NAMESPACE
        ));

        $definitions = $dom->documentElement;

        if (!$definitions instanceof DOMElement) {
            throw new BpmnConversionException('BPMN 导出失败：无法构造 <bpmn:definitions> 根元素。');
        }

        $definitions->setAttribute('id', 'Definitions_' . $processId);

        $process = $dom->createElementNS(self::BPMN_NS, 'bpmn:process');
        $process->setAttribute('id', $processId);
        $process->setAttribute('name', $processName);
        $process->setAttribute('isExecutable', 'false');

        foreach ($nodes as $nodeId => $node) {
            $element = $dom->createElementNS(self::BPMN_NS, 'bpmn:' . $node['bpmn_element']);
            $element->setAttribute('id', $nodeId);

            if ($node['name'] !== '') {
                $element->setAttribute('name', $node['name']);
            }

            foreach ($incoming[$nodeId] ?? [] as $flowId) {
                $element->appendChild($this->elementWithText($dom, 'bpmn:incoming', $flowId));
            }

            foreach ($outgoing[$nodeId] ?? [] as $flowId) {
                $element->appendChild($this->elementWithText($dom, 'bpmn:outgoing', $flowId));
            }

            if (isset($defaultFlowByNode[$nodeId])) {
                $element->setAttribute('default', $defaultFlowByNode[$nodeId]);
            }

            $process->appendChild($element);
        }

        foreach ($flows as $flow) {
            $element = $dom->createElementNS(self::BPMN_NS, 'bpmn:sequenceFlow');
            $element->setAttribute('id', $flow['id']);
            $element->setAttribute('sourceRef', $flow['source']);
            $element->setAttribute('targetRef', $flow['target']);

            if ($flow['name'] !== '') {
                $element->setAttribute('name', $flow['name']);
            }

            if ($flow['condition'] !== null) {
                $expression = $dom->createElementNS(self::BPMN_NS, 'bpmn:conditionExpression');
                // 必须带 xsi:type，否则 bpmn.io / Camunda 会把 conditionExpression 当成抽象元素报错
                $expression->setAttributeNS(self::XSI_NS, 'xsi:type', 'bpmn:tFormalExpression');
                $expression->appendChild($dom->createTextNode($flow['condition']));
                $element->appendChild($expression);
            }

            $process->appendChild($element);
        }

        // 根元素已由骨架 loadXML 挂进文档，这里只挂它的子元素
        $definitions->appendChild($process);
        $definitions->appendChild($this->buildDiagram($dom, $processId, $nodes, $positions));

        // 命名空间归一化：createElementNS() 会把 xmlns 声明挂在**每个**新元素上，
        // 不归一化就会看到 `xmlns:dc` 在每层重复。normalizeDocument() 走 libxml 的
        // xmlDOMWrapReconcileNamespaces，把声明上提到公共祖先并去重。
        $dom->normalizeDocument();

        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new BpmnConversionException('BPMN 导出失败：DOMDocument::saveXML() 返回 false。');
        }

        return $xml;
    }

    // ---------------------------------------------------------------------
    // 导入方向
    // ---------------------------------------------------------------------

    /**
     * 在 `<definitions>` 下找出唯一的 `<bpmn:process>`
     *
     * 同时把「definitions 层的子集外 BPMN 元素」一次性报出来（`collaboration` / `message` /
     * `choreography` 等），因为它们会改变「这个文件描述的是哪个流程」的含义。
     *
     * @param DOMElement $root 根元素 `<definitions>`
     * @return DOMElement 唯一的 `<bpmn:process>`
     * @throws BpmnConversionException 找不到/找到多个/存在子集外元素时
     */
    private function findSingleProcess(DOMElement $root): DOMElement
    {
        $processes = [];
        $unsupported = [];

        foreach ($this->elementChildren($root) as $child) {
            // 非 BPMN 命名空间（bpmndi:/dc:/di:）只是渲染信息，不影响执行语义
            if (!$this->isBpmnElement($child)) {
                continue;
            }

            if ($child->localName === 'process') {
                $processes[] = $child;
                continue;
            }

            $unsupported[] = '<' . (string) $child->localName . '>';
        }

        if ($unsupported !== []) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：<definitions> 下存在不支持的 BPMN 元素：%s。'
                . '当前子集只支持 <process>，以及 startEvent / endEvent / userTask / serviceTask / '
                . 'scriptTask / sendTask / exclusiveGateway / parallelGateway / sequenceFlow。'
                . '协作（collaboration）、消息/信号定义、编排（choreography）等都会改变流程边界，'
                . '忽略等于改写图，因此直接失败。',
                implode('、', $unsupported)
            ));
        }

        if ($processes === []) {
            throw new BpmnConversionException(
                'BPMN 导入失败：<definitions> 下没有 <bpmn:process>，无法确定要导入哪个流程。'
            );
        }

        if (count($processes) > 1) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：<definitions> 下有 %d 个 <bpmn:process>。当前子集一次只导入一个流程，'
                . '多池/多流程协作请先拆成单流程文件。',
                count($processes)
            ));
        }

        return $processes[0];
    }

    /**
     * 把一个 `<bpmn:process>` 转成 LogicFlow 图
     *
     * @param DOMElement $root 根元素（读 DI 坐标用）
     * @param DOMElement $process 流程元素
     * @return array{name: string, display_name: string, nodes: array, edges: array}
     * @throws BpmnConversionException 存在子集外元素、id 重复、连线悬空、条件无法桥接时
     */
    private function importProcess(DOMElement $root, DOMElement $process): array
    {
        $flowNodes = [];  // id ⇒ ['parser_key', 'bpmn_element', 'name', 'default']
        $flows = [];      // list<['id', 'source', 'target', 'name', 'condition', 'is_default']>
        $unsupported = [];

        foreach ($this->elementChildren($process) as $child) {
            if (!$this->isBpmnElement($child)) {
                continue;
            }

            $localName = (string) $child->localName;

            if (array_key_exists($localName, self::FLOW_NODE_PARSER_KEYS)) {
                $nodeId = trim((string) $child->getAttribute('id'));

                if ($nodeId === '') {
                    throw new BpmnConversionException(sprintf(
                        'BPMN 导入失败：<%s> 缺少 id 属性。节点 id 是连线的唯一定位依据，不能为空。',
                        $localName
                    ));
                }

                if (isset($flowNodes[$nodeId])) {
                    throw new BpmnConversionException(sprintf(
                        'BPMN 导入失败：节点 id「%s」重复（BPMN 要求 id 在文档内唯一）。',
                        $nodeId
                    ));
                }

                foreach ($this->collectUnsupportedChildren(
                    $child,
                    self::FLOW_NODE_CHILD_ALLOWLIST,
                    sprintf('节点 %s（<%s>）', $nodeId, $localName)
                ) as $item) {
                    $unsupported[] = $item;
                }

                $flowNodes[$nodeId] = [
                    'parser_key'   => self::FLOW_NODE_PARSER_KEYS[$localName],
                    'bpmn_element' => $localName,
                    'name'         => trim((string) $child->getAttribute('name')),
                    // default 属性指向「缺省分支」的连线 id
                    'default'      => trim((string) $child->getAttribute('default')),
                ];

                continue;
            }

            if ($localName === 'sequenceFlow') {
                $flowId = trim((string) $child->getAttribute('id'));

                if ($flowId === '') {
                    throw new BpmnConversionException('BPMN 导入失败：存在缺少 id 属性的 <sequenceFlow>。');
                }

                foreach ($this->collectUnsupportedChildren(
                    $child,
                    self::FLOW_CHILD_ALLOWLIST,
                    sprintf('连线 %s（<sequenceFlow>）', $flowId)
                ) as $item) {
                    $unsupported[] = $item;
                }

                $flows[] = [
                    'id'        => $flowId,
                    'source'    => trim((string) $child->getAttribute('sourceRef')),
                    'target'    => trim((string) $child->getAttribute('targetRef')),
                    'name'      => trim((string) $child->getAttribute('name')),
                    'condition' => $this->readConditionExpression($child),
                ];

                continue;
            }

            $unsupported[] = '<' . $localName . '>';
        }

        if ($unsupported !== []) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：存在当前子集不支持的元素：%s。'
                . '支持的元素只有 startEvent / endEvent / userTask / serviceTask / scriptTask / sendTask / '
                . 'exclusiveGateway / parallelGateway / sequenceFlow（以及节点内的 incoming/outgoing/documentation）。'
                . '这些元素会改变执行语义（定时/消息事件、子流程、会签、外部表单绑定……），'
                . '静默丢弃等于悄悄改写流程，因此直接失败。',
                implode('、', $unsupported)
            ));
        }

        if ($flowNodes === []) {
            throw new BpmnConversionException(
                'BPMN 导入失败：<bpmn:process> 下没有任何 BPMN 流节点，无法生成图。'
            );
        }

        // 1) 连线两端必须存在，并统计出入边数
        $incomingIds = [];
        $outgoingIds = [];
        $targets = [];

        foreach ($flows as $flow) {
            foreach (['source', 'target'] as $side) {
                if ($flow[$side] === '') {
                    throw new BpmnConversionException(sprintf(
                        'BPMN 导入失败：连线 %s 缺少 %sRef 属性。',
                        $flow['id'],
                        $side
                    ));
                }

                if (!isset($flowNodes[$flow[$side]])) {
                    throw new BpmnConversionException(sprintf(
                        'BPMN 导入失败：连线 %s 的 %sRef 指向未声明的节点「%s」。'
                        . '悬空连线会让引擎得到永远走不到的 transition，因此不导入。',
                        $flow['id'],
                        $side,
                        $flow[$side]
                    ));
                }
            }

            $outgoingIds[$flow['source']][] = $flow['id'];
            $incomingIds[$flow['target']][] = $flow['id'];
            $targets[$flow['source']][] = $flow['target'];
        }

        // 2) parallelGateway 按出入度细分 fork / join
        foreach ($flowNodes as $nodeId => $node) {
            if ($node['bpmn_element'] !== self::PARALLEL_GATEWAY) {
                continue;
            }

            $flowNodes[$nodeId]['parser_key'] = $this->resolveParallelGatewayKey(
                $nodeId,
                count($incomingIds[$nodeId] ?? []),
                count($outgoingIds[$nodeId] ?? [])
            );
        }

        // 3) 坐标（DI 优先，缺失则确定性 BFS 布局）
        $positions = $this->resolveImportPositions($root, $flowNodes, $targets);

        // 4) 组装 LogicFlow 节点（保持 XML 文档顺序）
        $nodes = [];
        foreach ($flowNodes as $nodeId => $node) {
            $properties = [];

            // 只有「多个 BPMN 元素塌缩成同一个解析器键」时才需要记原类型；
            // startEvent/endEvent/exclusiveGateway 与解析器键一一对应，记了也是冗余
            if (in_array($node['bpmn_element'], self::TASK_BPMN_TYPES, true)) {
                $properties[self::BPMN_TYPE_KEY] = $node['bpmn_element'];
            }

            $nodes[] = [
                'id'         => $nodeId,
                'type'       => self::NODE_TYPE_PREFIX . $node['parser_key'],
                // LfNode::$x/$y 是 int（src/model/logicflow/LfNode.php:34-35），必须给整数
                'x'          => $positions[$nodeId]['x'],
                'y'          => $positions[$nodeId]['y'],
                // 引擎从 text.value 取 display_name（src/parser/AbstractINodeParser.php:39-45）
                'text'       => [self::TEXT_VALUE_KEY => $node['name']],
                'properties' => $properties,
            ];
        }

        // 5) 组装 LogicFlow 边
        // `default` 属性必须指向本节点的一条出边，否则是坏 BPMN：静默丢掉等于把
        // 「这是缺省分支」这条信息一起丢掉，导出时就再也写不回去了
        $defaultFlowIds = [];
        $flowSources = [];
        foreach ($flows as $flow) {
            $flowSources[$flow['id']] = $flow['source'];
        }

        foreach ($flowNodes as $nodeId => $node) {
            if ($node['default'] === '') {
                continue;
            }

            if (!isset($flowSources[$node['default']])) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导入失败：节点 %s（<%s>）的 default 属性指向「%s」，但文档里没有这条 sequenceFlow。',
                    $nodeId,
                    $node['bpmn_element'],
                    $node['default']
                ));
            }

            if ($flowSources[$node['default']] !== $nodeId) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导入失败：节点 %s 声明的 default 连线「%s」实际由节点「%s」发出。'
                    . '缺省分支必须由声明它的节点自己发出。',
                    $nodeId,
                    $node['default'],
                    $flowSources[$node['default']]
                ));
            }

            $defaultFlowIds[$node['default']] = true;
        }

        $edges = [];
        foreach ($flows as $flow) {
            $properties = [
                // 与主应用连接语义对齐：BPMN 的 sequenceFlow 就是「顺序连线」
                self::RESERVED_PREFIX . 'type' => WorkflowConnectionInterface::TYPE_SEQUENCE,
            ];

            if ($flow['condition'] !== null) {
                $expression = $this->toEngineExpression($flow['condition'], $flow['id']);

                // 双写：condition 是主应用语义键（WorkflowConnection::getCondition），
                // expr 是引擎读取键（AbstractINodeParser / DecisionModel）。
                // 两份都写**桥接后**的表达式 —— 只写原文会让引擎读到 EL 语法并静默求值为 false。
                $properties[self::EDGE_CONDITION_KEY] = $expression;
                $properties[self::EDGE_EXPR_KEY] = $expression;

                // 原文与桥接结果不同时保留原文，导出时优先写回，BPMN 就被这次导入改写了
                if ($expression !== $flow['condition']) {
                    $properties[self::BPMN_CONDITION_KEY] = $flow['condition'];
                }
            }

            if ($flow['name'] !== '') {
                $properties[self::RESERVED_PREFIX . 'name'] = $flow['name'];
            }

            if (isset($defaultFlowIds[$flow['id']])) {
                $properties[self::BPMN_DEFAULT_KEY] = true;
            }

            $edges[] = [
                'id'           => $flow['id'],
                'type'         => self::EDGE_TYPE,
                'sourceNodeId' => $flow['source'],
                'targetNodeId' => $flow['target'],
                'text'         => [self::TEXT_VALUE_KEY => $flow['name']],
                'properties'   => $properties,
                // 只写 pointsList，不写 startPoint/endPoint：引擎读 startPoint 分支时
                // 会 `getStartPoint()->getX()` 崩在 stdClass 上（见 LogicFlowSerializer 同名注释）
                'pointsList'   => [
                    ['x' => $positions[$flow['source']]['x'], 'y' => $positions[$flow['source']]['y']],
                    ['x' => $positions[$flow['target']]['x'], 'y' => $positions[$flow['target']]['y']],
                ],
            ];
        }

        $processId = trim((string) $process->getAttribute('id'));
        $processName = trim((string) $process->getAttribute('name'));

        return [
            'name'         => $processId !== '' ? $processId : 'workflow',
            'display_name' => $processName !== '' ? $processName : ($processId !== '' ? $processId : 'workflow'),
            'nodes'        => $nodes,
            'edges'        => $edges,
        ];
    }

    /**
     * 并行网关按出入度细分成 fork / join
     *
     * 依据引擎语义：
     * - `ForkModel::exec()` 无条件执行**全部**出边（src/model/ForkModel.php:31-34）；
     * - `JoinModel::exec()` 走合并处理器等所有分支到齐（src/model/JoinModel.php:31-37）。
     *
     * 判定规则（含边界）：
     * - 出边 > 1 → `fork`；入边 > 1 且出边 <= 1 → `join`；
     * - 出入边都 > 1 → 抛异常：引擎没有「既汇聚又分发」的单个节点，必须拆成 join + fork；
     * - 1 进 1 出（或孤立节点）→ 退化为 `fork`：`ForkModel` 执行全部出边，只有一条时
     *   就是透传，语义与 BPMN 的「直通并行网关」一致，且能原样导出回去。
     *
     * @param string $nodeId 节点 id（仅用于报错）
     * @param int $inDegree 入边数
     * @param int $outDegree 出边数
     * @return string 'fork' 或 'join'
     * @throws BpmnConversionException 出入边都大于 1 时
     */
    private function resolveParallelGatewayKey(string $nodeId, int $inDegree, int $outDegree): string
    {
        if ($inDegree > 1 && $outDegree > 1) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：并行网关 %s 同时有 %d 条入边和 %d 条出边。'
                . '引擎的 fork（无条件走全部出边）与 join（等全部入边到齐）都没有「既汇聚又分发」的语义，'
                . '无法用单个节点表达。请拆成 join + fork 两个网关。',
                $nodeId,
                $inDegree,
                $outDegree
            ));
        }

        if ($outDegree > 1) {
            return 'fork';
        }

        if ($inDegree > 1) {
            return 'join';
        }

        // 1 进 1 出（或 0 进 0 出的孤立网关）：按透传 fork 处理，见方法注释
        return 'fork';
    }

    /**
     * 读取连线的条件表达式原文
     *
     * @param DOMElement $flow 连线元素
     * @return string|null 去空后的原文；没有该子元素或内容为空时返回 null
     */
    private function readConditionExpression(DOMElement $flow): ?string
    {
        $element = $this->findChild($flow, 'conditionExpression');

        if ($element === null) {
            return null;
        }

        $text = trim($element->textContent);

        // 空 <conditionExpression/> 在 BPMN 里等价于「无条件」，这里按缺省处理
        return $text === '' ? null : $text;
    }

    /**
     * 计算导入后的节点坐标：DI 外接矩形优先，缺失则用确定性 BFS 布局
     *
     * @param DOMElement $root 根元素
     * @param array<string, array<string, mixed>> $flowNodes 节点表
     * @param array<string, list<string>> $targets 邻接表（source ⇒ target 列表）
     * @return array<string, array{x: int, y: int}> 节点 id ⇒ 中心点坐标
     */
    private function resolveImportPositions(DOMElement $root, array $flowNodes, array $targets): array
    {
        $bounds = $this->readDiagramBounds($root);

        $startId = null;
        foreach ($flowNodes as $nodeId => $node) {
            if ($node['bpmn_element'] === 'startEvent') {
                $startId = $nodeId;
                break;
            }
        }

        $fallback = $this->computeLayout(array_keys($flowNodes), $targets, $startId);

        $positions = [];
        foreach (array_keys($flowNodes) as $nodeId) {
            $positions[$nodeId] = $bounds[$nodeId] ?? $fallback[$nodeId];
        }

        return $positions;
    }

    /**
     * 读取 BPMN DI 里的节点外接矩形，并换算成 LogicFlow 的中心点
     *
     * `dc:Bounds` 是外接矩形的左上角 + 宽高；LogicFlow 的 `x`/`y` 是节点中心。
     * 不做这层换算的话，`bpmn.io` 导出的图会在画布上整体偏移半个节点。
     *
     * @param DOMElement $root 根元素
     * @return array<string, array{x: int, y: int}> 节点 id ⇒ 中心点坐标
     */
    private function readDiagramBounds(DOMElement $root): array
    {
        $positions = [];

        foreach ($root->getElementsByTagNameNS(self::BPMNDI_NS, 'BPMNShape') as $shape) {
            if (!$shape instanceof DOMElement) {
                continue;
            }

            $elementId = trim((string) $shape->getAttribute('bpmnElement'));

            if ($elementId === '') {
                continue;
            }

            foreach ($this->elementChildren($shape) as $child) {
                if ($child->namespaceURI !== self::DC_NS || $child->localName !== 'Bounds') {
                    continue;
                }

                $width = (float) $child->getAttribute('width');
                $height = (float) $child->getAttribute('height');
                $x = (float) $child->getAttribute('x');
                $y = (float) $child->getAttribute('y');

                $positions[$elementId] = [
                    'x' => (int) round($x + $width / 2),
                    'y' => (int) round($y + $height / 2),
                ];

                break;
            }
        }

        return $positions;
    }

    /**
     * 确定性默认布局：按拓扑 BFS 分层
     *
     * 与 {@see LogicFlowSerializer::computeLayout()} 同一口径（同一组公开常量、同样「按注册顺序
     * 排列同层节点」），保证「BPMN 导入的图」和「主应用序列化的图」在画布上长得一样。
     * 为什么必须确定性：同一份 BPMN 每次导入都要得到同一份 JSON，否则每次保存都产生无意义 diff。
     *
     * @param list<string> $nodeIds 节点 id（文档顺序）
     * @param array<string, list<string>> $targets 邻接表（source ⇒ target 列表）
     * @param string|null $rootId BFS 起点（没有 startEvent 时取第一个节点）
     * @return array<string, array{x: int, y: int}> 节点 id ⇒ 坐标
     */
    private function computeLayout(array $nodeIds, array $targets, ?string $rootId): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $known = array_flip($nodeIds);
        $rootId = $rootId !== null && isset($known[$rootId]) ? $rootId : $nodeIds[0];

        $depth = [$rootId => 0];
        $queue = [$rootId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($targets[$current] ?? [] as $targetId) {
                if (!isset($known[$targetId]) || array_key_exists($targetId, $depth)) {
                    continue;
                }

                $depth[$targetId] = $depth[$current] + 1;
                $queue[] = $targetId;
            }
        }

        // 不可达/孤立节点统一排在最后一层，仍按文档顺序
        $maxDepth = max($depth);
        foreach ($nodeIds as $nodeId) {
            if (!array_key_exists($nodeId, $depth)) {
                $depth[$nodeId] = $maxDepth + 1;
            }
        }

        $layout = [];
        $rows = [];
        foreach ($nodeIds as $nodeId) {
            $layer = $depth[$nodeId];
            $row = $rows[$layer] ?? 0;
            $rows[$layer] = $row + 1;

            $layout[$nodeId] = [
                'x' => LogicFlowSerializer::LAYOUT_ORIGIN_X + $layer * LogicFlowSerializer::LAYOUT_X_STEP,
                'y' => LogicFlowSerializer::LAYOUT_ORIGIN_Y + $row * LogicFlowSerializer::LAYOUT_Y_STEP,
            ];
        }

        return $layout;
    }

    /**
     * 把 BPMN 条件表达式桥接成引擎可 eval 的 PHP 表达式
     *
     * 桥接规则与「拒绝清单」见类注释。核心原则：**能确定语义才转换，不确定就抛异常**，
     * 绝不把一段引擎解释不了的文本塞进 `expr` —— 那会静默求值为 false，
     * 表现为「条件分支永远不命中」，是本项目最贵的一类故障。
     *
     * @param string $raw BPMN 条件表达式原文
     * @param string $flowId 连线 id（仅用于报错定位）
     * @return string PHP 表达式
     * @throws BpmnConversionException 遇到无法桥接的构造时
     */
    private function toEngineExpression(string $raw, string $flowId): string
    {
        $body = $this->stripExpressionWrapper(trim($raw), $flowId);

        if ($body === '') {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：连线 %s 的条件表达式剥掉 ${...} 包裹后为空。',
                $flowId
            ));
        }

        $masked = $this->maskStringLiterals($body);

        // 点号属性访问（JUEL 的 order.amount）：引擎变量是扁平数组，没有对象成员访问。
        // 只认「标识符紧跟点号」的写法；`$a . $b` 这种带空格 PHP 连接不受影响
        // （不带空格的 `$a.$b` 会被判成属性访问而报错，属已知取严）。
        if (preg_match('/[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_]/', $masked) === 1) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：连线 %s 的条件表达式「%s」里有点号属性访问（如 order.amount）。'
                . '引擎的表达式求值器是 `extract($args)` + `eval()`，变量是扁平的一维值，'
                . '没有对象成员访问；请改成扁平变量名（如 $order_amount）后重试。'
                . '（若本意是 PHP 字符串连接，请在点号两侧加空格。）',
                $flowId,
                $raw
            ));
        }

        // JUEL/EL 的单词运算符与函数：PHP 里没有，加 `$` 只会变成解析错误并静默求值为 false
        $pattern = '/(?<![\w$])(' . implode('|', self::UNTRANSLATABLE_WORD_OPERATORS) . ')\b/i';
        if (preg_match($pattern, $masked, $matches) === 1) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：连线 %s 的条件表达式「%s」使用了「%s」，这是 JUEL/EL 的单词运算符或函数，'
                . '引擎的 PHP 表达式求值器不认识它（会静默求值为 false）。'
                . '请改写成 PHP 写法：eq/ne → ==/!=，lt/gt/le/ge → </>/<=/>=，'
                . 'not → !，in → in_array($x, [...])，empty → empty($x)，contains → str_contains(...)。',
                $flowId,
                $raw,
                $matches[1]
            ));
        }

        // 不带括号的 empty（JUEL 的 `empty x`）；PHP 的 empty($x) 是合法的，放行
        if (preg_match('/(?<![\w$])empty\b(?!\s*\()/i', $masked) === 1) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：连线 %s 的条件表达式「%s」里出现了不带括号的 empty。'
                . 'PHP 的 empty 是语言结构，必须写成 empty($var)。',
                $flowId,
                $raw
            ));
        }

        return $this->prefixBareIdentifiers($body);
    }

    /**
     * 剥掉整串 `${...}` / `#{...}` 包裹
     *
     * 只接受「整串恰好一对花括号」的单层包裹。多段混排（`${a} and ${b}`）语义上等价于
     * 逻辑与，但拼接规则随实现而异，不做猜测。
     *
     * @param string $text 去空后的原文
     * @param string $flowId 连线 id（仅用于报错）
     * @return string 包裹内的表达式（无包裹时原样返回）
     * @throws BpmnConversionException 多段包裹或括号不配对时
     */
    private function stripExpressionWrapper(string $text, string $flowId): string
    {
        if (preg_match('/^[#$]\{/', $text) !== 1) {
            return $text;
        }

        if (substr_count($text, '{') !== 1 || substr_count($text, '}') !== 1 || !str_ends_with($text, '}')) {
            throw new BpmnConversionException(sprintf(
                'BPMN 导入失败：连线 %s 的条件表达式「%s」包含多段或未闭合的 ${...}/#{...}。'
                . '请先在建模工具里合并成单个表达式（例如 `${a} and ${b}` → `${a and b}`）。',
                $flowId,
                $text
            ));
        }

        return trim(substr($text, 2, -1));
    }

    /**
     * 把字符串字面量替换成等长空白，供后续正则检查使用
     *
     * 目的：避免 `status == "in progress"` 这类表达式里的单词被误判成 JUEL 运算符。
     *
     * @param string $text 表达式
     * @return string 同长度的掩码串
     * @throws BpmnConversionException 引号未闭合时
     */
    private function maskStringLiterals(string $text): string
    {
        $masked = '';
        $length = strlen($text);
        $index = 0;

        while ($index < $length) {
            $char = $text[$index];

            if ($char === "'" || $char === '"') {
                $end = $this->findClosingQuote($text, $index, $char);
                $masked .= str_repeat(' ', $end - $index + 1);
                $index = $end + 1;
                continue;
            }

            $masked .= $char;
            $index++;
        }

        return $masked;
    }

    /**
     * 给裸标识符加 `$` 前缀（JUEL/EL → PHP 的核心一步）
     *
     * 保持不变的三类：
     * - 已经带 `$` 的标识符（原文本身就是 PHP 写法，或混排时已被作者写对）；
     * - PHP 字面量/逻辑关键字（`true` / `false` / `null` / `and` / `or` / `xor` / `instanceof`）；
     * - 函数调用（标识符后紧跟 `(`，例如 `empty($x)`、`in_array($x, [...])`）。
     *
     * @param string $expression 已剥包裹的表达式
     * @return string PHP 表达式
     * @throws BpmnConversionException 引号未闭合时
     */
    private function prefixBareIdentifiers(string $expression): string
    {
        $result = '';
        $length = strlen($expression);
        $index = 0;

        while ($index < $length) {
            $char = $expression[$index];

            // 字符串字面量整体拷贝（里面的标识符不是变量）
            if ($char === "'" || $char === '"') {
                $end = $this->findClosingQuote($expression, $index, $char);
                $result .= substr($expression, $index, $end - $index + 1);
                $index = $end + 1;
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($expression, $index), $matches) === 1) {
                $token = $matches[0];
                $previous = $result === '' ? '' : substr($result, -1);
                $rest = ltrim(substr($expression, $index + strlen($token)));
                $isFunctionCall = $rest !== '' && $rest[0] === '(';

                if ($previous === '$' || $isFunctionCall || in_array(strtolower($token), self::PHP_KEYWORDS, true)) {
                    $result .= $token;
                } else {
                    $result .= '$' . $token;
                }

                $index += strlen($token);
                continue;
            }

            $result .= $char;
            $index++;
        }

        return $result;
    }

    /**
     * 找到与起始引号配对的结束引号
     *
     * @param string $text 文本
     * @param int $start 起始引号下标
     * @param string $quote 引号字符
     * @return int 结束引号下标
     * @throws BpmnConversionException 找不到配对引号时
     */
    private function findClosingQuote(string $text, int $start, string $quote): int
    {
        $length = strlen($text);

        for ($index = $start + 1; $index < $length; $index++) {
            if ($text[$index] === '\\') {
                $index++;
                continue;
            }

            if ($text[$index] === $quote) {
                return $index;
            }
        }

        throw new BpmnConversionException(sprintf(
            'BPMN 导入失败：条件表达式「%s」里的 %s 引号没有闭合。',
            $text,
            $quote
        ));
    }

    // ---------------------------------------------------------------------
    // 导出方向
    // ---------------------------------------------------------------------

    /**
     * 归一化待导出的节点
     *
     * @param array $logicFlow LogicFlow 图
     * @return array<string, array{id: string, bpmn_element: string, name: string, x: int|null, y: int|null, has_point: bool}>
     * @throws BpmnConversionException 缺 nodes/id/type、类型无 BPMN 等价物时
     */
    private function normalizeExportNodes(array $logicFlow): array
    {
        $rawNodes = $logicFlow['nodes'] ?? [];

        if (!is_array($rawNodes) || $rawNodes === []) {
            throw new BpmnConversionException(
                'BPMN 导出失败：LogicFlow 图里没有 nodes，无法导出流程（空图导出只会得到一份无意义的 BPMN）。'
            );
        }

        $nodes = [];

        foreach ($rawNodes as $rawNode) {
            if (!is_array($rawNode)) {
                throw new BpmnConversionException(
                    'BPMN 导出失败：nodes 的元素必须是数组，实际是 ' . get_debug_type($rawNode) . '。'
                );
            }

            $nodeId = (string) ($rawNode['id'] ?? '');

            if ($nodeId === '') {
                throw new BpmnConversionException('BPMN 导出失败：存在缺少 id 的节点。');
            }

            if (isset($nodes[$nodeId])) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导出失败：节点 id「%s」重复。BPMN 要求 id 在文档内唯一。',
                    $nodeId
                ));
            }

            $type = (string) ($rawNode['type'] ?? '');

            if (!str_starts_with($type, self::NODE_TYPE_PREFIX)) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导出失败：节点 %s 的类型「%s」没有引擎前缀「%s」，无法判断它对应哪个 BPMN 元素。',
                    $nodeId,
                    $type,
                    self::NODE_TYPE_PREFIX
                ));
            }

            $parserKey = substr($type, strlen(self::NODE_TYPE_PREFIX));
            $bpmnElement = self::PARSER_KEY_TO_BPMN[$parserKey] ?? null;

            if ($bpmnElement === null) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导出失败：节点 %s 的类型「%s」在本子集里没有对应的 BPMN 元素。'
                    . '可导出的解析器键只有 %s；custom（调用自定义类方法）与 wfSubProcess（子流程）'
                    . '在 BPMN 子集里没有等价物，强转成 userTask 会改变语义，因此直接失败。',
                    $nodeId,
                    $parserKey,
                    implode('/', array_keys(self::PARSER_KEY_TO_BPMN))
                ));
            }

            // 任务类节点：优先用导入时记下的原 BPMN 类型，否则按 userTask 导出
            if ($parserKey === 'task') {
                $declared = $rawNode['properties'][self::BPMN_TYPE_KEY] ?? null;

                if (is_string($declared) && in_array($declared, self::TASK_BPMN_TYPES, true)) {
                    $bpmnElement = $declared;
                }
            }

            $hasPoint = is_numeric($rawNode['x'] ?? null) && is_numeric($rawNode['y'] ?? null);

            $nodes[$nodeId] = [
                'id'           => $nodeId,
                'bpmn_element' => $bpmnElement,
                'name'         => $this->extractTextValue($rawNode),
                'x'            => $hasPoint ? (int) $rawNode['x'] : null,
                'y'            => $hasPoint ? (int) $rawNode['y'] : null,
                'has_point'    => $hasPoint,
            ];
        }

        return $nodes;
    }

    /**
     * 归一化待导出的连线
     *
     * @param array $logicFlow LogicFlow 图
     * @param array<string, array<string, mixed>> $nodes 已归一化的节点
     * @return list<array{id: string, source: string, target: string, name: string, condition: string|null, is_default: bool}>
     * @throws BpmnConversionException 连线缺端点、端点不存在、id 重复时
     */
    private function normalizeExportFlows(array $logicFlow, array $nodes): array
    {
        $rawEdges = $logicFlow['edges'] ?? [];

        if (!is_array($rawEdges)) {
            throw new BpmnConversionException(
                'BPMN 导出失败：edges 必须是数组，实际是 ' . get_debug_type($rawEdges) . '。'
            );
        }

        $flows = [];
        $seenIds = [];

        foreach ($rawEdges as $index => $rawEdge) {
            if (!is_array($rawEdge)) {
                throw new BpmnConversionException(
                    'BPMN 导出失败：edges 的元素必须是数组，实际是 ' . get_debug_type($rawEdge) . '。'
                );
            }

            $source = (string) ($rawEdge['sourceNodeId'] ?? $rawEdge['source_node_id'] ?? '');
            $target = (string) ($rawEdge['targetNodeId'] ?? $rawEdge['target_node_id'] ?? '');

            if ($source === '' || $target === '') {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导出失败：第 %d 条边缺少 sourceNodeId/targetNodeId（source=%s, target=%s）。',
                    (int) $index + 1,
                    $source !== '' ? $source : '空',
                    $target !== '' ? $target : '空'
                ));
            }

            foreach ([$source, $target] as $nodeId) {
                if (!isset($nodes[$nodeId])) {
                    throw new BpmnConversionException(sprintf(
                        'BPMN 导出失败：连线 %s → %s 引用了未声明的节点「%s」。',
                        $source,
                        $target,
                        $nodeId
                    ));
                }
            }

            $flowId = (string) ($rawEdge['id'] ?? '');

            if ($flowId === '') {
                $flowId = 'SequenceFlow_' . ((int) $index + 1);
            }

            if (isset($seenIds[$flowId])) {
                throw new BpmnConversionException(sprintf(
                    'BPMN 导出失败：连线 id「%s」重复。BPMN 要求 id 在文档内唯一。',
                    $flowId
                ));
            }

            $seenIds[$flowId] = true;

            $properties = is_array($rawEdge['properties'] ?? null) ? $rawEdge['properties'] : [];

            // 条件优先级：BPMN 原文 > 主应用语义键 > 引擎键。
            // 原文优先是关键：导入时把 EL 桥接成了 PHP，导出要还原成作者当初写的那一份
            $condition = $properties[self::BPMN_CONDITION_KEY]
                ?? $properties[self::EDGE_CONDITION_KEY]
                ?? $properties[self::EDGE_EXPR_KEY]
                ?? null;

            if (!is_string($condition) || trim($condition) === '') {
                $condition = null;
            }

            $name = $properties[self::RESERVED_PREFIX . 'name'] ?? null;

            if (!is_string($name) || $name === '') {
                $name = $this->extractTextValue($rawEdge);
            }

            $flows[] = [
                'id'         => $flowId,
                'source'     => $source,
                'target'     => $target,
                'name'       => $name,
                'condition'  => $condition,
                'is_default' => (bool) ($properties[self::BPMN_DEFAULT_KEY] ?? false),
            ];
        }

        return $flows;
    }

    /**
     * 计算导出用的节点坐标
     *
     * 全部节点都有 `x`/`y` 时原样透传（这样 `fromBpmn(toBpmn(g))` 的坐标是不动点）；
     * 任一节点缺坐标就整体退化为 BFS 布局 —— 半套坐标会让 DI 里的节点互相重叠，
     * 而「一半按原坐标、一半按布局」既不可预测也没法解释。
     *
     * @param array<string, array<string, mixed>> $nodes 已归一化的节点
     * @param list<array<string, mixed>> $flows 已归一化的连线
     * @return array<string, array{x: int, y: int}>
     */
    private function resolveExportPositions(array $nodes, array $flows): array
    {
        $complete = true;
        foreach ($nodes as $node) {
            if (!$node['has_point']) {
                $complete = false;
                break;
            }
        }

        if ($complete) {
            $positions = [];
            foreach ($nodes as $nodeId => $node) {
                $positions[$nodeId] = ['x' => $node['x'], 'y' => $node['y']];
            }

            return $positions;
        }

        $targets = [];
        foreach ($flows as $flow) {
            $targets[$flow['source']][] = $flow['target'];
        }

        $startId = null;
        foreach ($nodes as $nodeId => $node) {
            if ($node['bpmn_element'] === 'startEvent') {
                $startId = $nodeId;
                break;
            }
        }

        return $this->computeLayout(array_keys($nodes), $targets, $startId);
    }

    /**
     * 构造 BPMN DI 段（只写节点外接矩形，不写连线路点）
     *
     * ⚠️ DI 元素一律用 `createElement('前缀:名')` 而不是 `createElementNS()` 构造：
     * `createElementNS()` 会把 xmlns 声明**钉在新元素自己身上**（元素创建时还没有父节点，
     * libxml 没机会去祖先里复用同名声明），于是一份 5 节点的图会输出 11 遍
     * `xmlns:dc="…"`。前缀改由骨架里已声明的那份解析，序列化结果与 bpmn.io 一致；
     * 命名空间仍然正确 —— `BpmnConverterTest` 会把导出的 XML 重新解析，
     * 断言 `getElementsByTagNameNS(BPMNDI_NS, 'BPMNShape')` / `(DC_NS, 'Bounds')`
     * 能按 URI 找到每一份元素，前缀解析一旦失效该用例立刻变红。
     *
     * @param DOMDocument $dom 文档
     * @param string $processId 流程 id
     * @param array<string, array<string, mixed>> $nodes 已归一化的节点
     * @param array<string, array{x: int, y: int}> $positions 节点中心点坐标
     * @return DOMElement `<bpmndi:BPMNDiagram>`
     */
    private function buildDiagram(DOMDocument $dom, string $processId, array $nodes, array $positions): DOMElement
    {
        $diagram = $dom->createElement('bpmndi:BPMNDiagram');
        $diagram->setAttribute('id', 'BPMNDiagram_' . $processId);

        $plane = $dom->createElement('bpmndi:BPMNPlane');
        $plane->setAttribute('id', 'BPMNPlane_' . $processId);
        $plane->setAttribute('bpmnElement', $processId);

        foreach (array_keys($nodes) as $nodeId) {
            $center = $positions[$nodeId];

            $shape = $dom->createElement('bpmndi:BPMNShape');
            $shape->setAttribute('id', 'BPMNShape_' . $nodeId);
            $shape->setAttribute('bpmnElement', $nodeId);

            $bounds = $dom->createElement('dc:Bounds');
            // 中心点 → 外接矩形左上角
            $bounds->setAttribute('x', (string) ($center['x'] - (int) (self::DEFAULT_NODE_WIDTH / 2)));
            $bounds->setAttribute('y', (string) ($center['y'] - (int) (self::DEFAULT_NODE_HEIGHT / 2)));
            $bounds->setAttribute('width', (string) self::DEFAULT_NODE_WIDTH);
            $bounds->setAttribute('height', (string) self::DEFAULT_NODE_HEIGHT);

            $shape->appendChild($bounds);
            $plane->appendChild($shape);
        }

        $diagram->appendChild($plane);

        return $diagram;
    }

    // ---------------------------------------------------------------------
    // 公共小工具
    // ---------------------------------------------------------------------

    /**
     * 判断元素是否属于 BPMN 语义模型命名空间
     *
     * 「无命名空间」也算 BPMN：`<definitions>…</definitions>` 这种省掉 xmlns 的写法在
     * 手写/内嵌片段里很常见，把它判成非法会让「无前缀」这一档支持落空。
     * 前缀（`bpmn:` / `bpmn2:`）不参与判断 —— 前缀只是 URI 的别名。
     *
     * @param DOMElement $element 元素
     * @return bool
     */
    private function isBpmnElement(DOMElement $element): bool
    {
        $namespace = $element->namespaceURI;

        return $namespace === null || $namespace === '' || $namespace === self::BPMN_NS;
    }

    /**
     * 取直接子元素（跳过文本/注释节点）
     *
     * @param DOMElement $parent 父元素
     * @return list<DOMElement>
     */
    private function elementChildren(DOMElement $parent): array
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * 找第一个指定 localName 的 BPMN 子元素
     *
     * @param DOMElement $parent 父元素
     * @param string $localName 元素本地名
     * @return DOMElement|null
     */
    private function findChild(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($this->elementChildren($parent) as $child) {
            if ($child->localName === $localName && $this->isBpmnElement($child)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * 收集「子集外」的子元素，返回带上下文的可读描述
     *
     * @param DOMElement $parent 父元素
     * @param list<string> $allowlist 允许出现的子元素本地名
     * @param string $context 上下文（写进报错信息）
     * @return list<string>
     */
    private function collectUnsupportedChildren(DOMElement $parent, array $allowlist, string $context): array
    {
        $unsupported = [];

        foreach ($this->elementChildren($parent) as $child) {
            if (!$this->isBpmnElement($child)) {
                continue;
            }

            $localName = (string) $child->localName;

            if (in_array($localName, $allowlist, true)) {
                continue;
            }

            $unsupported[] = sprintf('<%s>（%s 内）', $localName, $context);
        }

        return $unsupported;
    }

    /**
     * 创建一个只含文本的 BPMN 元素（`incoming` / `outgoing` 用）
     *
     * @param DOMDocument $dom 文档
     * @param string $qualifiedName 带前缀的元素名
     * @param string $text 文本内容
     * @return DOMElement
     */
    private function elementWithText(DOMDocument $dom, string $qualifiedName, string $text): DOMElement
    {
        $element = $dom->createElementNS(self::BPMN_NS, $qualifiedName);
        $element->appendChild($dom->createTextNode($text));

        return $element;
    }

    /**
     * 取节点的显示名（LogicFlow 的 `text.value`，引擎也从这里读 display_name）
     *
     * @param array $raw LogicFlow 节点/边
     * @return string
     */
    private function extractTextValue(array $raw): string
    {
        $text = $raw['text'] ?? null;

        if (is_object($text)) {
            $text = (array) $text;
        }

        if (!is_array($text) || !array_key_exists(self::TEXT_VALUE_KEY, $text)) {
            return '';
        }

        $value = $text[self::TEXT_VALUE_KEY];

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * 把任意字符串收敛成合法的 XML NCName（BPMN 的 id/属性值都要求它）
     *
     * @param string $value 原值（一般是 LogicFlow 图的 `name`）
     * @param string $fallback 全空时的兜底值
     * @return string
     */
    private function sanitizeId(string $value, string $fallback): string
    {
        $id = preg_replace('/[^A-Za-z0-9_.\-]/', '_', trim($value));

        if (!is_string($id) || $id === '') {
            return $fallback;
        }

        // NCName 不能以数字、点、连字符开头
        if (preg_match('/^[0-9.\-]/', $id) === 1) {
            $id = 'Process_' . $id;
        }

        return $id;
    }
}
