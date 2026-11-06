<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Services;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Services\DefaultTaskActorResolver;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\Tests\Mock\InMemoryTaskActorDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 任务参与者解析规则的**无数据库**逐条测试
 *
 * 覆盖 {@see DefaultTaskActorResolver} 的每一条规则与失败口径（见 ITaskActorResolver 的注释）：
 * `数字` / `user:` / `${表达式}` / `role:` / `dept:` / 裸值 / 跨租户拒绝 / 表达式求值失败 /
 * 未知前缀 / 无租户上下文 / 会签逐人拆分。
 *
 * 为什么不连库：插件自己的 PHPUnit 配置只有 `vendor/autoload.php` 作 bootstrap，
 * 注释里明确禁止把 DB 连接参数写进插件配置。规则测试注入
 * {@see InMemoryTaskActorDirectory}，一行 SQL 都不发；真库路径
 * （DefaultTaskActorDirectory + 真实 users/roles 表）由根套件
 * `tests/Feature/Workflow/WorkflowTaskActorResolutionTest.php` 覆盖。
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Services
 */
class DefaultTaskActorResolverTest extends TestCase
{
    /**
     * 本租户（规则测试的默认上下文）
     */
    private const TENANT = 1;

    /**
     * 另一个租户（跨租户反例用）
     */
    private const OTHER_TENANT = 2;

    private DefaultTaskActorResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clearAll();
        TenantContext::setTenantId(self::TENANT);

        // 租户来源有两条：TenantContext，以及 ServiceContext 的 `extension_parameters.tenant_id`
        // （见 ResolvesWorkflowTenant::tenantId()）。两者都是**静态**状态，会跨测试类残留：
        // 整套跑时若有前面的类调过 WorkflowBootstrap::initialize(['extension_parameters' => ...])，
        // 这里必须一并清掉，否则「无租户上下文 → 抛异常」那条用例会在整套里失效、单跑却通过。
        ServiceContext::extensions([]);

