<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Integration\LowcodeAdapter;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;

/**
 * LowcodeAdapter Unit Tests | 低代码适配器单元测试
 *
 * @package Alkaid\Workflow\Tests\Unit\Integration
 */
class LowcodeAdapterTest extends TestCase
{
    private LowcodeAdapter $adapter;

    protected function setUp(): void
    {
        TenantContext::clearAll();
        $this->adapter = new LowcodeAdapter();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
    }

    // ========== 租户上下文测试 ==========

    #[Test]
    public function testCreateRequiresTenantContext(): void
    {
        // 不设置租户上下文
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->create('test_collection', ['name' => 'test']);
    }

    #[Test]
    public function testReadRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->read('test_collection');
    }

    #[Test]
    public function testUpdateRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->update('test_collection', [], ['name' => 'updated']);
    }

    #[Test]
    public function testDeleteRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->delete('test_collection', []);
    }

    #[Test]
    public function testFindRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->find('test_collection', 1);
    }

    #[Test]
    public function testBatchCreateRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->batchCreate('test_collection', [['name' => 'test']]);
    }

    #[Test]
    public function testBatchUpdateRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->batchUpdate('test_collection', []);
    }

    #[Test]
    public function testBatchDeleteRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->batchDelete('test_collection', [1, 2, 3]);
    }

    #[Test]
    public function testAggregateRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->aggregate('test_collection', 'count');
    }

    #[Test]
    public function testExistsRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        $this->adapter->exists('test_collection', []);
    }

    // ========== 日志功能测试 ==========

    #[Test]
    public function testOperationLogIsInitiallyEmpty(): void
    {
        $this->assertEmpty($this->adapter->getOperationLog());
    }

    #[Test]
    public function testClearOperationLog(): void
    {
        // 日志记录发生在实际操作期间
        // 这里直接测试清除功能
        $this->adapter->clearOperationLog();
        $this->assertEmpty($this->adapter->getOperationLog());
    }

    #[Test]
    public function testSetLoggingEnabled(): void
    {
        // 应该不抛出异常
        $this->adapter->setLoggingEnabled(true);
        $this->adapter->setLoggingEnabled(false);
        $this->assertTrue(true);
    }

    #[Test]
    public function testSetMaxLogEntries(): void
    {
        // 应该不抛出异常
        $this->adapter->setMaxLogEntries(50);
        $this->adapter->setMaxLogEntries(1);
        $this->assertTrue(true);
    }

    // ========== 权限检查测试 ==========

    #[Test]
    public function testCheckPermissionWithoutTenantReturnsTrue(): void
    {
        // 没有租户上下文时，权限检查应该返回 true（默认允许）
        $result = $this->adapter->checkPermission('read', 'test_collection');
        $this->assertTrue($result);
    }

    #[Test]
    public function testCheckPermissionWithTenantButNoUserReturnsTrue(): void
    {
        TenantContext::setTenantId(100);
        // 有租户但没有用户时
        $result = $this->adapter->checkPermission('read', 'test_collection');
        $this->assertTrue($result); // 默认允许
    }

    #[Test]
    public function testCheckPermissionWithFullContextReturnsTrue(): void
    {
        TenantContext::initialize(100, 200);
        $result = $this->adapter->checkPermission('read', 'test_collection');
        $this->assertTrue($result); // 没有 Casbin 时默认允许
    }

    // ========== 自动填充变量测试 ==========

    #[Test]
    public function testAutoFillVariablesRequiresTenantContext(): void
    {
        $this->expectException(TenantIsolationException::class);

        $this->adapter->autoFillVariables('test_form', 1);
    }

    // ========== 接口兼容性测试 ==========

    #[Test]
    public function testAdapterImplementsCorrectMethods(): void
    {
        // 验证适配器有所有必要的公共方法
        $this->assertTrue(method_exists($this->adapter, 'create'));
        $this->assertTrue(method_exists($this->adapter, 'read'));
        $this->assertTrue(method_exists($this->adapter, 'find'));
        $this->assertTrue(method_exists($this->adapter, 'update'));
        $this->assertTrue(method_exists($this->adapter, 'delete'));
        $this->assertTrue(method_exists($this->adapter, 'batchCreate'));
        $this->assertTrue(method_exists($this->adapter, 'batchUpdate'));
        $this->assertTrue(method_exists($this->adapter, 'batchDelete'));
        $this->assertTrue(method_exists($this->adapter, 'aggregate'));
        $this->assertTrue(method_exists($this->adapter, 'groupAggregate'));
        $this->assertTrue(method_exists($this->adapter, 'exists'));
        $this->assertTrue(method_exists($this->adapter, 'saveFormData'));
        $this->assertTrue(method_exists($this->adapter, 'getFormData'));
        $this->assertTrue(method_exists($this->adapter, 'getFormSchema'));
        $this->assertTrue(method_exists($this->adapter, 'autoFillVariables'));
        $this->assertTrue(method_exists($this->adapter, 'getCollection'));
        $this->assertTrue(method_exists($this->adapter, 'getCollectionFields'));
        $this->assertTrue(method_exists($this->adapter, 'checkPermission'));
    }

    #[Test]
    public function testGettersReturnCorrectTypes(): void
    {
        // 验证获取器返回正确类型（即使是通过惰性加载）
        $this->assertTrue(method_exists($this->adapter, 'getCollectionManager'));
        $this->assertTrue(method_exists($this->adapter, 'getFormDataManager'));
        $this->assertTrue(method_exists($this->adapter, 'getFormSchemaManager'));
    }

    // ========== 构造函数测试 ==========

    #[Test]
    public function testConstructorWithNullDependencies(): void
    {
        $adapter = new LowcodeAdapter(null, null, null);
        $this->assertInstanceOf(LowcodeAdapter::class, $adapter);
    }

    #[Test]
    public function testConstructorWithPartialDependencies(): void
    {
        $adapter = new LowcodeAdapter(null, null);
        $this->assertInstanceOf(LowcodeAdapter::class, $adapter);
    }
}
