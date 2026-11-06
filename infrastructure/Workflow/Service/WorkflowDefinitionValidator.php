<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Alkaid\Workflow\Engine\Definition\BpmnConverter;
use Alkaid\Workflow\Engine\Definition\LogicFlowSerializer;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\parser\ModelParser;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use ParseError;
use Throwable;

/**
 * 工作流定义体校验器（`POST /workflow/definitions/{id}/validate` 与 `publish` 的唯一实现）
 *
 * ## 为什么必须真的跑引擎的解析器
 *
 * 「字段齐不齐」是最容易写、也最容易骗人的校验：`{nodes: [...], edges: [...]}` 两个键都在，
 * 但节点类型拼错、连线悬空、缺 start —— 引擎会**静默**产出空模型或残缺模型
 * （`ModelParser::parse()` 对未注册节点类型直接跳过，src/parser/ModelParser.php:81-97），
 * 故障延迟到 `$processModel->getStart()->execute()` 才以
 * 「Call to a member function on null」崩成 500。
 *
 * 所以本类的判据是**引擎自己的行为**，不是另写一套规则：
 *
 * | 阶段 | 用的东西 | 抓什么 |
 * | ---- | -------- | ------ |
 * | ① 形状 | 本类 | 不是 LogicFlow 结构、空图、重复 id、悬空边、start/end 数量、不可达节点 |
 * | ② 语义桥接 | `LogicFlowSerializer::fromLogicFlow()` | 节点类型在引擎 8 个解析器键里没有等价物 |
 * | ③ 引擎解析 | `ModelParser::parse()` | 解析后的模型是否非空、start 是否存在、有没有节点被静默丢弃 |
 * | ④ 条件表达式 | `token_get_all(..., TOKEN_PARSE)` | 边/决策节点的 `expr` 能不能被引擎 `Expression::eval()` 当成 PHP 编译 |
 * | ⑤ BPMN 导入 | `BpmnConverter::fromBpmn()` | 不支持的 BPMN 元素、条件无法桥接 |
 *
 * ① 只做 ②③ 无法给出**定位**的部分（引擎的异常只说「类型不存在」，不说图里还有一条悬空边），
 * 因此两层都要，且 ① 的每一条都对应 ②③ 真实会崩/静默丢点的情形。
 *
 * ## 为什么 ④ 不是「跑一遍表达式」
 *
 * 引擎用 `eval("return ($expr);")` 求值（plugins/workflow-engine/src/libs/utils/Expression.php），
 * 且它把 `ParseError` **吞掉返回 false** —— 语法错误的表现是「这条分支永远不成立」，
 * 比崩溃更难查。校验期绝不能 `eval` 用户输入（那等于执行任意代码），
 * 所以用 `token_get_all(..., TOKEN_PARSE)`：**只编译不执行**，语法错误会抛 `ParseError`。
 *
 * ## 输出契约
 *
 * 返回结构同时保留旧契约（`valid` / `errors` / `warnings` / `info`）与结构化明细（`issues`）：
 * - `errors[*]` / `warnings[*]` 是给人看的一句话；
 * - `issues[*]` 是给前端定位用的 `{code, severity, path, message}`，
 *   `path` 形如 `nodes[2]` / `edges[flow_1]`，前端可以据此高亮画布上的那个节点。
 *
 * @package Infrastructure\Workflow\Service
 */
class WorkflowDefinitionValidator
{
    /** 严重级别：错误（定义不可发布） */
    public const SEVERITY_ERROR = 'error';

    /** 严重级别：告警（可发布，但很可能不是作者本意） */
    public const SEVERITY_WARNING = 'warning';

    /** 引擎的节点类型前缀（`ingenious:`，与 INodeParser::NODE_NAME_PREFIX 同源） */
    private const NODE_TYPE_PREFIX = LogicFlowSerializer::NODE_TYPE_PREFIX;

    /** 边条件表达式所在的键（引擎只读 properties.expr，见 AbstractINodeParser:66） */
    private const EDGE_EXPR_KEY = LogicFlowSerializer::EDGE_EXPR_KEY;

    /**
     * 节点不可达时最多逐条报几个（避免一张坏图刷出几百条同样的 issue）
     */
    private const MAX_UNREACHABLE_ISSUES = 10;

