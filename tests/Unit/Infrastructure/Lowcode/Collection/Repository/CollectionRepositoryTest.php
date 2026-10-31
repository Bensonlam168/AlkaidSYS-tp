<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Tests\ThinkPHPTestCase;
use Tests\Traits\DatabaseTransactions;
use think\facade\Db;

/**
 * CollectionRepository 单元测试
 *
 * ## 数据库隔离（ER-17）
 *
 * 本类每个用例都往 `lowcode_collections` 插数据，原先只有「**下一个**用例的 setUp」会删
 * `test_repo_%` —— 也就是**跑完最后一个用例后没有收尾**：实测每轮结束都会留下
 * `test_repo_del_b`（tenant_id=1002）一行，长期驻留测试库。
 *
 * 现在挂 `DatabaseTransactions`：`#[Before]` 在 `setUp()` 之前开事务、`#[After]` 在
 * `tearDown()` 之后回滚，用例内的 INSERT/UPDATE/DELETE 全部丢弃。
 *
 * 为什么**可以**用事务：本类只操作 `lowcode_collections` 的 DML，**没有 DDL**
 * （`CollectionRepository::save()` 只写元数据，不建物理表 —— 建表在 `CollectionManager` 侧，
 * 本类没有涉及）。
 *
 * 为什么**保留** `setUp()` 里的 `DELETE ... LIKE 'test_repo_%'`：它仍在事务内执行，
 * 作用是把「历史遗留的同名前缀行」在本用例**可见范围内**清掉（对断言依然有效）；
 * 回滚后这些历史行原样回到库里。本类的目标是**不再新增**残渣，历史残渣的一次性清理见交付报告。
 */
