<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use app\model\BaseModel;
use app\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Tests\ThinkPHPTestCase;

/**
 * BaseModel 租户作用域「接线」单元测试 | BaseModel tenant scope wiring (unit)
 *
 * 本文件只覆盖**不需要真实 SQL 也能证明**的三件事，全部围绕「判据与降级」：
 *
 *   1. `areScopesEnabled()` 的判据已从 `php_sapi_name()` 换成 `App::runningInConsole()`；
 *   2. 两个环境变量逃生口（`DISABLE_TENANT_SCOPES` / `ENABLE_TENANT_SCOPES_IN_CLI`）语义不变；
 *   3. `getTenantContext()` 不再「静默」吞异常 —— 保留返回 null 的契约，但会记录降级；
 *      并且在「没有 request」的正常场景下**不**产生任何降级记录（不制造日志噪声）。
 *
 * 真实 SQL 行为（fail-closed 抛异常 / `WHERE tenant_id = N` / 全局表不误伤）见
 * `tests/Integration/Model/BaseModelTenantScopeSqlTest.php`。
 *
 * 为什么这些用例放在**新文件**而不是追加到 `BaseModelTest`：
 * `BaseModelTest` 的 9 tests / 12 assertions 是既有回归基线，追加用例会改变该文件的
 * 用例数，让「是否有回归」这个判断失去可比性。
 */
#[CoversClass(\app\model\BaseModel::class)]
final class BaseModelTenantScopeGuardTest extends ThinkPHPTestCase
{
    /**
     * 记录下来的环境变量原始值（tearDown 还原，避免污染后续测试类）
     *
     * `think\Env` 是**实例级**数据，`ThinkPHPTestCase` 的容器还原只换指针、不还原对象内部，
     * 因此必须手工还原。
     *
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();

        foreach (['DISABLE_TENANT_SCOPES', 'ENABLE_TENANT_SCOPES_IN_CLI'] as $key) {
            $this->originalEnv[$key] = $this->app()->env->get($key);
        }

        // 重置探针模型的静态状态
        ProbeContextRecordingModel::reset();
    }

    protected function tearDown(): void
    {
        // 还原被改写的环境变量（先写回原值，再让作用域开关缓存失效）
        $this->app()->env->set($this->originalEnv);
        BaseModel::resetScopesEnabled();
        BaseModel::clearColumnCache();
        ProbeContextRecordingModel::reset();

        // 必须调用：基类负责丢弃方法级的 Request 实例（本文件会把 request 换成桩对象）
        parent::tearDown();
    }

    /**
     * 用例 1：CLI 下 `areScopesEnabled()` 仍为 false（保持既有 BaseModelTest 的断言）
     *
     * 同时钉住两件事：
     *   - 判据输入确实来自 `App::runningInConsole()`（本进程为 true）；
     *   - SAPI 仍是 `cli` —— 也就是旧实现「碰巧」也是对的场景，
     *     下面 testRunningInConsoleJudgementDrivesScopes 才暴露旧实现的错。
     */
    public function testScopesDisabledInConsoleByDefault(): void
    {
        $this->assertSame('cli', php_sapi_name(), '前置条件：PHPUnit 运行在 CLI SAPI');
        $this->assertTrue(
            $this->app()->runningInConsole(),
            '前置条件：测试进程必须被框架判定为「控制台环境」'
        );

        $this->assertFalse(BaseModel::areScopesEnabled());
    }

