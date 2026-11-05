<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Core;

use Alkaid\Workflow\Engine\Core\ProcessEngines;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\Tests\TestCase;
use Alkaid\Workflow\Engine\interface\services\IProcessDefineService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService;
use madong\helper\Dict;

/**
 * 工作流引擎核心类测试
 *
 * @package Alkaid\Workflow\Engine\Tests\Unit\Core
 */
class ProcessEnginesTest extends TestCase
{
    /**
     * @var ProcessEngines 工作流引擎实例
     */
    private ProcessEngines $engine;

    /**
     * 设置测试环境
     */
    protected function setupTestData(): void
    {
        parent::setupTestData();

        $service = $this->getService('processEngines');

        // 判空口径必须是「是否真的拿到了可用的引擎实例」，不能只判 null。
        // 原因：WorkflowContainer::buildCoreServices() 登记的是「名字 => 类名」，
        // 而 ServiceContext::setContext() 把这份映射原样交给 PHP-DI，
        // PHP-DI 会把字符串当成**字面值**（ValueDefinition），
        // 于是 get('processEngines') 取回的是类名字符串而不是引擎对象。
        // 旧写法 `=== null` 判不住这种情况，字符串被赋给强类型属性
        // private ProcessEngines $engine 时直接抛 TypeError（本用例 10 个 error 的成因）。
        if ($service instanceof ProcessEngines) {
            $this->engine = $service;
            return;
        }

        // 容器没有给出可用引擎时，按本用例原意手动构造一个真实引擎，
        // 依赖全部用 mock 注入（不为了绕过类型检查而放宽生产代码的属性类型）。
        $config = [
            'service_dependencies' => [
                'processDefineService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class),
                'processInstanceService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class),
                'processTaskService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class),
                'processInstanceHistoryService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService::class),
                'processTaskHistoryService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskHistoryService::class),
            ],
        ];

        $this->engine = new ProcessEngines($config);
    }

    /**
     * 测试引擎实例化
     */
    public function testEngineInstantiation(): void
    {
        $this->assertInstanceOf(ProcessEngines::class, $this->engine);
        $this->assertInstanceOf(IProcessEngines::class, $this->engine);
    }

    /**
     * 测试引擎配置
     */
    public function testEngineConfiguration(): void
    {
        // 引擎应该有配置对象
        $this->assertNotNull($this->engine->configuration);

        // 引擎应该可以被重新配置
        $newConfig = [
            'service_dependencies' => [
                'processDefineService' => $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class),
            ],
        ];

