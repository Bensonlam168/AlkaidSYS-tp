<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;
use Alkaid\Workflow\Engine\Definition\LogicFlowSerializationException;
use Alkaid\Workflow\Engine\Definition\LogicFlowSerializer;
use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\model\DecisionModel;
use Alkaid\Workflow\Engine\model\EndModel;
use Alkaid\Workflow\Engine\model\ForkModel;
use Alkaid\Workflow\Engine\model\JoinModel;
use Alkaid\Workflow\Engine\model\StartModel;
use Alkaid\Workflow\Engine\model\TaskModel;
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
use Alkaid\Workflow\Engine\parser\handler\CustomParser;
use Alkaid\Workflow\Engine\parser\handler\DecisionParser;
use Alkaid\Workflow\Engine\parser\handler\EndParser;
use Alkaid\Workflow\Engine\parser\handler\ForkParser;
use Alkaid\Workflow\Engine\parser\handler\JoinParser;
use Alkaid\Workflow\Engine\parser\handler\StartParser;
use Alkaid\Workflow\Engine\parser\handler\TaskParser;
use Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser;
use Alkaid\Workflow\Engine\parser\ModelParser;
use PHPUnit\Framework\TestCase;

/**
 * LogicFlow 序列化器单测
 *
 * 覆盖四件事：
 * 1. 映射表：主应用每个节点类的 `getType()`/`getSubType()` 落到哪个引擎解析器键；
 * 2. 三种典型拓扑（直线 / 条件分支 / 并行 fork-join）的序列化结果；
 * 3. round-trip：`fromLogicFlow(toLogicFlow($def))` 的 id、类型、拓扑与原始一致，且 JSON 是不动点；
 * 4. 与引擎对接：`ModelParser::parse()` 能吃下序列化结果并产出**非空** ProcessModel。
 *
 * 第 4 项是本包的硬验收 —— 只断言「数组里有 nodes/edges」是不够的，格式对不上时
 * `ModelParser::parse()` 会静默返回空模型（src/parser/ModelParser.php:67-69），
 * 那才是真正会打到线上的故障形态。
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Definition
 */
class LogicFlowSerializerTest extends TestCase
{
    /**
     * 引擎注册的节点解析器（与 WorkflowContainer::buildCoreServices() 的键名逐一对应）
     *
     * 单测直接注册这 8 个键：`ModelParser::parse()` 就是按
     * `ServiceContext::find(<去前缀后的 type>)` 找解析器的（src/parser/ModelParser.php:81-84）。
     */
    private const ENGINE_PARSERS = [
        'start'        => StartParser::class,
        'end'          => EndParser::class,
        'task'         => TaskParser::class,
        'decision'     => DecisionParser::class,
        'fork'         => ForkParser::class,
        'join'         => JoinParser::class,
        'custom'       => CustomParser::class,
        'wfSubProcess' => WfSubProcessParser::class,
    ];

    private LogicFlowSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serializer = new LogicFlowSerializer();

