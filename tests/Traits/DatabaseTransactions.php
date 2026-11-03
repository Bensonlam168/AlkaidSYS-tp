<?php

declare(strict_types=1);

namespace Tests\Traits;

use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use RuntimeException;
use think\facade\Db;

/**
 * 数据库事务测试隔离 Trait
 * Database Transaction Test Isolation Trait
 *
 * 为测试提供数据库事务隔离，测试结束后自动回滚所有数据库更改。
 * Provides database transaction isolation for tests, automatically rolling back all database changes after test completion.
 *
 * 使用方法 | Usage:
 * ```php
 * class MyTest extends ThinkPHPTestCase
 * {
 *     use DatabaseTransactions;
 *
 *     // 可以照常定义自己的 setUp() / tearDown()，共享的事务钩子仍然会执行
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->factory = new TestDataFactory();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->factory->cleanup();
 *         parent::tearDown();
 *     }
 *
 *     public function testSomething(): void
 *     {
 *         // 测试代码，所有数据库更改会在测试结束后自动回滚
 *         // Test code, all database changes will be automatically rolled back after test completion
 *     }
 * }
 * ```
 *
 * ## 为什么用 `#[Before]` / `#[After]` 而不是 `setUp()` / `tearDown()`（ER-16）
 *
 * 旧实现把钩子写成 trait 内的 `protected function setUp()/tearDown()`，依赖「trait 方法被插入到类里」
 * 来挂钩子。但 PHP 的规则是**类自身声明的方法覆盖 trait 插入的同名方法**：只要测试类自己定义了
 * `setUp()`/`tearDown()`（本仓库的测试几乎都会），trait 的钩子就**整段消失**，既不会被调用，
 * 也不会报错 —— `Db::startTrans()` 从未执行，测试数据被真提交，`transaction_test_user` 之类
 * 的脏数据就这样永久留在了库里（这就是「静默失效」）。
 *
 * 改用 PHPUnit 11 的属性挂载后，钩子方法名与 `setUp()`/`tearDown()` **不同名**，不存在覆盖关系：
 * 即使测试类定义了自己的 `setUp()`/`tearDown()`，事务钩子依然执行。
 *
 * ## 实测的执行顺序（PHPUnit 11.5.44，见 `vendor/phpunit/phpunit/src/Framework/TestCase.php::runBare()`
 * 与 `src/Runner/HookMethod/HookMethodCollection.php`）
 *
 * ```
 * #[Before] → setUp() → assertPreConditions() → 测试方法体 → assertPostConditions() → tearDown() → #[After]
 * ```
 *
 * 依据（源码 + 实测双重确认）：
 * - `HookMethodCollection::defaultBefore()` 以 `setUp` 为默认项且 `shouldPrepend = true`，
 *   所以 `#[Before]` 方法被**前置**到 `setUp` 之前；
 * - `defaultAfter()` 以 `tearDown` 为默认项且 `shouldPrepend = false`，所以 `#[After]` 被**追加**到 `tearDown` 之后。
 *
 * 由此得到两条本 Trait 依赖的保证：
 * 1. 事务在**测试方法体之前**已开启（连 `setUp()` 里的写库操作也在事务内，一并回滚）；
 * 2. 回滚发生在**测试类的 tearDown() 清理逻辑之后**，所以 tearDown 里
 *    `TestDataFactory::cleanup()` 发出的 DELETE 仍处于同一个事务内，随后被回滚一并丢弃，
 *    不会出现「清理语句跑到事务外、留下残渣」或「清理先提交」的问题。
 *
 * ## 已知限制：MySQL 的 DDL 会**隐式提交**事务
 *
 * MySQL（以及 MariaDB）在执行 `CREATE TABLE` / `ALTER TABLE` / `DROP TABLE` / `TRUNCATE` 等 DDL 时，
 * 会先隐式提交当前事务（隐式提交发生在语句执行前，且**不可撤销**）。这意味着：
 *
 * - 若某个用例在事务里执行了 DDL（例如 `ALLOW_RUNTIME_DDL=true` 下由 `SchemaBuilder` 建表），
 *   那么在 DDL 之前的 INSERT 已经被真提交，**本 Trait 的回滚救不回来**；
 * - 更坏的是这种破坏**不会报错**：`Db::rollback()` 在「已不在事务中」时只是把 ThinkPHP 自己的
 *   `transTimes` 减到 0 并跳过 `PDO::rollBack()`（见 `PDOConnection::rollback()`），
 *   PDO 也不会警告，于是隔离被破坏却全程静默。
 *
 * 因此本 Trait 会**检测**这种状态：`#[After]` 阶段若登记为「已开启事务」但连接已经不在事务中，
 * 直接抛 `RuntimeException` 让用例变红（而不是只发警告）。
 * 选抛异常而不是「警告」的两个理由：
 * 1. **PHPUnit 11.5 已没有 `addWarning()`**（`src/Framework/TestCase.php` 里只剩 `addToAssertionCount()`），
 *    用户级「加警告」的 API 不复存在；
 * 2. 与本仓库既有先例一致：`tests/ThinkPHPTestCase.php::assertTestDatabaseName()` 的注释写明
 *    「刻意用异常而不是 trigger_error / 输出警告：警告不会让 PHPUnit 停下来」。
 *    警告（W）不会阻止后续用例继续在脏状态上跑，异常（E）才会。
 *
 * 若某个用例确实需要 DDL，正确做法是**不要**把它放进本 Trait 的事务里（二者互斥），
 * 或者在该用例内自行重建隔离（如用完即 DROP 并断言残留为 0）。
 *
 * ## 其它注意事项 | Other Notes
 *
 * - 事务回滚只对支持事务的数据库引擎有效（如 InnoDB）
 * - Transaction rollback only works for database engines that support transactions (e.g., InnoDB)
 * - 如果测试需要真实提交数据，不要使用此 Trait
 * - Do not use this Trait if the test needs to actually commit data
 * - 需要手动控制事务时机时，直接调用公开的 `setUpDatabaseTransaction()` / `tearDownDatabaseTransaction()`；
 *   它们与自动钩子幂等共存（手动回滚后 `#[After]` 不会重复回滚）
 */
