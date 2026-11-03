<?php

declare(strict_types=1);

namespace Tests\Integration\Permission;

use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 迁移 `20251227000001_cleanup_colon_format_lowcode_permissions` 的行为测试
 * Behaviour tests for the colon-format permission cleanup migration
 *
 * ## 为什么要在测试里「真的跑一遍 up()」
 *
 * 这条迁移的动作是**删除数据**（带转授权），一旦写错方向就是「静默丢授权」——
 * 光靠 `migrate:run` 的退出码和一段日志看不出「角色是否还持有等价权限」。
 * 因此这里用测试替身把 `$this->query()` / `$this->execute()` 接到 think-orm 的测试库连接上，
 * 直接执行**迁移类里的真实代码**（SQL 未改写），断言三件事：
 *
 * 1. **先转授权、再删除**：原本只持有冒号版的角色，在冒号行被删掉后**仍然**持有等价的点号版权限
 *    —— 这是 `ON DELETE CASCADE` 下唯一正确的顺序；
 * 2. **幂等**：up() 连跑两次不报错、不重复授权（第二次是纯 no-op）；
 * 3. **干净库上是 no-op**：冒号行不存在时（测试库/全新库的真实状态）不写任何数据。
 *
 * ## 为什么不用真 Phinx Adapter
 *
 * 构造 `Phinx\Db\Adapter\MysqlAdapter` 需要复制一份连接参数，容易与 `phpunit.xml` 的
 * 测试库隔离逻辑（`ThinkPHPTestCase` 的 fail-closed 校验）分叉。这里改为覆写
 * `query()` / `execute()` 两个公开方法，走**同一个** `Db` 连接，隔离规则由基类统一保证。
 *
 * @package Tests\Integration\Permission
 */
class CleanupColonFormatPermissionsMigrationTest extends ThinkPHPTestCase
{
    /**
     * 迁移文件（相对仓库根）
     */
    private const MIGRATION_FILE = 'database/migrations/20251227000001_cleanup_colon_format_lowcode_permissions.php';

    /**
     * 迁移版本号
     */
    private const MIGRATION_VERSION = 20251227000001;

    /**
     * 测试用角色 id（>= 8000 是本仓库测试数据的约定区间）
     */
    private const FIXTURE_ROLE_ID = 8071;

    /**
     * 冒号格式 → 点号格式（本迁移负责处理的 3 条）
     *
     * @var array<string, string>
     */
    private const SLUG_PAIRS = [
        'lowcode:read'   => 'lowcode.read',
        'lowcode:write'  => 'lowcode.write',
        'lowcode:delete' => 'lowcode.delete',
    ];

    /**
     * 本用例创建过的权限 id（tearDown 里清理，避免污染测试库）
     *
     * @var int[]
     */
    private array $createdPermissionIds = [];

    /**
     * 最近一次迁移执行的输出缓冲（便于断言「跳过了几条」）
     */
    private ?\think\console\Output $lastMigrationOutput = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdPermissionIds = [];
        $this->cleanupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    /**
     * 场景 1：冒号行存在 → 先转授权，再删行；角色仍然持有等价权限
     */
    public function testMigrationTransfersGrantsBeforeDeletingColonRows(): void
    {
        // 造一个只能靠「转授权」保住权限的角色：它**只**持有冒号版权限
        $this->createFixtureRole();

        $expectedCodes = [];
        foreach (self::SLUG_PAIRS as $colonSlug => $canonicalSlug) {
            $canonicalId = $this->ensurePermission($canonicalSlug);
            $colonId     = $this->insertPermission($colonSlug);

            // 只授予冒号版（点号版不授予）—— 若迁移「先删后转」或「只删不转」，角色就会失去它
            Db::table('role_permissions')->insert([
                'role_id'       => self::FIXTURE_ROLE_ID,
                'permission_id' => $colonId,
            ]);

            $expectedCodes[] = ['colon' => $colonId, 'canonical' => $canonicalId];
        }

        $this->runMigrationUp();

        foreach ($expectedCodes as $pair) {
            $this->assertSame(
                0,
                Db::table('permissions')->where('id', $pair['colon'])->count(),
                '冒号版权限行必须被删除'
            );
            $this->assertSame(
                0,
                Db::table('role_permissions')
                    ->where('role_id', self::FIXTURE_ROLE_ID)
                    ->where('permission_id', $pair['colon'])
                    ->count(),
                '冒号版授权必须随权限行一起被级联删除（否则就是重复授权残留）'
            );
            $this->assertSame(
                1,
                Db::table('role_permissions')
                    ->where('role_id', self::FIXTURE_ROLE_ID)
                    ->where('permission_id', $pair['canonical'])
                    ->count(),
                '角色必须持有等价的点号版权限：先转授权、再删除，顺序不能反'
            );
            $this->assertSame(
                1,
                Db::table('permissions')->where('id', $pair['canonical'])->count(),
                '规范（点号）版权限行不得被删除'
            );
        }
    }

