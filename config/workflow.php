<?php

declare(strict_types=1);

/**
 * 工作流引擎宿主配置（**唯一权威来源**）
 * ===========================================================================
 *
 * ## 与 `plugins/workflow-engine/config/workflow.php` 的关系
 *
 * 插件那份是**模板**，而且**没有任何代码 include 它**（引擎从未被主应用装配过，
 * 连 `Integration\WorkflowBootstrap::loadConfig()` 找的都是 `app/config/workflow.php`，
 * 而本仓库的配置目录是根目录下的 `config/`）。
 *
 * 模板里 `database / cache / nodes / task / trigger / event / expression / performance /
 * monitoring / api / extensions` 这十一段**在引擎源码里一个键都没被读过**（全仓 grep 可证），
 * 因此这里**不复制模板**、也不 `require` 模板：
 * 复制 = 制造「看起来权威、其实没人读」的假配置；`require` = 把一份含 `env()`/`app()` 调用的
 * 大文件塞进配置加载路径，且其取值（如 `database.prefix = 'alkaid_'`）与真实行为相反。
 *
 * 本文件只写「宿主差异项」，由 `Infrastructure\Workflow\WorkflowEngineBootstrap`
 * 做「宿主 schema → 引擎 schema」的显式映射（{@see \Infrastructure\Workflow\WorkflowEngineBootstrap::buildEngineConfig()}）。
 * 新增可配项时改这两处，而不要回头去改引擎源码。
 *
 * @package config
 */
return [
    // =======================================================================
    // 总开关
    // =======================================================================
    // false 时 `WorkflowEngineBootstrap::engine()` 直接抛异常，而不是返回一个空引擎：
    // 空引擎会让调用方「看起来调用成功、实际什么都没做」，比明确失败更难排查。
    'enabled' => true,

    // =======================================================================
    // 引擎核心配置（映射到 WorkflowContainer::initialize() 的顶层键）
    // =======================================================================
    'engine' => [
        // 引擎配置类（必须是实现了 IConfiguration 的类，装配期校验）
        'configuration_class' => \Alkaid\Workflow\Engine\cfg\Configuration::class,

        // 调试开关（映射到 extension_parameters.debug）
        // 刻意**不**读 env('APP_DEBUG')：配置文件要能在「框架未启动」的纯 CLI 脚本里被 require，
        // 而 env() 是 ThinkPHP 的全局助手函数，那种场景下不存在（调用即 \Error）。
        'debug' => false,

        'logging' => [
            // 引擎日志通道。引擎侧的写入点是硬编码的 `Log::channel('workflow')`
            // （plugins/workflow-engine/src/Services/Support/RecordsHistorySafely.php），
            // 因此这里取值必须与 config/log.php 里真实存在的通道名一致 ——
            // 装配期会校验「channels 里有这个键」，缺失即抛异常（见 assertLogChannelExists）。
            'channel' => 'workflow',
        ],

        // 是否在应用启动（Service::boot）时预热引擎：默认 false
        //
        // false（默认）：第一次真正调用引擎时才构造（每进程一次，静态单例复用）。
        //   代价是「每个 Swoole worker 的第一个请求」多付一次构造耗时（实测首次约 140ms，
        //   之后 0.2ms 级）；好处是 `php think <任意命令>` 不为未使用的子系统白付这笔钱，
        //   且引擎装配出错不会拖垮整个应用的启动。
        // true：在 boot 期构造（Swoole 下发生在 fork 之前的主进程，所有 worker 继承）。
        //   适合「工作流是核心链路、宁可在启动期就失败」的部署；代价是每个 CLI 命令多约 140ms，
        //   且装配失败会让应用起不来（boot 失败会以非 0 退出，见 app/provider/WorkflowEngineService）。
        'warmup' => false,
    ],

    // =======================================================================
    // 多租户
    // =======================================================================
    'tenant' => [
        // 是否强制租户隔离（映射到 TenantContext::setIsolationEnforced()）
        //
        // ⚠️ 唯一正确的生产取值是 true。设为 false 后 TenantScopeManager 在「没有租户上下文」时
        // 会退化成 `where tenant_id = 0`，即「查不到任何数据」而不是「明确报错」；
        // 仅单租户部署在明确知晓后果时才可关闭。
        'enforce_isolation' => true,

        // CLI / 队列场景的租户兜底（映射到 extension_parameters.tenant_id）
        //
        // ⚠️ 必须保持 null。HTTP 请求的租户由中间件 `app\middleware\WorkflowTenantContext`
        // 写进 TenantContext；此值只是「没有请求上下文时（cron / 队列 worker）用哪个租户」的兜底。
        // 一旦在这里写死正整数，HTTP 侧「没有租户」的请求会被静默解释成「按该租户读写」，
        // 属于跨租户越界（引擎侧 ResolvesWorkflowTenant 的优先级就是 TenantContext → 本值）。
        'tenant_id' => null,
    ],

    // =======================================================================
    // 服务覆盖（映射到引擎的 service_dependencies）
    // =======================================================================
    // 短名与接口 FQCN 都可作为键（WorkflowContainer::buildCoreServices() 两种都认），
    // 值可以是类名 / 对象 / 闭包。装配期会校验类是否存在，避免「登记了但取不到 → 静默跳过能力」。
    //
    // 例：
    //   'task_actor_resolver' => \Infrastructure\Workflow\OrganizationTaskActorResolver::class,
    //   \Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService::class => ...,
    'services' => [],
];
