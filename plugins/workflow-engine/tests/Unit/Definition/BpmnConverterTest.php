<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Definition;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Definition\BpmnConversionException;
use Alkaid\Workflow\Engine\Definition\BpmnConverter;
use Alkaid\Workflow\Engine\Definition\LogicFlowSerializer;
use Alkaid\Workflow\Engine\model\DecisionModel;
use Alkaid\Workflow\Engine\model\EndModel;
use Alkaid\Workflow\Engine\model\ForkModel;
use Alkaid\Workflow\Engine\model\JoinModel;
use Alkaid\Workflow\Engine\model\StartModel;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\parser\handler\CustomParser;
use Alkaid\Workflow\Engine\parser\handler\DecisionParser;
use Alkaid\Workflow\Engine\parser\handler\EndParser;
use Alkaid\Workflow\Engine\parser\handler\ForkParser;
use Alkaid\Workflow\Engine\parser\handler\JoinParser;
use Alkaid\Workflow\Engine\parser\handler\StartParser;
use Alkaid\Workflow\Engine\parser\handler\TaskParser;
use Alkaid\Workflow\Engine\parser\handler\WfSubProcessParser;
use Alkaid\Workflow\Engine\parser\ModelParser;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BPMN ⇄ LogicFlow 转换器单测
 *
 * 覆盖五件事：
 * 1. 子集映射：BPMN 元素 → 引擎解析器键（含 parallelGateway 按出入度细分 fork/join）；
 * 2. 命名空间前缀变体：`bpmn:` / `bpmn2:` / 默认 xmlns / 完全无 xmlns；
 * 3. round-trip：`fromBpmn(toBpmn(fromBpmn($xml)))` 的节点集合、类型、拓扑、条件与原图一致；
 * 4. 不支持的 BPMN 元素必须**抛异常并列出元素名**（静默丢弃 = 悄悄改写图）；
 * 5. 与库里两条种子定义对齐：`bpmn_xml` 解析出的拓扑必须与 `nodes_config` 的 LogicFlow 拓扑
 *    一致，并且 `ModelParser::parse()` 真的能把结果吃成非空模型。
 *
 * 第 5 项是本包的硬验收：格式对不上时 `ModelParser::parse()` 会静默返回空模型
 * （src/parser/ModelParser.php:67-69），那才是会打到线上的故障形态。
 *
 * ## 种子夹具的来源与自证
 *
 * `tests/Fixtures/bpmn/*.bpmn` 是 `database/migrations/20251216000003_insert_initial_data.php`
 * 里 `getSampleBpmnXml()` 返回值的**逐字副本**（即库里两条种子定义的 `bpmn_xml`）。
 * 插件套件不连库，因此用夹具 + {@see self::testSeedFixturesAreVerbatimCopiesOfTheMigrationSource()}
 * 把「夹具 == 种子」这件事钉在测试里：谁改了迁移里的 XML 而没同步夹具，该用例立刻变红。
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Definition
 */
class BpmnConverterTest extends TestCase
{
    /**
     * 引擎注册的节点解析器（与 WorkflowContainer::buildCoreServices() 的键名逐一对应）
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

    /**
     * `nodes_config` 的 `(tenant_id, name, version)` 种子定义名 ⇒ 夹具文件名
     */
    private const SEED_DEFINITIONS = ['expense_reimbursement', 'leave_application'];

    private BpmnConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new BpmnConverter();