    /**
     * 场景 2：up() 连跑两次 —— 第二次是纯 no-op（幂等）
     */
    public function testMigrationIsIdempotent(): void
    {
        $this->createFixtureRole();

        foreach (self::SLUG_PAIRS as $colonSlug => $canonicalSlug) {
            $this->ensurePermission($canonicalSlug);
            $colonId = $this->insertPermission($colonSlug);

            Db::table('role_permissions')->insert([
                'role_id'       => self::FIXTURE_ROLE_ID,
                'permission_id' => $colonId,
            ]);
        }

        // 第 1 次：清理
        $this->runMigrationUp();

        $permissionCount     = $this->countTable('permissions');
        $rolePermissionCount = $this->countTable('role_permissions');

        // 第 2 次：冒号行已不存在 → 应打印「跳过」并零写入
        $output = $this->runMigrationUp();

        $this->assertSame($permissionCount, $this->countTable('permissions'), '重复执行不得再改动 permissions');
        $this->assertSame(
            $rolePermissionCount,
            $this->countTable('role_permissions'),
            '重复执行不得再改动 role_permissions'
        );
        $this->assertStringContainsString('跳过 3 条', $output, '第二次执行应走「全部跳过」的 no-op 路径');
        $this->assertStringContainsString('转授权 0 条', $output, '第二次执行不应再产生任何转授权');
        $this->assertStringContainsString('删除权限行 0 条', $output, '第二次执行不应再删除任何行');
    }

    /**
     * 场景 3：干净库（没有冒号行）→ 完全 no-op，不报错
     *
     * 这正是测试库与全新库的真实状态（冒号行从未被任何迁移创建过）。
     */
    public function testMigrationIsNoOpOnCleanDatabase(): void
    {
        $this->assertSame(
            0,
            Db::table('permissions')->where('slug', 'like', '%:%')->count(),
            '前置条件：测试库里不该有冒号格式的权限（若这里失败，说明环境本身已被污染）'
        );

        $permissionCount     = $this->countTable('permissions');
        $rolePermissionCount = $this->countTable('role_permissions');

        $output = $this->runMigrationUp();

        $this->assertSame($permissionCount, $this->countTable('permissions'), '干净库上不得插入或删除任何权限行');
        $this->assertSame($rolePermissionCount, $this->countTable('role_permissions'), '干净库上不得改动任何授权');
        $this->assertStringContainsString('删除权限行 0 条', $output, '干净库上应是零写入的 no-op');
    }

    /**
     * 场景 4：down() 有意为空操作 —— 不得把冒号行插回来
     */
    public function testDownIsIntentionalNoOp(): void
    {
        $permissionCount = $this->countTable('permissions');

        $migration = $this->makeMigration();
        $migration->down();

        $this->assertSame($permissionCount, $this->countTable('permissions'), 'down() 不得恢复已清理的冒号版权限');
        $this->assertStringContainsString(
            '有意空操作',
            $this->fetchMigrationOutput(),
            'down() 应显式说明「有意空操作」，避免后来者误以为漏写'
        );
    }

