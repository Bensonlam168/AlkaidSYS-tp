<?php

declare(strict_types=1);

namespace Infrastructure\Workflow;

use Alkaid\Workflow\Engine\Container\WorkflowContainer;
use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\interface\IConfiguration;
use Alkaid\Workflow\Engine\interface\IProcessEngines;
use Alkaid\Workflow\Engine\interface\services\IProcessDefineService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceHistoryService;
use Alkaid\Workflow\Engine\interface\services\IProcessInstanceService;
use Alkaid\Workflow\Engine\interface\services\IProcessTaskService;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Closure;
use RuntimeException;
use think\facade\Config;
use think\facade\Log;
use Throwable;

/**
 * 工作流引擎宿主引导（进程级单例）
 *
 * ## 为什么放在 `infrastructure/Workflow/`
 *
 * 这一层的职责是「把外部实现接进宿主」——按 AGENTS.md 的分层约定，
 * `infrastructure/` 就是 adapters/integrations（DB/queue/cache/IO wiring）的归属地。
 * 本类不承载任何业务规则，只做三件事：读宿主配置 → 装配引擎容器 → 交出引擎/服务。
 * `app/` 下没有 service 层，`domain/` 是业务规则层（不该知道 DI 容器长什么样），
 * 因此放在 `infrastructure/` 与既有 `Infrastructure\Tenant\Repository`、
 * `Infrastructure\Lowcode\...` 的组织方式一致。
 *
 * ## 为什么是「静态单例」而不是「容器里的单例」
 *
 * 有两个各自独立的原因，**都不是**「每请求构造很贵」：
 *
 * 1. **容器里的单例只活一个请求**（结构性原因）。Swoole 常驻进程下，`think-swoole` 的
 *    `Sandbox` 会按请求 clone 一份 App（含容器），请求结束再 `clearInstances()` 丢弃。
 *    也就是说「登记在容器 instances 里」的单例下一个请求拿到的是克隆出来的空
 *    instances，会重新构造一次引擎 —— 那等于「每请求重建」，只是看起来像单例。
 *
 * 2. **`ServiceContext` 是进程级静态，重建会覆盖它**（正确性原因，比耗时更要紧）。
 *    `WorkflowContainer::initialize()` 会 `ServiceContext::setContext()` /
 *    `extensions()` / `setBackupConfig()` 覆盖这几个静态槽位；而**已经解析出来的
 *    服务对象仍持着上一代容器里的依赖**（`WorkflowContainer::$resolvedServices`
 *    的注释正是在讲这件事）。于是同进程里存在两代容器时，
 *    `ServiceContext::find($name)` 回答的是**新一代**，而早先拿到手的对象还在跑老一代 ——
 *    这类错乱不会报错，只会让「后建的容器接管了全局解析」静默生效。
 *
 * 实测代价（容器内 PHP 8.2，见交付报告）：
 *   - 首次构造 **128–169ms**（含 autoload / 反射，一个 worker 一生只付一次）；
 *   - 热进程里「再建一个容器」**平均 0.156ms**、复用本单例 **0.000–0.003ms**。
 * 所以耗时上「每请求重建」并不致命，**单例真正换来的是上面第 1、2 条**：
 * 一个进程只有一个 `ServiceContext` 世代、一份引擎实例。
 *
 * ## 宿主配置与插件配置的关系（唯一权威 = `config/workflow.php`）
 *
 * `plugins/workflow-engine/config/workflow.php` 是**模板**，且**没有任何代码 include 它**
 * （`Integration\WorkflowBootstrap::loadConfig()` 找的是 `app/config/workflow.php`，
 * 而本仓库的配置目录是根目录下的 `config/`，所以它连「被错误加载」都不会发生）。
 * 模板里 `database/cache/nodes/task/trigger/event/expression/performance/monitoring/api`
 * 这九段**引擎源码一个键都没读过**（全仓 grep 可证），把它们复制进宿主配置只会制造
 * 「看起来权威、实际没人读」的假配置。
 *
 * 因此这里的做法是：**宿主只写差异项 + 由本类做「宿主 schema → 引擎 schema」的显式映射**，
 * 不 include 模板、不复制模板。映射关系见 {@see self::buildEngineConfig()}，
 * 引擎真正读取的键只有：
 *   - `configuration_class`（引擎配置类）
 *   - `service_dependencies`（服务覆盖）
 *   - `extension_parameters.tenant_id`（CLI/队列的租户兜底，HTTP 下必须为 null）
 *   - `extension_parameters.debug`
 *   - `extension_parameters.logger_channel`（仅记录用途，引擎日志通道目前硬编码为 `workflow`）
 *
 * ## 数据库前缀：引擎**不拼**自己的前缀
 *
 * 模板里的 `database.prefix = 'alkaid_'` 是**死配置**：引擎所有读写都走
 * `Db::name('workflow_definitions')`（见 `Multitenant/TenantScopeManager`），
 * 前缀由 ThinkPHP 连接的 `config/database.php → connections.mysql.prefix` 决定。
 * 本仓库 `.env` 未设置 `DB_PREFIX`，故前缀为空串，实际表名就是 `workflow_definitions` ——
 * 与库里的表名一致。若有人把模板里的 `alkaid_` 真的接到连接配置上，
 * 每一张表都会变成 `alkaid_workflow_definitions`（不存在），全链路立即 500。
 * 详见 `tests/Feature/Workflow/WorkflowEngineHostWiringTest.php` 的前缀断言。
 *
 * @package Infrastructure\Workflow
 */
