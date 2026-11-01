<?php

declare(strict_types=1);

namespace Tests\Integration\Model;

use app\exception\TenantContextMissingException;
use app\model\BaseModel;
use think\facade\Db;
use Tests\ThinkPHPTestCase;

/**
 * BaseModel 租户作用域「真实 SQL」集成测试 | Tenant scope against real SQL
 *
 * 与 `BaseModelTenantScopeGuardTest`（纯判据 / 降级逻辑）互补：本文件用**真实数据表**
 * 钉住作用域产生的 SQL 与结果集，覆盖验收要求的四种情况：
 *
 *   1. 启用 + 有 `tenant_id` 列 + **无上下文** → 抛 `TenantContextMissingException`（fail-closed）；
 *   2. 启用 + 有上下文 → SQL 带 `tenant_id = N`，结果集只含该租户；
 *   3. 启用 + **无 `tenant_id` 列**（全局表）→ 不加过滤、**不抛异常**；
 *   4. 禁用作用域 → 不加任何过滤、不抛异常（CLI 默认 / `DISABLE_TENANT_SCOPES=true` 的等价路径）。
 *
 * 为什么两类探针模型都**只**挂 `['tenant']` 作用域（不带 `site`）：
 * 本文件的目标是验证 `scopeTenant()` 的 fail-closed 语义。若同时挂 `site`，
 * 一旦将来 `scopeSite()` 也改成 fail-closed，失败原因会混在一起，排错成本上升。
 */
final class BaseModelTenantScopeSqlTest extends ThinkPHPTestCase
{
    private const TENANT_TABLE = 'bm_tenant_scope_probe';

    private const GLOBAL_TABLE = 'bm_global_scope_probe';

    protected function setUp(): void
    {
        parent::setUp();

        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();
        TenantScopedProbeModel::$tenantContext = null;
        GlobalTableProbeModel::$tenantContext = null;

        // 表必须先建好：think\Model 的构造函数就会解析表结构，表不存在时连 new 都过不去
        $this->createTables();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        Db::execute('DROP TABLE IF EXISTS ' . self::TENANT_TABLE);
        Db::execute('DROP TABLE IF EXISTS ' . self::GLOBAL_TABLE);

        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();
        TenantScopedProbeModel::$tenantContext = null;
        GlobalTableProbeModel::$tenantContext = null;

        parent::tearDown();
    }