    /**
     * 用例 2：判据是 `runningInConsole()`，**不是** `php_sapi_name()`
     *
     * 这是本次修复的核心证据。Swoole worker 的 SAPI 恒为 `cli`、`STDIN` 恒存在，
     * 因此旧的 `php_sapi_name() === 'cli' || defined('STDIN')` 在真实 HTTP 请求里
     * 必然判定为「CLI」→ 作用域永久关闭。
     *
     * 本用例把 `runningInConsole()` 覆写为 false（模拟 think-swoole 在请求沙箱内
     * 执行 `$app->setInConsole(false)`），**而 SAPI 依旧是 cli**：
     *   - 若判据还是 `php_sapi_name()` → 结果必然 false（旧实现）；
     *   - 判据改成 `runningInConsole()` → 结果必须是 true（新实现）。
     */
    public function testRunningInConsoleJudgementDrivesScopes(): void
    {
        $this->assertSame('cli', php_sapi_name(), '前置条件：SAPI 全程为 cli，模拟 Swoole worker');
        $this->assertTrue(defined('STDIN'), '前置条件：STDIN 全程存在，旧判据下必然被判为 CLI');

        // 真实判据（走容器里的 think\App）→ 控制台 → 关闭
        $this->assertFalse(BaseModel::areScopesEnabled(), '控制台环境下应关闭作用域');

        // 只换「是否控制台」这一个输入，SAPI 不动
        BaseModel::resetScopesEnabled();
        $this->assertTrue(
            ConsoleLikeRequestScopeModel::areScopesEnabled(),
            'runningInConsole()=false 时必须打开作用域 —— 这正是 Swoole 请求内的真实输入'
        );
    }

    /**
     * 用例 3：`DISABLE_TENANT_SCOPES=true` 优先于一切（逃生口语义不变）
     *
     * 即使运行环境判定为「非控制台」（本用例用覆写模拟），也必须关闭。
     */
    public function testDisableEscapeHatchWinsOverRuntime(): void
    {
        $this->app()->env->set(['DISABLE_TENANT_SCOPES' => 'true']);
        BaseModel::resetScopesEnabled();

        $this->assertFalse(
            ConsoleLikeRequestScopeModel::areScopesEnabled(),
            'DISABLE_TENANT_SCOPES=true 必须关闭作用域，不能被「非控制台」覆盖'
        );
    }

    /**
     * 用例 4：`ENABLE_TENANT_SCOPES_IN_CLI=true` 可在控制台内打开（逃生口语义不变）
     */
    public function testEnableInConsoleEscapeHatch(): void
    {
        $this->app()->env->set([
            'DISABLE_TENANT_SCOPES' => null,
            'ENABLE_TENANT_SCOPES_IN_CLI' => 'true',
        ]);
        BaseModel::resetScopesEnabled();

        $this->assertTrue(
            BaseModel::areScopesEnabled(),
            '控制台 + ENABLE_TENANT_SCOPES_IN_CLI=true 应打开作用域'
        );

        // 再叠加 DISABLE 时必须以 DISABLE 为准（关闭优先）
        $this->app()->env->set(['DISABLE_TENANT_SCOPES' => 'true']);
        BaseModel::resetScopesEnabled();
        $this->assertFalse(BaseModel::areScopesEnabled(), '两个逃生口同时存在时 DISABLE 优先');
    }

    /**
     * 用例 5：显式覆盖 `setScopesEnabled()` 优先级最高（既有语义）
     */
    public function testExplicitOverrideBeatsEverything(): void
    {
        $this->app()->env->set(['DISABLE_TENANT_SCOPES' => 'true']);
        BaseModel::resetScopesEnabled();

        BaseModel::setScopesEnabled(true);
        $this->assertTrue(BaseModel::areScopesEnabled(), '显式覆盖必须优先于环境变量');

        BaseModel::setScopesEnabled(false);
        $this->assertFalse(BaseModel::areScopesEnabled());
    }

    /**
     * 用例 6：`getTenantContext()` 保留「返回 null」契约，且**没有异常时不产生降级记录**
     *
     * 对应「CLI / seeder / 迁移 场景下必须仍能返回 null，不能因此抛」这一条。
     * 同时断言「降级记录为空」—— 证明它没有变成「每次查询都往日志里塞一条」。
     */
    public function testTenantContextReturnsNullWithoutNoiseInConsole(): void
    {
        $this->assertNull(
            ProbeContextRecordingModel::getTenantContext(),
            '无请求上下文时必须返回 null，不得抛异常'
        );
        $this->assertSame(
            [],
            ProbeContextRecordingModel::getProbeFailures(),
            '正常的「没有上下文」不应被记为降级（否则日志会被淹没）'
        );
    }