final class WorkflowEngineBootstrap
{
    /**
     * 宿主配置的读取键（对应 `config/workflow.php`）
     */
    public const CONFIG_KEY = 'workflow';

    /**
     * 进程级单例
     */
    private static ?self $instance = null;

    /**
     * @var array<string, mixed> 宿主侧原始配置（`config/workflow.php` 的内容）
     */
    private array $hostConfig;

    /**
     * @var WorkflowContainer|null 引擎 DI 容器（构造一次后复用）
     */
    private ?WorkflowContainer $container = null;

    /**
     * @var IProcessEngines|null 引擎实例（构造一次后复用）
     */
    private ?IProcessEngines $engine = null;

    /**
     * @param array<string, mixed> $hostConfig 宿主配置
     */
    private function __construct(array $hostConfig)
    {
        $this->hostConfig = $hostConfig;
    }

    /**
     * 获取进程级单例（配置来自 `config/workflow.php`）
     *
     * 注意：本方法**不构造引擎**，真正的构造发生在第一次 {@see self::engine()} 时。
     * 这样 `php think <任意命令>` 不会为一个尚未被调用的子系统白付一次 130–170ms；
     * 而一旦构造过，后续（含 Swoole 的每个请求快照）都复用同一个实例。
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(self::loadHostConfig());
        }

        return self::$instance;
    }

    /**
     * 用显式配置创建一个**不写入单例**的引导器
     *
     * 用途：测试需要在同一进程里验证不同配置（例如关掉引擎、换服务实现）而互不影响；
     * CLI 命令需要按命令行参数临时指定租户时也可以用它。
     *
     * @param array<string, mixed> $hostConfig 宿主配置（与 `config/workflow.php` 同结构）
     */
    public static function make(array $hostConfig): self
    {
        return new self($hostConfig);
    }

    /**
     * 丢弃进程级单例（测试隔离用）
     *
     * 只清「哪个对象是单例」，不清 `ServiceContext`/`TenantContext` 这些引擎内部静态状态 ——
     * 那些是引擎自己的责任域，本类不越权。
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 取得工作流引擎
     *
     * 返回类型是接口 `IProcessEngines`，默认实现为
     * {@see \Alkaid\Workflow\Engine\Core\ProcessEngines}（可用宿主配置 `services.processEngines` 替换）。
     * 刻意**不**把返回类型写成具体类：宿主一旦换实现，这里的签名不该跟着改。
     *
     * @throws RuntimeException 引擎被禁用 / 装配失败
     */
    public function engine(): IProcessEngines
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $engine = $this->container()->getProcessEngines();

        if (!$engine instanceof IProcessEngines) {
            throw new RuntimeException(sprintf(
                '工作流引擎装配失败：容器返回 %s，未实现 %s',
                get_debug_type($engine),
                IProcessEngines::class
            ));
        }

