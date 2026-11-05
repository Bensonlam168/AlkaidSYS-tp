<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Definition;

use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowConnectionInterface;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\Types\ApprovalNode;
use Alkaid\Workflow\Engine\Nodes\Types\ConditionNode;
use Alkaid\Workflow\Engine\Nodes\Types\CountersignNode;
use Alkaid\Workflow\Engine\Nodes\Types\DataOperationNode;
use Alkaid\Workflow\Engine\Nodes\Types\DelayNode;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\GatewayNode;
use Alkaid\Workflow\Engine\Nodes\Types\HttpRequestNode;
use Alkaid\Workflow\Engine\Nodes\Types\NotifyNode;
use Alkaid\Workflow\Engine\Nodes\Types\ScriptNode;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;
use Alkaid\Workflow\Engine\parser\INodeParser;

/**
 * 工作流定义体 ⇄ LogicFlow JSON 序列化器
 *
 * 定案前提（不要推翻）：工作流定义体的**唯一执行格式是 LogicFlow JSON**，存在
 * `workflow_definitions.nodes_config`。原因不是取舍，而是三条事实互相咬合：
 * 1. 引擎只吃 LogicFlow —— `ModelParser::parse()` 直接消费 `{nodes, edges}`
 *    （src/parser/ModelParser.php:44-70，节点类型要带 `ingenious:` 前缀）；
 * 2. 前端设计器 `lf.getGraphData()` 直接产出同一结构，不需要第三套中间格式；
 * 3. `ProcessDefineEntity::CONTENT_KEY`（content）直接指向 `nodes_config`，
 *    且 `hasEngineReadableContent()` 只认 `{nodes, edges}` 两键（src/Entities/ProcessDefineEntity.php:89-97）。
 * `bpmn_xml` 列保留给导入/导出，**不作执行源**。
 *
 * 本类负责两个方向：
 * - `toLogicFlow(WorkflowDefinition)` —— 主应用定义 → 引擎可直接 `ModelParser::parse()` 的结构；
 * - `fromLogicFlow(array)` —— 库里的 JSON → 主应用 `WorkflowDefinition`（经 `WorkflowBuilder`）。
 *
 * 字段映射见 {@see self::nodeTypeMap()}，实现要点：
 * - 节点 `type` = `ingenious:` + **解析器键**（不是主应用类型）。两者不是一回事：
 *   主应用只有 start/end/task/gateway/event/subprocess/custom 这几个 `getType()`，
 *   而引擎按 decision/fork/join 区分网关，所以网关要按 sub_type + direction 再细分；
 * - 节点 `properties` = 主应用 `config` **摊平** + `sub_type` + `layout` + 少量 `_alkaid_*` 保留键。
 *   摊平是必须的：引擎各 `*Parser` 读的就是 `properties.<?>`（如 TaskParser 读 `properties.form`），
 *   主应用的 `config` 里本来就是这些业务键，直接摊平才能让引擎真的读到；
 * - 节点 `text` = `{value: 显示名}`：引擎只从这个位置取 display_name（AbstractINodeParser:39-45）；
 * - 节点 `x`/`y`：主应用模型没有坐标列。若 `config.layout` / `config.x,y` 里有坐标就透传，
 *   否则按**拓扑 BFS 分层**给一套确定性默认布局（理由见 {@see self::computeLayout()}），
 *   并同时写进 `properties.layout`，让坐标能随定义一起持久化；
 * - 边：`source_node_id`/`target_node_id`/`condition` 直接映射，条件另外复制一份到 `properties.expr`
 *   （引擎 DecisionModel 只读 expr），几何只写 `pointsList`（写 startPoint/endPoint 会让引擎崩，见
 *   {@see self::connectionToLogicFlow()}）。
 *
 * 无法映射的情形（`event` 类型的 DelayNode、inclusive/event_based 网关、引擎的 custom/wfSubProcess
 * 反向还原）一律抛 {@see LogicFlowSerializationException}，不做静默降级。
 *
 * @package Alkaid\Workflow\Engine\Definition
 */
class LogicFlowSerializer
{
    /**
     * 节点类型前缀（与引擎解析器同源，避免两处各写一份字符串）
     */
    public const NODE_TYPE_PREFIX = INodeParser::NODE_NAME_PREFIX;

    /**
     * 图级元数据键
     *
     * 放在 LogicFlow 图顶层，键名带下划线避免与设计器字段冲突。
     * 引擎侧读不到它也不会报错：`ArrayHelper::arrayToObject()` + `PropertyCopier`
     * 只复制 `LfModel` 声明过的属性（src/libs/utils/PropertyCopier.php:59-83），未知键被忽略。
     */
    public const GRAPH_META_KEY = '_alkaid';

    /**
     * 节点/边 `properties` 里的保留键前缀
     *
     * 主应用节点的 name/display_name/description/metadata 与连接的 name/type/priority/...
     * 在 LogicFlow 里没有标准位置，只能用保留键承载，否则 round-trip 会丢字段。
     */
    public const RESERVED_PREFIX = '_alkaid_';