    private function createTables(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TENANT_TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL DEFAULT 0,
                value VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // 刻意**没有** tenant_id 列：模拟 permissions / tenants / role_permissions / user_roles
        // 这类全局表。作用域启用时它们必须照常查询，不能被 fail-closed 误伤。
        Db::execute(
            'CREATE TABLE IF NOT EXISTS ' . self::GLOBAL_TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function seedData(): void
    {
        Db::table(self::TENANT_TABLE)->insertAll([
            ['tenant_id' => 1, 'value' => 't1-a'],
            ['tenant_id' => 1, 'value' => 't1-b'],
            ['tenant_id' => 2, 'value' => 't2-a'],
            ['tenant_id' => 2, 'value' => 't2-b'],
            ['tenant_id' => 3, 'value' => 't3-a'],
        ]);

        Db::table(self::GLOBAL_TABLE)->insertAll([
            ['name' => 'permissions'],
            ['name' => 'tenants'],
            ['name' => 'role_permissions'],
        ]);
    }

    /**
     * 用例 1（fail-closed 关键证据）：启用 + 有 tenant_id 列 + 无租户上下文 → 抛异常
     */
    public function testFailClosedWhenTenantContextMissing(): void
    {
        BaseModel::setScopesEnabled(true);
        TenantScopedProbeModel::$tenantContext = null;

        try {
            TenantScopedProbeModel::select();
            $this->fail('上下文缺失时**必须**抛异常；没有抛说明作用域退回了 fail-open（会读出全表）');
        } catch (TenantContextMissingException $e) {
            // 对外映射：HTTP 400 + 业务码 1001（PARAMETER_MISSING），复用 ExceptionHandle 既有分支
            $this->assertSame(TenantContextMissingException::REASON_REQUEST, $e->getReason());
            $this->assertSame(1001, $e->getCode());

            $message = $e->getMessage();
            $this->assertStringContainsString('缺少租户上下文', $message);
            $this->assertStringContainsString(TenantScopedProbeModel::class, $message, '异常必须指明是哪个模型');
            $this->assertStringContainsString(self::TENANT_TABLE, $message, '异常必须指明是哪张表');
            $this->assertStringContainsString('fail-closed', $message, '异常必须说明为什么拒绝查询');
        }
    }

    /**
     * 用例 2：上下文是非正数（0 / 负数）时同样 fail-closed
     *
     * `tenant_id <= 0` 不是「有效租户」，原实现会在这条分支上静默读全表。
     */
    public function testFailClosedWhenTenantContextIsNonPositive(): void
    {
        BaseModel::setScopesEnabled(true);

        foreach ([0, -1] as $invalidTenantId) {
            TenantScopedProbeModel::$tenantContext = $invalidTenantId;

            try {
                TenantScopedProbeModel::select();
                $this->fail('租户ID=' . $invalidTenantId . ' 不是有效租户，必须抛异常');
            } catch (TenantContextMissingException $e) {
                $this->assertSame(1001, $e->getCode());
            }
        }
    }

    /**
     * 用例 3（正常路径）：有上下文 → SQL 带 `tenant_id = N`，结果集只含该租户
     */
    public function testAppliesTenantFilterWhenContextPresent(): void
    {
        BaseModel::setScopesEnabled(true);
        TenantScopedProbeModel::$tenantContext = 2;

        // 3-1：断言**实际 SQL**
        $sql = TenantScopedProbeModel::fetchSql(true)->select();
        $this->assertIsString($sql, 'fetchSql 应返回 SQL 字符串');
        $this->assertMatchesRegularExpression(
            '/`?tenant_id`?\s*=\s*2/',
            $sql,
            '启用作用域且上下文有效时，SQL 必须带 tenant_id = 2；实际 SQL：' . $sql
        );

        // 3-2：断言结果集
        $rows = TenantScopedProbeModel::select()->toArray();
        $this->assertCount(2, $rows, '租户 2 只有 2 行，拿到更多说明过滤没生效');
        foreach ($rows as $row) {
            $this->assertSame(2, (int) $row['tenant_id']);
        }

        // 3-3：基准确认（不走作用域时全表是 5 行，排除「表里本来就只有 2 行」这种假绿）
        $this->assertSame(5, (int) Db::table(self::TENANT_TABLE)->count());
    }

    /**
     * 用例 4（全局表不误伤）：无 `tenant_id` 列 + 无上下文 → 不加过滤、**不抛异常**
     *
     * 这是「fail-closed 不能把全局表打挂」的正向证据：
     * permissions / tenants / role_permissions / user_roles 都靠这条路径继续工作。
     */
    public function testGlobalTableIsNeitherFilteredNorThrows(): void
    {
        BaseModel::setScopesEnabled(true);
        GlobalTableProbeModel::$tenantContext = null;

        // 4-1：不抛异常（若抛了，本用例会直接报错而不是走到断言）
        $sql = GlobalTableProbeModel::fetchSql(true)->select();
        $this->assertIsString($sql);
        $this->assertStringNotContainsString('tenant_id', (string) $sql, '全局表不能出现租户过滤');

        // 4-2：结果集完整
        $rows = GlobalTableProbeModel::select()->toArray();
        $this->assertCount(3, $rows, '全局表应返回全部 3 行');
        $this->assertSame(
            ['permissions', 'tenants', 'role_permissions'],
            array_column($rows, 'name')
        );
    }

    /**
     * 用例 5：作用域禁用 → 不加过滤、不抛异常（CLI 默认路径的等价物）
     *
     * 即使模型有 `tenant_id` 列、上下文也缺失，禁用状态下必须原样放行 ——
     * 这是迁移 / seeder / 运维脚本继续工作的前提。
     */
    public function testNoFilterWhenScopesDisabled(): void
    {
        BaseModel::setScopesEnabled(false);
        TenantScopedProbeModel::$tenantContext = null;

        $sql = TenantScopedProbeModel::fetchSql(true)->select();
        $this->assertIsString($sql);
        $this->assertStringNotContainsString('tenant_id', (string) $sql, '禁用作用域时不得附加租户过滤');

        $this->assertCount(5, TenantScopedProbeModel::select()->toArray());
    }

    /**
     * 用例 6：CLI 默认（不显式设置开关）同样是「不加过滤、不抛异常」
     *
     * PHPUnit 跑在 CLI，`areScopesEnabled()` 走 `runningInConsole() === true` → false。
     * 与用例 5 的区别：这里**不调用** `setScopesEnabled()`，走的是真实判据。
     */
    public function testNoFilterUnderConsoleDefaultJudgement(): void
    {
        TenantScopedProbeModel::$tenantContext = null;

        $this->assertFalse(BaseModel::areScopesEnabled(), 'PHPUnit 进程应被判定为控制台环境');
        $this->assertCount(5, TenantScopedProbeModel::select()->toArray());
    }
}

/**
 * 租户级探针模型（表有 tenant_id 列）
 *
 * 租户上下文用静态属性**显式钉死**，不依赖容器里 Request 的残留状态：
 * 这样「无上下文」与「有上下文」都是确定的，测试不会随执行顺序抖动。
 */
final class TenantScopedProbeModel extends BaseModel
{
    protected $name = 'bm_tenant_scope_probe';

    protected $table = 'bm_tenant_scope_probe';

    protected $globalScope = ['tenant'];

    public static ?int $tenantContext = null;

    public static function getTenantContext(): ?int
    {
        return static::$tenantContext;
    }
}

/**
 * 全局表探针模型（表**没有** tenant_id 列，却仍声明了 tenant 作用域）
 *
 * 这正是「作用域必须自行判断列是否存在」的场景：模型写错了 `$globalScope`，
 * 不应该导致整表查询被 fail-closed 打挂。
 */
final class GlobalTableProbeModel extends BaseModel
{
    protected $name = 'bm_global_scope_probe';

    protected $table = 'bm_global_scope_probe';

    protected $globalScope = ['tenant'];

    public static ?int $tenantContext = null;

    public static function getTenantContext(): ?int
    {
        return static::$tenantContext;
    }
}