    /**
     * 用例 7：`getTenantContext()` 不再**静默**吞异常
     *
     * 做法：把容器里的 `request` 换成一个 `tenantIdOrNull()` 必定抛错的桩，
     * 再通过探针模型覆写的 `logContextProbeFailure()` 观察「是否真的记录了降级」。
     *
     * 断言三件事：
     *   1. 仍然返回 null（契约不变，不把异常抛给调用方）；
     *   2. **记录了**降级（旧实现在这里是 `catch (\Throwable) { return null; }`，一条记录都不会有）；
     *   3. 记录里带上探测对象与异常类型（可排查）。
     */
    public function testTenantContextLogsInsteadOfSilentlySwallowing(): void
    {
        $this->app()->instance('request', new ThrowingTenantContextProbeRequest());

        $this->assertNull(ProbeContextRecordingModel::getTenantContext());

        $failures = ProbeContextRecordingModel::getProbeFailures();
        $this->assertCount(1, $failures, '探测失败必须被记录，不能被静默吞掉');
        $this->assertSame('tenant', $failures[0]['what']);
        $this->assertSame(RuntimeException::class, $failures[0]['type']);
        $this->assertStringContainsString('故意失败', $failures[0]['message']);
    }

    /**
     * 用例 8：`probeColumn()` 三态 —— **探测失败绝不写缓存**
     *
     * 为什么这条重要：旧实现把「`hasColumn()` 捕获异常 → false」的结果写进静态缓存，
     * 在 Swoole 常驻 worker 里等于「一次抖动 → 整个进程生命周期内都判定没有 tenant_id
     * → 静默跳过租户过滤」。这里用一个 `getTableFields()` **必定抛错**的模型模拟
     * 「无连接 / 表结构查不到」，断言：不向上抛、返回 `null`、且缓存里没有留下任何痕迹。
     */
    public function testColumnProbeFailureReturnsNullAndIsNotCached(): void
    {
        $model = new BrokenColumnProbeModel();

        $this->assertNull(
            $model->probeColumn('tenant_id'),
            '表结构查询失败时必须返回「无法判定」，不能谎报 false，更不能抛给调用方'
        );
        $this->assertFalse(
            $model->hasColumnCached('tenant_id'),
            'bool 版契约：无法判定时按「没有该列」返回（保持向后兼容）'
        );

        $reflection = new ReflectionClass(BaseModel::class);
        $property = $reflection->getProperty('columnCache');
        $property->setAccessible(true);
        $cache = $property->getValue();

        $this->assertArrayNotHasKey(
            BrokenColumnProbeModel::class . ':tenant_id',
            $cache,
            '探测失败绝不能进缓存 —— 否则一次瞬断会变成永久 fail-open'
        );
    }

    /**
     * 用例 9：确定「有 / 没有该列」时会写缓存，且缓存命中后不再触库
     *
     * 与用例 8 成对：只缓存「确定」的结论。
     */
    public function testColumnProbeDefinitiveResultsAreCached(): void
    {
        // 9-1：确定有该列 → true 且写入缓存
        $withTenant = new DeclaredSchemaProbeModel(['id', 'tenant_id', 'name']);
        $this->assertTrue($withTenant->probeColumn('tenant_id'));

        $reflection = new ReflectionClass(BaseModel::class);
        $property = $reflection->getProperty('columnCache');
        $property->setAccessible(true);
        $this->assertTrue($property->getValue()[DeclaredSchemaProbeModel::class . ':tenant_id'] ?? null);

        // 9-2：确定没有该列 → false 且写入缓存（false 也是有效结论，不能用 isset 判命中）
        BaseModel::clearColumnCache();
        $withoutTenant = new DeclaredSchemaProbeModel(['id', 'name']);
        $this->assertFalse($withoutTenant->probeColumn('tenant_id'));
        $this->assertArrayHasKey(
            DeclaredSchemaProbeModel::class . ':tenant_id',
            $property->getValue(),
            '「确定没有该列」也必须缓存，否则每个查询都要重查表结构'
        );

        // 9-3：缓存命中后即使底层查表已经坏掉，也不再触库、结论不变
        BaseModel::clearColumnCache();
        $property->setValue(null, [BrokenColumnProbeModel::class . ':tenant_id' => true]);
        $this->assertTrue(
            (new BrokenColumnProbeModel())->probeColumn('tenant_id'),
            '缓存命中必须短路，不能再去查已经坏掉的表结构'
        );
    }
}