        // ModelParser::parse() 依赖静态的 ServiceContext，不注册解析器就什么都解析不出来
        ServiceContext::setContext(self::ENGINE_PARSERS);
    }

    /**
     * 主应用全部节点类 ⇒ getType()/getSubType()/解析器键 的完整清单
     *
     * 这张表就是字段映射的权威口径：发现新增节点类时这里会立刻变红。
     */
    public function testNodeTypeMappingTableCoversEveryBuiltinNodeClass(): void
    {
        // [节点实例, 期望 getType(), 期望 getSubType(), 期望解析器键]
        $cases = [
            [new StartNode('n_start', ['name' => 'start']), NodeInterface::TYPE_START, null, 'start'],
            [new EndNode('n_end', ['name' => 'end']), NodeInterface::TYPE_END, null, 'end'],
            [new TaskNode('n_task', ['name' => 'task']), NodeInterface::TYPE_TASK, NodeInterface::TASK_SERVICE, 'task'],
            [new ApprovalNode('n_approval', ['name' => 'approval']), NodeInterface::TYPE_TASK, NodeInterface::TASK_USER, 'task'],
            [new CountersignNode('n_countersign', ['name' => 'countersign']), NodeInterface::TYPE_TASK, NodeInterface::TASK_USER, 'task'],
            [new DataOperationNode('n_data', ['name' => 'data']), NodeInterface::TYPE_TASK, NodeInterface::TASK_SERVICE, 'task'],
            [new HttpRequestNode('n_http', ['name' => 'http']), NodeInterface::TYPE_TASK, NodeInterface::TASK_SERVICE, 'task'],
            [new NotifyNode('n_notify', ['name' => 'notify']), NodeInterface::TYPE_TASK, NodeInterface::TASK_SEND, 'task'],
            [new ScriptNode('n_script', ['name' => 'script']), NodeInterface::TYPE_TASK, NodeInterface::TASK_SCRIPT, 'task'],
            [
                new GatewayNode('n_exclusive', ['name' => 'exclusive', 'sub_type' => NodeInterface::GATEWAY_EXCLUSIVE]),
                NodeInterface::TYPE_GATEWAY,
                NodeInterface::GATEWAY_EXCLUSIVE,
                'decision',
            ],
            [
                new ConditionNode('n_condition', ['name' => 'condition', 'gateway_type' => NodeInterface::GATEWAY_EXCLUSIVE]),
                NodeInterface::TYPE_GATEWAY,
                NodeInterface::GATEWAY_EXCLUSIVE,
                'decision',
            ],
            [
                new GatewayNode('n_fork', [
                    'name'      => 'fork',
                    'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
                    'direction' => GatewayNode::DIRECTION_DIVERGING,
                ]),
                NodeInterface::TYPE_GATEWAY,
                NodeInterface::GATEWAY_PARALLEL,
                'fork',
            ],
            [
                new GatewayNode('n_join', [
                    'name'      => 'join',
                    'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
                    'direction' => GatewayNode::DIRECTION_CONVERGING,
                ]),
                NodeInterface::TYPE_GATEWAY,
                NodeInterface::GATEWAY_PARALLEL,
                'join',
            ],
        ];

        foreach ($cases as [$node, $expectedType, $expectedSubType, $expectedParserKey]) {
            $this->assertSame($expectedType, $node->getType(), $node::class . ' 的 getType() 变了，映射表需要同步');
            $this->assertSame($expectedSubType, $node->getSubType(), $node::class . ' 的 getSubType() 变了');
            $this->assertSame(
                $expectedParserKey,
                LogicFlowSerializer::parserKeyForNode($node),
                $node::class . ' 应映射到解析器键 ' . $expectedParserKey
            );
            $this->assertContains($expectedParserKey, LogicFlowSerializer::PARSER_KEYS);
        }

        // 对外公布的映射表（LogicFlowSerializer::nodeTypeMap()）必须与上面的实测一致
        $map = LogicFlowSerializer::nodeTypeMap();
        $this->assertSame('start', $map[NodeInterface::TYPE_START]['*']);
        $this->assertSame('end', $map[NodeInterface::TYPE_END]['*']);
        $this->assertSame('task', $map[NodeInterface::TYPE_TASK]['*']);
        $this->assertSame('decision', $map[NodeInterface::TYPE_GATEWAY][NodeInterface::GATEWAY_EXCLUSIVE]);
        $this->assertSame('fork', $map[NodeInterface::TYPE_GATEWAY][NodeInterface::GATEWAY_PARALLEL . '+diverging']);
        $this->assertSame('join', $map[NodeInterface::TYPE_GATEWAY][NodeInterface::GATEWAY_PARALLEL . '+converging']);
        $this->assertArrayHasKey(NodeInterface::TYPE_EVENT, $map, 'DelayNode 的 event 类型必须显式登记为「不支持」');
    }

    /**
     * 无法映射的节点必须**报错**，不能静默降级
     */
    public function testUnmappableNodesAreRejectedLoudly(): void
    {
        // DelayNode：getType() = event，引擎 8 个解析器键里没有 event/timer
        $this->assertSame(NodeInterface::TYPE_EVENT, (new DelayNode('n_delay'))->getType());
        try {
            LogicFlowSerializer::parserKeyForNode(new DelayNode('n_delay'));
            $this->fail('DelayNode 不应被映射到任何解析器键');
        } catch (LogicFlowSerializationException $e) {
            $this->assertStringContainsString('n_delay', $e->getMessage());
            $this->assertStringContainsString('start/end/task/decision/fork/join/custom/wfSubProcess', $e->getMessage());
        }

        // 包容网关：inclusive 是「全部匹配分支」，ForkModel 会无条件全走，DecisionModel 只走一条
        try {
            LogicFlowSerializer::parserKeyForNode(new ConditionNode('n_inclusive', [
                'gateway_type' => ConditionNode::GATEWAY_INCLUSIVE,
            ]));
            $this->fail('包容网关不应被映射到任何解析器键');
        } catch (LogicFlowSerializationException $e) {
            $this->assertStringContainsString('inclusive', $e->getMessage());
        }

        // 事件网关：引擎没有事件总线驱动的网关解析器
        $this->expectException(LogicFlowSerializationException::class);
        LogicFlowSerializer::parserKeyForNode(new GatewayNode('n_event', [
            'sub_type' => NodeInterface::GATEWAY_EVENT,
        ]));
    }

    /**
     * 直线流程：start → task → end
     */
    public function testSerializesStraightLineFlow(): void
    {
        $graph = $this->serializer->toLogicFlow($this->straightFlowDefinition());

        // 引擎侧契约：ProcessDefineEntity::hasEngineReadableContent() 只认这两个数组键
        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);
        $this->assertIsArray($graph['nodes']);
        $this->assertIsArray($graph['edges']);

        $this->assertCount(3, $graph['nodes']);
        $this->assertCount(2, $graph['edges']);

        $this->assertSame(['ingenious:start', 'ingenious:task', 'ingenious:end'], array_column($graph['nodes'], 'type'));
        $this->assertSame(['n_start', 'n_task', 'n_end'], array_column($graph['nodes'], 'id'));

        // 节点 text 走 LogicFlow 的 {value: ...} 形状，引擎从这里取 display_name
        $this->assertSame(['value' => '填写申请'], $graph['nodes'][1]['text']);

        // 边的连线关系与主应用一致；type 是常量（没有任何解析器读它，见序列化器注释）
        $this->assertSame('n_start', $graph['edges'][0]['sourceNodeId']);
        $this->assertSame('n_task', $graph['edges'][0]['targetNodeId']);
        $this->assertSame(LogicFlowSerializer::EDGE_TYPE, $graph['edges'][0]['type']);

        // 边几何只写 pointsList：写 startPoint/endPoint 会让引擎抛
        // 「Call to undefined method stdClass::getX()」（ModelParser 不把 {x,y} 转成 LfPoint）
        $this->assertArrayHasKey('pointsList', $graph['edges'][0]);
        $this->assertArrayNotHasKey('startPoint', $graph['edges'][0]);
        $this->assertArrayNotHasKey('endPoint', $graph['edges'][0]);
        $this->assertSame(
            [
                ['x' => $graph['nodes'][0]['x'], 'y' => $graph['nodes'][0]['y']],
                ['x' => $graph['nodes'][1]['x'], 'y' => $graph['nodes'][1]['y']],
            ],
            $graph['edges'][0]['pointsList']
        );
    }

    /**
     * 条件分支：decision 出两条带条件的边
     */
    public function testSerializesConditionalBranches(): void
    {
        $definition = $this->branchFlowDefinition();
        $graph = $this->serializer->toLogicFlow($definition);

        $byId = [];
        foreach ($graph['nodes'] as $node) {
            $byId[$node['id']] = $node;
        }

        $this->assertSame('ingenious:decision', $byId['n_decision']['type']);

        // 条件必须同时写进 condition（主应用语义）与 expr（引擎 DecisionModel 真正读的键）
        $branchEdges = array_values(array_filter(
            $graph['edges'],
            fn (array $edge) => $edge['sourceNodeId'] === 'n_decision'
        ));
        $this->assertCount(2, $branchEdges);
        $this->assertSame('amount > 1000', $branchEdges[0]['properties']['condition']);
        $this->assertSame('amount > 1000', $branchEdges[0]['properties']['expr']);
        $this->assertSame('amount <= 1000', $branchEdges[1]['properties']['condition']);
        $this->assertSame('amount <= 1000', $branchEdges[1]['properties']['expr']);
    }

    /**
     * 并行：fork（并行分支）与 join（并行汇聚）
     */
    public function testSerializesForkAndJoin(): void
    {
        $graph = $this->serializer->toLogicFlow($this->parallelFlowDefinition());

        $types = array_column($graph['nodes'], 'type', 'id');
        $this->assertSame('ingenious:fork', $types['n_fork']);
        $this->assertSame('ingenious:join', $types['n_join']);

        // fork 两条出边、join 两条入边，拓扑不能丢
        $forkOut = array_filter($graph['edges'], fn (array $edge) => $edge['sourceNodeId'] === 'n_fork');
        $joinIn = array_filter($graph['edges'], fn (array $edge) => $edge['targetNodeId'] === 'n_join');
        $this->assertCount(2, $forkOut);
        $this->assertCount(2, $joinIn);
    }

    /**
     * 硬验收：序列化结果喂给引擎 ModelParser::parse() 必须得到**非空** ProcessModel
     */
    public function testModelParserProducesNonEmptyProcessModel(): void
    {
        $definition = $this->fullDefinition();
        $graph = $this->serializer->toLogicFlow($definition);

        $processModel = ModelParser::parse($graph);

        $this->assertNotEmpty($processModel->getNodes(), 'ModelParser::parse() 产出了空模型：序列化格式与引擎不匹配');
        $this->assertCount(count($definition->getNodes()), $processModel->getNodes());
        $this->assertInstanceOf(StartModel::class, $processModel->getStart());
        $this->assertNotEmpty($processModel->getTasks(), '任务节点没有被解析成 TaskModel');

        // 每类节点都被解析成对应的引擎模型
        $modelsByClass = [];
        foreach ($processModel->getNodes() as $nodeModel) {
            $modelsByClass[$nodeModel::class] = ($modelsByClass[$nodeModel::class] ?? 0) + 1;
        }
        $this->assertSame(1, $modelsByClass[StartModel::class] ?? 0, '应有 1 个 StartModel');
        $this->assertSame(1, $modelsByClass[EndModel::class] ?? 0, '应有 1 个 EndModel');
        $this->assertSame(1, $modelsByClass[DecisionModel::class] ?? 0, '应有 1 个 DecisionModel');
        $this->assertSame(1, $modelsByClass[ForkModel::class] ?? 0, '应有 1 个 ForkModel');
        $this->assertSame(1, $modelsByClass[JoinModel::class] ?? 0, '应有 1 个 JoinModel');
        $this->assertGreaterThanOrEqual(4, $modelsByClass[TaskModel::class] ?? 0, 'task 家族节点都应解析成 TaskModel');

        // 决策节点的出边表达式必须真的落到 TransitionModel 上，否则 DecisionModel 运行期会抛
        // 「Not found next node」（src/model/DecisionModel.php:41-72）
        $decisionModel = $processModel->getNode('n_decision');
        $this->assertInstanceOf(DecisionModel::class, $decisionModel);
        $expressions = array_map(
            fn ($transition) => $transition->getExpr(),
            $decisionModel->getOutputs()
        );
        $this->assertSame(['amount > 1000', 'amount <= 1000'], array_values($expressions));
    }

    /**
     * 走真实链路：`nodes_config` 存的是 JSON 字符串，ModelParser 也接受字符串输入
     */
    public function testModelParserAcceptsJsonStringFromNodesConfig(): void
    {
        $json = json_encode($this->serializer->toLogicFlow($this->straightFlowDefinition()), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $processModel = ModelParser::parse($json);
        $this->assertNotEmpty($processModel->getNodes());
        $this->assertCount(3, $processModel->getNodes());
    }

    /**
     * round-trip：节点 id 集合、类型、连接拓扑必须与原始一致
     */
    public function testRoundTripKeepsIdsTypesAndTopology(): void
    {
        $original = $this->fullDefinition();
        $restored = $this->serializer->fromLogicFlow($this->serializer->toLogicFlow($original));

        // 节点 id 集合
        $this->assertEqualsCanonicalizing(
            array_keys($original->getNodes()),
            array_keys($restored->getNodes())
        );

        // 每个节点的类、类型、子类型、名称、显示名、配置
        foreach ($original->getNodes() as $nodeId => $node) {
            $other = $restored->getNode($nodeId);
            $this->assertNotNull($other, "节点 {$nodeId} 在 round-trip 后丢失");
            $this->assertSame($node::class, $other::class, "节点 {$nodeId} 的类变了");
            $this->assertSame($node->getType(), $other->getType(), "节点 {$nodeId} 的 type 变了");
            $this->assertSame($node->getSubType(), $other->getSubType(), "节点 {$nodeId} 的 sub_type 变了");
            $this->assertSame($node->getName(), $other->getName(), "节点 {$nodeId} 的 name 变了");
            $this->assertSame($node->getDisplayName(), $other->getDisplayName(), "节点 {$nodeId} 的 display_name 变了");

            // 原始配置必须逐键保留（layout 是序列化器回写的坐标，允许新增）
            foreach ($node->getConfig() as $configKey => $configValue) {
                $this->assertArrayHasKey($configKey, $other->getConfig(), "节点 {$nodeId} 丢了配置键 {$configKey}");
                $this->assertSame($configValue, $other->getConfig()[$configKey], "节点 {$nodeId} 的配置 {$configKey} 变了");
            }
        }

        // 连接 id 与拓扑
        $this->assertEqualsCanonicalizing(
            array_keys($original->getConnections()),
            array_keys($restored->getConnections())
        );
        $this->assertSame(
            $this->topologyOf($original),
            $this->topologyOf($restored),
            '连接拓扑在 round-trip 后变了'
        );

        // 连接的 condition / type / priority / default / label 也要活过往返
        foreach ($original->getConnections() as $connectionId => $connection) {
            $other = $restored->getConnection($connectionId);
            $this->assertNotNull($other, "连接 {$connectionId} 在 round-trip 后丢失");
            $this->assertSame($connection->getCondition(), $other->getCondition());
            $this->assertSame($connection->getType(), $other->getType());
            $this->assertSame($connection->getPriority(), $other->getPriority());
            $this->assertSame($connection->isDefault(), $other->isDefault());
            $this->assertSame($connection->getLabel(), $other->getLabel());
        }

        // 定义级字段
        $this->assertSame($original->getId(), $restored->getId());
        $this->assertSame($original->getName(), $restored->getName());
        $this->assertSame($original->getDisplayName(), $restored->getDisplayName());
        $this->assertSame($original->getDescription(), $restored->getDescription());
        $this->assertSame($original->getType(), $restored->getType());
        $this->assertSame($original->getStatus(), $restored->getStatus());
        $this->assertSame($original->getVersion(), $restored->getVersion());
        $this->assertSame($original->getTenantId(), $restored->getTenantId());
        $this->assertSame($original->getMetadata(), $restored->getMetadata());
        $this->assertSame($original->getConfig(), $restored->getConfig());
    }

    /**
     * round-trip 的 JSON 是**不动点**：再序列化一次不会多键、不会少键
     *
     * 这条防的是「每次保存都把画布/配置改一点点」这类慢性数据漂移。
     */
    public function testRoundTripIsStableJsonFixedPoint(): void
    {
        $first = $this->serializer->toLogicFlow($this->fullDefinition());
        $second = $this->serializer->toLogicFlow($this->serializer->fromLogicFlow($first));
        $third = $this->serializer->toLogicFlow($this->serializer->fromLogicFlow($second));

        $this->assertSame($this->sortRecursive($first), $this->sortRecursive($second), '第一次往返后 JSON 发生了变化');
        $this->assertSame($this->sortRecursive($second), $this->sortRecursive($third), '第二次往返后 JSON 又变了');
    }

    /**
     * 坐标：确定性 + 透传
     */
    public function testLayoutIsDeterministicAndPassedThrough(): void
    {
        $definition = $this->fullDefinition();

        $first = $this->serializer->toLogicFlow($definition);
        $second = $this->serializer->toLogicFlow($definition);
        $this->assertSame($first, $second, '同一份定义两次序列化必须完全一致（不能有随机坐标）');

        $points = [];
        foreach ($first['nodes'] as $node) {
            $points[$node['id']] = ['x' => $node['x'], 'y' => $node['y']];
            $this->assertIsInt($node['x'], 'LfNode::$x 是 int，坐标必须给整数');
            $this->assertIsInt($node['y'], 'LfNode::$y 是 int，坐标必须给整数');
            // 坐标同时写进 properties.layout，反向还原时才能落盘
            $this->assertSame(['x' => $node['x'], 'y' => $node['y']], $node['properties']['layout']);
        }

        // 分层：起点在第 0 列，直接后继在第 1 列
        $this->assertSame(LogicFlowSerializer::LAYOUT_ORIGIN_X, $points['n_start']['x']);
        $this->assertGreaterThan($points['n_start']['x'], $points['n_submit']['x']);
        $this->assertGreaterThan($points['n_submit']['x'], $points['n_decision']['x']);
        // 同层节点排在不同行
        $this->assertGreaterThan($points['n_approve']['x'], $points['n_fork']['x']);
        $this->assertNotSame($points['n_branch_a']['y'], $points['n_branch_b']['y']);

        // 显式坐标优先透传
        $custom = $this->straightFlowDefinition();
        $task = $custom->getNode('n_task');
        $task->setConfig(array_merge($task->getConfig(), ['layout' => ['x' => 888, 'y' => 666]]));
        $graph = $this->serializer->toLogicFlow($custom);
        $byId = array_column($graph['nodes'], null, 'id');
        $this->assertSame(888, $byId['n_task']['x']);
        $this->assertSame(666, $byId['n_task']['y']);
    }

    /**
     * 没有 `_alkaid_*` 保留键的外来 LogicFlow（设计器直出）也要能还原
     */
    public function testFromLogicFlowAcceptsForeignDesignerGraph(): void
    {
        $graph = [
            'name' => '设计器直出流程',
            'nodes' => [
                ['id' => 'a', 'type' => 'ingenious:start', 'x' => 100, 'y' => 100, 'text' => ['value' => '开始'], 'properties' => []],
                ['id' => 'b', 'type' => 'ingenious:task', 'x' => 300, 'y' => 100, 'text' => ['value' => '处理'], 'properties' => ['form' => 'f1']],
                ['id' => 'c', 'type' => 'ingenious:decision', 'x' => 500, 'y' => 100, 'text' => ['value' => '判断'], 'properties' => []],
                ['id' => 'd', 'type' => 'ingenious:fork', 'x' => 700, 'y' => 100, 'text' => ['value' => ''], 'properties' => []],
                ['id' => 'e', 'type' => 'ingenious:join', 'x' => 900, 'y' => 100, 'text' => ['value' => ''], 'properties' => []],
                ['id' => 'f', 'type' => 'ingenious:end', 'x' => 1100, 'y' => 100, 'text' => ['value' => '结束'], 'properties' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'sourceNodeId' => 'a', 'targetNodeId' => 'b', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'b', 'targetNodeId' => 'c', 'properties' => []],
                ['id' => 'e3', 'sourceNodeId' => 'c', 'targetNodeId' => 'd', 'properties' => ['expr' => 'x > 0']],
                ['id' => 'e4', 'sourceNodeId' => 'd', 'targetNodeId' => 'e', 'properties' => []],
                ['id' => 'e5', 'sourceNodeId' => 'e', 'targetNodeId' => 'f', 'properties' => []],
            ],
        ];

        $definition = $this->serializer->fromLogicFlow($graph);

        $this->assertCount(6, $definition->getNodes());
        $this->assertInstanceOf(StartNode::class, $definition->getNode('a'));
        $this->assertInstanceOf(TaskNode::class, $definition->getNode('b'));
        $this->assertSame('f1', $definition->getNode('b')->getConfig()['form']);
        $this->assertInstanceOf(GatewayNode::class, $definition->getNode('c'));
        $this->assertSame(NodeInterface::GATEWAY_EXCLUSIVE, $definition->getNode('c')->getSubType());
        $this->assertSame(NodeInterface::GATEWAY_PARALLEL, $definition->getNode('d')->getSubType());
        $this->assertSame(GatewayNode::DIRECTION_DIVERGING, $definition->getNode('d')->getConfig()['direction']);
        $this->assertSame(GatewayNode::DIRECTION_CONVERGING, $definition->getNode('e')->getConfig()['direction']);
        $this->assertInstanceOf(EndNode::class, $definition->getNode('f'));
        $this->assertSame('x > 0', $definition->getConnection('e3')->getCondition());
        $this->assertSame('设计器直出流程', $definition->getName());

        // 还原后再序列化，引擎依旧能解析出非空模型
        $processModel = ModelParser::parse($this->serializer->toLogicFlow($definition));
        $this->assertCount(6, $processModel->getNodes());
    }

    /**
     * 安全边界：不允许按库里的字符串随意实例化类
     */
    public function testFromLogicFlowRejectsUnknownNodeClass(): void
    {
        $graph = [
            'nodes' => [
                [
                    'id'         => 'n_evil',
                    'type'       => 'ingenious:task',
                    'x'          => 0,
                    'y'          => 0,
                    'properties' => ['_alkaid_node_class' => 'SplFileObject'],
                ],
            ],
            'edges' => [],
        ];

        $this->expectException(LogicFlowSerializationException::class);
        $this->expectExceptionMessageMatches('/不在允许清单内/');
        $this->serializer->fromLogicFlow($graph);
    }

    /**
     * 库里数据自相矛盾时要早失败（node_type 与 node_class 打架）
     */
    public function testFromLogicFlowRejectsInconsistentNodeType(): void
    {
        $graph = [
            'nodes' => [
                [
                    'id'         => 'n_bad',
                    'type'       => 'ingenious:task',
                    'x'          => 0,
                    'y'          => 0,
                    'properties' => [
                        '_alkaid_node_class' => 'StartNode',
                        '_alkaid_node_type'  => 'task',
                    ],
                ],
            ],
            'edges' => [],
        ];

        $this->expectException(LogicFlowSerializationException::class);
        $this->expectExceptionMessageMatches('/自相矛盾/');
        $this->serializer->fromLogicFlow($graph);
    }

    // ------------------------------------------------------------------
    // 测试夹具
    // ------------------------------------------------------------------

    /**
     * start → task → end
     */
    private function straightFlowDefinition(): WorkflowDefinition
    {
        $builder = $this->createBuilder('直线流程');

        $builder->addNode(new StartNode('n_start', ['name' => 'start', 'display_name' => '开始']), false);
        $builder->addNode(new TaskNode('n_task', [
            'name'         => 'fill_form',
            'display_name' => '填写申请',
            'form'         => 'leave_form',
            'assignee'     => '1',
        ]), false);
        $builder->addNode(new EndNode('n_end', ['name' => 'end', 'display_name' => '结束']), false);

        $builder->connect('n_start', 'n_task');
        $builder->connect('n_task', 'n_end');

        return $builder->getDefinition();
    }

    /**
     * start → submit → decision → (approve | reject) → end
     */
    private function branchFlowDefinition(): WorkflowDefinition
    {
        $builder = $this->createBuilder('条件分支流程');

        $builder->addNode(new StartNode('n_start', ['name' => 'start', 'display_name' => '开始']), false);
        $builder->addNode(new TaskNode('n_submit', ['name' => 'submit', 'display_name' => '提交申请']), false);
        $builder->addNode(new GatewayNode('n_decision', [
            'name'     => 'amount_gateway',
            'sub_type' => NodeInterface::GATEWAY_EXCLUSIVE,
        ]), false);
        $builder->addNode(new TaskNode('n_approve', ['name' => 'approve', 'display_name' => '大额审批']), false);
        $builder->addNode(new TaskNode('n_reject', ['name' => 'small', 'display_name' => '小额直接通过']), false);
        $builder->addNode(new EndNode('n_end', ['name' => 'end', 'display_name' => '结束']), false);

        $builder->connect('n_start', 'n_submit');
        $builder->connect('n_submit', 'n_decision');
        $builder->connect('n_decision', 'n_approve', ['condition' => 'amount > 1000']);
        $builder->connect('n_decision', 'n_reject', ['condition' => 'amount <= 1000']);
        $builder->connect('n_approve', 'n_end');
        $builder->connect('n_reject', 'n_end');

        return $builder->getDefinition();
    }

    /**
     * start → fork → (task | task) → join → end
     */
    private function parallelFlowDefinition(): WorkflowDefinition
    {
        $builder = $this->createBuilder('并行流程');

        $builder->addNode(new StartNode('n_start', ['name' => 'start']), false);
        $builder->addNode(new GatewayNode('n_fork', [
            'name'      => 'fork',
            'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_DIVERGING,
        ]), false);
        $builder->addNode(new TaskNode('n_branch_a', ['name' => 'branch_a', 'display_name' => '分支A']), false);
        $builder->addNode(new TaskNode('n_branch_b', ['name' => 'branch_b', 'display_name' => '分支B']), false);
        $builder->addNode(new GatewayNode('n_join', [
            'name'      => 'join',
            'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_CONVERGING,
        ]), false);
        $builder->addNode(new EndNode('n_end', ['name' => 'end']), false);

        $builder->connect('n_start', 'n_fork');
        $builder->connect('n_fork', 'n_branch_a');
        $builder->connect('n_fork', 'n_branch_b');
        $builder->connect('n_branch_a', 'n_join');
        $builder->connect('n_branch_b', 'n_join');
        $builder->connect('n_join', 'n_end');

        return $builder->getDefinition();
    }

    /**
     * 综合夹具：覆盖 start/task 家族/decision/fork/join/end 与带条件的边
     */
    private function fullDefinition(): WorkflowDefinition
    {
        $builder = $this->createBuilder('完整流程');

        $builder->addNode(new StartNode('n_start', ['name' => 'start', 'display_name' => '开始']), false);
        $builder->addNode(new TaskNode('n_submit', [
            'name'         => 'submit',
            'display_name' => '提交申请',
            'form'         => 'leave_form',
            'assignee'     => '1',
        ]), false);
        $builder->addNode(new GatewayNode('n_decision', [
            'name'     => 'amount_gateway',
            'sub_type' => NodeInterface::GATEWAY_EXCLUSIVE,
        ]), false);
        $builder->addNode(new ApprovalNode('n_approve', ['name' => 'approve', 'display_name' => '主管审批']), false);
        $builder->addNode(new CountersignNode('n_countersign', ['name' => 'countersign', 'display_name' => '多人会签']), false);
        $builder->addNode(new GatewayNode('n_fork', [
            'name'      => 'fork',
            'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_DIVERGING,
        ]), false);
        $builder->addNode(new TaskNode('n_branch_a', ['name' => 'branch_a', 'display_name' => '分支A']), false);
        $builder->addNode(new TaskNode('n_branch_b', ['name' => 'branch_b', 'display_name' => '分支B']), false);
        $builder->addNode(new GatewayNode('n_join', [
            'name'      => 'join',
            'sub_type'  => NodeInterface::GATEWAY_PARALLEL,
            'direction' => GatewayNode::DIRECTION_CONVERGING,
        ]), false);
        $builder->addNode(new EndNode('n_end', ['name' => 'end', 'display_name' => '结束']), false);

        $builder->connect('n_start', 'n_submit');
        $builder->connect('n_submit', 'n_decision');
        $builder->connect('n_decision', 'n_approve', ['condition' => 'amount > 1000']);
        $builder->connect('n_decision', 'n_countersign', ['condition' => 'amount <= 1000']);
        $builder->connect('n_approve', 'n_fork');
        $builder->connect('n_countersign', 'n_fork');
        $builder->connect('n_fork', 'n_branch_a');
        $builder->connect('n_fork', 'n_branch_b');
        $builder->connect('n_branch_a', 'n_join');
        $builder->connect('n_branch_b', 'n_join');
        $builder->connect('n_join', 'n_end');

        $definition = $builder->getDefinition();
        $definition->setDescription('覆盖 start/task/decision/fork/join/end 的综合夹具');
        $definition->setMeta('owner', 'unit-test');
        $definition->setConfig(['expire_time' => '7d']);
        $definition->setStatus(WorkflowDefinitionInterface::STATUS_PUBLISHED);

        return $definition;
    }

    /**
     * 建一个带固定 id/类型/租户的构建器
     */
    private function createBuilder(string $name): WorkflowBuilder
    {
        return WorkflowBuilder::create($name, [
            'id'           => 'wf_' . md5($name),
            'display_name' => $name,
            'type'         => WorkflowDefinitionInterface::TYPE_APPROVAL,
            'tenant_id'    => 1,
        ]);
    }

    /**
     * 拓扑指纹：[源节点, 目标节点] 的有序列表
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function topologyOf(WorkflowDefinition $definition): array
    {
        $topology = [];
        foreach ($definition->getConnections() as $connection) {
            $topology[] = [$connection->getSourceNodeId(), $connection->getTargetNodeId()];
        }

        return $topology;
    }

    /**
     * 递归按键名排序（用于「结构相同」比较，忽略 key 顺序）
     */
    private function sortRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        return $data;
    }
}