        return $this->engine = $engine;
    }

    /**
     * 取得引擎 DI 容器（构造一次后复用）
     *
     * @throws RuntimeException 引擎被禁用 / 装配失败
     */
    public function container(): WorkflowContainer
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $this->assertEnabled();
        $this->warnIfLogChannelMissing();

        $container = new WorkflowContainer();

        try {
            $container->initialize($this->engineConfig());
        } catch (Throwable $e) {
            throw new RuntimeException(
                '工作流引擎容器初始化失败：' . $e->getMessage(),
                0,
                $e
            );
        }

        // 租户隔离策略是**静态**开关，跟随宿主配置（默认 fail-closed：true）。
        // 刻意放在「容器构造成功之后」：装配都失败时，不该顺手改掉一个全局安全开关。
        TenantContext::setIsolationEnforced($this->isTenantIsolationEnforced());

        return $this->container = $container;
    }

    /**
     * 工作流定义服务（控制器/服务层读定义的入口）
     */
    public function defineService(): IProcessDefineService
    {
        return $this->container()->getProcessDefineService();
    }

    /**
     * 工作流实例服务（启动/查询实例的入口）
     */
    public function instanceService(): IProcessInstanceService
    {
        return $this->container()->getProcessInstanceService();
    }

    /**
     * 工作流任务服务（待办/审批的入口）
     */
    public function taskService(): IProcessTaskService
    {
        return $this->container()->getProcessTaskService();
    }

    /**
     * 工作流实例历史（轨迹）服务
     *
     * 引擎容器把实现登记在 `IProcessInstanceHistoryService::class` 这个**接口名**下
     * （见 `WorkflowContainer::buildCoreServices()`），并且**没有**对应的 `getXxx()` 便捷方法，
     * 因此这里走 `ServiceContext::find()`。必须先 `container()`：`ServiceContext` 的上下文
     * 是在 `WorkflowContainer::initialize()` 里建立的，没初始化就查只会拿到 null。
     *
     * 为什么不在调用点直接 `ServiceContext::find()`：宿主只需要「拿到能用的历史服务」，
     * 「取不到时怎么报错」这件事应当和其它服务一样集中在这里，而不是散落在服务层。
     *
     * @throws RuntimeException 容器里没有可用的历史服务实现
     */
    public function historyService(): IProcessInstanceHistoryService
    {
        $this->container();

        $service = ServiceContext::find(IProcessInstanceHistoryService::class);

        if (!$service instanceof IProcessInstanceHistoryService) {
            throw new RuntimeException(sprintf(
                '工作流引擎装配失败：%s 未注册（实际取到 %s），无法读取流程轨迹',
                IProcessInstanceHistoryService::class,
                get_debug_type($service)
            ));
        }

        return $service;
    }

    /**
     * 读取宿主配置项（点号路径，例如 `engine.logging.channel`）
     *
     * @param string|null $key     配置键；null 表示返回整份配置
     * @param mixed       $default 取不到时的默认值
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->hostConfig;
        }

        $value = $this->hostConfig;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 引擎是否已在本进程内构造过（诊断用，不触发构造）
     */
    public function isBuilt(): bool
    {
        return $this->engine !== null && $this->container !== null;
    }

    /**
     * 把「宿主配置」映射成「引擎配置」
     *
     * 这是宿主与引擎之间**唯一**的配置翻译点：新增一个可配项时改这里，而不是在引擎源码里找。
     *
     * @param array<string, mixed> $hostConfig 宿主配置
     * @return array<string, mixed> 引擎 `WorkflowContainer::initialize()` 需要的配置
     * @throws RuntimeException 配置值非法（fail-closed，不做「猜一个默认值」）
     */
    public static function buildEngineConfig(array $hostConfig): array
    {
        $engineSection = self::section($hostConfig, 'engine');
        $tenantSection = self::section($hostConfig, 'tenant');
        $logging      = self::section($engineSection, 'logging');

        // 引擎配置类：必须是实现了 IConfiguration 的**存在的类**
        $configurationClass = $engineSection['configuration_class']
            ?? \Alkaid\Workflow\Engine\cfg\Configuration::class;

        if (!is_string($configurationClass) || !class_exists($configurationClass)) {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.engine.configuration_class）：类 %s 不存在',
                self::CONFIG_KEY,
                is_string($configurationClass) ? $configurationClass : get_debug_type($configurationClass)
            ));
        }

        if (!is_a($configurationClass, IConfiguration::class, true)) {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.engine.configuration_class）：%s 未实现 %s',
                self::CONFIG_KEY,
                $configurationClass,
                IConfiguration::class
            ));
        }

        return [
            'configuration_class'  => $configurationClass,
            // 服务覆盖：短名或接口 FQCN 均可（WorkflowContainer::buildCoreServices 两种都认）
            'service_dependencies' => self::normalizeServices($hostConfig['services'] ?? []),
            'extension_parameters' => [
                'debug'          => (bool) ($engineSection['debug'] ?? false),
                // ⚠️ 这是「CLI/队列的租户兜底」，**不是** HTTP 的租户来源。
                // HTTP 请求的租户由中间件写进 TenantContext；此值留 null 时，
                // 没有租户上下文就 fail-closed 抛 TenantIsolationException。
                // 一旦在这里写死正整数，HTTP 侧的「无租户」会被静默解释成「按该租户读写」——
                // 那正是多租户红线（详见 ResolvesWorkflowTenant 的优先级说明）。
                'tenant_id'      => self::normalizeTenantId($tenantSection['tenant_id'] ?? null),
                'logger_channel' => (string) ($logging['channel'] ?? 'workflow'),
            ],
        ];
    }

    /**
     * 宿主配置 → 引擎配置（实例版，便于外部诊断）
     *
     * @return array<string, mixed>
     */
    public function engineConfig(): array
    {
        return self::buildEngineConfig($this->hostConfig);
    }

    /**
     * 引擎是否启用（`workflow.enabled`，默认启用）
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->hostConfig['enabled'] ?? true);
    }

    /**
     * 租户隔离是否强制（`workflow.tenant.enforce_isolation`，默认强制）
     */
    public function isTenantIsolationEnforced(): bool
    {
        return (bool) ($this->hostConfig['tenant']['enforce_isolation'] ?? true);
    }

    /**
     * 从框架读取宿主配置；框架未启动时回落为直接读 `config/workflow.php`
     *
     * 为什么需要回落：纯 CLI 脚本（`php scripts/*.php`）与插件独立测试里没有启动 `think\App`，
     * `Config` 门面解析不到 `config` 绑定会抛 `ClassNotFoundException: class not exists: config`。
     * 那种场景下仍应能用同一份配置文件构造引擎，而不是被迫再抄一份默认值。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 配置文件缺失或未返回数组
     */
    private static function loadHostConfig(): array
    {
        if (class_exists(Config::class)) {
            try {
                $config = Config::get(self::CONFIG_KEY);

                // ⚠️ 判定必须是「非空数组」而不是「是数组」：
                // `think\Config::get()` 对**不存在**的一级配置返回的是**空数组**
                // （`pull()` 里 `$this->config[$name] ?? []`），不是 null。
                // 用 `is_array()` 判空会让「配置压根没加载」被当成「宿主配置为空」，
                // 于是所有默认值静默生效 —— 典型的 fail-open 假象。
                // 这个分支在 PHPUnit 里是常态：`Tests\ThinkPHPTestCase` 从不调用
                // `App::initialize()`（框架是懒启动的），因此 `config/workflow.php`
                // 根本没进 Config，`get('workflow')` 返回 []。
                if ($config !== []) {
                    return $config;
                }
            } catch (Throwable) {
                // 框架未启动：落到下面的文件直读
            }
        }

        $file = dirname(__DIR__, 2) . '/config/' . self::CONFIG_KEY . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf(
                '缺少工作流引擎宿主配置：%s（引擎拒绝在「配置不明」的状态下启动）',
                $file
            ));
        }

        $config = require $file;

        if (!is_array($config)) {
            throw new RuntimeException(sprintf(
                '工作流引擎宿主配置必须返回数组：%s 返回了 %s',
                $file,
                get_debug_type($config)
            ));
        }

        return $config;
    }

    /**
     * 引擎被禁用时给出明确异常（而不是返回一个「看起来能用」的空引擎）
     *
     * @throws RuntimeException
     */
    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException(sprintf(
                '工作流引擎已被禁用（%s.enabled = false）：请先在宿主配置中启用，再调用引擎',
                self::CONFIG_KEY
            ));
        }
    }

    /**
     * 检查引擎日志通道在当前运行时的 `log.channels` 里是否存在；缺失则**告警**
     *
     * 为什么是告警而不是抛异常：引擎的日志写入点用的是 `Log::channel('workflow')`
     * （`Services/Support/RecordsHistorySafely` 硬编码），通道不存在时 `think\Log::channel()`
     * 直接抛 `InvalidArgumentException: Channel [workflow] not found.`（实测），
     * 引擎会吞掉它并降级到 `error_log()` —— 表现为「流程能跑，但一条日志都进不了应用日志」。
     * 这种退化必须**可见**，但它属于**旁路能力**：为一条日志通道把整条审批链路打断不成比例
     * （与 RecordsHistorySafely 的既定口径一致：审计能力失败不得改变主流程结果）。
     *
     * 因此策略是：装配期打一条 WARNING（走默认通道，保证落得到应用日志），
     * 由 `config/log.php` 提供通道、由测试守住「通道确实被声明且可用」。
     *
     * 典型触发场景：PHPUnit 的 `Tests\ThinkPHPTestCase` 会**整份替换** `log` 配置
     * （只留 file 通道），因此测试环境里本告警必然出现 —— 那是测试基线的选择，
     * 不是部署缺陷，故这里绝不能抛异常。
     */
    private function warnIfLogChannelMissing(): void
    {
        $channel = $this->config('engine.logging.channel', 'workflow');

        if (!is_string($channel) || $channel === '') {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.engine.logging.channel）：必须是非空字符串',
                self::CONFIG_KEY
            ));
        }

        try {
            $channels = Config::get('log.channels');
        } catch (Throwable) {
            // 框架未启动：没有日志通道的概念，跳过（此时引擎本来就走 error_log 降级）
            return;
        }

        if (!is_array($channels) || array_key_exists($channel, $channels)) {
            return;
        }

        try {
            // 走**默认**通道：目标通道本身就不存在，不能再用它来报自己的缺失
            Log::channel()->warning(sprintf(
                '工作流引擎日志通道 [%s] 在当前运行时不存在（log.channels 里没有该键），'
                . '引擎日志将降级为默认通道 / error_log()。请在 config/log.php 补齐该通道',
                $channel
            ));
        } catch (Throwable) {
            // 连默认通道都不可用（插件独立运行 / 应用未启动）：这个诊断信息不值得上抛
        }
    }

    /**
     * 归一化服务覆盖表
     *
     * 接受三种值：类名字符串（必须存在）、对象、闭包（PHP-DI 的工厂定义）。
     * 非法值直接抛错 —— 引擎侧对「登记了但取不到」的表现是返回 null 后静默跳过能力，
     * 比装配期直接失败更难排查。
     *
     * @param mixed $services 宿主配置里的 `services` 段
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private static function normalizeServices(mixed $services): array
    {
        if ($services === [] || $services === null) {
            return [];
        }

        if (!is_array($services)) {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.services）：必须是数组，实际为 %s',
                self::CONFIG_KEY,
                get_debug_type($services)
            ));
        }

        foreach ($services as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException(sprintf(
                    '工作流引擎配置非法（%s.services）：服务名必须是非空字符串，实际为 %s',
                    self::CONFIG_KEY,
                    get_debug_type($name)
                ));
            }

            if (is_string($value)) {
                if (!class_exists($value)) {
                    throw new RuntimeException(sprintf(
                        '工作流引擎配置非法（%s.services.%s）：类 %s 不存在',
                        self::CONFIG_KEY,
                        $name,
                        $value
                    ));
                }

                continue;
            }

            if ($value instanceof Closure || is_object($value)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.services.%s）：只接受类名 / 对象 / 闭包，实际为 %s',
                self::CONFIG_KEY,
                $name,
                get_debug_type($value)
            ));
        }

        return $services;
    }

    /**
     * 归一化「CLI/队列租户兜底值」
     *
     * null 是**默认且推荐**的取值（= 没有兜底，缺上下文就 fail-closed）。
     * 正整数 / 数字字符串被接受（命令行、环境变量注入常是字符串）。
     *
     * @param mixed $tenantId 配置值
     * @return int|null
     * @throws RuntimeException
     */
    private static function normalizeTenantId(mixed $tenantId): ?int
    {
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        if (is_int($tenantId)) {
            $value = $tenantId;
        } elseif (is_string($tenantId) && ctype_digit($tenantId)) {
            $value = (int) $tenantId;
        } else {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.tenant.tenant_id）：只接受 null 或正整数，实际为 %s',
                self::CONFIG_KEY,
                get_debug_type($tenantId)
            ));
        }

        if ($value <= 0) {
            throw new RuntimeException(sprintf(
                '工作流引擎配置非法（%s.tenant.tenant_id）：必须是正整数，实际为 %d',
                self::CONFIG_KEY,
                $value
            ));
        }

        return $value;
    }

    /**
     * 取配置里的一个数组段（不是数组时按空数组处理）
     *
     * @param array<string, mixed> $config 配置
     * @param string               $key    段名
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}