trait DatabaseTransactions
{
    /**
     * 单次收尾允许的最大回滚次数
     *
     * 正常情况只需 1 次；多于 1 次说明用例自己开了嵌套事务（`Db::startTrans()` 未配对），
     * 这里做有限次的兜底清空，避免连接带着未结束的事务进入下一个用例。
     */
    private const MAX_ROLLBACK_ATTEMPTS = 8;

    /**
     * 事务是否处于「已开启且尚未收尾」状态
     * Whether the transaction is currently started and not yet torn down
     *
     * @var bool
     */
    protected bool $transactionStarted = false;

    /**
     * `#[Before]` 钩子的执行次数（用于识别「钩子被影子覆盖 / 属性被删」的静默失效）
     *
     * @var int
     */
    protected int $transactionHookInvocations = 0;

    /**
     * PHPUnit `#[Before]` 钩子：开启事务
     * PHPUnit #[Before] hook: start the transaction
     *
     * 执行时机早于测试类的 `setUp()`（见类注释中的实测顺序）。
     * 之所以不叫 `setUp()`：同名方法会被测试类自己的 `setUp()` 覆盖，导致钩子静默消失。
     *
     * `final`：防止子类/测试类再声明同名方法把钩子顶掉（那样会致命错误而不是静默失效）。
     */
    #[Before]
    final protected function beginDatabaseTransactionHook(): void
    {
        $this->transactionHookInvocations++;

        $this->setUpDatabaseTransaction();
    }

    /**
     * PHPUnit `#[After]` 钩子：回滚事务
     * PHPUnit #[After] hook: roll back the transaction
     *
     * 执行时机晚于测试类的 `tearDown()`（见类注释中的实测顺序），
     * 因此 tearDown 里的清理 DELETE 仍在事务内，会随回滚一并丢弃。
     *
     * @throws RuntimeException 钩子未执行（隔离失效回归）或事务已被隐式提交
     */
    #[After]
    final protected function finishDatabaseTransactionHook(): void
    {
        // 回归防线：登记不到 #[Before] 的执行记录，说明钩子根本没跑
        // （典型历史成因：trait 用 setUp() 挂钩子，被测试类的 setUp() 影子覆盖）
        if (0 === $this->transactionHookInvocations) {
            throw new RuntimeException(
                'DatabaseTransactions 事务钩子未执行：#[Before] 没有运行，本次用例完全没有事务隔离。' .
                "\n可能原因：1) trait 的 beginDatabaseTransactionHook() 上的 #[Before] 属性被删；" .
                ' 2) 当前测试基类不是 PHPUnit\Framework\TestCase 体系（钩子不会被 PHPUnit 调用）。' .
                "\n自检：tests/Unit/Traits/TestIsolationTest.php 的 testTraitDeclaresHookAttributes() 会先红。"
            );
        }

        $this->tearDownDatabaseTransaction();
    }

