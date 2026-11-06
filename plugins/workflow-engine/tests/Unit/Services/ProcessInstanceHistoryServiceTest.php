<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Services;

use Alkaid\Workflow\Engine\Entities\ProcessInstanceEntity;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Services\ProcessInstanceHistoryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProcessInstanceHistoryService 的**无数据库**契约测试
 *
 * 为什么这里只测「拒绝路径」：
 * 插件自身的 PHPUnit 配置不连数据库（`phpunit.xml` 的 bootstrap 只有 vendor/autoload，
 * 其注释明确禁止把连接参数写进插件配置）。所以插件套件只能覆盖
 * **在任何 SQL 之前就返回/抛错**的分支 —— 正好是三条最重要的 fail-closed 守卫：
 *
 * 1. 实例没有 `tenant_id` → 抛异常，**绝不回落默认租户 1**；
 * 2. `tenant_id = 0` → 同样视为「没有租户」（0 不是合法租户）；
 * 3. 非法实例主键 / 非实体对象 → 抛 LFlowException，而不是把字符串丢进 SQL。
 *
 * 「真的有行落库」「timeline 的租户隔离」「写入失败不影响主流程」这三类需要真库，
 * 放在根套件 `tests/Feature/Workflow/WorkflowInstanceHistoryServiceTest.php`。
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Services
 */
class ProcessInstanceHistoryServiceTest extends TestCase
{
    private ProcessInstanceHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 本类无构造依赖，直接 new：刻意不初始化 WorkflowContainer，
        // 保证这些用例一行 SQL 都不会发出去
        $this->service = new ProcessInstanceHistoryService();
    }

    #[Test]
    public function createHistoryProcessInstanceRejectsInstanceWithoutTenant(): void
    {
        $instance = ProcessInstanceEntity::fromRow(['id' => 1, 'definition_id' => 1]);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        $this->service->createHistoryProcessInstance($instance, 'flow_auto');
    }

    #[Test]
    public function createHistoryProcessInstanceRejectsZeroTenant(): void
    {
        $instance = ProcessInstanceEntity::fromRow(['id' => 1, 'definition_id' => 1, 'tenant_id' => 0]);

        $this->expectException(TenantIsolationException::class);

        $this->service->createHistoryProcessInstance($instance);
    }

    #[Test]
    public function createHistoryProcessInstanceRejectsObjectWithoutGetData(): void
    {
        // 宿主覆盖实现时可能传来任意对象：没有 getData() 就无法定位实例，必须明确报错
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/getData/');

        $this->service->createHistoryProcessInstance(new \stdClass());
    }

    #[Test]
    public function createHistoryProcessInstanceRejectsNonNumericInstanceId(): void
    {
        $instance = ProcessInstanceEntity::fromRow(['id' => 'inst_abc', 'tenant_id' => 1]);

        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/整数主键/');

        $this->service->createHistoryProcessInstance($instance);
    }

    #[Test]
    public function timelineRejectsNonNumericInstanceIdBeforeTouchingDatabase(): void
    {
        // 顺序很重要：先收敛主键，再取租户作用域。所以这条用例在没有数据库上下文时
        // 也能拿到 LFlowException（而不是「容器未初始化」之类的无关错误）
        $this->expectException(LFlowException::class);
        $this->expectExceptionMessageMatches('/整数主键/');

        $this->service->timeline('inst_abc');
    }

    #[Test]
    public function eventTypeConstantsAreStableMachineReadableTokens(): void
    {
        // 这四个值是「流程轨迹」的对外契约（前端按它渲染图标/文案），不允许随手改名
        $this->assertSame('instance_started', ProcessInstanceHistoryService::EVENT_INSTANCE_STARTED);
        $this->assertSame('task_completed', ProcessInstanceHistoryService::EVENT_TASK_COMPLETED);
        $this->assertSame('instance_completed', ProcessInstanceHistoryService::EVENT_INSTANCE_COMPLETED);
        $this->assertSame('instance_rejected', ProcessInstanceHistoryService::EVENT_INSTANCE_REJECTED);
    }
}