    /**
     * 边类型常量
     *
     * 实测**没有任何解析器读边的 type**：`AbstractINodeParser::parse()` 只取
     * `edge->getId()` / `getTargetNodeId()` / `properties.expr` / `startPoint` / `endPoint` / `pointsList`
     * （src/parser/AbstractINodeParser.php:58-88），`ModelParser` 也不看。
     * 因此这里填 LogicFlow 折线边的惯例值，主应用连接类型另存 `_alkaid_type` 以保 round-trip。
     */
    public const EDGE_TYPE = 'polyline';

    /**
     * 边属性键：条件表达式
     *
     * 引擎读的键是 `expr`（`INodeParser::EXPR_KEY`，见 AbstractINodeParser:66 与 DecisionModel:49），
     * 主应用语义键是 `condition`（`WorkflowConnection::getCondition()`）。两者都写，见
     * {@see self::connectionToLogicFlow()}。
     */
    public const EDGE_EXPR_KEY = INodeParser::EXPR_KEY;

    /**
     * 默认布局：原点
     */
    public const LAYOUT_ORIGIN_X = 200;
    public const LAYOUT_ORIGIN_Y = 160;

    /**
     * 默认布局：列间距（一列一个 BFS 层级）
     */
    public const LAYOUT_X_STEP = 220;

    /**
     * 默认布局：行间距（同层节点自上而下排开）
     */
    public const LAYOUT_Y_STEP = 140;

    /**
     * 引擎注册的 8 个节点解析器键（WorkflowContainer::buildCoreServices 与 Configuration::defaultService 一致）
     *
     * 只有这 8 个键会被 `ServiceContext::find()` 找到；其余类型在 `ModelParser::parse()` 里
     * 因 `$nodeParser != null` 判假而被**静默丢弃**。
     */
    public const PARSER_KEYS = [
        'start',
        'end',
        'task',
        'decision',
        'fork',
        'join',
        'custom',
        'wfSubProcess',
    ];

    /**
     * 主应用 `getType()` ⇒ 解析器键（网关单独按子类型细分，见 gatewayParserKey()）
     */
    private const TYPE_TO_PARSER_KEY = [
        NodeInterface::TYPE_START      => 'start',
        NodeInterface::TYPE_END        => 'end',
        NodeInterface::TYPE_TASK       => 'task',
        NodeInterface::TYPE_CUSTOM     => 'custom',
        NodeInterface::TYPE_SUBPROCESS => 'wfSubProcess',
    ];

    /**
     * 节点类白名单：短类名 ⇒ FQCN
     *
     * 反向还原**只允许**这份清单里的类。`nodes_config` 是用户可写的 JSON 列，
     * 直接 `new $class` 等于把「从库里读到的字符串」当类名实例化 —— 白名单把这条路堵死。
     */
    private const NODE_CLASS_WHITELIST = [
        'StartNode'         => StartNode::class,
        'EndNode'           => EndNode::class,
        'TaskNode'          => TaskNode::class,
        'ApprovalNode'      => ApprovalNode::class,
        'CountersignNode'   => CountersignNode::class,
        'GatewayNode'       => GatewayNode::class,
        'ConditionNode'     => ConditionNode::class,
        'DelayNode'         => DelayNode::class,
        'DataOperationNode' => DataOperationNode::class,
        'HttpRequestNode'   => HttpRequestNode::class,
        'NotifyNode'        => NotifyNode::class,
        'ScriptNode'        => ScriptNode::class,
    ];

    /**
     * 解析器键 ⇒ 反向还原的缺省节点类（仅用于「没有 _alkaid_node_class 的外来 LogicFlow」）
     *
     * `custom` / `wfSubProcess` 故意缺失：主应用没有对应节点类，无法还原，只能报错。
     */
    private const DEFAULT_CLASS_BY_PARSER_KEY = [
        'start'    => 'StartNode',
        'end'      => 'EndNode',
        'task'     => 'TaskNode',
        'decision' => 'GatewayNode',
        'fork'     => 'GatewayNode',
        'join'     => 'GatewayNode',
    ];

    /**
     * 主应用定义 → LogicFlow JSON（引擎 `ModelParser::parse()` 的输入）
     *
     * @param WorkflowDefinition $definition 主应用工作流定义
     * @return array{nodes: array, edges: array, name: string, display_name: string, type: string}
     * @throws LogicFlowSerializationException 存在无法映射的节点时
     */
    public function toLogicFlow(WorkflowDefinition $definition): array
    {
        $nodes = $definition->getNodes();
        $layout = $this->computeLayout($definition);

        // 先算全部节点坐标：边的 startPoint/endPoint 要用到
        $points = [];
        foreach ($nodes as $nodeId => $node) {
            $points[$nodeId] = $this->resolvePoint($node, $layout[$nodeId]);
        }

        $lfNodes = [];
        foreach ($nodes as $nodeId => $node) {
            $lfNodes[] = [
                'id'         => $node->getId(),
                'type'       => self::NODE_TYPE_PREFIX . self::parserKeyForNode($node),
                // LfNode::$x/$y 是 int（src/model/logicflow/LfNode.php:34-35），必须给整数，
                // 给浮点会在 PropertyCopier 赋值时丢精度并被 PHP 8 记为 deprecation
                'x'          => $points[$nodeId]['x'],
                'y'          => $points[$nodeId]['y'],
                // 引擎解析器从 text.value 取显示名（AbstractINodeParser:39-45 + ProcessFlowUtils::variableToDict）
                'text'       => ['value' => $node->getDisplayName()],
                'properties' => $this->nodeProperties($node, $points[$nodeId]),
            ];
        }

        $lfEdges = [];
        foreach ($definition->getConnections() as $connection) {
            $lfEdges[] = $this->connectionToLogicFlow($connection, $points);
        }

        return [
            'name'         => $definition->getName(),
            'display_name' => $definition->getDisplayName(),
            'type'         => $definition->getType(),
            // 必须是 list（array_values）：json_encode 后才是数组，
            // ProcessDefineEntity::hasEngineReadableContent() 要求 nodes/edges 是数组
            'nodes'        => $lfNodes,
            'edges'        => $lfEdges,
            self::GRAPH_META_KEY => [
                'id'          => $definition->getId(),
                'description' => $definition->getDescription(),
                'status'      => $definition->getStatus(),
                'version'     => $definition->getVersion(),
                'tenant_id'   => $definition->getTenantId(),
                'metadata'    => $definition->getMetadata(),
                'config'      => $definition->getConfig(),
            ],
        ];
    }