    /**
     * 开启事务（公开：需要手动控制时机时可直接调用）
     * Start the transaction (public: call directly when manual control is needed)
     *
     * @throws RuntimeException 事务没有真正开启
     */
    public function setUpDatabaseTransaction(): void
    {
        Db::startTrans();
        $this->transactionStarted = true;

        // fail-closed：startTrans() 没抛异常不等于事务真的开了（引擎不支持事务时会静默退化）
        if (!self::isDatabaseTransactionActive()) {
            $this->transactionStarted = false;

            throw new RuntimeException(
                'DatabaseTransactions 开启事务失败：Db::startTrans() 之后连接仍不在事务中。' .
                "\n请确认测试库使用支持事务的引擎（InnoDB），且默认连接没有被中途重建。"
            );
        }
    }

    /**
     * 回滚事务并校验回滚真的生效（公开：需要手动控制时机时可直接调用）
     * Roll back the transaction and verify the rollback really happened
     *
     * 与钩子幂等：未开启事务时直接返回，不会抛错（便于用例内手动提前收尾）。
     *
     * @throws RuntimeException 事务已被隐式提交（典型原因：用例内执行了 DDL）或回滚未能清空事务
     */
    public function tearDownDatabaseTransaction(): void
    {
        // 幂等：没开事务（或已被本方法处理过）就直接返回
        if (!$this->transactionStarted) {
            return;
        }

        $this->transactionStarted = false;

        // 检测 1：登记为「已开启」但连接已不在事务中 —— MySQL 的 DDL 隐式提交就是这条路径。
        // 此时 Db::rollback() 只会把 transTimes 减到 0 并跳过 PDO::rollBack()，不会有任何提示。
        if (!self::isDatabaseTransactionActive()) {
            throw new RuntimeException(
                'DatabaseTransactions 检测到事务已被隐式提交：用例结束时连接已不在事务中，数据已真实写入测试库，无法回滚。' .
                "\n最常见原因：事务内执行了 DDL（CREATE/ALTER/DROP/TRUNCATE），MySQL 会在 DDL 前隐式提交当前事务。" .
                "\n处理建议：把该用例从事务型隔离中移出，或在该用例内自行清理并断言残留为 0。"
            );
        }

        // 回滚；正常一次到底。嵌套 startTrans() 未配对时用有限次回滚兜底，避免脏事务带到下一个用例
        $rollbacks = 0;
        while (self::isDatabaseTransactionActive() && $rollbacks < self::MAX_ROLLBACK_ATTEMPTS) {
            Db::rollback();
            $rollbacks++;
        }

        if (self::isDatabaseTransactionActive()) {
            throw new RuntimeException(sprintf(
                'DatabaseTransactions 回滚失败：连续回滚 %d 次后连接仍处于事务中，测试库可能已被污染。',
                $rollbacks
            ));
        }

        // 多于 1 次说明用例自己开了嵌套事务且没有配对收尾：数据虽然清空了，但这是应当被修正的缺陷
        if ($rollbacks > 1) {
            throw new RuntimeException(sprintf(
                'DatabaseTransactions 检测到未配对的嵌套事务：需要 %d 次回滚才清空，' .
                '说明用例内调用了 Db::startTrans() 却没有对应的 commit()/rollback()。请自行配对。',
                $rollbacks
            ));
        }
    }

    /**
     * 读取「连接当前是否真的处在事务中」
     * Whether the underlying PDO connection is really inside a transaction
     *
     * 为什么不用 ThinkPHP 自己的 `transTimes`：它是 protected，而且是 ThinkPHP 的**计数簿记**，
     * 与数据库的真实状态会脱节（DDL 隐式提交后 `transTimes` 仍是 1，但服务器端事务已经结束）。
     * `PDO::inTransaction()` 读的是驱动/服务端的真实状态，才能识别出隐式提交。
     *
     * `Db::getPdo()` 经 `DbManager::__call` 转发到默认连接的 `getPdo()`，
     * 未建立连接时返回 `false`（见 `PDOConnection::getPdo()`）。
     */
    private static function isDatabaseTransactionActive(): bool
    {
        $pdo = Db::getPdo();

        return $pdo instanceof PDO && $pdo->inTransaction();
    }
}
