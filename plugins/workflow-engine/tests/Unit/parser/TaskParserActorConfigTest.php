<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\parser;

use Alkaid\Workflow\Engine\model\logicflow\LfNode;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\parser\handler\TaskParser;
use madong\helper\Dict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 节点参与者配置的**解析入口**测试（无数据库）
 *
 * ## 这条测试盯的是哪一段
 *
 * 节点上 `candidate_ext_groups: ["role:admin"]` 这样的配置，要经过三层才能变成任务列：
 *
 *   `nodes_config`(JSON) → `ModelParser` → `TaskParser::parseNode()` → `TaskModel` → 解析器 → 任务列
 *
 * 其中 `ModelParser::parse()` 走的是
 * `ArrayHelper::jsonToArray()` → `ArrayHelper::arrayToObject()`，
 * 于是 JSON 里的**数组在 `properties` 里变成 `stdClass`**（键名是数字字符串）。
 * 而 `TaskModel::$candidateUsers` / `$candidateGroups` 的声明是 `string|array|null` ——
 * 给它们塞 `stdClass` 会直接 `TypeError`：**流程定义一解析就崩，配了等于没配**。
 *
 * 这个文件用「与真实链路相同的形态」（stdClass 包着数组）喂给 `TaskParser`，
 * 钉住 `TaskParser` 里的归一化：stdClass → 数组、数组单值 → 逗号串、非标量 → JSON 串。
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\parser
 */
class TaskParserActorConfigTest extends TestCase
{
    /**
     * 候选人组：LogicFlow 里的数组会以 stdClass 到达，必须归一化成数组
     */
    #[Test]
    public function candidateGroupsArriveAsArrayNotStdClass(): void
    {
        $model = $this->parseTaskNode([
            'candidate_ext_groups' => (object) ['0' => 'role:admin'],
        ]);

        $this->assertSame(
            ['role:admin'],
            $model->getCandidateGroups(),
            'candidate_ext_groups 必须落成数组（stdClass 会让 TaskModel 抛 TypeError）'
        );
    }

    /**
     * 候选人：同样要归一化，且顺序保持
     */
    #[Test]
    public function candidateUsersArriveAsArray(): void
    {
        $model = $this->parseTaskNode([
            'candidate_ext_users' => (object) ['0' => 'user:11', '1' => '12'],
        ]);

        $this->assertSame(
            ['user:11', '12'],
            $model->getCandidateUsers(),
            'candidate_ext_users 必须落成数组，且保持配置顺序（解析器按顺序取候选）'
        );
    }

    /**
     * 字符串形态（老配置写法）原样保留，不做任何改写
     */
    #[Test]
    public function candidateGroupsKeepPlainStringForm(): void
    {
        $model = $this->parseTaskNode([
            'candidate_ext_groups' => 'role:manager,role:finance',
        ]);

        $this->assertSame(
            'role:manager,role:finance',
            $model->getCandidateGroups(),
            '逗号分隔的字符串是老写法，解析器自己能切分，parser 不该动它'
        );
    }

    /**
     * 单值参与者：数组/对象按 `,` 连接 —— 这正是解析器认可的「多人配置」
     * （首个当处理人，其余进候选集）
     */
    #[Test]
    public function assigneeArrayBecomesCommaJoinedString(): void
    {
        $model = $this->parseTaskNode([
            'assignee' => (object) ['0' => 'user:11', '1' => 'user:12'],
        ]);

        $this->assertSame('user:11,user:12', $model->getAssignee());
    }

    /**
     * assignee 是普通字符串时保持原样（最常见形态，不能被归一化改坏）
     */
    #[Test]
    public function assigneeStringIsUntouched(): void
    {
        $model = $this->parseTaskNode(['assignee' => '${applicant_id}']);

        $this->assertSame('${applicant_id}', $model->getAssignee());
    }

    /**
     * 到期时间：写成数字（小时/天）时也要能进模型
     *
     * `expire_time` 的唯一消费方是 `ProcessFlowUtils::processTime()`，
     * 而 `TaskModel::$expireTime` 声明为 `string|null` —— 整数会抛 TypeError。
     */
    #[Test]
    public function expireTimeNumberBecomesString(): void
    {
        $model = $this->parseTaskNode(['expire_time' => 24]);

        $this->assertSame('24', $model->getExpireTime());
    }

    /**
     * 空集合等价于「没配」，不能变成 `['']` 这种空令牌
     */
    #[Test]
    public function emptyCollectionsBecomeNull(): void
    {
        $model = $this->parseTaskNode([
            'candidate_ext_groups' => [],
            'candidate_ext_users'  => (object) [],
        ]);

        $this->assertNull($model->getCandidateGroups(), '空组集合应视为未配置');
        $this->assertNull($model->getCandidateUsers(), '空候选集合应视为未配置');
    }

    /**
     * 嵌套的非标量配置**不静默丢弃**：编成 JSON 串交给下游报错，
     * 而不是悄悄收敛成某个用户的 ID
     */
    #[Test]
    public function nestedStructuresAreEncodedNotDropped(): void
    {
        $model = $this->parseTaskNode([
            'candidate_ext_groups' => (object) ['0' => (object) ['kind' => 'role', 'slug' => 'admin']],
        ]);

        $groups = $model->getCandidateGroups();

        $this->assertIsArray($groups);
        $this->assertSame(
            '{"kind":"role","slug":"admin"}',
            $groups[0],
            '嵌套结构应保留为 JSON 串，让解析器因「没有合法前缀」而报错，而不是被丢掉'
        );
    }

    /**
     * 自定义扩展属性（`properties.field`）里的 priority 必须原样进 `ext`
     */
    #[Test]
    public function customFieldKeepsPriority(): void
    {
        $model = $this->parseTaskNode([
            'field' => (object) ['priority' => 3],
        ]);

        $ext = $model->getExt();

        $this->assertInstanceOf(Dict::class, $ext);
        $this->assertSame(3, $ext->get('priority'), 'priority 走 properties.field，buildTaskColumns() 从这里读');
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    /**
     * 用「与 ModelParser 相同的形态」构造 LfNode 并解析出 TaskModel
     *
     * @param array<string, mixed> $properties 节点属性（值刻意用 stdClass 模拟 LogicFlow 的数组）
     */
    private function parseTaskNode(array $properties): TaskModel
    {
        $dict = new Dict();
        $dict->putAll($properties);

        $node = new LfNode();
        $node->setId('review_by_role');
        $node->setType('ingenious:task');
        $node->setX(100);
        $node->setY(100);
        $node->setText((object) ['value' => '角色审批']);
        $node->setProperties($dict);

        $parser = new TaskParser();
        $parser->parse($node, []);

        $model = $parser->getModel();

        $this->assertInstanceOf(TaskModel::class, $model);

        return $model;
    }
}