        $this->engine->configure($newConfig);
        $this->assertNotNull($this->engine->configuration);
    }

    /**
     * 测试服务获取
     */
    public function testServiceGetters(): void
    {
        // 模拟服务
        $mockDefineService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class);
        $mockInstanceService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class);
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);

        // 设置到容器
        // 键必须用**接口 FQCN**：引擎内部经 ProcessServiceTrait::processXxxService()
        // 取服务，而它查的是 ServiceContext::find(IProcessXxxService::class)。
        // 用短名（如 'processDefineService'）注册时 find(接口 FQCN) 取不到，得到 null。
        $container = $this->getContainer();
        $container->register(IProcessDefineService::class, $mockDefineService);
        $container->register(IProcessInstanceService::class, $mockInstanceService);
        $container->register(IProcessTaskService::class, $mockTaskService);

        // 测试服务获取
        $this->assertSame($mockDefineService, $this->engine->processDefineService());
        $this->assertSame($mockInstanceService, $this->engine->processInstanceService());
        $this->assertSame($mockTaskService, $this->engine->processTaskService());
    }

    /**
     * 测试启动工作流实例
     */
    public function testStartProcessInstance(): void
    {
        // 创建模拟定义服务
        $mockDefineService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessDefineService::class);
        $mockDefineService->method('getById')->willReturn(null);

        // 创建模拟实例服务
        $mockInstanceService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessInstanceService::class);
        $mockInstanceService->method('createProcessInstance')->willReturn(null);

        // 注意：原用例在此处为「任务历史服务」配置了 createHistoryProcessInstance()，
        // 但该方法在所有接口里都**不存在**：
        //  - IProcessTaskHistoryService 只声明 created / updated / findById / list；
        //  - 真正需要它的调用点在 ProcessEngines::startProcessInstanceById()，
        //    走的是 processInstanceHistoryService()；而 IProcessInstanceHistoryService
        //    目前所有方法都被注释掉（空接口），任何 mock 都无法配置该方法。
        // 配置不存在的方法会抛 MethodCannotBeConfiguredException（本用例原本的 error）。
        // 本用例在到达历史服务之前就会因「缺少流程定义」抛异常（见末尾 expectException），
        // 因此这里只注册 mock、不再配置不存在的接口方法。

        // 设置到容器
        $container = $this->getContainer();
        $container->register(IProcessDefineService::class, $mockDefineService);
        $container->register(IProcessInstanceService::class, $mockInstanceService);

        // 创建测试参数
        // 注意：必须用 madong\helper\Dict —— ProcessEngines::startProcessInstanceById()
        // 等方法的签名要求 madong\interface\IDict，而插件自带的 libs\utils\Dict
        // 实现的是插件自己的 interface\IDict，两者不是同一个接口。
        // 另外 madong\helper\Dict::__construct() 不接收初始数据，参数要用 putAll() 注入。
        $args = new Dict();
        $args->putAll([
            'applicant_name' => '测试用户',
            'amount' => 1000,
        ]);

        // 尝试启动工作流实例（预期失败，因为定义不存在）
        $this->expectException(\Exception::class);
        $this->engine->startProcessInstanceById('nonexistent_id', '1', $args);
    }

    /**
     * 测试执行工作流任务
     */
    public function testExecuteProcessTask(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register(IProcessTaskService::class, $mockTaskService);

        // 创建测试参数
        $args = new Dict();
        $args->putAll([
            'approved' => true,
            'comment' => '同意',
        ]);

        // 尝试执行任务（预期失败，因为任务不存在）
        $this->expectException(\Exception::class);
        $result = $this->engine->executeProcessTask('nonexistent_task_id', '1', $args);

        // 应该返回空数组
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试执行并跳转任务
     */
    public function testExecuteAndJumpTask(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register(IProcessTaskService::class, $mockTaskService);

        // 创建测试参数
        $args = new Dict();
        $args->putAll([
            'approved' => true,
        ]);

        // 测试跳转
        // 按生产契约修正断言：ProcessEngines::execute() 在查不到「进行中」的任务时
        // 抛 LFlowException(LfErrEnum::NOT_FOUND_DOING_PROCESS_TASK)，
        // 不会返回空数组 —— 四个执行入口里的 `if ($execution == null) return [];`
        // 是永远走不到的死分支。原用例断言「返回空数组」与实现相反，
        // 与同文件 testExecuteProcessTask（断言 expectException）自相矛盾。
        // 注：当前异常 message 是枚举原值 20010003（LFlowException 收到的是 int），
        // 不是枚举的可读文案，属另一处独立缺陷，不在本用例断言范围内。
        $this->expectException(\Alkaid\Workflow\Engine\ex\LFlowException::class);
        $this->engine->executeAndJumpTask('nonexistent_task_id', '1', $args, 'next_node');
    }

    /**
     * 测试执行并跳转到结束
     */
    public function testExecuteAndJumpToEnd(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register(IProcessTaskService::class, $mockTaskService);

        // 创建测试参数
        $args = new Dict();
        $args->putAll([
            'approved' => false,
        ]);

        // 测试跳转到结束（断言口径同 testExecuteAndJumpTask：缺任务即抛 LFlowException）
        $this->expectException(\Alkaid\Workflow\Engine\ex\LFlowException::class);
        $this->engine->executeAndJumpToEnd('nonexistent_task_id', '1', $args);
    }

    /**
     * 测试执行并跳转到第一个任务节点
     */
    public function testExecuteAndJumpToFirstTaskNode(): void
    {
        // 创建模拟任务服务
        $mockTaskService = $this->createMock(\Alkaid\Workflow\Engine\interface\services\IProcessTaskService::class);
        $mockTaskService->method('getById')->willReturn(null);

        // 设置到容器
        $container = $this->getContainer();
        $container->register(IProcessTaskService::class, $mockTaskService);

        // 创建测试参数
        $args = new Dict();
        $args->putAll([
            'restart' => true,
        ]);

        // 测试跳转到第一个任务节点（断言口径同 testExecuteAndJumpTask：缺任务即抛 LFlowException）
        $this->expectException(\Alkaid\Workflow\Engine\ex\LFlowException::class);
        $this->engine->executeAndJumpToFirstTaskNode('nonexistent_task_id', '1', $args);
    }

    /**
     * 测试引擎类型检查
     */
    public function testEngineTypeChecks(): void
    {
        // 引擎应该实现IProcessEngines接口
        $this->assertInstanceOf(IProcessEngines::class, $this->engine);

        // 引擎应该有processDefineService方法
        $this->assertTrue(method_exists($this->engine, 'processDefineService'));

        // 引擎应该有processInstanceService方法
        $this->assertTrue(method_exists($this->engine, 'processInstanceService'));

        // 引擎应该有processTaskService方法
        $this->assertTrue(method_exists($this->engine, 'processTaskService'));

        // 引擎应该有startProcessInstanceById方法
        $this->assertTrue(method_exists($this->engine, 'startProcessInstanceById'));

        // 引擎应该有executeProcessTask方法
        $this->assertTrue(method_exists($this->engine, 'executeProcessTask'));
    }

    /**
     * 测试配置异常处理
     */
    public function testConfigurationException(): void
    {
        // 使用无效配置创建引擎
        $this->expectException(\Exception::class);

        $invalidEngine = new ProcessEngines([
            'invalid_config' => 'invalid_value',
        ]);
    }
}
