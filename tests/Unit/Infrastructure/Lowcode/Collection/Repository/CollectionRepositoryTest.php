<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

class CollectionRepositoryTest extends ThinkPHPTestCase
{
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

    public function testFindByNameDefaultsToSystemTenantWhenTenantIdIsNull(): void
    {
        $repo = new CollectionRepository();

        Db::name('lowcode_collections')->insert([
            'name' => 'test_repo_system_tenant',
            'table_name' => 'lc_system_tenant',
            'schema' => json_encode(['title' => 'System']),
            'tenant_id' => 0,
            'site_id' => 0,
        ]);

        $found = $repo->findByName('test_repo_system_tenant', null);

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

        $resultTenant1 = $repo->list([], 1, 50, 1);
        $this->assertSame(1, $resultTenant1['page']);
        $this->assertSame(50, $resultTenant1['pageSize']);

        $namesTenant1 = array_map(static fn (CollectionInterface $c) => $c->getName(), $resultTenant1['list']);
        $this->assertContains('test_repo_list_t1_a', $namesTenant1);
        $this->assertContains('test_repo_list_t1_b', $namesTenant1);
        $this->assertNotContains('test_repo_list_t2', $namesTenant1);

        $resultTenant2 = $repo->list([], 1, 50, 2);
        $namesTenant2 = array_map(static fn (CollectionInterface $c) => $c->getName(), $resultTenant2['list']);
        $this->assertContains('test_repo_list_t2', $namesTenant2);
        $this->assertNotContains('test_repo_list_t1_a', $namesTenant2);
    }

    public function testListDefaultsToSystemTenantWhenTenantIdIsNull(): void
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

        $result = $repo->list([], 1, 50, null);
        $names = array_map(static fn (CollectionInterface $c) => $c->getName(), $result['list']);

        $this->assertContains('test_repo_list_system', $names);
        $this->assertNotContains('test_repo_list_other_tenant', $names);
    }
}