        // ModelParser::parse() 依赖静态的 ServiceContext，不注册解析器就什么都解析不出来
        ServiceContext::setContext(self::ENGINE_PARSERS);
    }

    // -----------------------------------------------------------------
    // 1. 子集映射
    // -----------------------------------------------------------------

    /**
     * 种子直线流程：5 节点 / 4 边，四个任务节点必须都落到 task 解析器键
     */
    public function testSeedStraightThroughFlowImportsFiveNodesAndFourEdges(): void
    {
        $graph = $this->converter->fromBpmn(self::fixture('expense_reimbursement'));

        $this->assertCount(5, $graph['nodes'], 'startEvent + 3×userTask + endEvent 应是 5 个节点');
        $this->assertCount(4, $graph['edges'], '4 条 sequenceFlow 应是 4 条边');

        $this->assertSame(
            [
                'StartEvent_1'        => 'ingenious:start',
                'Task_ApplyExpense'   => 'ingenious:task',
                'Task_ManagerReview'  => 'ingenious:task',
                'Task_FinanceReview'  => 'ingenious:task',
                'EndEvent_1'          => 'ingenious:end',
            ],
            $this->typesById($graph)
        );

        // userTask 塌缩成 task 后必须记住原类型，否则导出时无法还原成 userTask
        $this->assertSame('userTask', $graph['nodes'][1]['properties'][BpmnConverter::BPMN_TYPE_KEY]);
        // start/end 与解析器键一一对应，不写冗余的 _alkaid_bpmn_type
        $this->assertArrayNotHasKey(BpmnConverter::BPMN_TYPE_KEY, $graph['nodes'][0]['properties']);

        // 流程级信息来自 <process id/name>
        $this->assertSame('Process_ExpenseReimbursement', $graph['name']);
        $this->assertSame('费用报销流程', $graph['display_name']);
    }

    /**
     * 排他网关 → decision；`${...}` 包裹与裸变量都要桥接成 `$var`
     */
    public function testExclusiveGatewayMapsToDecisionAndBridgesConditions(): void
    {
        $graph = $this->converter->fromBpmn(self::exclusiveGatewayBpmn());

        $this->assertSame('ingenious:decision', $this->typesById($graph)['Gateway_1']);

        $edges = $this->edgesById($graph);
        $highRisk = $edges['Flow_High']['properties'];
        $lowRisk = $edges['Flow_Low']['properties'];

        // 引擎读 expr、主应用读 condition，两份都必须是**桥接后**的 PHP 表达式
        $this->assertSame('$amount > 1000', $highRisk[BpmnConverter::EDGE_EXPR_KEY]);
        $this->assertSame('$amount > 1000', $highRisk[BpmnConverter::EDGE_CONDITION_KEY]);
        // 原文另存，导出时写回，BPMN 不被这次导入改写
        $this->assertSame('${amount > 1000}', $highRisk[BpmnConverter::BPMN_CONDITION_KEY]);
        // 连线名以 LogicFlow 惯例放 text.value，同时保留在 _alkaid_name 供 fromLogicFlow 还原
        $this->assertSame('金额较大', $edges['Flow_High']['text']['value']);
        $this->assertSame('金额较大', $highRisk['_alkaid_name']);

        // default 属性指向的缺省分支：拓扑不变，但「这是缺省分支」必须留痕，不能悄悄丢
        $this->assertTrue($lowRisk[BpmnConverter::BPMN_DEFAULT_KEY]);
        $this->assertArrayNotHasKey(BpmnConverter::EDGE_EXPR_KEY, $lowRisk);
    }

    /**
     * 并行网关按出入度细分：多出边 → fork，多入边 → join
     */
    public function testParallelGatewaySplitsIntoForkAndJoinByDegree(): void
    {
        $graph = $this->converter->fromBpmn(self::parallelGatewayBpmn());

        $types = $this->typesById($graph);
        $this->assertSame('ingenious:fork', $types['Fork_1'], '1 进 2 出 = 分发');
        $this->assertSame('ingenious:join', $types['Join_1'], '2 进 1 出 = 汇聚');
        $this->assertSame(
            ['S' => 'ingenious:start', 'E' => 'ingenious:end'],
            array_intersect_key($types, ['S' => true, 'E' => true])
        );
        $this->assertCount(6, $graph['edges']);
    }

    /**
     * 四种任务元素都塌缩成 `task`，但原类型必须能往返（记在 `_alkaid_bpmn_type`）
     */
    #[DataProvider('taskElementProvider')]
    public function testTaskElementTypesSurviveRoundTrip(string $bpmnElement): void
    {
        $xml = self::document(sprintf(
            '<bpmn:startEvent id="S" name="开始"/><bpmn:%s id="T" name="处理"/>'
            . '<bpmn:endEvent id="E" name="结束"/>'
            . '<bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="T"/>'
            . '<bpmn:sequenceFlow id="F2" sourceRef="T" targetRef="E"/>',
            $bpmnElement
        ));

        $graph = $this->converter->fromBpmn($xml);
        $this->assertSame('ingenious:task', $this->typesById($graph)['T'], '四种任务都必须落到 task 解析器键');
        $this->assertSame($bpmnElement, $graph['nodes'][1]['properties'][BpmnConverter::BPMN_TYPE_KEY]);

        $exported = $this->converter->toBpmn($graph);
        $this->assertStringContainsString(
            '<bpmn:' . $bpmnElement . ' id="T"',
            $exported,
            '导出必须还原原任务元素，而不是一律写回 userTask'
        );
        $this->assertEquals($graph, $this->converter->fromBpmn($exported));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function taskElementProvider(): array
    {
        return [
            'userTask'    => ['userTask'],
            'serviceTask' => ['serviceTask'],
            'scriptTask'  => ['scriptTask'],
            'sendTask'    => ['sendTask'],
        ];
    }

    /**
     * 条件表达式桥接：EL 包装、裸变量、已是 PHP 的写法、字符串字面量里的关键字
     */
    public function testConditionBridgingHandlesElAndPhpSyntaxes(): void
    {
        $xml = self::document(<<<'XML'
            <bpmn:startEvent id="S" name="开始"/>
            <bpmn:exclusiveGateway id="G" name="判定"/>
            <bpmn:endEvent id="E" name="结束"/>
            <bpmn:sequenceFlow id="F0" sourceRef="S" targetRef="G"/>
            <bpmn:sequenceFlow id="F1" sourceRef="G" targetRef="E">
              <bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">amount &gt;= 1000 &amp;&amp; level != 0</bpmn:conditionExpression>
            </bpmn:sequenceFlow>
            <bpmn:sequenceFlow id="F2" sourceRef="G" targetRef="E">
              <bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">$status === 'in progress'</bpmn:conditionExpression>
            </bpmn:sequenceFlow>
            <bpmn:sequenceFlow id="F3" sourceRef="G" targetRef="E">
              <bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">status == "not paid" and level &gt; 2</bpmn:conditionExpression>
            </bpmn:sequenceFlow>
            XML);

        $edges = $this->edgesById($this->converter->fromBpmn($xml));

        // 裸变量加 $，比较符/逻辑符原样保留
        $this->assertSame('$amount >= 1000 && $level != 0', $edges['F1']['properties'][BpmnConverter::EDGE_EXPR_KEY]);
        // 已经是 PHP 写法的不重复加 $；字符串字面量里的 'in' 不能被当成 JUEL 运算符
        $this->assertSame('$status === \'in progress\'', $edges['F2']['properties'][BpmnConverter::EDGE_EXPR_KEY]);
        // 双引号里的 "not paid" 同理；and 是 PHP 合法关键字，保持原样
        $this->assertSame('$status == "not paid" and $level > 2', $edges['F3']['properties'][BpmnConverter::EDGE_EXPR_KEY]);
    }

    // -----------------------------------------------------------------
    // 2. 命名空间前缀变体
    // -----------------------------------------------------------------

    /**
     * 四种命名空间写法必须解析出**同一张图**
     */
    #[DataProvider('namespaceVariantProvider')]
    public function testNamespacePrefixVariantsProduceTheSameGraph(string $xml): void
    {
        $graph = $this->converter->fromBpmn($xml);

        $this->assertSame(
            ['N1' => 'ingenious:start', 'N2' => 'ingenious:task', 'N3' => 'ingenious:end'],
            $this->typesById($graph),
            '前缀只是 URI 的别名，不该影响解析结果'
        );
        $this->assertSame('前缀变体流程', $graph['display_name']);
        $this->assertCount(2, $graph['edges']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namespaceVariantProvider(): array
    {
        $nodes = <<<'BODY'
            <startEvent id="N1" name="开始"/>
            <userTask id="N2" name="审批"/>
            <endEvent id="N3" name="结束"/>
            <sequenceFlow id="E1" sourceRef="N1" targetRef="N2"/>
            <sequenceFlow id="E2" sourceRef="N2" targetRef="N3"/>
            BODY;

        return [
            'bpmn: 前缀' => [sprintf(
                '<bpmn:definitions xmlns:bpmn="%s" id="D" targetNamespace="t">'
                . '<bpmn:process id="P" name="前缀变体流程" isExecutable="false">%s</bpmn:process>'
                . '</bpmn:definitions>',
                BpmnConverter::BPMN_NS,
                str_replace(['<startEvent', '<userTask', '<endEvent', '<sequenceFlow'], ['<bpmn:startEvent', '<bpmn:userTask', '<bpmn:endEvent', '<bpmn:sequenceFlow'], $nodes)
            )],
            'bpmn2: 前缀' => [sprintf(
                '<bpmn2:definitions xmlns:bpmn2="%s" id="D" targetNamespace="t">'
                . '<bpmn2:process id="P" name="前缀变体流程" isExecutable="false">%s</bpmn2:process>'
                . '</bpmn2:definitions>',
                BpmnConverter::BPMN_NS,
                str_replace(['<startEvent', '<userTask', '<endEvent', '<sequenceFlow'], ['<bpmn2:startEvent', '<bpmn2:userTask', '<bpmn2:endEvent', '<bpmn2:sequenceFlow'], $nodes)
            )],
            '默认命名空间 xmlns=' => [sprintf(
                '<definitions xmlns="%s" id="D" targetNamespace="t">'
                . '<process id="P" name="前缀变体流程" isExecutable="false">%s</process>'
                . '</definitions>',
                BpmnConverter::BPMN_NS,
                $nodes
            )],
            '完全无 xmlns' => [sprintf(
                '<definitions id="D" targetNamespace="t">'
                . '<process id="P" name="前缀变体流程" isExecutable="false">%s</process>'
                . '</definitions>',
                $nodes
            )],
        ];
    }

    // -----------------------------------------------------------------
    // 3. round-trip
    // -----------------------------------------------------------------

    /**
     * `fromBpmn(toBpmn(fromBpmn($xml)))` 与原图逐字段一致
     */
    #[DataProvider('bpmnGraphProvider')]
    public function testRoundTripPreservesNodesTypesTopologyAndConditions(string $xml): void
    {
        $original = $this->converter->fromBpmn($xml);
        $reimported = $this->converter->fromBpmn($this->converter->toBpmn($original));

        $this->assertSame($original['name'], $reimported['name'], '流程 id 必须往返');
        $this->assertSame($original['display_name'], $reimported['display_name'], '流程显示名必须往返');
        $this->assertSame($this->topology($original), $this->topology($reimported), '节点集合/类型/拓扑必须一致');
        $this->assertSame($this->conditions($original), $this->conditions($reimported), '边条件必须一致');

        // 完整结构也应当是同一份：坐标、显示名、保留键一起往返
        $this->assertEquals($original['nodes'], $reimported['nodes']);
        $this->assertEquals($original['edges'], $reimported['edges']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bpmnGraphProvider(): array
    {
        return [
            '直线流程（种子 expense_reimbursement）' => [self::fixture('expense_reimbursement')],
            '直线流程（种子 leave_application）'     => [self::fixture('leave_application')],
            '排他网关分支'                           => [self::exclusiveGatewayBpmn()],
            '并行网关 fork/join'                     => [self::parallelGatewayBpmn()],
        ];
    }

    /**
     * 导出的 XML 必须能按命名空间 URI 取到 DI 元素（前缀解析的守卫）
     *
     * 这条用例守的是 {@see BpmnConverter::buildDiagram()} 里「DI 元素用 createElement 带前缀
     * 而不是 createElementNS」这个选择：一旦根元素的 xmlns 声明丢了，按 URI 查询就会是 0 条。
     */
    public function testExportedXmlResolvesNamespaceUris(): void
    {
        $graph = $this->converter->fromBpmn(self::exclusiveGatewayBpmn());
        $xml = $this->converter->toBpmn($graph);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), '导出的 XML 必须是合法文档');

        $this->assertCount(
            count($graph['nodes']),
            iterator_to_array($dom->getElementsByTagNameNS(BpmnConverter::BPMNDI_NS, 'BPMNShape')),
            '每个节点都要有 bpmndi:BPMNShape'
        );
        $this->assertCount(
            count($graph['nodes']),
            iterator_to_array($dom->getElementsByTagNameNS(BpmnConverter::DC_NS, 'Bounds')),
            '每个 shape 都要有 dc:Bounds（前缀必须能按 URI 解析到）'
        );
        $this->assertCount(
            count($graph['edges']),
            iterator_to_array($dom->getElementsByTagNameNS(BpmnConverter::BPMN_NS, 'sequenceFlow'))
        );

        $expression = $dom->getElementsByTagNameNS(BpmnConverter::BPMN_NS, 'conditionExpression')->item(0);
        $this->assertNotNull($expression);
        $this->assertSame(
            'bpmn:tFormalExpression',
            $expression->getAttributeNS(BpmnConverter::XSI_NS, 'type'),
            'conditionExpression 必须带 xsi:type，否则 BPMN 工具会当成抽象元素报错'
        );
        $this->assertSame('${amount > 1000}', trim($expression->textContent), '导出写回 BPMN 原文，而不是桥接后的 PHP');
    }

    /**
     * 节点没有坐标时导出必须给一套确定性布局，且重新导入后拓扑不变
     */
    public function testExportFallsBackToDeterministicLayoutWhenCoordinatesMissing(): void
    {
        $graph = [
            'name'         => 'no_layout',
            'display_name' => '无坐标流程',
            'nodes'        => [
                ['id' => 'n1', 'type' => 'ingenious:start', 'text' => ['value' => '开始']],
                ['id' => 'n2', 'type' => 'ingenious:task', 'text' => ['value' => '审批']],
                ['id' => 'n3', 'type' => 'ingenious:end', 'text' => ['value' => '结束']],
            ],
            'edges'        => [
                ['id' => 'e1', 'sourceNodeId' => 'n1', 'targetNodeId' => 'n2', 'properties' => []],
                ['id' => 'e2', 'sourceNodeId' => 'n2', 'targetNodeId' => 'n3', 'properties' => []],
            ],
        ];

        $reimported = $this->converter->fromBpmn($this->converter->toBpmn($graph));

        // 确定性：一列一个层级，起点与步长直接复用 LogicFlowSerializer 的公开常量
        $this->assertSame(
            [
                'n1' => ['x' => 200, 'y' => 160],
                'n2' => ['x' => 420, 'y' => 160],
                'n3' => ['x' => 640, 'y' => 160],
            ],
            array_map(
                static fn (array $node): array => ['x' => $node['x'], 'y' => $node['y']],
                $this->keyById($reimported['nodes'])
            )
        );
        $this->assertSame(
            ['n1' => 'ingenious:start', 'n2' => 'ingenious:task', 'n3' => 'ingenious:end'],
            $this->typesById($reimported)
        );
    }

    // -----------------------------------------------------------------
    // 4. 子集外的输入必须失败
    // -----------------------------------------------------------------

    /**
     * process 下的子集外元素：抛异常并列出元素名
     */
    public function testUnsupportedProcessElementsAreRejectedWithElementNames(): void
    {
        $xml = self::document(<<<'XML'
            <bpmn:startEvent id="S" name="开始"/>
            <bpmn:subProcess id="SUB" name="子流程"/>
            <bpmn:boundaryEvent id="BE" attachedToRef="SUB"/>
            <bpmn:inclusiveGateway id="IG" name="包容网关"/>
            <bpmn:endEvent id="E" name="结束"/>
            XML);

        $message = $this->expectConversionFailure($xml);

        foreach (['subProcess', 'boundaryEvent', 'inclusiveGateway'] as $element) {
            $this->assertStringContainsString($element, $message, "报错必须点名 <{$element}>，否则读者不知道该删哪个");
        }
    }

    /**
     * 节点内部的子集外构造（如会签特性）同样是「静默改写语义」，必须失败
     */
    public function testUnsupportedConstructInsideFlowNodeIsRejected(): void
    {
        $xml = self::document(<<<'XML'
            <bpmn:startEvent id="S" name="开始"/>
            <bpmn:userTask id="T" name="会签审批">
              <bpmn:multiInstanceLoopCharacteristics isSequential="false"/>
            </bpmn:userTask>
            <bpmn:endEvent id="E" name="结束"/>
            <bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="T"/>
            <bpmn:sequenceFlow id="F2" sourceRef="T" targetRef="E"/>
            XML);

        $message = $this->expectConversionFailure($xml);

        $this->assertStringContainsString('multiInstanceLoopCharacteristics', $message);
        $this->assertStringContainsString('T', $message, '报错要指出是哪个节点里的构造');
    }

    /**
     * 悬空连线（sourceRef 指向不存在的节点）直接失败
     */
    public function testDanglingSequenceFlowIsRejected(): void
    {
        $xml = self::document(<<<'XML'
            <bpmn:startEvent id="S" name="开始"/>
            <bpmn:endEvent id="E" name="结束"/>
            <bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="Ghost_Node"/>
            XML);

        $this->assertStringContainsString('Ghost_Node', $this->expectConversionFailure($xml));
    }

    /**
     * 多个 process 一次导不了
     */
    public function testMultipleProcessesAreRejected(): void
    {
        $xml = sprintf(
            '<bpmn:definitions xmlns:bpmn="%s" id="D" targetNamespace="t">'
            . '<bpmn:process id="P1"/><bpmn:process id="P2"/>'
            . '</bpmn:definitions>',
            BpmnConverter::BPMN_NS
        );

        $this->assertStringContainsString('2 个', $this->expectConversionFailure($xml));
    }

    /**
     * 并行网关同时多进多出：引擎没有对应语义，不允许「挑一个方向」蒙过去
     */
    public function testParallelGatewayWithMixedDegreesIsRejected(): void
    {
        $xml = self::document(<<<'XML'
            <bpmn:startEvent id="S"/>
            <bpmn:parallelGateway id="G" name="既汇聚又分发"/>
            <bpmn:userTask id="A"/>
            <bpmn:userTask id="B"/>
            <bpmn:endEvent id="E"/>
            <bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="A"/>
            <bpmn:sequenceFlow id="F2" sourceRef="S" targetRef="B"/>
            <bpmn:sequenceFlow id="F3" sourceRef="A" targetRef="G"/>
            <bpmn:sequenceFlow id="F4" sourceRef="B" targetRef="G"/>
            <bpmn:sequenceFlow id="F5" sourceRef="G" targetRef="E"/>
            <bpmn:sequenceFlow id="F6" sourceRef="G" targetRef="S"/>
            XML);

        $this->assertStringContainsString('并行网关', $this->expectConversionFailure($xml));
    }

    /**
     * `default` 属性指向不存在的连线 / 不是自己发出的连线：属坏 BPMN，直接失败
     */
    #[DataProvider('brokenDefaultFlowProvider')]
    public function testBrokenDefaultAttributeIsRejected(string $xml, string $expectedFragment): void
    {
        $this->assertStringContainsString($expectedFragment, $this->expectConversionFailure($xml));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenDefaultFlowProvider(): array
    {
        return [
            'default 指向不存在的连线' => [self::document(<<<'XML'
                <bpmn:startEvent id="S"/>
                <bpmn:exclusiveGateway id="G" default="Flow_Missing"/>
                <bpmn:endEvent id="E"/>
                <bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="G"/>
                <bpmn:sequenceFlow id="F2" sourceRef="G" targetRef="E"/>
                XML), 'Flow_Missing'],
            'default 指向别人发出的连线' => [self::document(<<<'XML'
                <bpmn:startEvent id="S" default="F2"/>
                <bpmn:exclusiveGateway id="G"/>
                <bpmn:endEvent id="E"/>
                <bpmn:sequenceFlow id="F1" sourceRef="S" targetRef="G"/>
                <bpmn:sequenceFlow id="F2" sourceRef="G" targetRef="E"/>
                XML), '必须由声明它的节点自己发出'],
        ];
    }

    /**
     * 无法桥接的条件表达式：宁可失败，也不要把引擎解释不了的文本塞进 expr
     */
    #[DataProvider('untranslatableConditionProvider')]
    public function testUntranslatableConditionIsRejected(string $condition, string $expectedFragment): void
    {
        $xml = self::document(sprintf(
            '<bpmn:startEvent id="S"/><bpmn:exclusiveGateway id="G"/><bpmn:endEvent id="E"/>'
            . '<bpmn:sequenceFlow id="F0" sourceRef="S" targetRef="G"/>'
            . '<bpmn:sequenceFlow id="F1" sourceRef="G" targetRef="E">'
            . '<bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">%s</bpmn:conditionExpression>'
            . '</bpmn:sequenceFlow>',
            htmlspecialchars($condition, ENT_XML1 | ENT_QUOTES)
        ));

        $this->assertStringContainsString($expectedFragment, $this->expectConversionFailure($xml));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function untranslatableConditionProvider(): array
    {
        return [
            'JUEL 点号属性访问'   => ['${order.amount > 1000}', '点号属性访问'],
            'JUEL 单词比较符 eq'  => ['amount eq 1000', '单词运算符'],
            'JUEL in'             => ["status in ('A','B')", 'in'],
            'JUEL not'            => ['not (level > 2)', 'not'],
            'JUEL 不带括号 empty' => ['empty approver', 'empty'],
            '多段 ${...} 混排'    => ['${a} and ${b}', '多段'],
        ];
    }

    /**
     * 导出方向：BPMN 子集里没有对应元素的解析器键必须失败
     */
    #[DataProvider('unexportableParserKeyProvider')]
    public function testExportRejectsParserKeysWithoutBpmnEquivalent(string $parserKey): void
    {
        $graph = [
            'name'  => 'x',
            'nodes' => [
                ['id' => 'n1', 'type' => 'ingenious:start'],
                ['id' => 'n2', 'type' => 'ingenious:' . $parserKey],
            ],
            'edges' => [['id' => 'e1', 'sourceNodeId' => 'n1', 'targetNodeId' => 'n2']],
        ];

        $this->expectException(BpmnConversionException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($parserKey, '/') . '/');

        $this->converter->toBpmn($graph);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unexportableParserKeyProvider(): array
    {
        return [
            'custom'       => ['custom'],
            'wfSubProcess' => ['wfSubProcess'],
            'unknown'      => ['unknownKey'],
        ];
    }

    // -----------------------------------------------------------------
    // 5. 与库里种子定义 / 引擎对齐
    // -----------------------------------------------------------------

    /**
     * 库里两条种子定义：`bpmn_xml` 与 `nodes_config` 描述的是同一个流程
     *
     * `nodes_config` 侧的期望值取自 `20251228000001_add_executable_logicflow_to_sample_definitions.php`
     * 的 `graphs()`（节点 id 为 start / apply / review_* / end，直线 5 节点 4 边）。
     * 两边节点 id 不同（BPMN 用 StartEvent_1/Task_*，LogicFlow 用 start/apply/...），
     * 所以比较的是**形状**：解析器键序列 + 边连接的序号序列。
     *
     * @param list<string> $nodesConfigTypes nodes_config 侧的解析器键序列
     * @param array{bpmn: list<string>, nodes_config: list<string>} $nodesConfigNames
     *        BPMN 侧与 nodes_config 侧的显示名序列（后者只在上一条差异用例里使用）
     */
    #[DataProvider('seedDefinitionProvider')]
    public function testSeedBpmnTopologyMatchesNodesConfig(
        string $definition,
        array $nodesConfigTypes,
        array $nodesConfigNames
    ): void {
        $graph = $this->converter->fromBpmn(self::fixture($definition));

        $this->assertCount(count($nodesConfigTypes), $graph['nodes'], "{$definition}：节点数必须与 nodes_config 一致");
        $this->assertCount(count($nodesConfigTypes) - 1, $graph['edges'], "{$definition}：直线流程应有 n-1 条边");

        // 拓扑①：解析器键序列（start → 3×task → end）
        $this->assertSame($nodesConfigTypes, array_column($graph['nodes'], 'type'));

        // 拓扑②：边必须是同一串 0→1→2→…→n-1 的链
        $this->assertSame(
            $this->chainShape(count($graph['nodes'])),
            $this->topology($graph)['edges'],
            "{$definition}：BPMN 侧必须是直线链"
        );

        // 显示名：BPMN 侧是权威内容，逐字断言
        $this->assertSame(
            $nodesConfigNames['bpmn'],
            array_column(array_column($graph['nodes'], 'text'), 'value')
        );
    }

    /**
     * @return array<string, array{string, list<string>, array{bpmn: list<string>, nodes_config: list<string>}}>
     */
    public static function seedDefinitionProvider(): array
    {
        $chain = ['ingenious:start', 'ingenious:task', 'ingenious:task', 'ingenious:task', 'ingenious:end'];

        return [
            'expense_reimbursement' => [
                'expense_reimbursement',
                $chain,
                [
                    'bpmn'        => ['开始', '填写报销申请', '部门经理审批', '财务审批', '结束'],
                    'nodes_config' => ['开始', '填写申请', '部门经理审批', '财务审批', '结束'],
                ],
            ],
            'leave_application' => [
                'leave_application',
                $chain,
                [
                    'bpmn'        => ['开始', '填写请假申请', '直属领导审批', 'HR审批', '结束'],
                    'nodes_config' => ['开始', '填写申请', '直属领导审批', 'HR审批', '结束'],
                ],
            ],
        ];
    }

    /**
     * 两种表示之间**唯一的差异**是「填写申请」这条节点的措辞
     *
     * 这不是缺陷修复目标，而是刻意记录的已知差异：BPMN 里写的是「填写报销申请」/「填写请假申请」，
     * `nodes_config` 里都简写成「填写申请」。拓扑、类型、顺序完全一致（见上一条用例）。
     * 若将来有人把两边对齐，这条用例会变红 —— 届时**更新它**，别把它删掉：
     * 它存在的意义就是让「两份表示已经对齐」这件事有人显式确认。
     */
    public function testSeedDisplayNamesDifferOnlyOnTheApplyNode(): void
    {
        foreach (self::seedDefinitionProvider() as $definition => [$name, , $names]) {
            $actual = array_column(array_column($this->converter->fromBpmn(self::fixture($name))['nodes'], 'text'), 'value');

            $differing = [];
            foreach ($names['nodes_config'] as $index => $expected) {
                if ($actual[$index] !== $expected) {
                    $differing[$index] = [$expected, $actual[$index]];
                }
            }

            $this->assertSame(
                [1 => ['填写申请', $names['bpmn'][1]]],
                $differing,
                "{$definition}：除 apply 节点的措辞外，显示名应与 nodes_config 完全一致"
            );
        }
    }

    /**
     * 夹具必须逐字等于迁移里的种子 `bpmn_xml`（防止夹具与库里的数据悄悄脱钩）
     *
     * 插件套件不连库，因此「夹具 == 种子」只能这样守：直接读宿主仓库的迁移文件比对原文。
     * 插件被单独抽出去用时没有这个文件，跳过而不是报错。
     */
    public function testSeedFixturesAreVerbatimCopiesOfTheMigrationSource(): void
    {
        $migration = dirname(__DIR__, 5) . '/database/migrations/20251216000003_insert_initial_data.php';

        if (!is_file($migration)) {
            $this->markTestSkipped('宿主仓库里没有种子迁移文件（插件被单独使用时属正常情况）。');
        }

        $source = (string) file_get_contents($migration);

        foreach (self::SEED_DEFINITIONS as $definition) {
            $this->assertStringContainsString(
                self::fixture($definition),
                $source,
                sprintf('夹具 %s.bpmn 已与迁移里的 getSampleBpmnXml() 不一致，请同步', $definition)
            );
        }
    }

    /**
     * 导入结果必须能被引擎真的消费（`ModelParser::parse()` 非空）
     *
     * 只断言「数组里有 nodes/edges」是不够的：格式对不上时 `ModelParser::parse()` 会静默返回
     * 空模型（src/parser/ModelParser.php:67-69），那才是会打到线上的故障形态。
     */
    public function testModelParserConsumesSeedImport(): void
    {
        $model = ModelParser::parse($this->converter->fromBpmn(self::fixture('expense_reimbursement')));

        $this->assertSame('Process_ExpenseReimbursement', $model->getName());
        $this->assertSame('费用报销流程', $model->getDisplayName());
        $this->assertCount(5, $model->getNodes(), '5 个 BPMN 节点必须全部解析出模型，一个都不能被静默丢弃');
        $this->assertCount(3, $model->getTasks(), '3 个 userTask 必须落进 tasks');
        $this->assertSame('StartEvent_1', $model->getStart()?->getName());
        $this->assertInstanceOf(StartModel::class, $model->getStart());
        $this->assertInstanceOf(EndModel::class, $this->nodeByName($model, 'EndEvent_1'));
        $this->assertInstanceOf(TaskModel::class, $this->nodeByName($model, 'Task_ApplyExpense'));
    }

    /**
     * 并行网关导入后必须是 ForkModel / JoinModel，而不是「随便一个网关」
     */
    public function testModelParserConsumesParallelGatewayImport(): void
    {
        $model = ModelParser::parse($this->converter->fromBpmn(self::parallelGatewayBpmn()));

        $this->assertCount(6, $model->getNodes());
        $this->assertInstanceOf(ForkModel::class, $this->nodeByName($model, 'Fork_1'));
        $this->assertInstanceOf(JoinModel::class, $this->nodeByName($model, 'Join_1'));
        $this->assertCount(2, $this->nodeByName($model, 'Fork_1')->getOutputs(), 'fork 必须带 2 条出边');
        $this->assertCount(2, $this->nodeByName($model, 'Join_1')->getInputs(), 'join 必须带 2 条入边');
    }

    /**
     * 排他网关导入后边上的 expr 必须被引擎读进 TransitionModel（决策节点靠它选分支）
     */
    public function testModelParserReadsConditionIntoTransitions(): void
    {
        $model = ModelParser::parse($this->converter->fromBpmn(self::exclusiveGatewayBpmn()));

        $gateway = $this->nodeByName($model, 'Gateway_1');
        $this->assertInstanceOf(DecisionModel::class, $gateway);

        $expressions = [];
        foreach ($gateway->getOutputs() as $transition) {
            $expressions[$transition->getName()] = $transition->getExpr();
        }

        $this->assertSame('$amount > 1000', $expressions['Flow_High'], '引擎读的是 properties.expr，桥接结果必须落在这里');
        // 无条件的边：引擎 `TransitionModel` 的 expr 是 `string|null`，而 `DynamicPropsTrait::handleGet()`
        // 用 `?? defaultValue()` 兜底，null 会被读成空串。空串在 `Expression::eval()` 里返回 false，
        // 因此这里断言「空」而不是「null」—— 断言 null 会红，红的不是转换器而是引擎的取值习惯。
        $this->assertEmpty($expressions['Flow_Low'], '无条件分支不应带上任何表达式');
    }

    /**
     * 导入结果要真的能落进 `nodes_config`，并能被现有链路继续消费
     *
     * 两条链路都验：
     * 1. `json_encode()` → `json_decode()` → `ModelParser::parse()`：模拟「存进 JSON 列再读回」；
     * 2. `LogicFlowSerializer::fromLogicFlow()`：本类刻意复用了它的 `_alkaid_*` 保留键与
     *    `ingenious:` 前缀，因此 BPMN 导入的图应当能直接还原成主应用 `WorkflowDefinition`。
     */
    public function testImportedGraphSurvivesNodesConfigAndMainAppRoundTrip(): void
    {
        $graph = $this->converter->fromBpmn(self::fixture('expense_reimbursement'));

        $json = json_encode($graph, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertSame($graph, json_decode($json, true), 'JSON 列往返不应改变结构');
        $this->assertCount(5, ModelParser::parse($json)->getNodes(), 'JSON 字符串形式也必须能被引擎消费');

        $definition = (new LogicFlowSerializer())->fromLogicFlow($graph);
        $this->assertSame('Process_ExpenseReimbursement', $definition->getName());
        $this->assertCount(5, $definition->getNodes());
        $this->assertCount(4, $definition->getConnections());
        // 反向再序列化一次仍然可执行：说明两边共用同一套节点类型/保留键约定
        $this->assertCount(
            5,
            ModelParser::parse((new LogicFlowSerializer())->toLogicFlow($definition))->getNodes()
        );
    }

    // -----------------------------------------------------------------
    // 夹具 / 工具
    // -----------------------------------------------------------------

    /**
     * 读取种子 BPMN 夹具
     *
     * @param string $definition 定义名（= 文件名）
     * @return string
     */
    private static function fixture(string $definition): string
    {
        $path = __DIR__ . '/../../Fixtures/bpmn/' . $definition . '.bpmn';

        if (!is_file($path)) {
            self::fail('缺少夹具：' . $path);
        }

        return (string) file_get_contents($path);
    }

    /**
     * 把一段 `<process>` 子元素包进完整的 BPMN 文档
     *
     * @param string $body process 的子元素
     * @return string
     */
    private static function document(string $body): string
    {
        return sprintf(
            '<bpmn:definitions xmlns:bpmn="%s" xmlns:xsi="%s" id="Definitions_Test" targetNamespace="t">'
            . '<bpmn:process id="Process_Test" name="测试流程" isExecutable="false">%s</bpmn:process>'
            . '</bpmn:definitions>',
            BpmnConverter::BPMN_NS,
            BpmnConverter::XSI_NS,
            $body
        );
    }

    /**
     * 排他网关样例：`${amount > 1000}` 走财务，否则（default）走经理
     *
     * @return string
     */
    private static function exclusiveGatewayBpmn(): string
    {
        return self::document(<<<'XML'
            <bpmn:startEvent id="Start_1" name="开始"/>
            <bpmn:exclusiveGateway id="Gateway_1" name="金额判断" default="Flow_Low"/>
            <bpmn:userTask id="Task_Manager" name="经理审批"/>
            <bpmn:userTask id="Task_Finance" name="财务审批"/>
            <bpmn:endEvent id="End_1" name="结束"/>
            <bpmn:sequenceFlow id="Flow_Start" sourceRef="Start_1" targetRef="Gateway_1"/>
            <bpmn:sequenceFlow id="Flow_High" name="金额较大" sourceRef="Gateway_1" targetRef="Task_Finance">
              <bpmn:conditionExpression xsi:type="bpmn:tFormalExpression">${amount &gt; 1000}</bpmn:conditionExpression>
            </bpmn:sequenceFlow>
            <bpmn:sequenceFlow id="Flow_Low" name="金额较小" sourceRef="Gateway_1" targetRef="Task_Manager"/>
            <bpmn:sequenceFlow id="Flow_A" sourceRef="Task_Manager" targetRef="End_1"/>
            <bpmn:sequenceFlow id="Flow_B" sourceRef="Task_Finance" targetRef="End_1"/>
            XML);
    }

    /**
     * 并行网关样例：fork（1 进 2 出）+ join（2 进 1 出）
     *
     * @return string
     */
    private static function parallelGatewayBpmn(): string
    {
        return self::document(<<<'XML'
            <bpmn:startEvent id="S" name="开始"/>
            <bpmn:parallelGateway id="Fork_1" name="分发"/>
            <bpmn:userTask id="A" name="技术评审"/>
            <bpmn:userTask id="B" name="商务评审"/>
            <bpmn:parallelGateway id="Join_1" name="汇聚"/>
            <bpmn:endEvent id="E" name="结束"/>
            <bpmn:sequenceFlow id="f1" sourceRef="S" targetRef="Fork_1"/>
            <bpmn:sequenceFlow id="f2" sourceRef="Fork_1" targetRef="A"/>
            <bpmn:sequenceFlow id="f3" sourceRef="Fork_1" targetRef="B"/>
            <bpmn:sequenceFlow id="f4" sourceRef="A" targetRef="Join_1"/>
            <bpmn:sequenceFlow id="f5" sourceRef="B" targetRef="Join_1"/>
            <bpmn:sequenceFlow id="f6" sourceRef="Join_1" targetRef="E"/>
            XML);
    }

    /**
     * 断言转换失败并返回异常信息
     *
     * @param string $xml BPMN XML
     * @return string 异常信息
     */
    private function expectConversionFailure(string $xml): string
    {
        try {
            $this->converter->fromBpmn($xml);
        } catch (BpmnConversionException $exception) {
            $this->assertNotSame('', $exception->getMessage());

            return $exception->getMessage();
        }

        $this->fail('应当抛 BpmnConversionException，但转换成功了 —— 静默丢弃/静默改写比报错更贵。');
    }

    /**
     * 节点 id ⇒ 类型 的映射（断言用）
     *
     * @param array $graph LogicFlow 图
     * @return array<string, string>
     */
    private function typesById(array $graph): array
    {
        $types = [];
        foreach ($graph['nodes'] as $node) {
            $types[$node['id']] = $node['type'];
        }

        return $types;
    }

    /**
     * 边 id ⇒ 边 的映射（断言用）
     *
     * @param array $graph LogicFlow 图
     * @return array<string, array>
     */
    private function edgesById(array $graph): array
    {
        $edges = [];
        foreach ($graph['edges'] as $edge) {
            $edges[$edge['id']] = $edge;
        }

        return $edges;
    }

    /**
     * 节点列表 ⇒ id 索引的映射（断言用）
     *
     * @param array $nodes 节点列表
     * @return array<string, array>
     */
    private function keyById(array $nodes): array
    {
        $indexed = [];
        foreach ($nodes as $node) {
            $indexed[$node['id']] = $node;
        }

        return $indexed;
    }

    /**
     * 图的结构签名：节点 id ⇒ 类型 + 边的 `源序号->目标序号` 列表
     *
     * 边用**节点序号**而不是节点 id 表达，是为了让两套 id 命名不同的表示
     * （BPMN 的 `StartEvent_1` vs `nodes_config` 的 `start`）也能比较形状。
     *
     * @param array $graph LogicFlow 图
     * @return array{nodes: array<string, string>, edges: list<string>}
     */
    private function topology(array $graph): array
    {
        $index = [];
        foreach ($graph['nodes'] as $position => $node) {
            $index[$node['id']] = $position;
        }

        $edges = [];
        foreach ($graph['edges'] as $edge) {
            $edges[] = $index[$edge['sourceNodeId']] . '->' . $index[$edge['targetNodeId']];
        }

        return ['nodes' => $this->typesById($graph), 'edges' => $edges];
    }

    /**
     * 边条件的签名：边 id ⇒ [expr, condition, BPMN 原文]
     *
     * @param array $graph LogicFlow 图
     * @return array<string, array{expr: string|null, condition: string|null, bpmn: string|null}>
     */
    private function conditions(array $graph): array
    {
        $conditions = [];
        foreach ($graph['edges'] as $edge) {
            $conditions[$edge['id']] = [
                'expr'      => $edge['properties'][BpmnConverter::EDGE_EXPR_KEY] ?? null,
                'condition' => $edge['properties'][BpmnConverter::EDGE_CONDITION_KEY] ?? null,
                'bpmn'      => $edge['properties'][BpmnConverter::BPMN_CONDITION_KEY] ?? null,
            ];
        }

        return $conditions;
    }

    /**
     * n 个节点的直线链形状：`0->1`, `1->2`, …
     *
     * @param int $nodeCount 节点数
     * @return list<string>
     */
    private function chainShape(int $nodeCount): array
    {
        $edges = [];
        for ($index = 0; $index < $nodeCount - 1; $index++) {
            $edges[] = $index . '->' . ($index + 1);
        }

        return $edges;
    }

    /**
     * 按节点名从模型里取节点
     *
     * @param object $model ProcessModel
     * @param string $name 节点名
     * @return object
     */
    private function nodeByName(object $model, string $name): object
    {
        foreach ($model->getNodes() as $node) {
            if ($node->getName() === $name) {
                return $node;
            }
        }

        $this->fail(sprintf('模型里没有节点 %s（实际有：%s）', $name, implode(
            ',',
            array_map(static fn ($node) => $node->getName(), $model->getNodes())
        )));
    }
}