    private ?BpmnConverter $bpmn;

    private ?LogicFlowSerializer $serializer;

    /**
     * @param BpmnConverter|null        $bpmn       BPMN 转换器（null 时惰性新建）
     * @param LogicFlowSerializer|null  $serializer LogicFlow 序列化器（null 时惰性新建）
     */
    public function __construct(?BpmnConverter $bpmn = null, ?LogicFlowSerializer $serializer = null)
    {
        $this->bpmn       = $bpmn;
        $this->serializer = $serializer;
    }

    /**
     * 校验一条定义行（读 `nodes_config`；没有图但有 `bpmn_xml` 时先做 BPMN 导入）
     *
     * @param array<string, mixed> $definition 定义行（`nodes_config` / `bpmn_xml` 需已解码 / 原始文本）
     * @return array{
     *     valid: bool,
     *     errors: array<int, string>,
     *     warnings: array<int, string>,
     *     info: array<int, string>,
     *     issues: array<int, array{code: string, severity: string, path: string, message: string}>,
     *     source: string,
     *     summary: array{errors: int, warnings: int},
     *     engine: array{parsed_nodes: int|null, declared_nodes: int, has_start: bool|null}
     * }
     */
    public function validateRow(array $definition): array
    {
        $issues = [];
        $info   = [];
        $source = 'none';
        $graph  = null;

        $rawConfig = $definition['nodes_config'] ?? null;

        if (is_array($rawConfig) && $rawConfig !== []) {
            $graph  = $rawConfig;
            $source = 'nodes_config';
        } else {
            $bpmnXml = $definition['bpmn_xml'] ?? null;

            if (is_string($bpmnXml) && trim($bpmnXml) !== '') {
                $source = 'bpmn_xml';
                $graph  = $this->importBpmn($bpmnXml, $issues);
                $info[] = '定义体取自 bpmn_xml（经 BpmnConverter 导入为 LogicFlow）';
            }
        }

        if ($graph === null) {
            // 只有「两边真的都空」才报 graph_missing。
            // 若 bpmn_xml 有内容但导入失败，`importBpmn()` 已经写入了 `bpmn_unsupported`，
            // 此时再补一条「定义体为空」纯属误导：调用方会以为要补图，
            // 实际要做的是改掉那个不支持的 BPMN 元素。
            if ($source !== 'bpmn_xml') {
                $issues[] = $this->issue(
                    'graph_missing',
                    '定义体为空：nodes_config 与 bpmn_xml 都没有内容，无法验证（草稿可以先空着，但发布前必须补图）',
                    'nodes_config'
                );
            }

            return $this->report($issues, $info, $source, 0, null);
        }

        // ① 形状 / 拓扑
        $this->checkShape($graph, $issues);

        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        // 图的形状还不足以做拓扑判断时，跳过 ②③（它们的报错只会是「空模型」，没有增量信息）
        $topologyUsable = $this->checkTopology($nodes, $edges, $issues);

        // ② 语义桥接：能不能还原成宿主定义模型
        if ($topologyUsable) {
            $this->checkBridge($graph, $issues, $info);
        }

        // ③ 引擎解析：真跑 ModelParser
        $engineInfo = ['parsed_nodes' => null, 'has_start' => null];

        if ($topologyUsable) {
            $engineInfo = $this->checkEngine($graph, $nodes, $issues, $info);
        }

        // ④ 条件表达式：只编译不执行
        $this->checkExpressions($nodes, $edges, $issues);

        return $this->report($issues, $info, $source, count($nodes), $engineInfo);
    }

    /**
     * 只校验一张 LogicFlow 图（供保存时「先校验再落库」复用）
     *
     * @param array<string, mixed> $graph LogicFlow 图
     * @return array<string, mixed> 与 {@see validateRow()} 同结构
     */
    public function validateGraph(array $graph): array
    {
        return $this->validateRow(['nodes_config' => $graph, 'bpmn_xml' => null]);
    }

    // =====================================================================
    // ① 形状 / 拓扑
    // =====================================================================