/**
 * 模拟「非控制台（Swoole 请求）环境」的模型
 *
 * 只覆写 `runningInConsole()` 这一个输入 —— SAPI / STDIN / 容器一概不动，
 * 因此它能精确区分「判据用的是 runningInConsole」还是「判据用的是 php_sapi_name」。
 */
final class ConsoleLikeRequestScopeModel extends BaseModel
{
    protected static function runningInConsole(): bool
    {
        return false;
    }
}

/**
 * 记录「上下文探测降级」的探针模型
 *
 * `getTenantContext()` 内部用 `static::logContextProbeFailure(...)`，
 * 因此覆写该钩子即可观察到「是否被静默吞掉」，而不必去读日志文件。
 */
final class ProbeContextRecordingModel extends BaseModel
{
    /**
     * @var array<int, array{what: string, type: string, message: string}>
     */
    public static array $probeFailures = [];

    public static function reset(): void
    {
        static::$probeFailures = [];
    }

    /**
     * @return array<int, array{what: string, type: string, message: string}>
     */
    public static function getProbeFailures(): array
    {
        return static::$probeFailures;
    }

    protected static function logContextProbeFailure(string $what, Throwable $e): void
    {
        static::$probeFailures[] = [
            'what' => $what,
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ];
    }
}

/**
 * 表结构探测必定抛错的模型（模拟「无连接 / 表不存在」）
 *
 * 为什么用 `$schema` 声明 + 覆写 `getTableFields()`，而不是「指向一张不存在的表」：
 * `think\Model` 的**构造函数**就会执行 `initializeData()` → `getFields()` 解析表结构，
 * 指向不存在的表时连 `new` 都过不去，根本走不到 `probeColumn()`。
 * 声明非空 `$schema` 后构造期不再触库，`getTableFields()` 则精确模拟「探测阶段失败」。
 */
final class BrokenColumnProbeModel extends BaseModel
{
    protected $schema = ['id' => 'int'];

    public function getTableFields(): array
    {
        throw new RuntimeException('表结构探测故意失败（测试桩）');
    }
}

/**
 * 使用**声明式 schema** 的探针模型（不触库，结论完全确定）
 */
final class DeclaredSchemaProbeModel extends BaseModel
{
    /**
     * 必须**声明**该属性：未声明时 `$this->schema = ...` 会落到 `Model::__set()` →
     * `set()` → `getMappingName()` → `array_search($name, getOption('mapping') ?: null)`，
     * 而 `mapping` 此时尚未初始化 → TypeError。
     *
     * @var array<string, string>
     */
    protected $schema = ['id' => 'string'];

    /**
     * @param array<int, string> $columns 声明的列名
     */
    public function __construct(private array $columns = ['id'])
    {
        // 把声明式 schema 交给父类，构造期即不再触库解析表结构
        $this->schema = array_fill_keys($this->columns, 'string');

        parent::__construct();
    }

    public function getTableFields(): array
    {
        return array_values($this->columns);
    }
}

/**
 * 租户上下文探测必定抛错的请求桩
 *
 * 继承 `app\Request` 以保持 `request()` 返回类型兼容（`tenantIdOrNull()` 原样覆写）。
 */
final class ThrowingTenantContextProbeRequest extends Request
{
    public function tenantIdOrNull(): ?int
    {
        throw new RuntimeException('租户上下文探测故意失败（测试桩）');
    }
}