class CollectionRepositoryTest extends ThinkPHPTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Db::name('lowcode_collections')
            ->whereLike('name', 'test_repo_%')
            ->delete();
    }

    public function testSavePersistsTenantAndSiteIdsWithDefaults(): void
    {
        $repo = new CollectionRepository();

        $collection = $this->createMock(CollectionInterface::class);
        $collection->method('getId')->willReturn(null);
        $collection->method('getName')->willReturn('test_repo_save_default');
        $collection->method('getTableName')->willReturn('lc_test_repo_save_default');
        $collection->method('getTitle')->willReturn('Test Repo Save Default');
        $collection->method('getDescription')->willReturn('desc');
        $collection->method('getOptions')->willReturn([]);

        $id = $repo->save($collection);

        $row = Db::name('lowcode_collections')->where('id', $id)->find();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['tenant_id']);
        $this->assertSame(0, (int) $row['site_id']);
    }

    public function testFindByNameIsIsolatedByTenant(): void
    {
        $repo = new CollectionRepository();

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_tenant_1',
            'table_name' => 'lc_mt_1',
            'schema' => json_encode(['title' => 'T1']),
            'tenant_id' => 1,
            'site_id' => 0,
        ]);

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_tenant_2',
            'table_name' => 'lc_mt_2',
            'schema' => json_encode(['title' => 'T2']),
            'tenant_id' => 2,
            'site_id' => 0,
        ]);

        $tenant1Record = $repo->findByName('test_repo_tenant_1', 1);
        $this->assertNotNull($tenant1Record);
        $this->assertSame('lc_mt_1', $tenant1Record->getTableName());

        // 同名查询在其他租户下应返回 null，避免跨租户泄露
        $crossTenantRecord = $repo->findByName('test_repo_tenant_1', 2);
        $this->assertNull($crossTenantRecord);
    }

    public function testFindByNameScopesToSystemTenantWhenTenantIdIsZero(): void
    {
        $repo = new CollectionRepository();

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_system_tenant',
            'table_name' => 'lc_system_tenant',
            'schema' => json_encode(['title' => 'System']),
            'tenant_id' => 0,
            'site_id' => 0,
        ]);

        // 系统模板空间必须**显式**传 0：tenantId 已改为必填，传 null 会 TypeError
        $found = $repo->findByName('test_repo_system_tenant', 0);

        $this->assertNotNull($found);
        $this->assertSame('lc_system_tenant', $found->getTableName());
    }

    public function testListFiltersByTenant(): void
    {
        $repo = new CollectionRepository();

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_list_t1_a',
            'table_name' => 'lc_list_t1_a',
            'schema' => json_encode(['title' => 'L1A']),
            'tenant_id' => 1,
            'site_id' => 0,
        ]);

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_list_t1_b',
            'table_name' => 'lc_list_t1_b',
            'schema' => json_encode(['title' => 'L1B']),
            'tenant_id' => 1,
            'site_id' => 0,
        ]);

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_list_t2',
            'table_name' => 'lc_list_t2',
            'schema' => json_encode(['title' => 'L2']),
            'tenant_id' => 2,
            'site_id' => 0,
        ]);

        // list() 的租户已提到首位且必填：(tenantId, filters, page, pageSize)
        $resultTenant1 = $repo->list(1, [], 1, 50);
        $this->assertSame(1, $resultTenant1['page']);
        $this->assertSame(50, $resultTenant1['pageSize']);

        $namesTenant1 = array_map(static fn (CollectionInterface $c) => $c->getName(), $resultTenant1['list']);
        $this->assertContains('test_repo_list_t1_a', $namesTenant1);
        $this->assertContains('test_repo_list_t1_b', $namesTenant1);
        $this->assertNotContains('test_repo_list_t2', $namesTenant1);

        $resultTenant2 = $repo->list(2, [], 1, 50);
        $namesTenant2 = array_map(static fn (CollectionInterface $c) => $c->getName(), $resultTenant2['list']);
        $this->assertContains('test_repo_list_t2', $namesTenant2);
        $this->assertNotContains('test_repo_list_t1_a', $namesTenant2);
    }

    public function testListScopesToSystemTenantWhenTenantIdIsZero(): void
    {
        $repo = new CollectionRepository();

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_list_system',
            'table_name' => 'lc_list_system',
            'schema' => json_encode(['title' => 'System']),
            'tenant_id' => 0,
            'site_id' => 0,
        ]);

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_list_other_tenant',
            'table_name' => 'lc_list_other_tenant',
            'schema' => json_encode(['title' => 'OtherTenant']),
            'tenant_id' => 1,
            'site_id' => 0,
        ]);

        // 显式传 0（系统模板空间）仍然是一次「按 tenant_id = 0 过滤」，不是「不过滤」
        $result = $repo->list(0, [], 1, 50);
        $names = array_map(static fn (CollectionInterface $c) => $c->getName(), $result['list']);

        $this->assertContains('test_repo_list_system', $names);
        $this->assertNotContains('test_repo_list_other_tenant', $names);
    }

    /**
     * 缺陷 A 的契约证明：`delete()` 的租户参数必须必填且不可为 null
     *
     * 旧签名 `delete(int $id, ?int $tenantId = null)` 允许调用方省略租户，
     * 省略后仓储执行的是 `where('id', $id)->delete()` —— 按主键跨租户删除。
     * 本用例用反射盯住签名：一旦有人把 `?` 或 `= null` 加回来，立刻失败。
     */
    public function testDeleteTenantParameterIsMandatoryAndNotNullable(): void
    {
        $parameters = (new \ReflectionMethod(CollectionRepository::class, 'delete'))->getParameters();

        $this->assertCount(2, $parameters, 'delete() 必须同时接收集合ID与租户ID');
        $this->assertSame('tenantId', $parameters[1]->getName());
        $this->assertFalse(
            $parameters[1]->isOptional(),
            'delete() 的 tenantId 不能有默认值：省略即等于「不按租户过滤」'
        );
        $this->assertSame('int', (string) $parameters[1]->getType(), 'tenantId 必须是 int，不能是可空类型');
        $this->assertFalse($parameters[1]->allowsNull(), 'tenantId 不允许为 null');
    }

    /**
     * 缺陷 A 的行为证明：租户 B 删不掉租户 A 的集合
     *
     * 单库行级隔离下，`lowcode_collections` 里各租户的行共用同一张表，
     * 因此「按主键删除且不带 tenant_id」就等于跨租户删除。
     */
    public function testDeleteCannotRemoveAnotherTenantsCollection(): void
    {
        $repo = new CollectionRepository();

        $collectionIdA = (int) Db::name('lowcode_collections')->insertGetId([
            'name' => 'test_repo_del_a',
            'table_name' => 'lc_test_repo_del_a',
            'schema' => json_encode(['title' => 'A']),
            'tenant_id' => 1001,
            'site_id' => 0,
        ]);

        $collectionIdB = (int) Db::name('lowcode_collections')->insertGetId([
            'name' => 'test_repo_del_b',
            'table_name' => 'lc_test_repo_del_b',
            'schema' => json_encode(['title' => 'B']),
            'tenant_id' => 1002,
            'site_id' => 0,
        ]);

        // 1) 租户 1002（B）拿着租户 1001（A）的 ID 去删：必须一行都删不掉
        $this->assertFalse(
            $repo->delete($collectionIdA, 1002),
            '跨租户 delete 必须返回 false，而不是把别人的行删掉'
        );
        $this->assertSame(
            1,
            Db::name('lowcode_collections')->where('id', $collectionIdA)->count(),
            '租户 A 的集合在跨租户删除后必须仍然存在'
        );

        // 2) 本租户删除仍然正常工作（防止修复把 delete 改成永远不生效）
        $this->assertTrue($repo->delete($collectionIdA, 1001));
        $this->assertSame(0, Db::name('lowcode_collections')->where('id', $collectionIdA)->count());

        // 3) 租户 B 的行不受影响
        $this->assertSame(1, Db::name('lowcode_collections')->where('id', $collectionIdB)->count());
    }
}