    /**
     * 结构形状检查（是否 LogicFlow、是否有 nodes/edges 两个数组）
     *
     * @param array<string, mixed>                                $graph
     * @param array<int, array<string, string>>                   $issues
     */
    private function checkShape(array $graph, array &$issues): void
    {
        if (!array_key_exists('nodes', $graph) || !is_array($graph['nodes'])) {
            $issues[] = $this->issue(
                'graph_not_logicflow',
                '定义体不是 LogicFlow 结构：缺少 nodes 数组（引擎 ModelParser 只认 {nodes: [...], edges: [...]}）',
                'nodes_config.nodes'
            );
        }

        if (!array_key_exists('edges', $graph) || !is_array($graph['edges'])) {
            $issues[] = $this->issue(
                'graph_not_logicflow',
                '定义体不是 LogicFlow 结构：缺少 edges 数组（引擎 ModelParser 只认 {nodes: [...], edges: [...]}）',
                'nodes_config.edges'
            );
        }

        $nodes = $graph['nodes'] ?? null;

        if (is_array($nodes) && $nodes === []) {
            $issues[] = $this->issue(
                'graph_empty',
                '定义体是空图：nodes 为空数组，引擎解析后得不到任何节点',
                'nodes_config.nodes'
            );
        }
    }

    /**
     * 拓扑检查：节点/边标识、悬空边、start/end 数量、可达性
     *
     * @param array<int, mixed>                                 $nodes
     * @param array<int, mixed>                                 $edges
     * @param array<int, array<string, string>>                 $issues
     * @return bool 图是否「足以做引擎级校验」（有节点、有边、节点都有 id）
     */
    private function checkTopology(array $nodes, array $edges, array &$issues): bool
    {
        if ($nodes === []) {
            return false;
        }

        $nodeIds   = [];
        $nodeTypes = [];
        $usable    = true;

        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                $issues[] = $this->issue(
                    'node_not_object',
                    sprintf('节点必须是对象，实际是 %s', get_debug_type($node)),
                    'nodes[' . $index . ']'
                );
                $usable = false;
                continue;
            }

            $id = isset($node['id']) && is_scalar($node['id']) ? trim((string) $node['id']) : '';

            if ($id === '') {
                $issues[] = $this->issue(
                    'node_missing_id',
                    '节点缺少 id：id 是连线唯一的定位依据，缺失时整个图无法解释',
                    'nodes[' . $index . ']'
                );
                $usable = false;
                continue;
            }

            if (isset($nodeIds[$id])) {
                $issues[] = $this->issue(
                    'node_duplicate_id',
                    sprintf('节点 id「%s」重复：两条节点同名时引擎只会保留先解析到的那条，连线会指向错误节点', $id),
                    'nodes[' . $id . ']'
                );
                $usable = false;
                continue;
            }

            $nodeIds[$id]   = true;
            $nodeTypes[$id] = isset($node['type']) && is_scalar($node['type']) ? trim((string) $node['type']) : '';

