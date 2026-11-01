<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use Mockery;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * FormDataController 多租户隔离 HTTP 级集成测试
 *
 * 通过 HTTP 路由调用验证 FormDataController + FormDataManager 在同表多租户场景下的隔离性。
 */
class FormDataControllerMultiTenantTest extends ThinkPHPTestCase
{
    private string $tableName = 'lc_mt_http';
    private ?int $tenant2RowId = null;
    private ?int $tenant1Site2RowId = null;

    /**
     * 「同租户第二个站点」夹具的站点 ID
     *
     * `testSiteIsolationWithinSameTenant()` 用 `X-Site-ID: 2` 验证站点维度隔离，
     * 而 `SiteIdentify` 自「站点归属校验」落地后会校验该站点**真实存在、属于租户 1 且 active** ——
     * 测试库默认只有站点 1（`slug = default`），因此这里显式造一个属于租户 1 的站点 2。
     * 断言保持不变（仍然断言「站点 1 看不到站点 2 的数据」「站点 2 能读自己的数据」），
     * 变的只是「站点 2 在 sites 表里存在」这个前提。
     */
    private const SECOND_SITE_ID = 2;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId()/siteId() 行为一致
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载低代码路由
        $routeFile = $this->app()->getRootPath() . 'route/lowcode.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        $this->bindLowcodeMocks();
        $this->bypassAuthAndPermission();
        $this->createTestTable();
        $this->seedTestData();
        $this->seedSiteFixture();
    }

    protected function tearDown(): void
    {
        Db::execute(sprintf('DROP TABLE IF EXISTS `%s`', $this->tableName));
        Db::name('sites')->where('id', self::SECOND_SITE_ID)->delete();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 造出「同租户第二个站点」（租户 1 的站点 2，active）
     *
     * 先删后建：上一次异常中断可能留下 id = 2 的残留行（或属于别的租户），
     * 不先删会让「属于租户 1」这个前提不成立。
     */
    protected function seedSiteFixture(): void
    {
        Db::name('sites')->where('id', self::SECOND_SITE_ID)->delete();

        Db::name('sites')->insert([
            'id'        => self::SECOND_SITE_ID,
            'tenant_id' => 1,
            'name'      => 'FormDataMultiTenant Fixture Site 2',
            'slug'      => 'form-data-mt-fixture-2',
            'domain'    => null,
            'status'    => 'active',
            'theme'     => 'default',
        ]);
    }

    /**
     * 列表接口仅返回当前租户的数据
     */
    public function testListIsTenantScoped(): void
    {
        $response = $this->httpRequest(
            'GET',
            sprintf('v1/lowcode/forms/%s/data', $this->tableName),
            [],
            [
                'X-Tenant-ID' => '1',
                'X-Site-ID' => '1',
            ]
        );

        $this->assertSame(200, $response->getCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(0, $body['code']);
        $this->assertSame(2, $body['data']['total']);
        $this->assertCount(2, $body['data']['list']);
        foreach ($body['data']['list'] as $row) {
            $this->assertSame(1, (int) $row['tenant_id']);
            $this->assertSame(1, (int) $row['site_id']);
        }
    }

    /**
     * 跨租户读取应返回 404
     */
    public function testCrossTenantReadReturnsNotFound(): void
    {
        $this->assertNotNull($this->tenant2RowId);

        $response = $this->httpRequest(
            'GET',
            sprintf('v1/lowcode/forms/%s/data/%d', $this->tableName, $this->tenant2RowId),
            [],
            [
                'X-Tenant-ID' => '1',
                'X-Site-ID' => '1',
            ]
        );

        $this->assertSame(404, $response->getCode());
    }

    /**
     * 同租户不同站点的数据应按 site_id 隔离
     */
    public function testSiteIsolationWithinSameTenant(): void
    {
        $this->assertNotNull($this->tenant1Site2RowId);

        // 站点1列表不应看到站点2的数据
        $respSite1 = $this->httpRequest(
            'GET',
            sprintf('v1/lowcode/forms/%s/data', $this->tableName),
            [],
            [
                'X-Tenant-ID' => '1',
                'X-Site-ID' => '1',
            ]
        );
        $body1 = json_decode($respSite1->getContent(), true);
        $this->assertSame(0, $body1['code']);
        $idsSite1 = array_column($body1['data']['list'], 'id');
        $this->assertNotContains($this->tenant1Site2RowId, $idsSite1);

        // 站点2读取自身数据应成功
        $respSite2 = $this->httpRequest(
            'GET',
            sprintf('v1/lowcode/forms/%s/data/%d', $this->tableName, $this->tenant1Site2RowId),
            [],
            [
                'X-Tenant-ID' => '1',
                'X-Site-ID' => '2',
            ]
        );
        $this->assertSame(200, $respSite2->getCode());
    }

    /**
     * 绑定 FormSchemaManager / FormValidatorManager / CollectionManager 的最小实现，便于 HTTP 路由直接调用
     */
    protected function bindLowcodeMocks(): void
    {
        $schemaManager = Mockery::mock(FormSchemaManager::class);
        $schemaManager->shouldReceive('get')
            ->andReturn([
                'schema' => [],
                'collection_name' => $this->tableName,
            ]);

        $validator = Mockery::mock(FormValidatorManager::class);
        $validator->shouldReceive('validate')->withAnyArgs()->andReturn(true);

        $collection = Mockery::mock(\Domain\Lowcode\Collection\Interfaces\CollectionInterface::class);
        $collection->shouldReceive('getTableName')->andReturn($this->tableName);

        // 允许过滤/写入的字段名
        $fieldName = Mockery::mock();
        $fieldName->shouldReceive('getName')->andReturn('name');
        $fieldValue = Mockery::mock();
        $fieldValue->shouldReceive('getName')->andReturn('value');
        $collection->shouldReceive('getFields')->andReturn([$fieldName, $fieldValue]);

        $collectionManager = Mockery::mock(CollectionManager::class);
        $collectionManager->shouldReceive('get')->andReturn($collection);

        // 注入到容器，FormDataController/FormDataManager 会从容器解析
        $this->app()->bind(FormSchemaManager::class, $schemaManager);
        $this->app()->bind(FormValidatorManager::class, $validator);
        $this->app()->bind(CollectionManager::class, $collectionManager);
    }

    /**
     * 覆盖 Auth/Permission 中间件为 no-op，专注验证多租户隔离
     */
    protected function bypassAuthAndPermission(): void
    {
        $noop = new class () {
            public function handle($request, \Closure $next)
            {
                return $next($request);
            }
        };

        $this->app()->bind(\app\middleware\Auth::class, fn () => $noop);
        $this->app()->bind(\app\middleware\Permission::class, fn () => $noop);
    }

    /**
     * 创建测试表（包含租户/站点字段）
     */
    protected function createTestTable(): void
    {
        Db::execute(sprintf('DROP TABLE IF EXISTS `%s`', $this->tableName));
        Db::execute(sprintf(
            <<<SQL
CREATE TABLE `%s` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `site_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `name` VARCHAR(255) NOT NULL,
    `value` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tenant_id_id` (`tenant_id`, `id`),
    KEY `idx_tenant_site` (`tenant_id`, `site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
            $this->tableName
        ));
    }

    /**
     * 填充多租户测试数据
     */
    protected function seedTestData(): void
    {
        Db::table($this->tableName)->insertAll([
            ['tenant_id' => 1, 'site_id' => 1, 'name' => 't1_item1', 'value' => 'v1'],
            ['tenant_id' => 1, 'site_id' => 1, 'name' => 't1_item2', 'value' => 'v2'],
        ]);

        $this->tenant2RowId = Db::table($this->tableName)->insertGetId([
            'tenant_id' => 2,
            'site_id' => 1,
            'name' => 't2_item1',
            'value' => 'v3',
        ]);

        $this->tenant1Site2RowId = Db::table($this->tableName)->insertGetId([
            'tenant_id' => 1,
            'site_id' => 2,
            'name' => 't1s2_item1',
            'value' => 'v4',
        ]);
    }

    /**
     * 简易 HTTP 调用辅助
     */
    protected function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader($headers);

        return $this->runHttp($request);
    }
}