    /**
     * 构造迁移实例：把 query()/execute() 接到 think-orm 的测试库连接
     *
     * 输出用 `think\console\Output('buffer')` 收集（迁移基类的 `$output` 类型就是它），
     * 这样 `writeln()` 的内容既能被断言，也不会漏到 PHPUnit 的 stdout
     * （phpunit.xml 开了 beStrictAboutOutputDuringTests）。
     *
     * @return object 迁移实例
     */
    private function makeMigration(): object
    {
        require_once root_path() . self::MIGRATION_FILE;

        $migration = new class ('testing', self::MIGRATION_VERSION) extends \CleanupColonFormatLowcodePermissions {
            /**
             * 覆写：走 think-orm 连接（返回 PDOStatement，与迁移里的用法一致）
             *
             * @param string $sql SQL
             * @param array $params 绑定参数
             * @return \PDOStatement
             */
            public function query(string $sql, array $params = [])
            {
                $statement = Db::connect()->getPdo()->prepare($sql);
                $statement->execute($params);

                return $statement;
            }

            /**
             * 覆写：走 think-orm 连接，返回受影响行数
             *
             * @param string $sql SQL
             * @param array $params 绑定参数
             * @return int
             */
            public function execute(string $sql, array $params = []): int
            {
                return (int) Db::execute($sql, $params);
            }
        };

        $output = new \think\console\Output('buffer');
        $migration->setOutput($output);
        $this->lastMigrationOutput = $output;

        return $migration;
    }

    /**
     * 取最近一次迁移执行的输出
     */
    private function fetchMigrationOutput(): string
    {
        if ($this->lastMigrationOutput === null) {
            return '';
        }

        return (string) $this->lastMigrationOutput->fetch();
    }

    /**
     * 执行一次 up()，返回本次输出
     */
    private function runMigrationUp(): string
    {
        $migration = $this->makeMigration();
        $migration->up();

        return $this->fetchMigrationOutput();
    }

    /**
     * 建测试角色（已存在则复用）
     */
    private function createFixtureRole(): void
    {
        if (Db::table('roles')->where('id', self::FIXTURE_ROLE_ID)->count() > 0) {
            return;
        }

        Db::table('roles')->insert([
            'id'        => self::FIXTURE_ROLE_ID,
            'tenant_id' => 1,
            'name'      => 'R6 Cleanup Colon Permission Role',
            'slug'      => 'r6_cleanup_colon_role',
        ]);
    }

    /**
     * 确保点号（规范）权限存在；不存在则创建并登记待清理
     *
     * @param string $slug 规范 slug（resource.action）
     */
    private function ensurePermission(string $slug): int
    {
        $existing = Db::table('permissions')->where('slug', $slug)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->insertPermission($slug);
    }

    /**
     * 插入一条权限（slug 由测试独占，tearDown 负责删除）
     *
     * @param string $slug 权限 slug
     */
    private function insertPermission(string $slug): int
    {
        [$resource, $action] = array_pad(explode(':', str_replace('.', ':', $slug)), 2, '');

        Db::table('permissions')->insert([
            'name'     => 'R6 Cleanup Fixture ' . $slug,
            'slug'     => $slug,
            'resource' => $resource,
            'action'   => $action,
        ]);

        $id = (int) Db::table('permissions')->where('slug', $slug)->value('id');
        $this->createdPermissionIds[] = $id;

        return $id;
    }

    /**
     * 清理本用例创建的数据
     *
     * 只删「本用例造的东西」与那 3 个已知冒号 slug 的残留：
     * 用 `like '%:%'` 删全表会把别的用例/别人留下的数据一起带走，属于测试污染源。
     */
    private function cleanupFixtures(): void
    {
        // 上次运行若中途失败，冒号行可能残留（只针对本迁移负责的 3 个 slug）
        Db::table('permissions')->whereIn('slug', array_keys(self::SLUG_PAIRS))->delete();

        // 本用例创建的点号版权限（只删自己造的，避免误删真实的 lowcode.read 等）
        if ($this->createdPermissionIds !== []) {
            Db::table('role_permissions')->whereIn('permission_id', $this->createdPermissionIds)->delete();
            Db::table('permissions')->whereIn('id', $this->createdPermissionIds)->delete();
            $this->createdPermissionIds = [];
        }

        Db::table('role_permissions')->where('role_id', self::FIXTURE_ROLE_ID)->delete();
        Db::table('roles')->where('id', self::FIXTURE_ROLE_ID)->delete();
    }

    /**
     * 统计表行数
     */
    private function countTable(string $table): int
    {
        return (int) Db::table($table)->count();
    }
}