            if ($nodeTypes[$id] === '') {
                $issues[] = $this->issue(
                    'node_missing_type',
                    sprintf('节点「%s」缺少 type（形如 ingenious:start）', $id),
                    'nodes[' . $id . ']'
                );
            }
        }

        // 边：id 唯一 + 两端都在 nodes 里
        $edgeIds = [];

        foreach ($edges as $index => $edge) {
            if (!is_array($edge)) {
                $issues[] = $this->issue(
                    'edge_not_object',
                    sprintf('连线必须是对象，实际是 %s', get_debug_type($edge)),
                    'edges[' . $index . ']'
                );
                continue;
            }

            $edgeId = isset($edge['id']) && is_scalar($edge['id']) ? trim((string) $edge['id']) : '';

            if ($edgeId === '') {
                $issues[] = $this->issue(
                    'edge_missing_id',
                    '连线缺少 id（引擎用它做 transition.name，缺失时审批轨迹无法区分两条同源连线）',
                    'edges[' . $index . ']'
                );
            } elseif (isset($edgeIds[$edgeId])) {
                $issues[] = $this->issue(
                    'edge_duplicate_id',
                    sprintf('连线 id「%s」重复', $edgeId),
                    'edges[' . $edgeId . ']'
                );
            } else {
                $edgeIds[$edgeId] = true;
            }

            $source = $this->edgeEndpoint($edge, 'source');
            $target = $this->edgeEndpoint($edge, 'target');

            foreach (['source' => $source, 'target' => $target] as $side => $nodeId) {
                if ($nodeId === '') {
                    $issues[] = $this->issue(
                        'edge_missing_endpoint',
                        sprintf('连线「%s」缺少 %sNodeId', $edgeId !== '' ? $edgeId : '#' . $index, $side),
                        'edges[' . ($edgeId !== '' ? $edgeId : (string) $index) . ']'
                    );
                    continue;
                }

                if (!isset($nodeIds[$nodeId])) {
                    $issues[] = $this->issue(
                        'edge_dangling',
                        sprintf('连线「%s」的 %s 指向不存在的节点「%s」', $edgeId !== '' ? $edgeId : '#' . $index, $side, $nodeId),
                        'edges[' . ($edgeId !== '' ? $edgeId : (string) $index) . ']'
                    );
                }
            }
        }

        // start / end：引擎 ProcessEngines 在 `$processModel->getStart()->execute()` 处没有空判断
        $startIds = [];
        $endIds   = [];

        foreach ($nodeTypes as $id => $type) {
            $key = $this->parserKeyOf($type);

            if ($key === 'start') {
                $startIds[] = $id;
            } elseif ($key === 'end') {
                $endIds[] = $id;
            }
        }

        if ($startIds === []) {
            $issues[] = $this->issue(
                'start_node_missing',
                '缺少开始节点（type = ingenious:start）：引擎启动实例时会在 getStart() 处拿到 null 并崩成 500',
                'nodes_config.nodes'
            );
            $usable = false;
        } elseif (count($startIds) > 1) {
            $issues[] = $this->issue(
                'start_node_duplicated',
                sprintf('存在多个开始节点（%s）：引擎只取第一个 StartModel，其余分支永远不会被执行', implode('、', $startIds)),
                'nodes[' . implode(',', $startIds) . ']'
            );
        }

        if ($endIds === []) {
            $issues[] = $this->issue(
                'end_node_missing',
                '缺少结束节点（type = ingenious:end）：流程走到最后一条连线时没有落点',
                'nodes_config.nodes'
            );
        }

        // 可达性：从 start 出发沿边 BFS，走不到的节点 = 永远执行不到的分支
        if ($startIds !== []) {
            $this->checkReachability($nodes, $edges, $startIds[0], $issues);
        }

        return $usable;
    }

    /**
     * 从开始节点出发做 BFS，报出不可达节点
     *
     * @param array<int, mixed>                                 $nodes
     * @param array<int, mixed>                                 $edges
     * @param array<int, array<string, string>>                 $issues
     */
    private function checkReachability(array $nodes, array $edges, string $startId, array &$issues): void
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $source = $this->edgeEndpoint($edge, 'source');
            $target = $this->edgeEndpoint($edge, 'target');

            if ($source !== '' && $target !== '') {
                $adjacency[$source][] = $target;
            }
        }

        $visited = [$startId => true];
        $queue   = [$startId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($adjacency[$current] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[]        = $next;
                }
            }
        }

        $unreachable = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id']) || !is_scalar($node['id'])) {
                continue;
            }

            $id = trim((string) $node['id']);

            if ($id !== '' && !isset($visited[$id])) {
                $unreachable[] = $id;
            }
        }

        if ($unreachable === []) {
            return;
        }

        $shown = array_slice($unreachable, 0, self::MAX_UNREACHABLE_ISSUES);

        foreach ($shown as $id) {
            $issues[] = $this->issue(
                'node_unreachable',
                sprintf('节点「%s」从开始节点不可达（没有入边，或上游都没连到它）：这条分支永远不会被执行', $id),
                'nodes[' . $id . ']'
            );
        }

        if (count($unreachable) > count($shown)) {
            $issues[] = $this->issue(
                'node_unreachable',
                sprintf('另有 %d 个不可达节点未逐条列出', count($unreachable) - count($shown)),
                'nodes_config.nodes'
            );
        }
    }

    // =====================================================================
    // ② 语义桥接 / ③ 引擎解析
    // =====================================================================

    /**
     * 桥接检查：LogicFlow 图能否还原成宿主定义模型（节点类型是否有等价物）
     *
     * @param array<string, mixed>                              $graph
     * @param array<int, array<string, string>>                 $issues
     * @param array<int, string>                                $info
     */
    private function checkBridge(array $graph, array &$issues, array &$info): void
    {
        try {
            $definition = $this->serializer()->fromLogicFlow($graph);
        } catch (LFlowException $e) {
            $issues[] = $this->issue(
                'node_type_unmappable',
                'LogicFlow 图无法还原成宿主定义模型：' . $this->shorten($e->getMessage()),
                'nodes_config'
            );

            return;
        }

        $info[] = sprintf(
            'LogicFlow → 宿主模型：%d 个节点 / %d 条连线',
            count($definition->getNodes()),
            count($definition->getConnections())
        );
    }

    /**
     * 引擎解析检查：真跑 `ModelParser::parse()`，比对「声明节点数」与「解析出的节点数」
     *
     * 为什么必须比对数量：`ModelParser` 对**未注册**的节点类型是静默跳过
     * （src/parser/ModelParser.php:81-97），少了几个节点时模型照样「非空」，
     * 只有走到那个节点才会发现「流程走到一半没有下一步」。
     *
     * @param array<string, mixed>                                       $graph
     * @param array<int, mixed>                                          $declaredNodes
     * @param array<int, array<string, string>>                          $issues
     * @param array<int, string>                                         $info
     * @return array{parsed_nodes: int|null, has_start: bool|null}
     */
    private function checkEngine(array $graph, array $declaredNodes, array &$issues, array &$info): array
    {
        $payload = json_encode($graph, JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            $issues[] = $this->issue('graph_not_encodable', '定义体无法编码成 JSON（可能含非法 UTF-8 或资源类型）', 'nodes_config');

            return ['parsed_nodes' => null, 'has_start' => null];
        }

        try {
            // 必须先初始化引擎容器：节点解析器登记在进程级静态槽位 ServiceContext 上，
            // 没初始化就 parse 会把每个节点都判成「未知类型」→ 空模型 → 假报错。
            WorkflowEngineBootstrap::instance()->container();

            $model = ModelParser::parse($payload);
        } catch (Throwable $e) {
            $issues[] = $this->issue(
                'engine_unavailable',
                '引擎解析器不可用，无法完成引擎级校验：' . $this->shorten($e->getMessage()),
                'nodes_config'
            );

            return ['parsed_nodes' => null, 'has_start' => null];
        }

        $parsedNodes = $model->getNodes();

        if ($parsedNodes === []) {
            $issues[] = $this->issue(
                'engine_empty_model',
                '引擎 ModelParser 解析后没有任何节点：图缺 nodes/edges，或所有节点类型都未被引擎登记',
                'nodes_config'
            );

            return ['parsed_nodes' => 0, 'has_start' => false];
        }

        $parsedNames = [];

        foreach ($parsedNodes as $node) {
            $parsedNames[(string) $node->getName()] = true;
        }

        // 逐条报出「图里有、模型里没有」的节点：这是静默丢点的唯一可观察信号
        $dropped = [];

        foreach ($declaredNodes as $node) {
            if (!is_array($node) || !isset($node['id']) || !is_scalar($node['id'])) {
                continue;
            }

            $id = trim((string) $node['id']);

            if ($id !== '' && !isset($parsedNames[$id])) {
                $type     = isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : '?';
                $dropped[] = sprintf('%s(%s)', $id, $type);
            }
        }

        if ($dropped !== []) {
            $issues[] = $this->issue(
                'engine_dropped_nodes',
                sprintf(
                    '引擎解析时丢弃了 %d 个节点（模型里没有它们）：%s。'
                    . '原因为这些节点类型没有对应的解析器（引擎只登记 start/end/task/decision/fork/join/custom/wfSubProcess），'
                    . '运行时表现为「流程走到一半没有下一步」',
                    count($dropped),
                    implode('、', array_slice($dropped, 0, self::MAX_UNREACHABLE_ISSUES))
                ),
                'nodes_config.nodes'
            );
        }

        $hasStart = $model->getStart() !== null;

        if (!$hasStart) {
            $issues[] = $this->issue(
                'engine_start_missing',
                '引擎模型里没有 StartModel：启动实例会在 getStart() 处拿到 null',
                'nodes_config.nodes'
            );
        }

        $info[] = sprintf(
            '引擎 ModelParser 解析出 %d 个节点（图里声明 %d 个），任务节点 %d 个',
            count($parsedNodes),
            count($declaredNodes),
            count($model->getTasks() ?? [])
        );

        return ['parsed_nodes' => count($parsedNodes), 'has_start' => $hasStart];
    }

    // =====================================================================
    // ④ 条件表达式
    // =====================================================================

    /**
     * 条件表达式可编译性检查（只编译不执行）
     *
     * 检查两处：
     * - 边的 `properties.expr`（`DecisionModel` 用它分流，引擎 eval 失败会**静默返回 false**）；
     * - 决策节点的 `properties.expr`（`DecisionModel::exec()` 用它算下一跳节点名）。
     *
     * @param array<int, mixed>                                 $nodes
     * @param array<int, mixed>                                 $edges
     * @param array<int, array<string, string>>                 $issues
     */
    private function checkExpressions(array $nodes, array $edges, array &$issues): void
    {
        foreach ($edges as $index => $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $properties = is_array($edge['properties'] ?? null) ? $edge['properties'] : [];
            $expr       = $properties[self::EDGE_EXPR_KEY] ?? null;

            if ($expr === null || $expr === '') {
                continue;
            }

            $edgeId = isset($edge['id']) && is_scalar($edge['id']) ? (string) $edge['id'] : (string) $index;
            $this->assertExpressionCompilable($expr, 'edges[' . $edgeId . '].properties.expr', $issues);
        }

        // 决策节点：多条出边且一条 expr 都没有 → 引擎只能靠节点 expr 命中的 nextNodeName 分流，
        // 否则 DecisionModel::exec() 会抛 NOT_FOUND_NEXT_NODE
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : (string) $index;
            $type   = isset($node['type']) && is_scalar($node['type']) ? $this->parserKeyOf((string) $node['type']) : '';
            $props  = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $expr   = $props[self::EDGE_EXPR_KEY] ?? null;

            if (is_string($expr) && $expr !== '') {
                $this->assertExpressionCompilable($expr, 'nodes[' . $nodeId . '].properties.expr', $issues);
            }

            if ($type !== 'decision') {
                continue;
            }

            $outgoing = [];

            foreach ($edges as $edge) {
                if (is_array($edge) && $this->edgeEndpoint($edge, 'source') === $nodeId) {
                    $outgoing[] = $edge;
                }
            }

            if (count($outgoing) < 2) {
                continue;
            }

            $hasEdgeExpr = false;

            foreach ($outgoing as $edge) {
                $edgeProps = is_array($edge['properties'] ?? null) ? $edge['properties'] : [];

                if (($edgeProps[self::EDGE_EXPR_KEY] ?? '') !== '') {
                    $hasEdgeExpr = true;
                    break;
                }
            }

            if (!$hasEdgeExpr && !(is_string($expr) && $expr !== '')) {
                $issues[] = $this->issue(
                    'decision_without_condition',
                    sprintf(
                        '决策节点「%s」有 %d 条出边，但没有任何条件表达式（properties.expr）：'
                        . '引擎 DecisionModel 找不到下一跳时会抛 NOT_FOUND_NEXT_NODE，实例将无法推进',
                        $nodeId,
                        count($outgoing)
                    ),
                    'nodes[' . $nodeId . ']',
                    self::SEVERITY_WARNING
                );
            }
        }
    }

    /**
     * 断言一个表达式能被 PHP **编译**（不执行）
     *
     * @param mixed                                             $expr
     * @param string                                            $path
     * @param array<int, array<string, string>>                 $issues
     */
    private function assertExpressionCompilable(mixed $expr, string $path, array &$issues): void
    {
        if (!is_string($expr)) {
            $issues[] = $this->issue(
                'condition_not_string',
                sprintf('条件表达式必须是字符串，实际是 %s', get_debug_type($expr)),
                $path
            );

            return;
        }

        // 包在 `return (...)` 里再编译：既复刻引擎 `eval("return ($expr);")` 的上下文，
        // 又顺带挡掉语句注入（括号内出现 `;` 一定是语法错误）。
        try {
            token_get_all('<?php return (' . $expr . ');', TOKEN_PARSE);
        } catch (ParseError $e) {
            $issues[] = $this->issue(
                'condition_uncompilable',
                sprintf(
                    '条件表达式不是合法的 PHP 表达式（引擎用 eval("return ($expr);") 求值，失败时**静默返回 false**，'
                    . '该分支将永远不成立）：%s',
                    $this->shorten($e->getMessage())
                ),
                $path
            );
        }
    }

    // =====================================================================
    // ⑤ BPMN 导入
    // =====================================================================

    /**
     * BPMN XML → LogicFlow 图（不支持的元素/无法桥接的条件一律转成 issue）
     *
     * @param string                            $xml
     * @param array<int, array<string, string>> $issues
     * @return array<string, mixed>|null 转换失败时 null
     */
    private function importBpmn(string $xml, array &$issues): ?array
    {
        try {
            return $this->bpmn()->fromBpmn($xml);
        } catch (LFlowException $e) {
            $issues[] = $this->issue(
                'bpmn_unsupported',
                'BPMN 导入失败：' . $this->shorten($e->getMessage()),
                'bpmn_xml'
            );

            return null;
        } catch (Throwable $e) {
            $issues[] = $this->issue(
                'bpmn_unsupported',
                'BPMN 导入失败（非预期异常）：' . $this->shorten($e->getMessage()),
                'bpmn_xml'
            );

            return null;
        }
    }

    // =====================================================================
    // 内部
    // =====================================================================

    /**
     * 汇总报告
     *
     * @param array<int, array<string, string>> $issues
     * @param array<int, string>                $info
     * @param array{parsed_nodes: int|null, has_start: bool|null}|null $engineInfo
     * @return array<string, mixed>
     */
    private function report(array $issues, array $info, string $source, int $declaredNodes, ?array $engineInfo): array
    {
        $errors   = [];
        $warnings = [];

        foreach ($issues as $issue) {
            if ($issue['severity'] === self::SEVERITY_WARNING) {
                $warnings[] = $issue['message'];
            } else {
                $errors[] = $issue['message'];
            }
        }

        return [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
            'info'     => $info,
            'issues'   => array_values($issues),
            'source'   => $source,
            'summary'  => [
                'errors'   => count($errors),
                'warnings' => count($warnings),
            ],
            'engine'   => [
                'declared_nodes' => $declaredNodes,
                'parsed_nodes'   => $engineInfo['parsed_nodes'] ?? null,
                'has_start'      => $engineInfo['has_start'] ?? null,
            ],
        ];
    }

    /**
     * 构造一条 issue
     *
     * @return array{code: string, severity: string, path: string, message: string}
     */
    private function issue(string $code, string $message, string $path, string $severity = self::SEVERITY_ERROR): array
    {
        return [
            'code'     => $code,
            'severity' => $severity,
            'path'     => $path,
            'message'  => $message,
        ];
    }

    /**
     * 边的一端节点 id（同时兼容 LogicFlow 的驼峰与蛇形两种写法）
     *
     * @param array<string, mixed> $edge
     */
    private function edgeEndpoint(array $edge, string $side): string
    {
        $camel = $side . 'NodeId';
        $snake = $side . '_node_id';

        $value = $edge[$camel] ?? $edge[$snake] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * 节点 type → 引擎解析器键（去掉 `ingenious:` 前缀；无前缀时原样返回）
     */
    private function parserKeyOf(string $type): string
    {
        $type = trim($type);

        return str_starts_with($type, self::NODE_TYPE_PREFIX)
            ? substr($type, strlen(self::NODE_TYPE_PREFIX))
            : $type;
    }

    /**
     * issue 文案里带内部异常消息时截断，避免把整段堆栈式长文塞进响应
     */
    private function shorten(string $message, int $limit = 400): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        return mb_strlen($message) > $limit ? mb_substr($message, 0, $limit) . '…' : $message;
    }

    private function bpmn(): BpmnConverter
    {
        return $this->bpmn ??= new BpmnConverter();
    }

    private function serializer(): LogicFlowSerializer
    {
        return $this->serializer ??= new LogicFlowSerializer();
    }
}