        $this->resolver = new DefaultTaskActorResolver($this->directory());
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
        ServiceContext::extensions([]);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1) assignee
    // -----------------------------------------------------------------

    #[Test]
    public function resolvesPlainNumericAssigneeAndFillsName(): void
    {
        $resolution = $this->resolve(['assignee' => '101']);

        $this->assertSame(101, $resolution->assignee, '纯数字 assignee 应解析成该用户');
        $this->assertSame('张三', $resolution->assigneeName, 'assignee_name 应来自 users.name');
        $this->assertSame([101], $resolution->candidateUserIds, 'candidate_users 必须含 assignee');
        $this->assertSame([], $resolution->warnings, '正常配置不应产生告警');
    }

    #[Test]
    public function resolvesPrefixedUserAssignee(): void
    {
        $resolution = $this->resolve(['assignee' => 'user:102']);

        $this->assertSame(102, $resolution->assignee);
        $this->assertSame('李四', $resolution->assigneeName);
    }

    #[Test]
    public function multipleAssigneesKeepFirstAsAssigneeAndRestAsCandidates(): void
    {
        // 节点上写了多人：首个当处理人，其余降级为候选人（与原 firstActorId 的口径一致）
        $resolution = $this->resolve(['assignee' => '101, user:102']);

        $this->assertSame(101, $resolution->assignee);
        $this->assertSame([101, 102], $resolution->candidateUserIds);
    }

    // -----------------------------------------------------------------
    // 2) candidate_users
    // -----------------------------------------------------------------

    #[Test]
    public function resolvesCommaSeparatedCandidateUsers(): void
    {
        $resolution = $this->resolve(['candidateUsers' => '101,102; user:103|101']);

        $this->assertNull($resolution->assignee, '只配候选人不等于指派，assignee 应为空');
        $this->assertSame([101, 102, 103], $resolution->candidateUserIds, '逗号/分号/竖线分隔都应支持，并去重');
    }

    #[Test]
    public function resolvesArrayCandidateUsers(): void
    {
        // 设计器导出的数组形态（TaskModel::$candidateUsers 已放宽为 string|array|null）
        $resolution = $this->resolve(['candidateUsers' => [101, 'user:102', '  ']]);

        $this->assertSame([101, 102], $resolution->candidateUserIds, '数组形态应逐个解析并忽略空项');
    }

    // -----------------------------------------------------------------
    // 3) candidate_groups：角色展开
    // -----------------------------------------------------------------

    #[Test]
    public function expandsRoleGroupIntoMemberIds(): void
    {
        $resolution = $this->resolve(['candidateGroups' => ['role:admin']]);

        $this->assertSame([101, 102], $resolution->candidateUserIds, 'role:admin 应展开成本租户内该角色的全部成员');
        $this->assertSame(['role:admin'], $resolution->candidateGroupRefs, 'candidate_groups 必须原样保留组引用（审计用）');
        $this->assertNull($resolution->assignee, '只配角色组时任务应是候选池（无处理人）');
    }

    #[Test]
    public function expandsBareGroupValueAsRoleButKeepsRawRef(): void
    {
        // 裸值（无前缀）按角色处理：本字段的语义就是「组 = 角色」，dept 必须显式写前缀
        $resolution = $this->resolve(['candidateGroups' => 'admin']);

        $this->assertSame([101, 102], $resolution->candidateUserIds, '裸值应按 role:<值> 展开');
        $this->assertSame(['admin'], $resolution->candidateGroupRefs, '落库的仍是配置里写的那串（不归一化）');
    }

    #[Test]
    public function mergesAssigneeCandidateUsersAndExpandedGroups(): void
    {
        $resolution = $this->resolve([
            'assignee'       => 'user:103',
            'candidateUsers' => '101',
            'candidateGroups' => ['role:admin'],
        ]);

        $this->assertSame(103, $resolution->assignee);
        $this->assertSame([103, 101, 102], $resolution->candidateUserIds, '候选人是三者的并集（去重，含 assignee）');
        $this->assertSame(['role:admin'], $resolution->candidateGroupRefs);
    }

    #[Test]
    public function roleMissingInCurrentTenantYieldsWarningInsteadOfThrowing(): void
    {
        // 该角色只存在于租户 2：对租户 1 而言不存在 —— 拒绝并记录，但不让流程建不出任务
        $resolution = $this->resolve(['candidateGroups' => ['role:other_tenant_role']]);

        $this->assertSame([], $resolution->candidateUserIds);
        $this->assertCount(1, $resolution->warnings);
        $this->assertStringContainsString('other_tenant_role', $resolution->warnings[0]);
        $this->assertStringContainsString('跨租户', $resolution->warnings[0], '必须点明可能是跨租户引用');
        $this->assertSame(['role:other_tenant_role'], $resolution->candidateGroupRefs, '引用了但展不开，仍要留档');
    }

    #[Test]
    public function emptyRoleYieldsWarning(): void
    {
        $resolution = $this->resolve(['candidateGroups' => ['role:empty_role']]);

        $this->assertSame([], $resolution->candidateUserIds);
        $this->assertCount(1, $resolution->warnings);
        $this->assertStringContainsString('没有任何成员', $resolution->warnings[0]);
    }

    // -----------------------------------------------------------------
    // 4) candidate_groups：部门（本仓库无法展开，只识别）
    // -----------------------------------------------------------------

    #[Test]
    public function deptGroupIsRecognisedButCannotBeExpanded(): void
    {
        // 本仓库 users 表没有 dept_id，也没有 users↔depts 关联表，
        // 因此 dept: 只能识别、不能展开 —— 不编人，记告警
        $resolution = $this->resolve(['candidateGroups' => ['dept:7']]);

        $this->assertSame([], $resolution->candidateUserIds, 'dept: 不应凭空产出任何用户ID');
        $this->assertSame(['dept:7'], $resolution->candidateGroupRefs);
        $this->assertCount(1, $resolution->warnings);
        $this->assertStringContainsString('dept_id', $resolution->warnings[0], '告警要说明为什么展不开');
        $this->assertStringContainsString('财务部', $resolution->warnings[0], '告警要带上部门名，便于定位配置');
    }

    #[Test]
    public function deptGroupMissingInTenantYieldsWarning(): void
    {
        $resolution = $this->resolve(['candidateGroups' => ['dept:999']]);

        $this->assertSame([], $resolution->candidateUserIds);
        $this->assertCount(1, $resolution->warnings);
        $this->assertStringContainsString('不存在', $resolution->warnings[0]);
    }

    // -----------------------------------------------------------------
    // 5) 表达式
    // -----------------------------------------------------------------

    #[Test]
    public function evaluatesExpressionReturningSingleId(): void
    {
        $resolution = $this->resolve(['assignee' => '${applicant_id}'], ['applicant_id' => 102]);

        $this->assertSame(102, $resolution->assignee, '${...} 应按流程变量求值成用户ID');
        $this->assertSame('李四', $resolution->assigneeName);
    }

    #[Test]
    public function evaluatesExpressionReturningIdArray(): void
    {
        // 整串是一条表达式时不切分：`[101, 102]` 里的逗号属于表达式本身
        $resolution = $this->resolve(['candidateUsers' => '${[101, 102]}']);

        $this->assertSame([101, 102], $resolution->candidateUserIds, '表达式可返回 id 数组');
    }

    #[Test]
    public function failedExpressionThrowsInsteadOfSilentlyNull(): void
    {
        // 变量未定义 → 求值失败。必须抛，不能静默变成 null
        // （静默的后果是「任务看起来指派了、实际没人」，只能靠翻日志发现）
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/求值失败/u');

        $this->resolve(['assignee' => '${not_defined_variable}']);
    }

    #[Test]
    public function expressionReturningNullThrows(): void
    {
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/null/u');

        $this->resolve(['assignee' => '${null_value}'], ['null_value' => null]);
    }

    #[Test]
    public function expressionReturningNonNumericThrows(): void
    {
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/非法用户ID/u');

        $this->resolve(['assignee' => '${bad_value}'], ['bad_value' => 'zhangsan']);
    }

    // -----------------------------------------------------------------
    // 6) 跨租户拒绝
    // -----------------------------------------------------------------

    #[Test]
    public function dropsAssigneeThatBelongsToAnotherTenant(): void
    {
        // 用户 9001 只存在于租户 2。外键只校验「users 表里有这个 id」，拦不住跨租户写入，
        // 所以租户内存在性校验必须在这里做，并留下可检索的告警
        $resolution = $this->resolve(['assignee' => 'user:9001']);

        $this->assertNull($resolution->assignee, '跨租户用户不得成为处理人');
        $this->assertSame([], $resolution->candidateUserIds, '跨租户用户不得进入候选人');
        $this->assertCount(1, $resolution->warnings);
        $this->assertStringContainsString('9001', $resolution->warnings[0]);
        $this->assertStringContainsString('跨租户', $resolution->warnings[0]);
    }

    #[Test]
    public function dropsCrossTenantCandidateUserButKeepsTenantOnes(): void
    {
        $resolution = $this->resolve(['candidateUsers' => '101, 9001']);

        $this->assertSame([101], $resolution->candidateUserIds, '只保留本租户的用户');
        $this->assertCount(1, $resolution->warnings);
    }

    // -----------------------------------------------------------------
    // 7) 语法错误（改数据修不好的配置，必须抛）
    // -----------------------------------------------------------------

    #[Test]
    public function unknownPrefixInCandidateUsersThrows(): void
    {
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/前缀/u');

        $this->resolve(['candidateUsers' => 'post:3']);
    }

    #[Test]
    public function nonNumericUserTokenThrows(): void
    {
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/不是合法的用户ID/u');

        $this->resolve(['candidateUsers' => 'zhangsan']);
    }

    #[Test]
    public function expressionInCandidateGroupsThrows(): void
    {
        // 组的取值只能是 role:/dept: 引用；表达式请配到 candidate_users
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/不支持表达式/u');

        $this->resolve(['candidateGroups' => '${group_var}']);
    }

    #[Test]
    public function unknownPrefixInCandidateGroupsThrows(): void
    {
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/不被支持/u');

        $this->resolve(['candidateGroups' => ['post:9']]);
    }

    // -----------------------------------------------------------------
    // 8) 租户上下文（fail-closed）
    // -----------------------------------------------------------------

    #[Test]
    public function missingTenantContextThrows(): void
    {
        TenantContext::clearAll();
        // 引擎配置里的 tenant_id 也是租户来源（优先级 2），必须一并清空才是真的「没有上下文」
        ServiceContext::extensions([]);

        $this->expectException(TenantIsolationException::class);

        $this->resolve(['assignee' => '101']);
    }

    // -----------------------------------------------------------------
    // 9) 会签：一人一条
    // -----------------------------------------------------------------

    #[Test]
    public function forActorSplitsCountersignTasksPerPerson(): void
    {
        $resolution = $this->resolve(['candidateGroups' => ['role:admin']]);

        $first = $resolution->forActor(101);
        $second = $resolution->forActor(102);

        $this->assertSame(101, $first->assignee, '会签任务的处理人是该参与者本人');
        $this->assertSame([101], $first->candidateUserIds, '会签任务的候选人也只有他自己（不允许代签）');
        $this->assertSame('张三', $first->assigneeName);
        $this->assertSame([102], $second->candidateUserIds);
        $this->assertSame(['role:admin'], $first->candidateGroupRefs, '组引用原样带过去，保持审计一致');
    }

    // -----------------------------------------------------------------
    // 10) 无参与者配置
    // -----------------------------------------------------------------

    #[Test]
    public function noActorConfigYieldsEmptyResolutionWithoutThrowing(): void
    {
        $resolution = $this->resolve([]);

        $this->assertTrue($resolution->hasNoActor(), '什么都没配时应判定为「无人可办」');
        $this->assertSame([], $resolution->warnings, '没配置不是错误，不该编告警');
    }

    #[Test]
    public function preferredAssigneeWinsOverNodeAssignee(): void
    {
        // 驳回场景：处理人沿用被驳回任务的处理人，但节点自己的候选人仍然展开
        $resolution = $this->resolve(
            ['assignee' => 'user:101', 'candidateGroups' => ['role:admin']],
            [],
            '102'
        );

        $this->assertSame(102, $resolution->assignee, '强制处理人优先');
        $this->assertContains(101, $resolution->candidateUserIds, '节点配置的参与者仍在候选集里');
        $this->assertContains(102, $resolution->candidateUserIds, '强制处理人也要进候选集');
    }

    // -----------------------------------------------------------------
    // 辅助方法
    // -----------------------------------------------------------------

    /**
     * 构造一个任务节点模型并解析
     *
     * @param array $config    `assignee` / `candidateUsers` / `candidateGroups` 键
     * @param array $variables 表达式上下文
     * @param string|int|null $preferred 强制处理人
     */
    private function resolve(array $config, array $variables = [], string|int|null $preferred = null)
    {
        $taskModel = new TaskModel();
        $taskModel->setName('review_manager');
        $taskModel->setDisplayName('部门经理审批');

        if (array_key_exists('assignee', $config)) {
            $taskModel->setAssignee((string) $config['assignee']);
        }

        if (array_key_exists('candidateUsers', $config)) {
            $taskModel->setCandidateUsers($config['candidateUsers']);
        }

        if (array_key_exists('candidateGroups', $config)) {
            $taskModel->setCandidateGroups($config['candidateGroups']);
        }

        return $this->resolver->resolve($taskModel, $variables, '测试节点 review_manager', $preferred);
    }

    /**
     * 本租户的目录夹具
     *
     * 租户 1：角色 admin（成员 101/102）、empty_role（无成员）；用户 101 张三、102 李四、103 王五；部门 7 财务部
     * 租户 2：角色 other_tenant_role（成员 9001）；用户 9001 赵六
     */
    private function directory(): InMemoryTaskActorDirectory
    {
        return new InMemoryTaskActorDirectory(
            roles: [
                self::TENANT . ':admin'            => ['id' => 11, 'slug' => 'admin', 'name' => '管理员'],
                self::TENANT . ':empty_role'       => ['id' => 12, 'slug' => 'empty_role', 'name' => '空角色'],
                self::OTHER_TENANT . ':other_tenant_role' => ['id' => 21, 'slug' => 'other_tenant_role', 'name' => '别租户角色'],
            ],
            roleMembers: [
                self::TENANT . ':11'       => [101, 102],
                self::TENANT . ':12'       => [],
                self::OTHER_TENANT . ':21' => [9001],
            ],
            userNames: [
                self::TENANT . ':101'              => '张三',
                self::TENANT . ':102'              => '李四',
                self::TENANT . ':103'              => '王五',
                self::OTHER_TENANT . ':9001'       => '赵六（别租户）',
            ],
            depts: [
                self::TENANT . ':7' => ['id' => 7, 'name' => '财务部'],
            ],
        );
    }
}