    /**
     * LogicFlow JSON → 主应用定义
     *
     * 只做机械还原：不验证拓扑、不补节点、不猜缺省连线（这些该由调用方或
     * `WorkflowDefinition::validate()` 负责）。缺字段按缺省值处理。
     *
     * @param array $graph LogicFlow 图（`nodes_config` 列的内容）
     * @return WorkflowDefinition
     * @throws LogicFlowSerializationException 节点类型无法还原时
     */
    public function fromLogicFlow(array $graph): WorkflowDefinition
    {
        $meta = $graph[self::GRAPH_META_KEY] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $name = (string) ($graph['name'] ?? '');

        $builder = WorkflowBuilder::create($name !== '' ? $name : 'workflow', [
            'id'           => $meta['id'] ?? null,
            'display_name' => $graph['display_name'] ?? null,
            'description'  => $meta['description'] ?? '',
            'type'         => $graph['type'] ?? WorkflowDefinitionInterface::TYPE_BUSINESS,
            'status'       => $meta['status'] ?? WorkflowDefinitionInterface::STATUS_DRAFT,
            'version'      => $meta['version'] ?? 1,
            'tenant_id'    => $meta['tenant_id'] ?? null,
            'metadata'     => is_array($meta['metadata'] ?? null) ? $meta['metadata'] : [],
            'config'       => is_array($meta['config'] ?? null) ? $meta['config'] : [],
        ]);

        $definition = $builder->getDefinition();

        $rawNodes = $graph['nodes'] ?? [];
        if (is_array($rawNodes)) {
            foreach ($rawNodes as $rawNode) {
                // addNode(..., false)：不让 builder 自动串线，连线只从 edges 还原，
                // 否则「并行分支 / 跳线 / 回环」会被额外的顺序连接污染
                $builder->addNode($this->nodeFromLogicFlow($rawNode), false);
            }
        }

        $rawEdges = $graph['edges'] ?? [];
        if (is_array($rawEdges)) {
            foreach ($rawEdges as $rawEdge) {
                // 直接 addConnection 而不是 builder->connect()：后者会 new 一个连接并**重新生成 id**，
                // 连接 id 就没法 round-trip 了
                $definition->addConnection($this->connectionFromLogicFlow($rawEdge));
            }
        }

        return $definition;
    }

