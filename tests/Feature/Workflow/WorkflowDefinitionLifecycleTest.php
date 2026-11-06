<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流定义 / 触发器「真库 + 真引擎」全链路测试
 *
 * ## 覆盖的语义（都是 Mock 实现根本无法证明的）
 *
 * 1. **定义全生命周期**：创建（draft）→ 校验（合法）→ 发布 → 启动实例（引擎真跑）→
 *    归档 → 归档后再启动被拒；
 * 2. **版本语义**：同一 `name` 存在 v1（在跑）与 v2（新发布）时，在跑实例仍然指向 v1 那一行，
 *    v1 的图一个字节都没变；新实例只能用 v2 启动；
 * 3. **`validateAction` 的负例**：非 LogicFlow 结构 / 缺 start 且有孤立节点 / 不支持的 BPMN 元素 /
 *    条件表达式编译不过 —— 四类都必须给出**结构化 issues**，而不是一句「验证失败」；
 * 4. **触发器**：增删改查 + 启用/禁用 + 测试 + 日志 + webhook 真启动实例；
 * 5. **租户隔离**：定义与触发器的跨租户访问 403，且**零副作用**（库里那行一个字段都没变）。
 *
 * ## 清理
 *
 * 所有夹具定义名以 {@see self::PREFIX} 开头，清理时按前缀圈定定义 → 实例 → 任务 → 事件 → 触发器；
 * 用户/角色/权限由 `WorkflowFixtureIsolation` 负责。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowDefinitionLifecycleTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;
    use \Tests\Traits\WorkflowExecutableFixture;

    /**
     * 夹具定义名前缀（清理时按它圈定，绝不按 ID 区间乱删）
     */
    private const PREFIX = 'lifecycle_';

    private int $tenant1UserId = 6801;
    private int $tenant2UserId = 6802;
    private int $tenant1RoleId = 6811;
    private int $tenant2RoleId = 6812;

    /**
     * @var array<string, int> resource.action => permission id
     */
    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeBusinessFixtures();
        $this->purgeWorkflowFixtures();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    // =====================================================================
    // 1) 全生命周期
    // =====================================================================

    /**
     * 创建 → 校验 → 发布 → 启动实例 → 归档 → 归档后再启动被拒
     */
    public function testDefinitionLifecycleFromCreateToArchiveBlocksStart(): void
    {
        $name = self::PREFIX . 'full_' . uniqid();
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        // ① 创建：永远是 version=1 的草稿
        $create = $this->httpRequest('POST', 'v1/workflow/definitions', [
            'name'         => $name,
            'display_name' => '全生命周期流程',
            'type'         => 'approval',
            'nodes_config' => $this->approvalGraph($name),
        ], $headers);

        $this->assertSame(201, $create->getCode(), '创建定义应返回 201：' . $create->getContent());
        $created = json_decode($create->getContent(), true);
        $definitionId = (int) ($created['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $definitionId, '创建响应应包含数字主键：' . $create->getContent());
        $this->assertSame('draft', $created['data']['status'], '新定义必须是草稿');
        $this->assertSame(0, $created['data']['is_active'], '新定义不可启动');
        $this->assertSame(1, $created['data']['version'], '新定义版本号必须是 1');
        $this->assertFalse($created['data']['is_startable'], '草稿的 is_startable 必须为 false');

        // ② 校验（合法）
        $validate = $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/validate', [], $headers);
        $this->assertSame(200, $validate->getCode(), '校验端点应返回 200：' . $validate->getContent());
        $valid = json_decode($validate->getContent(), true)['data'];
        $this->assertTrue($valid['valid'], '合法图应校验通过：' . $validate->getContent());
        $this->assertSame('nodes_config', $valid['source'], '定义体来源应为 nodes_config');
        $this->assertGreaterThan(0, (int) $valid['engine']['parsed_nodes'], '引擎应解析出非空模型');
        $this->assertTrue($valid['engine']['has_start'], '引擎模型应能找到 start 节点');

        // ③ 草稿不可启动（发布闸的真实存在证据）
        $earlyStart = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'variables'     => ['amount' => 100],
        ], $headers);
        $this->assertSame(400, $earlyStart->getCode(), '草稿不可启动：' . $earlyStart->getContent());
        $this->assertSame('draft', json_decode($earlyStart->getContent(), true)['data']['errors']['status'] ?? null);

        // ④ 发布
        $publish = $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/publish', [], $headers);
        $this->assertSame(200, $publish->getCode(), '发布应返回 200：' . $publish->getContent());
        $published = json_decode($publish->getContent(), true)['data'];
        $this->assertSame('published', $published['status']);
        $this->assertSame(1, $published['is_active']);
        $this->assertTrue($published['is_startable']);

        // ⑤ 启动实例（引擎真跑）
        $start = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'variables'     => ['amount' => 100, 'applicant' => '张三'],
        ], $headers);

        $this->assertSame(201, $start->getCode(), '发布后的定义应能启动实例：' . $start->getContent());
        $instanceId = (int) (json_decode($start->getContent(), true)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '启动应返回真实实例ID：' . $start->getContent());
        $this->assertSame($definitionId, (int) json_decode($start->getContent(), true)['data']['definition_id']);

        // ⑥ 归档
        $archive = $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/archive', [], $headers);
        $this->assertSame(200, $archive->getCode(), '归档应返回 200：' . $archive->getContent());
        $archived = json_decode($archive->getContent(), true)['data'];
        $this->assertSame('archived', $archived['status']);
        $this->assertSame(0, $archived['is_active']);
        $this->assertFalse($archived['is_startable']);

        // ⑦ 归档后再启动被拒
        $afterArchive = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'variables'     => ['amount' => 100],
        ], $headers);

        $this->assertSame(400, $afterArchive->getCode(), '归档后不可启动：' . $afterArchive->getContent());
        $this->assertSame(
            'archived',
            json_decode($afterArchive->getContent(), true)['data']['errors']['status'] ?? null,
            '拒绝原因应指出 status=archived：' . $afterArchive->getContent()
        );

        // ⑧ 在跑的那个实例不受归档影响
        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow, '归档不得删除在跑实例');
        $this->assertSame($definitionId, (int) $instanceRow['definition_id']);
    }

    // =====================================================================
    // 2) 版本语义
    // =====================================================================

    /**
     * 同一 name 的两个版本：在跑实例永远指向它启动时那一行，图不被改写
     */
    public function testTwoVersionsOfSameNameDoNotAffectRunningInstance(): void
    {
        $name    = self::PREFIX . 'versioned_' . uniqid();
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        // v1：start → apply → review → end
        $v1Graph = $this->approvalGraph($name);
        $v1Id    = $this->createPublishedDefinition($name, $v1Graph, $headers);

        $v1RowBefore = Db::table('workflow_definitions')->where('id', $v1Id)->find();
        $this->assertIsArray($v1RowBefore);

        // 在 v1 上启动一个实例
        $start = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $v1Id,
            'variables'     => ['amount' => 500],
        ], $headers);
        $this->assertSame(201, $start->getCode(), 'v1 应能启动实例：' . $start->getContent());
        $instanceId = (int) (json_decode($start->getContent(), true)['data']['id'] ?? 0);

        // 对已发布版本 PUT → 必须 fork 出一条 v2 草稿，而不是就地改 v1
        $forkGraph = $this->approvalGraph($name);
        $forkGraph['nodes'][1]['text']['value'] = '填写申请（v2 改动）';

        $fork = $this->httpRequest(
            'PUT',
            'v1/workflow/definitions/' . $v1Id,
            ['nodes_config' => $forkGraph, 'description' => 'v2 描述'],
            $headers
        );

        $this->assertSame(201, $fork->getCode(), '对已发布定义 PUT 应 fork 出新草稿：' . $fork->getContent());
        $forked = json_decode($fork->getContent(), true)['data'];
        $v2Id   = (int) $forked['id'];

        $this->assertNotSame($v1Id, $v2Id, 'fork 必须产出**新行**，不能就地改原行');
        $this->assertSame(2, (int) $forked['version'], '新版本号应为 max+1');
        $this->assertSame('draft', $forked['status'], '新版本是草稿');
        $this->assertSame(0, (int) $forked['is_active'], '新版本不可启动');
        $this->assertTrue((bool) ($forked['forked'] ?? false), '响应应标记 forked=true');
        $this->assertSame($v1Id, (int) ($forked['forked_from'] ?? 0), '响应应给出源版本ID');

        // 在跑实例仍然指向 v1，且 v1 的图一个字节都没变
        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertSame($v1Id, (int) $instanceRow['definition_id'], '在跑实例必须仍指向 v1');

        $v1RowAfter = Db::table('workflow_definitions')->where('id', $v1Id)->find();
        $this->assertSame(
            (string) $v1RowBefore['nodes_config'],
            (string) $v1RowAfter['nodes_config'],
            'v1 的 nodes_config 必须原样保留（否则在跑实例会中途换道）'
        );
        $this->assertSame('published', (string) $v1RowAfter['status'], 'v1 仍是已发布的快照');
        $this->assertSame(1, (int) $v1RowAfter['is_active'], 'v2 尚未发布时 v1 仍可启动');

        // 发布 v2 → v1 被降级为不可启动（status 不变）
        $publishV2 = $this->httpRequest('POST', 'v1/workflow/definitions/' . $v2Id . '/publish', [], $headers);
        $this->assertSame(200, $publishV2->getCode(), 'v2 应能发布：' . $publishV2->getContent());

        $v1RowFinal = Db::table('workflow_definitions')->where('id', $v1Id)->find();
        $this->assertSame('published', (string) $v1RowFinal['status'], '被取代的版本 status 仍是 published');
        $this->assertSame(0, (int) $v1RowFinal['is_active'], '被取代的版本 is_active 必须降为 0');
        $this->assertSame(
            (string) $v1RowBefore['nodes_config'],
            (string) $v1RowFinal['nodes_config'],
            '降级只改 is_active，图内容不得改写'
        );

        // 用 v1 启动新实例 → 400；用 v2 → 201
        $startOld = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $v1Id,
            'variables'     => [],
        ], $headers);
        $this->assertSame(400, $startOld->getCode(), '被取代的版本不可启动新实例：' . $startOld->getContent());

        $startNew = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $v2Id,
            'variables'     => ['amount' => 600],
        ], $headers);
        $this->assertSame(201, $startNew->getCode(), '当前发布版本应能启动：' . $startNew->getContent());
        $this->assertSame(
            $v2Id,
            (int) json_decode($startNew->getContent(), true)['data']['definition_id'],
            '新实例必须按 v2 启动'
        );

        // 在跑的那个 v1 实例仍在
        $this->assertSame(1, (int) Db::table('workflow_instances')->where('id', $instanceId)->count());
    }

    // =====================================================================
    // 3) validateAction 负例
    // =====================================================================

    /**
     * 四类非法定义体：每类都必须给出**结构化 issues**（不是一句「验证失败」）
     */
    public function testValidateReportsStructuredIssuesForFourNegativeClasses(): void
    {
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        // ① 非 LogicFlow 结构（旧「节点表」形态：没有 nodes/edges）
        $classA = $this->createDefinitionWithBody(self::PREFIX . 'bad_shape_' . uniqid(), [
            'nodes_config' => [
                'start'  => ['id' => 'start', 'name' => '开始', 'type' => 'start'],
                'apply'  => ['id' => 'apply', 'name' => '申请', 'type' => 'task'],
            ],
        ]);

        $reportA = $this->validateDefinition($classA, $headers);
        $this->assertFalse($reportA['valid'], '非 LogicFlow 结构必须判为不合法');
        $this->assertIssueCode($reportA, 'graph_not_logicflow');

        // ② 缺 start（另一类拓扑错误：有 start 但存在孤立节点，见下面 ②b）
        $classB = $this->createDefinitionWithBody(self::PREFIX . 'no_start_' . uniqid(), [
            'nodes_config' => [
                'nodes' => [
                    $this->logicFlowNode('apply', 'task', 200, 160, '填写申请'),
                    $this->logicFlowNode('end', 'end', 420, 160, '结束'),
                ],
                'edges' => [
                    $this->logicFlowEdge('flow_apply_end', 'apply', 'end'),
                ],
            ],
        ]);

        $reportB = $this->validateDefinition($classB, $headers);
        $this->assertFalse($reportB['valid'], '缺 start 的图必须判为不合法');
        $this->assertIssueCode($reportB, 'start_node_missing');

        // ②b 孤立节点（有 start、有 end，但有一个节点没有任何连线）
        $classB2 = $this->createDefinitionWithBody(self::PREFIX . 'orphan_' . uniqid(), [
            'nodes_config' => [
                'nodes' => [
                    $this->logicFlowNode('start', 'start', 200, 160, '开始'),
                    $this->logicFlowNode('apply', 'task', 420, 160, '填写申请'),
                    $this->logicFlowNode('orphan', 'task', 640, 160, '孤立节点'),
                    $this->logicFlowNode('end', 'end', 860, 160, '结束'),
                ],
                'edges' => [
                    $this->logicFlowEdge('flow_start_apply', 'start', 'apply'),
                    $this->logicFlowEdge('flow_apply_end', 'apply', 'end'),
                ],
            ],
        ]);

        $reportB2 = $this->validateDefinition($classB2, $headers);
        $this->assertFalse($reportB2['valid'], '含孤立节点的图必须判为不合法');
        $this->assertIssueCode($reportB2, 'node_unreachable');
        $this->assertStringContainsString(
            'orphan',
            (string) json_encode($reportB2['issues'], JSON_UNESCAPED_UNICODE),
            'issue 应指出是哪个节点孤立：' . json_encode($reportB2['issues'], JSON_UNESCAPED_UNICODE)
        );

        // ③ 不被支持的 BPMN 元素（`<bpmn:collaboration>` 会改变流程边界，BpmnConverter 直接抛）
        $classC = $this->insertDefinitionRow(self::PREFIX . 'bad_bpmn_' . uniqid(), [
            'bpmn_xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"'
                . ' id="Definitions_1" targetNamespace="http://bpmn.io/schema/bpmn">'
                . '<bpmn:collaboration id="Collaboration_1">'
                . '<bpmn:participant id="Participant_1" processRef="Process_1" />'
                . '</bpmn:collaboration>'
                . '<bpmn:process id="Process_1" isExecutable="true">'
                . '<bpmn:startEvent id="StartEvent_1" />'
                . '<bpmn:endEvent id="EndEvent_1" />'
                . '<bpmn:sequenceFlow id="Flow_1" sourceRef="StartEvent_1" targetRef="EndEvent_1" />'
                . '</bpmn:process>'
                . '</bpmn:definitions>',
        ]);

        $reportC = $this->validateDefinition($classC, $headers);
        $this->assertFalse($reportC['valid'], '不支持的 BPMN 元素必须判为不合法');
        $this->assertSame('bpmn_xml', $reportC['source'], '来源应识别为 bpmn_xml');
        $this->assertIssueCode($reportC, 'bpmn_unsupported');

        // ④ 条件表达式编译不过（引擎的 `Expression::eval()` 会 catch ParseError 后静默走 false）
        $classD = $this->createDefinitionWithBody(self::PREFIX . 'bad_expr_' . uniqid(), [
            'nodes_config' => [
                'nodes' => [
                    $this->logicFlowNode('start', 'start', 200, 160, '开始'),
                    $this->logicFlowNode('review', 'task', 420, 160, '审批'),
                    $this->logicFlowNode('end', 'end', 640, 160, '结束'),
                ],
                'edges' => [
                    $this->logicFlowEdge('flow_start_review', 'start', 'review'),
                    array_merge($this->logicFlowEdge('flow_review_end', 'review', 'end'), [
                        'properties' => ['_alkaid_type' => 'sequence', 'expr' => 'amount > && 100'],
                    ]),
                ],
            ],
        ]);

        $reportD = $this->validateDefinition($classD, $headers);
        $this->assertFalse($reportD['valid'], '条件表达式编译不过必须判为不合法');
        $this->assertIssueCode($reportD, 'condition_uncompilable');

        // ⑤ validate 是「查询式报告」，publish 才是把校验当门的地方：
        //    同一份非法定义体，发布必须 400 并把 issues/summary 一起带回来
        //    （否则前端只能看到「发布失败」四个字，无从修图）
        $publishInvalid = $this->httpRequest(
            'POST',
            'v1/workflow/definitions/' . $classD . '/publish',
            [],
            $headers
        );
        $this->assertSame(
            400,
            $publishInvalid->getCode(),
            '非法定义体发布必须被拒（400），实际响应：' . $publishInvalid->getContent()
        );

        $publishBody = json_decode($publishInvalid->getContent(), true);
        $this->assertIsArray($publishBody);
        $this->assertSame(400, $publishBody['code'] ?? null, '发布被拒的业务码应为 400');
        $this->assertArrayHasKey('errors', $publishBody['data'] ?? [], '发布被拒必须带回字段级错误');
        $this->assertArrayHasKey('issues', $publishBody['data']['errors'], '发布被拒必须带回结构化 issues');
        $this->assertArrayHasKey('summary', $publishBody['data']['errors'], '发布被拒必须带回 summary');
        $this->assertContains(
            'condition_uncompilable',
            array_column($publishBody['data']['errors']['issues'], 'code'),
            '发布被拒的 issues 应与 validate 报告一致（同一套引擎校验）'
        );
        $this->assertSame(
            'draft',
            (string) Db::table('workflow_definitions')->where('id', $classD)->value('status'),
            '发布被拒后定义必须仍是草稿（不得出现「校验没过但已发布」）'
        );

        // 结构断言：issues 必须是「带 code/path/message 的对象列表」，不是一堆字符串
        foreach ($reportD['issues'] as $issue) {
            $this->assertArrayHasKey('code', $issue);
            $this->assertArrayHasKey('severity', $issue);
            $this->assertArrayHasKey('path', $issue);
            $this->assertArrayHasKey('message', $issue);
        }

        // 合法图作为对照（防止「一律报不合法」的假绿）
        $good = $this->createDefinitionWithBody(self::PREFIX . 'good_' . uniqid(), [
            'nodes_config' => $this->approvalGraph('lifecycle_good'),
        ]);
        $reportGood = $this->validateDefinition($good, $headers);
        $this->assertTrue($reportGood['valid'], '合法图应通过校验：' . json_encode($reportGood['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $reportGood['issues'], '合法图不应有 issue');
    }

    // =====================================================================
    // 4) clone / delete 守卫
    // =====================================================================

    /**
     * clone：新行、version=1、草稿、默认名 `{源}_copy`；重名 409
     */
    public function testCloneCreatesDraftCopyAndRejectsDuplicateName(): void
    {
        $name    = self::PREFIX . 'clone_src_' . uniqid();
        $headers = $this->authHeaders($this->tenant1UserId, 1);
        $sourceId = $this->createPublishedDefinition($name, $this->approvalGraph($name), $headers);

        $clone = $this->httpRequest('POST', 'v1/workflow/definitions/' . $sourceId . '/clone', [], $headers);
        $this->assertSame(201, $clone->getCode(), '克隆应返回 201：' . $clone->getContent());

        $cloned = json_decode($clone->getContent(), true)['data'];
        $this->assertNotSame($sourceId, (int) $cloned['id'], '克隆必须是新行');
        $this->assertSame($name . '_copy', $cloned['name'], '默认名应为 {源name}_copy');
        $this->assertSame(1, (int) $cloned['version'], '克隆版本号回到 1');
        $this->assertSame('draft', $cloned['status'], '克隆是草稿');
        $this->assertSame(0, (int) $cloned['is_active'], '克隆不可启动');

        // MySQL 的 JSON 列会**重排对象键**（按长度 + 字典序），因此不能直接 assertSame 数组：
        // 先递归规范成「键有序」的结构，再比较内容
        $this->assertSame(
            $this->canonical($this->approvalGraph($name)),
            $this->canonical($cloned['nodes_config']),
            '克隆必须复制定义体'
        );

        // 同名再克隆 → 409（name 在租户内唯一，含历史版本）
        $again = $this->httpRequest('POST', 'v1/workflow/definitions/' . $sourceId . '/clone', [], $headers);
        $this->assertSame(409, $again->getCode(), '重复名克隆应 409：' . $again->getContent());
    }

    /**
     * delete 守卫：已发布 400 / 有实例 409 / 干净草稿 200
     */
    public function testDeleteGuardsForPublishedAndReferencedDefinitions(): void
    {
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        // ① 已发布 → 400
        $publishedName = self::PREFIX . 'del_published_' . uniqid();
        $publishedId   = $this->createPublishedDefinition($publishedName, $this->approvalGraph($publishedName), $headers);

        $deletePublished = $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $publishedId, [], $headers);
        $this->assertSame(400, $deletePublished->getCode(), '已发布定义不可删除：' . $deletePublished->getContent());
        $this->assertSame(1, (int) Db::table('workflow_definitions')->where('id', $publishedId)->count());

        // ② 有实例（先归档再删）→ 409 且带 instance_count
        $start = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $publishedId,
            'variables'     => [],
        ], $headers);
        $this->assertSame(201, $start->getCode(), '前置条件：实例应启动成功：' . $start->getContent());

        $archive = $this->httpRequest('POST', 'v1/workflow/definitions/' . $publishedId . '/archive', [], $headers);
        $this->assertSame(200, $archive->getCode(), '前置条件：归档应成功：' . $archive->getContent());

        $deleteReferenced = $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $publishedId, [], $headers);
        $this->assertSame(409, $deleteReferenced->getCode(), '有实例的定义不可删除：' . $deleteReferenced->getContent());
        $this->assertGreaterThanOrEqual(
            1,
            (int) (json_decode($deleteReferenced->getContent(), true)['data']['errors']['instance_count'] ?? 0),
            '409 响应应说明实例数量：' . $deleteReferenced->getContent()
        );
        $this->assertSame(1, (int) Db::table('workflow_definitions')->where('id', $publishedId)->count(), '越权删除后定义必须仍在');

        // ③ 干净草稿 → 200
        $draftName = self::PREFIX . 'del_draft_' . uniqid();
        $draftId   = $this->createDefinitionWithBody($draftName, ['nodes_config' => $this->approvalGraph($draftName)]);

        $deleteDraft = $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $draftId, [], $headers);
        $this->assertSame(200, $deleteDraft->getCode(), '干净草稿应可删除：' . $deleteDraft->getContent());
        $this->assertSame(0, (int) Db::table('workflow_definitions')->where('id', $draftId)->count());
    }

    // =====================================================================
    // 5) 触发器（真库 + 引擎 + 事件日志）
    // =====================================================================

    /**
     * 触发器 11 个端点里的 9 个：创建 / 详情 / 更新 / 启用 / 禁用 / 测试 / 日志 / 删除 / 列表
     */
    public function testTriggerCrudEnableTestAndLogs(): void
    {
        $name    = self::PREFIX . 'trig_def_' . uniqid();
        $headers = $this->authHeaders($this->tenant1UserId, 1);
        $definitionId = $this->createPublishedDefinition($name, $this->approvalGraph($name), $headers);

        // 创建（webhook 类型：会生成真实 key）
        $create = $this->httpRequest('POST', 'v1/workflow/triggers', [
            'definition_id' => $definitionId,
            'name'          => '报销回调',
            'type'          => 'webhook',
            'description'   => '外部系统回调入口',
            // 引擎的 WebhookTrigger schema 要求 secret 长度 16~128（minLength 是硬校验）
            'config'        => ['secret' => 'lifecycle-test-secret-0001', 'http_methods' => ['POST']],
        ], $headers);

        $this->assertSame(201, $create->getCode(), '创建触发器应 201：' . $create->getContent());
        $trigger = json_decode($create->getContent(), true)['data'];
        $triggerId = (int) $trigger['id'];

        $this->assertSame($definitionId, (int) $trigger['definition_id']);
        $this->assertSame('enabled', $trigger['status']);
        $this->assertSame(1, (int) $trigger['is_active']);
        $this->assertSame('外部系统回调入口', $trigger['description'], 'description 应存进 config 元数据并原样返回');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $trigger['webhook_key'], 'webhook_key 必须是 32 位十六进制');
        $this->assertSame(
            '/v1/workflow/triggers/webhook/' . $trigger['webhook_key'],
            $trigger['webhook_url'],
            'webhook_url 应指向真实路由'
        );
        $this->assertSame(0, (int) $trigger['execution_count'], '新建触发器没有执行记录');
        $this->assertArrayNotHasKey('_alkaid_meta', $trigger['config'], '保留子键不得出现在响应里');

        // 重名 → 409（uk_tenant_name）
        $dup = $this->httpRequest('POST', 'v1/workflow/triggers', [
            'definition_id' => $definitionId,
            'name'          => '报销回调',
            'type'          => 'manual',
        ], $headers);
        $this->assertSame(409, $dup->getCode(), '触发器名在租户内必须唯一：' . $dup->getContent());

        // 详情：带 recent_logs（CREATED 已落 workflow_event_logs）
        $read = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId, [], $headers);
        $this->assertSame(200, $read->getCode(), '详情应 200：' . $read->getContent());
        $detail = json_decode($read->getContent(), true)['data'];
        $this->assertNotEmpty($detail['recent_logs'], '详情应带 recent_logs');
        $this->assertSame('CREATED', $detail['recent_logs'][0]['event'], '创建应留下 CREATED 日志');
        $this->assertSame('trigger_created', $detail['recent_logs'][0]['event_type'], 'event_type 应是库里真实值');
        $this->assertSame($this->tenant1UserId, (int) $detail['recent_logs'][0]['user_id'], '操作人应是当前登录用户');

        // 更新（改名 + 描述）
        $update = $this->httpRequest('PUT', 'v1/workflow/triggers/' . $triggerId, [
            'name'        => '报销回调（已改名）',
            'description' => '改名后的描述',
        ], $headers);
        $this->assertSame(200, $update->getCode(), '更新应 200：' . $update->getContent());
        $updated = json_decode($update->getContent(), true)['data'];
        $this->assertSame('报销回调（已改名）', $updated['name']);
        $this->assertSame('改名后的描述', $updated['description']);
        $this->assertSame(
            $trigger['webhook_key'],
            $updated['webhook_key'],
            '更新不得改动既有 webhook_key（否则已发出的回调地址全部失效）'
        );

        // 禁用 / 启用
        $disable = $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/disable', [], $headers);
        $this->assertSame(200, $disable->getCode(), '禁用应 200：' . $disable->getContent());
        $this->assertSame('disabled', json_decode($disable->getContent(), true)['data']['status']);
        $this->assertSame(0, (int) Db::table('workflow_triggers')->where('id', $triggerId)->value('is_active'));

        $enable = $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/enable', [], $headers);
        $this->assertSame(200, $enable->getCode(), '启用应 200：' . $enable->getContent());
        $this->assertSame('enabled', json_decode($enable->getContent(), true)['data']['status']);
        $this->assertSame(1, (int) Db::table('workflow_triggers')->where('id', $triggerId)->value('is_active'));

        // 测试（dry_run）
        $test = $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/test', [
            'test_data' => ['amount' => 10],
            'dry_run'   => true,
        ], $headers);
        $this->assertSame(200, $test->getCode(), '测试应 200：' . $test->getContent());
        $testResult = json_decode($test->getContent(), true)['data'];
        $this->assertSame($triggerId, (int) $testResult['trigger_id']);
        $this->assertTrue($testResult['validation']['config_valid'], '配置应通过引擎校验');
        $this->assertTrue($testResult['dry_run']);
        $this->assertArrayHasKey('would_trigger', $testResult);

        // 日志：CREATED / UPDATED / DISABLED / ENABLED / TESTED 都在，且都带 trigger_id
        $logs = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId . '/logs', [], $headers);
        $this->assertSame(200, $logs->getCode(), '日志应 200：' . $logs->getContent());
        $logBody = json_decode($logs->getContent(), true)['data'];

        $events = array_column($logBody['list'], 'event');
        foreach (['CREATED', 'UPDATED', 'DISABLED', 'ENABLED', 'TESTED'] as $expected) {
            $this->assertContains($expected, $events, '日志应包含 ' . $expected . '：' . $logs->getContent());
        }

        foreach ($logBody['list'] as $entry) {
            $this->assertSame($triggerId, (int) $entry['trigger_id'], '日志必须带触发器归属');
            $this->assertNull($entry['instance_id'], '管理类事件不应带实例ID');
            $this->assertNotNull($entry['timestamp'], '日志应有时间戳');
        }

        // 列表（筛选参数走 withGet，不能拼在 pathinfo 里 —— 那会被当成路径的一部分）
        $list = $this->httpRequest('GET', 'v1/workflow/triggers', ['definition_id' => $definitionId], $headers);
        $this->assertSame(200, $list->getCode(), '列表应 200：' . $list->getContent());
        $this->assertContains(
            $triggerId,
            array_map('intval', array_column(json_decode($list->getContent(), true)['data']['list'], 'id')),
            '列表应包含本定义下的触发器'
        );

        // 删除 → 之后 404（日志行保留在事件表里，但资源已不存在）
        $delete = $this->httpRequest('DELETE', 'v1/workflow/triggers/' . $triggerId, [], $headers);
        $this->assertSame(200, $delete->getCode(), '删除应 200：' . $delete->getContent());
        $this->assertSame(0, (int) Db::table('workflow_triggers')->where('id', $triggerId)->count());

        // 删除本身必须留痕：`trigger_deleted` 行在触发器行消失后仍然可读（事件表不挂触发器外键）
        $deleteLog = Db::table('workflow_event_logs')
            ->where('tenant_id', 1)
            ->where('event_type', 'trigger_deleted')
            ->whereRaw(
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.trigger_id')) AS UNSIGNED) = ?",
                [$triggerId]
            )
            ->find();
        $this->assertIsArray($deleteLog, '删除触发器必须留下 trigger_deleted 事件（先留痕再删行）');
        $this->assertSame($this->tenant1UserId, (int) $deleteLog['user_id'], '删除日志的操作人应是当前登录用户');
        $this->assertNull($deleteLog['instance_id'], '管理类事件不得带实例ID');

        $afterDelete = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId, [], $headers);
        $this->assertSame(404, $afterDelete->getCode(), '删除后详情应 404：' . $afterDelete->getContent());
    }

    /**
     * webhook 端点：payload_mapping 生效 → 真启动实例 → 事件日志带真实 instance_id
     */
    public function testWebhookStartsRealInstanceAndRecordsLog(): void
    {
        $name    = self::PREFIX . 'hook_def_' . uniqid();
        $headers = $this->authHeaders($this->tenant1UserId, 1);
        $definitionId = $this->createPublishedDefinition($name, $this->approvalGraph($name), $headers);

        $create = $this->httpRequest('POST', 'v1/workflow/triggers', [
            'definition_id' => $definitionId,
            'name'          => '外部回调',
            'type'          => 'webhook',
            'config'        => [
                'http_methods'    => ['POST'],
                'payload_mapping' => [
                    'amount'    => 'data.amount',
                    'applicant' => ['path' => 'data.applicant', 'default' => '未知'],
                ],
            ],
        ], $headers);

        $this->assertSame(201, $create->getCode(), '前置条件：触发器应创建成功：' . $create->getContent());
        $trigger  = json_decode($create->getContent(), true)['data'];
        $triggerId = (int) $trigger['id'];
        $key       = (string) $trigger['webhook_key'];

        // 无租户上下文时不得「全局搜 key」
        $noTenant = $this->httpRequest('POST', 'v1/workflow/triggers/webhook/' . str_repeat('0', 32), []);
        $this->assertSame(404, $noTenant->getCode(), '不存在的 key 必须 404：' . $noTenant->getContent());

        // 正常回调（带 X-Tenant-ID，TenantIdentify 用它设置租户上下文）
        $fire = $this->httpRequest(
            'POST',
            'v1/workflow/triggers/webhook/' . $key,
            ['data' => ['amount' => 888, 'applicant' => '李四']],
            ['X-Tenant-ID' => '1', 'Accept' => 'application/json']
        );

        $this->assertSame(200, $fire->getCode(), 'webhook 应 200：' . $fire->getContent());
        $fired = json_decode($fire->getContent(), true)['data'];

        $this->assertTrue($fired['triggered'], 'webhook 应真的触发：' . $fire->getContent());
        $this->assertSame($definitionId, (int) $fired['definition_id']);
        $this->assertSame(888, (int) $fired['variables']['amount'], 'payload_mapping 应取到 data.amount');
        $this->assertSame('李四', $fired['variables']['applicant']);

        $instanceId = (int) $fired['instance_id'];
        $this->assertGreaterThan(0, $instanceId, 'webhook 必须返回真实实例ID：' . $fire->getContent());

        $instanceRow = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instanceRow, '实例必须真落库');
        $this->assertSame($definitionId, (int) $instanceRow['definition_id']);
        $this->assertSame(1, (int) $instanceRow['tenant_id']);

        // 事件日志：trigger_fired 带真实 instance_id
        $logRow = Db::table('workflow_event_logs')
            ->where('instance_id', $instanceId)
            ->where('event_type', 'trigger_fired')
            ->find();

        $this->assertIsArray($logRow, 'webhook 执行必须留下 trigger_fired 事件');
        $logData = json_decode((string) $logRow['event_data'], true);
        $this->assertSame($triggerId, (int) ($logData['trigger_id'] ?? 0), '事件应携带触发器归属');

        // 禁用后回调 → 400
        $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/disable', [], $headers);

        $disabled = $this->httpRequest(
            'POST',
            'v1/workflow/triggers/webhook/' . $key,
            ['data' => ['amount' => 1]],
            ['X-Tenant-ID' => '1', 'Accept' => 'application/json']
        );
        $this->assertSame(400, $disabled->getCode(), '禁用后回调应 400：' . $disabled->getContent());
    }

    // =====================================================================
    // 6) 租户隔离
    // =====================================================================

    /**
     * 定义：跨租户读 / 改 → 403，且库行零副作用
     */
    public function testDefinitionCrossTenantAccessIsForbiddenWithoutSideEffects(): void
    {
        $name    = self::PREFIX . 'iso_def_' . uniqid();
        $headers1 = $this->authHeaders($this->tenant1UserId, 1);
        $headers2 = $this->authHeaders($this->tenant2UserId, 2);

        $definitionId = $this->createDefinitionWithBody(
            $name,
            ['nodes_config' => $this->approvalGraph($name), 'description' => '原始描述']
        );

        $before = Db::table('workflow_definitions')->where('id', $definitionId)->find();

        // 读 → 403（不是 404：不能把「存在性」也藏起来）
        $read = $this->httpRequest('GET', 'v1/workflow/definitions/' . $definitionId, [], $headers2);
        $this->assertSame(403, $read->getCode(), '跨租户读定义应 403：' . $read->getContent());
        $this->assertSame(403, (int) json_decode($read->getContent(), true)['code']);

        // 改 → 403
        $update = $this->httpRequest(
            'PUT',
            'v1/workflow/definitions/' . $definitionId,
            ['description' => '越权修改'],
            $headers2
        );
        $this->assertSame(403, $update->getCode(), '跨租户改定义应 403：' . $update->getContent());

        // 删 → 403
        $delete = $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $definitionId, [], $headers2);
        $this->assertSame(403, $delete->getCode(), '跨租户删定义应 403：' . $delete->getContent());

        // 零副作用
        $after = Db::table('workflow_definitions')->where('id', $definitionId)->find();
        $this->assertIsArray($after, '越权调用后定义必须仍在');
        $this->assertSame($before['description'], $after['description'], '越权修改不得落库');
        $this->assertSame($before['status'], $after['status'], '越权调用不得改状态');

        // 同租户仍可读（防止改过头）
        $own = $this->httpRequest('GET', 'v1/workflow/definitions/' . $definitionId, [], $headers1);
        $this->assertSame(200, $own->getCode(), '同租户读定义应 200：' . $own->getContent());
    }

    /**
     * 触发器：跨租户读 / 禁用 → 403，且 `is_active` 一个字节都没变
     */
    public function testTriggerCrossTenantAccessIsForbiddenWithoutSideEffects(): void
    {
        $name     = self::PREFIX . 'iso_trig_' . uniqid();
        $headers1 = $this->authHeaders($this->tenant1UserId, 1);
        $headers2 = $this->authHeaders($this->tenant2UserId, 2);

        $definitionId = $this->createPublishedDefinition($name, $this->approvalGraph($name), $headers1);

        $create = $this->httpRequest('POST', 'v1/workflow/triggers', [
            'definition_id' => $definitionId,
            'name'          => '租户隔离触发器',
            'type'          => 'manual',
        ], $headers1);

        $this->assertSame(201, $create->getCode(), '前置条件：触发器应创建成功：' . $create->getContent());
        $triggerId = (int) json_decode($create->getContent(), true)['data']['id'];

        $read = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId, [], $headers2);
        $this->assertSame(403, $read->getCode(), '跨租户读触发器应 403：' . $read->getContent());

        $disable = $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/disable', [], $headers2);
        $this->assertSame(403, $disable->getCode(), '跨租户禁用触发器应 403：' . $disable->getContent());

        $this->assertSame(
            1,
            (int) Db::table('workflow_triggers')->where('id', $triggerId)->value('is_active'),
            '越权禁用后 is_active 必须仍为 1'
        );

        $logs = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId . '/logs', [], $headers2);
        $this->assertSame(403, $logs->getCode(), '跨租户读日志应 403：' . $logs->getContent());

        // 同租户仍可读
        $own = $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId, [], $headers1);
        $this->assertSame(200, $own->getCode(), '同租户读触发器应 200：' . $own->getContent());
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    /**
     * 创建一个草稿定义（直接落库，绕过 HTTP：用于构造「脏定义体」）
     *
     * @param array<string, mixed> $overrides
     */
    private function createDefinitionWithBody(string $name, array $overrides): int
    {
        return $this->insertDefinitionRow($name, $overrides);
    }

    /**
     * 直接插入一行定义（绕过服务层，用于构造服务层会拒绝的脏数据）
     *
     * @param array<string, mixed> $overrides
     */
    private function insertDefinitionRow(string $name, array $overrides): int
    {
        $now = date('Y-m-d H:i:s');

        $row = array_merge([
            'tenant_id'    => 1,
            'name'         => $name,
            'display_name' => '生命周期测试定义',
            'description'  => '生命周期测试夹具',
            'type'         => 'approval',
            'version'      => 1,
            'status'       => 'draft',
            'is_active'    => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
            'created_by'   => $this->tenant1UserId,
            'updated_by'   => $this->tenant1UserId,
        ], $overrides);

        foreach (['nodes_config', 'triggers_config', 'variables_schema'] as $jsonColumn) {
            if (isset($row[$jsonColumn]) && is_array($row[$jsonColumn])) {
                $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_UNESCAPED_UNICODE);
            }
        }

        return (int) Db::table('workflow_definitions')->insertGetId($row);
    }

    /**
     * 创建并发布一个定义（返回定义ID）
     *
     * @param array<string, mixed> $graph
     * @param array<string, string> $headers
     */
    private function createPublishedDefinition(string $name, array $graph, array $headers): int
    {
        $create = $this->httpRequest('POST', 'v1/workflow/definitions', [
            'name'         => $name,
            'display_name' => '已发布夹具流程',
            'type'         => 'approval',
            'nodes_config' => $graph,
        ], $headers);

        $this->assertSame(201, $create->getCode(), '前置条件：定义应创建成功：' . $create->getContent());
        $definitionId = (int) json_decode($create->getContent(), true)['data']['id'];

        $publish = $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/publish', [], $headers);
        $this->assertSame(200, $publish->getCode(), '前置条件：定义应发布成功：' . $publish->getContent());

        return $definitionId;
    }

    /**
     * 调用 validate 端点并返回 `data`
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function validateDefinition(int $definitionId, array $headers): array
    {
        $response = $this->httpRequest(
            'POST',
            'v1/workflow/definitions/' . $definitionId . '/validate',
            [],
            $headers
        );

        $this->assertSame(200, $response->getCode(), '校验端点应 200：' . $response->getContent());

        return json_decode($response->getContent(), true)['data'];
    }

    /**
     * 断言报告里出现了某个 issue code
     *
     * @param array<string, mixed> $report
     */
    private function assertIssueCode(array $report, string $code): void
    {
        $codes = array_column($report['issues'], 'code');

        $this->assertContains(
            $code,
            $codes,
            '校验报告应包含 issue code「' . $code . '」，实际：' . json_encode($codes, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 把数组递归规范成「键有序」结构（用于绕开 MySQL JSON 列的键重排）
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // 列表保持原序（节点的顺序有意义），映射按键排序（键序无意义）
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    /**
     * 构造携带 JWT 的请求头
     *
     * @return array<string, string>
     */
    private function authHeaders(int $userId, int $tenantId): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $userId,
                tenantId: $tenantId,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 简易 HTTP 调用辅助（与既有工作流 Feature 测试保持一致）
     *
     * 两个必须做对的细节：
     * 1. **每次请求前清空路由/控制器中间件队列**：ThinkPHP 的中间件队列在同一进程内跨请求累积，
     *    上一次请求的 Auth / Permission 会残留到本次（表现是「webhook 这种无 Auth 端点莫名 401」）；
     * 2. **必须带 `Content-Type: application/json`**：`Request::withInput()` 只在 JSON 或
     *    urlencoded 时才解析出 `post` / `put`，否则 `$request->put()` 恒为空数组，
     *    PUT 请求会被服务层判成「没有可更新的字段」。
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader(array_merge([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $headers));

        if ($method === 'GET') {
            $request->withGet($data);
        } else {
            $request->withInput((string) json_encode($data));
            // 兜底：空请求体时 withInput 不会解析出 post，显式给一份
            $request->withPost($data);
        }

        return $this->runHttp($request);
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($middleware);
        $queue['route']      = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    /**
     * 播种用户 / 角色 / 权限
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            $permissions = [
                'workflow_definition' => ['read', 'write', 'delete'],
                'workflow_instance'   => ['read', 'write'],
                'workflow_trigger'    => ['read', 'write', 'delete'],
            ];

            $preferred = 6820;

            foreach ($permissions as $resource => $actions) {
                foreach ($actions as $action) {
                    $this->permissionIds[$resource . '.' . $action] = $this->ensureWorkflowPermission(
                        $resource,
                        $action,
                        $preferred++,
                        'lifecycle 测试权限：' . $resource . '.' . $action
                    );
                }
            }

            Db::table('users')->insertAll([
                [
                    'id'         => $this->tenant1UserId,
                    'tenant_id'  => 1,
                    'username'   => 'wf_lifecycle_1',
                    'email'      => 'wf_lifecycle_1@workflow-lifecycle.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => $this->tenant2UserId,
                    'tenant_id'  => 2,
                    'username'   => 'wf_lifecycle_2',
                    'email'      => 'wf_lifecycle_2@workflow-lifecycle.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insertAll([
                [
                    'id'         => $this->tenant1RoleId,
                    'tenant_id'  => 1,
                    'name'       => 'wf_lifecycle_role_1',
                    'slug'       => 'wf_lifecycle_role_1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => $this->tenant2RoleId,
                    'tenant_id'  => 2,
                    'name'       => 'wf_lifecycle_role_2',
                    'slug'       => 'wf_lifecycle_role_2',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('user_roles')->insertAll([
                ['user_id' => $this->tenant1UserId, 'role_id' => $this->tenant1RoleId, 'created_at' => $now],
                ['user_id' => $this->tenant2UserId, 'role_id' => $this->tenant2RoleId, 'created_at' => $now],
            ]);

            $rolePermissions = [];

            foreach ([$this->tenant1RoleId, $this->tenant2RoleId] as $roleId) {
                foreach ($this->permissionIds as $permissionId) {
                    $rolePermissions[] = [
                        'role_id'       => $roleId,
                        'permission_id' => $permissionId,
                        'created_at'    => $now,
                    ];
                }
            }

            Db::table('role_permissions')->insertAll($rolePermissions);
        });
    }

    /**
     * 清除测试用户的权限缓存
     */
    private function clearPermissionCache(): void
    {
        $service = new PermissionService();

        foreach ([$this->tenant1UserId, $this->tenant2UserId] as $userId) {
            $service->clearUserCache($userId);
        }
    }

    /**
     * 删除本类播种的业务数据（按定义名前缀圈定）
     *
     * 外键顺序：事件 → 任务 → 实例 → 触发器 → 定义。
     */
    private function purgeBusinessFixtures(): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->whereLike('name', self::PREFIX . '%')
            ->column('id');

        if ($definitionIds === []) {
            return;
        }

        $instanceIds = Db::table('workflow_instances')->whereIn('definition_id', $definitionIds)->column('id');

        if ($instanceIds !== []) {
            Db::table('workflow_event_logs')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_tasks')->whereIn('instance_id', $instanceIds)->delete();
            Db::table('workflow_instances')->whereIn('id', $instanceIds)->delete();
        }

        // 触发器事件（管理类）的 instance_id 是 NULL，只能按 event_data.trigger_id 圈定
        $triggerIds = Db::table('workflow_triggers')->whereIn('definition_id', $definitionIds)->column('id');

        if ($triggerIds !== []) {
            Db::table('workflow_event_logs')
                ->whereIn('event_type', [
                    'trigger_created', 'trigger_updated', 'trigger_enabled',
                    'trigger_disabled', 'trigger_tested', 'trigger_fired', 'trigger_failed',
                ])
                ->where(function ($query) use ($triggerIds): void {
                    foreach (array_map('intval', $triggerIds) as $triggerId) {
                        $query->whereOrRaw(
                            "CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.trigger_id')) AS UNSIGNED) = ?",
                            [$triggerId]
                        );
                    }
                })
                ->delete();

            Db::table('workflow_triggers')->whereIn('id', $triggerIds)->delete();
        }

        Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }
}
