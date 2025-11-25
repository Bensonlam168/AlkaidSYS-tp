<?php

declare(strict_types=1);

namespace Tests\Traits;

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
 *     public function testSomething()
 *     {
 *         // 测试代码，所有数据库更改会在测试结束后自动回滚
 *         // Test code, all database changes will be automatically rolled back after test completion
 *     }
 * }
 * ```
 *
 * 注意事项 | Notes:
 * - 事务回滚只对支持事务的数据库引擎有效（如 InnoDB）
 * - Transaction rollback only works for database engines that support transactions (e.g., InnoDB)
 * - 如果测试需要真实提交数据，不要使用此 Trait
 * - Do not use this Trait if the test needs to actually commit data
 * - 嵌套事务可能导致意外行为，请谨慎使用
 * - Nested transactions may cause unexpected behavior, use with caution
 */
trait DatabaseTransactions
{
    /**
     * 事务是否已开启
     * Whether transaction has been started
     *
     * @var bool
     */
    protected bool $transactionStarted = false;

    /**
     * 在测试开始前开启事务
     * Start transaction before test
     *
     * @return void
     */
    protected function setUpDatabaseTransaction(): void
    {
        // 开启事务
        // Start transaction
        Db::startTrans();
        $this->transactionStarted = true;
    }

    /**
     * 在测试结束后回滚事务
     * Rollback transaction after test
     *
     * @return void
     */
    protected function tearDownDatabaseTransaction(): void
    {
        // 如果事务已开启，回滚事务
        // If transaction has been started, rollback transaction
        if ($this->transactionStarted) {
            Db::rollback();
            $this->transactionStarted = false;
        }
    }

    /**
     * PHPUnit setUp 钩子
     * PHPUnit setUp hook
     *
     * 自动在测试开始前开启事务
     * Automatically start transaction before test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseTransaction();
    }

    /**
     * PHPUnit tearDown 钩子
     * PHPUnit tearDown hook
     *
     * 自动在测试结束后回滚事务
     * Automatically rollback transaction after test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownDatabaseTransaction();
        parent::tearDown();
    }
}