    /**
     * 主应用 node `getType()`/子类型 → 引擎解析器键
     *
     * 这是本序列化器的核心映射，单测与文档都直接引用它。
     *
     * @param NodeInterface $node 节点
     * @return string 8 个解析器键之一
     * @throws LogicFlowSerializationException 无可映射解析器时
     */
    public static function parserKeyForNode(NodeInterface $node): string
    {
        if ($node->getType() === NodeInterface::TYPE_GATEWAY) {
            return self::gatewayParserKey($node);
        }

        $parserKey = self::TYPE_TO_PARSER_KEY[$node->getType()] ?? null;

        if ($parserKey === null) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s（%s，type=%s）在引擎里没有对应的节点解析器：'
                . '引擎只注册了 %s 这 %d 个键（WorkflowContainer::buildCoreServices），「%s」不在其中。'
                . '未注册类型会被 ModelParser::parse() 静默丢弃（src/parser/ModelParser.php:81-97），'
                . '所以这里直接失败。可选处置：① 给引擎补该类型的 *Parser 并在上述注册表登记；'
                . '② 改用语义等价的可映射节点。**不要塞进 custom** —— 那会变成调用自定义类方法，语义不同。',
                $node->getId(),
                get_debug_type($node),
                $node->getType(),
                implode('/', self::PARSER_KEYS),
                count(self::PARSER_KEYS),
                $node->getType()
            ));
        }

        return $parserKey;
    }

    /**
     * 主应用节点类型映射表（文档/测试用）
     *
     * 键为主应用 `getType()`，值为该类型下「子类型/方向 ⇒ 解析器键」的对照。
     * `!!` 后缀表示**故意不支持**（引擎无等价模型），遇到即抛异常。
     *
     * @return array<string, array<string, string>>
     */
    public static function nodeTypeMap(): array
    {
        return [
            NodeInterface::TYPE_START      => ['*' => 'start'],
            NodeInterface::TYPE_END        => ['*' => 'end'],
            NodeInterface::TYPE_TASK       => ['*' => 'task'],
            NodeInterface::TYPE_CUSTOM     => ['*' => 'custom'],
            NodeInterface::TYPE_SUBPROCESS => ['*' => 'wfSubProcess'],
            NodeInterface::TYPE_GATEWAY    => [
                NodeInterface::GATEWAY_EXCLUSIVE => 'decision',
                NodeInterface::GATEWAY_PARALLEL . '+diverging' => 'fork',
                NodeInterface::GATEWAY_PARALLEL . '+converging' => 'join',
                NodeInterface::GATEWAY_INCLUSIVE . '!!' => '无等价模型（inclusive 是「全部匹配分支」，引擎无对应实现）',
                NodeInterface::GATEWAY_EVENT . '!!'     => '无等价模型（事件网关依赖事件总线）',
            ],
            NodeInterface::TYPE_EVENT      => [
                '!!' => '无等价模型（DelayNode 是定时等待，引擎 8 个解析器里没有 timer/event）',
            ],
        ];
    }

    /**
     * 网关细分映射：sub_type + direction ⇒ decision/fork/join
     *
     * 依据：
     * - `DecisionModel::exec()` 按边表达式**只走一条**分支（src/model/DecisionModel.php:41-65）
     *   —— 对应主应用排他网关照选一条；
     * - `ForkModel::exec()` 无条件执行**全部**出边（src/model/ForkModel.php:31-34）—— 对应并行分支；
     * - `JoinModel::exec()` 走合并处理器等所有分支到齐（src/model/JoinModel.php:31-37）—— 对应并行汇聚。
     *
     * @param NodeInterface $node 网关节点
     * @return string 解析器键
     * @throws LogicFlowSerializationException 包容/事件网关或未知子类型
     */
    private static function gatewayParserKey(NodeInterface $node): string
    {
        // 未声明 sub_type 时引擎与主应用都以排他网关为缺省（GatewayNode::getSubType()）
        $subType = $node->getSubType() ?? NodeInterface::GATEWAY_EXCLUSIVE;

        if ($subType === NodeInterface::GATEWAY_EXCLUSIVE) {
            return 'decision';
        }

        if ($subType === NodeInterface::GATEWAY_PARALLEL) {
            return self::gatewayDirection($node) === GatewayNode::DIRECTION_CONVERGING
                ? 'join'
                : 'fork';
        }

        if ($subType === NodeInterface::GATEWAY_INCLUSIVE) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s（%s）是包容网关（inclusive），引擎没有等价模型：'
                . 'ForkModel 会无条件执行全部出边（忽略条件），DecisionModel 只会走一条，'
                . '两者都会改变语义，因此不序列化。请改用排他网关 + 明确的边条件。',
                $node->getId(),
                get_debug_type($node)
            ));
        }

        if ($subType === NodeInterface::GATEWAY_EVENT) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s（%s）是事件网关（event_based），引擎没有等价模型（无事件总线驱动的网关解析器）。',
                $node->getId(),
                get_debug_type($node)
            ));
        }

        throw new LogicFlowSerializationException(sprintf(
            '节点 %s（%s）的网关子类型「%s」没有映射规则，可用值为 %s。',
            $node->getId(),
            get_debug_type($node),
            $subType,
            implode('/', [
                NodeInterface::GATEWAY_EXCLUSIVE,
                NodeInterface::GATEWAY_PARALLEL,
                NodeInterface::GATEWAY_INCLUSIVE,
                NodeInterface::GATEWAY_EVENT,
            ])
        ));
    }

    /**
     * 网关方向：分支（diverging，缺省）还是汇聚（converging）
     *
     * @param NodeInterface $node 网关节点
     * @return string
     */
    private static function gatewayDirection(NodeInterface $node): string
    {
        if ($node instanceof GatewayNode) {
            return $node->getDirection();
        }

        // ConditionNode 没有 getDirection()，只有同类配置键可用
        $config = $node->getConfig();

        return (string) ($config['direction'] ?? GatewayNode::DIRECTION_DIVERGING);
    }

    /**
     * 主应用节点 → LogicFlow 节点 `properties`
     *
     * 摊平 `config` 是硬要求：引擎各解析器直接读 `properties.form` / `properties.expr` /
     * `properties.assignee` 等（见 TaskParser / DecisionParser / CustomParser / WfSubProcessParser），
     * 不摊平引擎就一个业务属性都读不到。
     *
     * @param NodeInterface $node 节点
     * @param array{x: int, y: int} $point 该节点最终坐标（同时写入 properties.layout，见下）
     * @return array
     * @throws LogicFlowSerializationException 业务配置里出现保留键前缀时
     */
    private function nodeProperties(NodeInterface $node, array $point): array
    {
        $properties = [];
        foreach ($node->getConfig() as $key => $value) {
            if (str_starts_with((string) $key, self::RESERVED_PREFIX)) {
                throw new LogicFlowSerializationException(sprintf(
                    '节点 %s 的配置键「%s」使用了序列化器保留前缀「%s」，无法与序列化元数据区分。'
                    . '请重命名该配置项。',
                    $node->getId(),
                    (string) $key,
                    self::RESERVED_PREFIX
                ));
            }
            $properties[$key] = $value;
        }

        // 说明：name / display_name / description 不在这里强写。
        // - 它们若在 config 里，上面已经摊平进来了（`AbstractNode::initFromConfig()` 也正是从 config 读回的）；
        // - 显示名另外还写在节点 `text.value` 上，这正是引擎取 display_name 的位置
        //   （AbstractINodeParser:39-45），反向还原也从那里读。
        // 强写它们会把「类默认值」当成用户数据落进 JSON：例如 TaskNode 的 getDescription() 返回
        // 「任务节点」，写进 properties 后就会永久留在业务配置里。

        // 子类型：主应用常常是「派生值」（如 GatewayNode 从 gateway_type 推出来），
        // 直接摊平 config 会漏掉它，反向还原就会拿到错误的子类型
        $subType = $node->getSubType();
        if ($subType !== null) {
            $properties['sub_type'] = $subType;
        }

        // 坐标：主应用没有坐标列，只能随 config 一起持久化。
        // 这里**始终**写 layout（默认布局也算），好处是序列化结果一次往返后不再变化：
        // fromLogicFlow() 把节点 x/y 写回 config.layout，下一次 toLogicFlow() 读它 → 同一个值。
        // 若只在「config 里本来就有坐标」时才写，往返就会凭空多出 layout 键，每次保存都产生 diff。
        $properties['layout'] = $point;

        // 只保留引擎侧无对应配置键、又必须活过 round-trip 的字段
        $properties[self::RESERVED_PREFIX . 'node_class'] = self::shortClassName($node);
        $properties[self::RESERVED_PREFIX . 'node_type']  = $node->getType();
        $properties[self::RESERVED_PREFIX . 'metadata']   = $node->getMeta();

        return $properties;
    }

    /**
     * 主应用连接 → LogicFlow 边
     *
     * @param WorkflowConnectionInterface $connection 连接
     * @param array<string, array{x: int, y: int}> $points 节点坐标
     * @return array
     */
    private function connectionToLogicFlow(WorkflowConnectionInterface $connection, array $points): array
    {
        $condition = $connection->getCondition();

        $properties = [];

        // 条件写两份，两份都有实际读者：
        // - `condition`：主应用语义键（WorkflowConnection::getCondition()），round-trip 用；
        // - `expr`：引擎真正读的键（AbstractINodeParser:64-67），DecisionModel::exec() 靠它判断走哪条边。
        //   只写 condition 的话，决策节点在引擎里会抛「Not found next node」。
        if ($condition !== null) {
            $properties['condition']                 = $condition;
            $properties[self::EDGE_EXPR_KEY]         = $condition;
        }

        $properties[self::RESERVED_PREFIX . 'name']     = $connection->getName();
        $properties[self::RESERVED_PREFIX . 'type']     = $connection->getType();
        $properties[self::RESERVED_PREFIX . 'priority'] = $connection->getPriority();
        $properties[self::RESERVED_PREFIX . 'default']  = $connection->isDefault();
        $properties[self::RESERVED_PREFIX . 'label']    = $connection->getLabel();
        $properties[self::RESERVED_PREFIX . 'metadata'] = $connection->getMetadata();

        $edge = [
            'id'           => $connection->getId(),
            'type'         => self::EDGE_TYPE,
            'sourceNodeId' => $connection->getSourceNodeId(),
            'targetNodeId' => $connection->getTargetNodeId(),
            'text'         => ['value' => (string) ($connection->getLabel() ?? '')],
            'properties'   => $properties,
        ];

        // 端点坐标只用于画布渲染（解析器把它拼成 TransitionModel->g，运行期不参与任何判断）。
        //
        // ⚠️ 必须写成 pointsList，**不能**写 startPoint/endPoint —— 实测引擎会崩：
        // `AbstractINodeParser::parse()` 走 `getStartPoint()->getX()`，而 ModelParser 用
        // `PropertyCopier::jsonObjToLfModel()` 复制时并不会把 JSON 里的 `{x,y}` 转成 LfPoint，
        // 于是 getStartPoint() 返回的是 stdClass，直接
        // `Error: Call to undefined method stdClass::getX()`（src/parser/AbstractINodeParser.php:78）。
        // pointsList 分支读的是 `$point->x`（属性访问，不是方法调用），因此在 stdClass 上正常工作。
        // 这条是引擎侧缺陷，不属于本序列化器的修改范围，只能绕开（见交付说明的风险项）。
        $source = $points[$connection->getSourceNodeId()] ?? null;
        $target = $points[$connection->getTargetNodeId()] ?? null;
        if ($source !== null && $target !== null) {
            $edge['pointsList'] = [
                ['x' => $source['x'], 'y' => $source['y']],
                ['x' => $target['x'], 'y' => $target['y']],
            ];
        }

        return $edge;
    }

    /**
     * LogicFlow 节点 → 主应用节点
     *
     * @param mixed $raw LogicFlow 节点数据
     * @return NodeInterface
     * @throws LogicFlowSerializationException 缺字段或类型无法还原时
     */
    private function nodeFromLogicFlow(mixed $raw): NodeInterface
    {
        if (!is_array($raw)) {
            throw new LogicFlowSerializationException('LogicFlow 节点必须是数组/对象：' . get_debug_type($raw));
        }

        $nodeId = (string) ($raw['id'] ?? '');
        if ($nodeId === '') {
            throw new LogicFlowSerializationException('LogicFlow 节点缺少 id，无法还原（节点 id 是连线的唯一定位依据）。');
        }

        $parserKey = $this->parserKeyFromType((string) ($raw['type'] ?? ''), $nodeId);

        $rawProperties = $raw['properties'] ?? [];
        if (!is_array($rawProperties)) {
            $rawProperties = [];
        }
        [$config, $reserved] = $this->splitProperties($rawProperties);

        $class = $this->resolveNodeClass($reserved, $parserKey, $nodeId);

        // 没有 _alkaid_node_class 的外来图（设计器直出）只有解析器键，需按键补回主应用语义
        $config = $this->applyParserKeyDefaults($parserKey, $config);

        // 坐标回写到 config.layout：主应用模型没有坐标列，不写回就等于「每次后端读写都把画布重排一遍」。
        // toLogicFlow() 会优先读它，于是 x/y 能原样透传（见 resolvePoint()）
        $point = $this->extractPoint($raw);
        if ($point !== null) {
            $config['layout'] = $point;
        }

        $subType = $rawProperties['sub_type'] ?? null;

        // 只把 config 交给 fromArray：name/display_name/description/sub_type 都已在 config 里
        // （见 nodeProperties()），走 initFromConfig() 同一条路还原。
        // 若改用 fromArray() 的「顶层字段并入 config」那条路，会把 metadata 等字段塞进 config，
        // 下一次序列化就多出键 —— 往返不再是同一份 JSON。
        $node = $class::fromArray([
            'id'     => $nodeId,
            'config' => $config,
        ]);

        // 显示名：properties.display_name 若存在，已由 initFromConfig() 写进节点；
        // 否则取节点 `text.value` —— 这正是引擎取 display_name 的位置（AbstractINodeParser:39-45），
        // 设计器直出的图也只有这里有显示名。用 setDisplayName() 而不是塞进 config，
        // 免得多出一个 config 键、让往返不再是不动点
        if (!isset($config['display_name'])) {
            $displayName = $this->extractTextValue($raw);
            if ($displayName !== null && $displayName !== '') {
                $node->setDisplayName($displayName);
            }
        }

        // metadata 不在 config 里（它是节点独立字段），用 setMeta() 逐键还原，避免污染 config
        $metadata = $reserved['metadata'] ?? [];
        if (is_array($metadata)) {
            foreach ($metadata as $metaKey => $metaValue) {
                $node->setMeta((string) $metaKey, $metaValue);
            }
        }

        // 完整性校验：主应用类型与还原出的节点类必须一致。
        // 不一致只可能是「有人手改了 nodes_config」，此时早失败远好过把错类型喂给引擎
        $nodeType = $reserved['node_type'] ?? null;
        if (is_string($nodeType) && $nodeType !== '' && $nodeType !== $node->getType()) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s 的数据自相矛盾：%snode_type=「%s」，但按 %snode_class=「%s」还原出的节点类型是「%s」。',
                $nodeId,
                self::RESERVED_PREFIX,
                $nodeType,
                self::RESERVED_PREFIX,
                (string) ($reserved['node_class'] ?? '缺省推断'),
                $node->getType()
            ));
        }

        // 子类型兜底：仅当 config 里没有 sub_type（外来图）且与还原结果不同才补，
        // 避免覆盖节点自身按 gateway_type 等规则推导出来的子类型
        if (is_string($subType) && $node->getSubType() !== $subType) {
            $node->setConfig(array_merge($node->getConfig(), ['sub_type' => $subType]));
        }

        return $node;
    }

    /**
     * LogicFlow 边 → 主应用连接
     *
     * @param mixed $raw LogicFlow 边数据
     * @return WorkflowConnectionInterface
     * @throws LogicFlowSerializationException 缺字段时
     */
    private function connectionFromLogicFlow(mixed $raw): WorkflowConnectionInterface
    {
        if (!is_array($raw)) {
            throw new LogicFlowSerializationException('LogicFlow 边必须是数组/对象：' . get_debug_type($raw));
        }

        $source = (string) ($raw['sourceNodeId'] ?? $raw['source_node_id'] ?? '');
        $target = (string) ($raw['targetNodeId'] ?? $raw['target_node_id'] ?? '');

        if ($source === '' || $target === '') {
            throw new LogicFlowSerializationException(sprintf(
                'LogicFlow 边 %s 缺少 sourceNodeId/targetNodeId（source=%s, target=%s）。',
                (string) ($raw['id'] ?? '-'),
                $source !== '' ? $source : '空',
                $target !== '' ? $target : '空'
            ));
        }

        $rawProperties = $raw['properties'] ?? [];
        if (!is_array($rawProperties)) {
            $rawProperties = [];
        }
        [$properties, $reserved] = $this->splitProperties($rawProperties);

        // condition 优先（主应用语义键），退到 expr（引擎/设计器键）
        $condition = $properties['condition'] ?? $properties[self::EDGE_EXPR_KEY] ?? null;

        return WorkflowConnection::fromArray([
            'id'             => $raw['id'] ?? null,
            'name'           => (string) ($reserved['name'] ?? ''),
            'type'           => (string) ($reserved['type'] ?? WorkflowConnectionInterface::TYPE_SEQUENCE),
            'source_node_id' => $source,
            'target_node_id' => $target,
            'condition'      => is_string($condition) && $condition !== '' ? $condition : null,
            'priority'       => (int) ($reserved['priority'] ?? 0),
            'default'        => (bool) ($reserved['default'] ?? false),
            'label'          => isset($reserved['label']) && is_string($reserved['label']) ? $reserved['label'] : null,
            'metadata'       => is_array($reserved['metadata'] ?? null) ? $reserved['metadata'] : [],
        ]);
    }

    /**
     * 把 LogicFlow 节点 `type`（`ingenious:<key>`）拆成解析器键
     *
     * @param string $type 节点类型串
     * @param string $nodeId 节点 id（仅用于报错）
     * @return string 解析器键
     * @throws LogicFlowSerializationException 前缀不对或键不认识时
     */
    private function parserKeyFromType(string $type, string $nodeId): string
    {
        if (!str_starts_with($type, self::NODE_TYPE_PREFIX)) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s 的类型「%s」没有引擎前缀「%s」，无法判断该用哪个解析器。',
                $nodeId,
                $type,
                self::NODE_TYPE_PREFIX
            ));
        }

        $parserKey = substr($type, strlen(self::NODE_TYPE_PREFIX));

        if (!in_array($parserKey, self::PARSER_KEYS, true)) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s 的类型键「%s」不是引擎注册的解析器键，可用值为 %s。',
                $nodeId,
                $parserKey,
                implode('/', self::PARSER_KEYS)
            ));
        }

        return $parserKey;
    }

    /**
     * 解析要还原成哪个主应用节点类
     *
     * @param array<string, mixed> $reserved 保留键（已去掉前缀）
     * @param string $parserKey 解析器键
     * @param string $nodeId 节点 id（仅用于报错）
     * @return class-string<NodeInterface>
     * @throws LogicFlowSerializationException 类名不在白名单或无法推断时
     */
    private function resolveNodeClass(array $reserved, string $parserKey, string $nodeId): string
    {
        $shortName = $reserved['node_class'] ?? null;

        if (is_string($shortName) && $shortName !== '') {
            if (!isset(self::NODE_CLASS_WHITELIST[$shortName])) {
                throw new LogicFlowSerializationException(sprintf(
                    '节点 %s 声明的类名「%s」不在允许清单内（%s）。' . 'nodes_config 是用户可写数据，'
                    . '不允许按库里的字符串随意实例化类。',
                    $nodeId,
                    $shortName,
                    implode('/', array_keys(self::NODE_CLASS_WHITELIST))
                ));
            }

            return self::NODE_CLASS_WHITELIST[$shortName];
        }

        $defaultShortName = self::DEFAULT_CLASS_BY_PARSER_KEY[$parserKey] ?? null;

        if ($defaultShortName === null) {
            throw new LogicFlowSerializationException(sprintf(
                '节点 %s 的类型是「%s」，但主应用没有对应的节点类，也无法从保留键推断（缺 %snode_class）。'
                . '当前引擎侧只有 custom / wfSubProcess 会落到这里。',
                $nodeId,
                $parserKey,
                self::RESERVED_PREFIX
            ));
        }

        return self::NODE_CLASS_WHITELIST[$defaultShortName];
    }

    /**
     * 外来 LogicFlow（无 `_alkaid_node_class`）按解析器键补齐主应用语义
     *
     * 只填空缺，不覆盖已有值：设计器已经写明的 sub_type/direction 优先。
     *
     * @param string $parserKey 解析器键
     * @param array<string, mixed> $config 节点配置
     * @return array<string, mixed>
     */
    private function applyParserKeyDefaults(string $parserKey, array $config): array
    {
        if ($parserKey === 'decision' && !isset($config['sub_type'])) {
            $config['sub_type'] = NodeInterface::GATEWAY_EXCLUSIVE;
        }

        if (($parserKey === 'fork' || $parserKey === 'join') && !isset($config['sub_type'])) {
            $config['sub_type'] = NodeInterface::GATEWAY_PARALLEL;
            $config['direction'] = $parserKey === 'join'
                ? GatewayNode::DIRECTION_CONVERGING
                : GatewayNode::DIRECTION_DIVERGING;
        }

        return $config;
    }

    /**
     * 拆分节点/边 properties：业务键 与 `_alkaid_*` 保留键
     *
     * @param array<string, mixed> $properties LogicFlow properties
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [业务键, 保留键(去前缀)]
     */
    private function splitProperties(array $properties): array
    {
        $config = [];
        $reserved = [];

        foreach ($properties as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, self::RESERVED_PREFIX)) {
                $reserved[substr($key, strlen(self::RESERVED_PREFIX))] = $value;
                continue;
            }
            $config[$key] = $value;
        }

        return [$config, $reserved];
    }

    /**
     * 取节点 `text.value`（LogicFlow 的文本形状，引擎也从这里取显示名）
     *
     * @param array $raw LogicFlow 节点数据
     * @return string|null
     */
    private function extractTextValue(array $raw): ?string
    {
        $text = $raw['text'] ?? null;

        if (is_object($text)) {
            $text = (array) $text;
        }

        if (!is_array($text) || !array_key_exists('value', $text)) {
            return null;
        }

        $value = $text['value'];

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * 取节点坐标（LogicFlow 侧 `x`/`y`）
     *
     * @param array $raw LogicFlow 节点数据
     * @return array{x: int, y: int}|null
     */
    private function extractPoint(array $raw): ?array
    {
        $x = $raw['x'] ?? null;
        $y = $raw['y'] ?? null;

        if (!is_numeric($x) || !is_numeric($y)) {
            return null;
        }

        return ['x' => (int) $x, 'y' => (int) $y];
    }

    /**
     * 取节点最终坐标：优先透传主应用配置里的坐标，否则用确定性默认布局
     *
     * @param NodeInterface $node 节点
     * @param array{x: int, y: int} $fallback 默认布局坐标
     * @return array{x: int, y: int}
     */
    private function resolvePoint(NodeInterface $node, array $fallback): array
    {
        $config = $node->getConfig();

        $x = null;
        $y = null;

        // 约定：config.layout = {x, y} 是序列化器写回的坐标（fromLogicFlow 的产物）
        $layout = $config['layout'] ?? null;
        if (is_array($layout)) {
            $x = $layout['x'] ?? null;
            $y = $layout['y'] ?? null;
        }

        // 兼容直接给 config.x / config.y 的写法
        if (!is_numeric($x)) {
            $x = $config['x'] ?? null;
        }
        if (!is_numeric($y)) {
            $y = $config['y'] ?? null;
        }

        return [
            'x' => is_numeric($x) ? (int) $x : $fallback['x'],
            'y' => is_numeric($y) ? (int) $y : $fallback['y'],
        ];
    }

    /**
     * 确定性默认布局：按拓扑 BFS 分层
     *
     * 为什么要有坐标：引擎只关心拓扑（`AbstractINodeParser` 只用它拼 `layout`/`g` 字符串，
     * 运行期不参与任何判断），但前端画布必须有点才能画。主应用模型里没有坐标列，
     * 因此这里必须给一套默认值。
     *
     * 为什么不随机/不按数组下标简单平铺：
     * - **必须确定性**：同一份定义每次序列化都要得到同一份 JSON，否则每次保存都产生无意义 diff、
     *   单测无法断言、前端画布每次刷新都跳位置；
     * - **必须看懂拓扑**：一条平铺的链子看不出分支，BFS 分层后「第 N 列 = 走 N 步」，
     *   并行分支自然纵向排开，与 LogicFlow 的水平流向一致。
     *
     * 实现细节：同层节点按**节点注册顺序**排列（不是 id 排序），因此结果只取决于定义本身；
     * 从 start 节点开始 BFS（没有 start 时取第一个注册节点），不可达/孤立节点统一排在最后一层。
     *
     * @param WorkflowDefinition $definition 工作流定义
     * @return array<string, array{x: int, y: int}> 节点 id ⇒ 坐标
     */
    private function computeLayout(WorkflowDefinition $definition): array
    {
        $nodes = $definition->getNodes();
        if ($nodes === []) {
            return [];
        }

        // 邻接表：按连接注册顺序，保证同一份定义得到同一份遍历次序
        $next = [];
        foreach ($definition->getConnections() as $connection) {
            $next[$connection->getSourceNodeId()][] = $connection->getTargetNodeId();
        }

        $startNode = $definition->getStartNode();
        $rootId = $startNode !== null
            ? $startNode->getId()
            : (string) array_key_first($nodes);

        // BFS 分层
        $depth = [$rootId => 0];
        $queue = [$rootId];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($next[$current] ?? [] as $targetId) {
                if (!isset($nodes[$targetId]) || array_key_exists($targetId, $depth)) {
                    continue;
                }
                $depth[$targetId] = $depth[$current] + 1;
                $queue[] = $targetId;
            }
        }

        // 不可达节点：排在最后一层，仍按注册顺序
        $maxDepth = max($depth);
        foreach (array_keys($nodes) as $nodeId) {
            if (!array_key_exists($nodeId, $depth)) {
                $depth[$nodeId] = $maxDepth + 1;
            }
        }

        $layout = [];
        $rows = [];
        foreach (array_keys($nodes) as $nodeId) {
            $layer = $depth[$nodeId];
            $row = $rows[$layer] ?? 0;
            $rows[$layer] = $row + 1;

            $layout[$nodeId] = [
                'x' => self::LAYOUT_ORIGIN_X + $layer * self::LAYOUT_X_STEP,
                'y' => self::LAYOUT_ORIGIN_Y + $row * self::LAYOUT_Y_STEP,
            ];
        }

        return $layout;
    }

    /**
     * 节点短类名（用于 `_alkaid_node_class`）
     *
     * @param NodeInterface $node 节点
     * @return string
     */
    private static function shortClassName(NodeInterface $node): string
    {
        $class = $node::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
