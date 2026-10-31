<?php

declare(strict_types=1);

namespace Infrastructure\Plugin;

use Infrastructure\DI\DependencyManager;
use Infrastructure\Plugin\Enum\PluginStatus;
use Infrastructure\Plugin\Hook\HookManager;
use Infrastructure\Plugin\Repository\PluginInstallationRepository;
use Infrastructure\Plugin\Repository\PluginPackageRepository;
use Psr\Log\LoggerInterface;
use think\App;
use think\facade\Config;
use think\facade\Log;

/**
 * 插件管理器（T-035 基础能力）
 *
 * 职责：
 * - 从配置/数据库注册插件，并维护运行时状态
 * - 负责启用、禁用、卸载生命周期控制（含 Hook 与 ServiceProvider 注册）
 * - 提供钩子委派入口（applyFilter/doAction），与全局函数保持一致
 *
 * 说明：
 * - 持久化表缺失时应降级不阻断启动（与设计文档一致）
 * - 默认多租户维度使用 tenant_id/site_id，命令行参数可覆盖
 */
class PluginManager
{
    private App $app;
    private HookManager $hookManager;
    private DependencyManager $dependencyManager;
    private PluginInstallationRepository $installationRepository;

    /**
     * @var array<string, CorePluginBaseService> 已注册插件实例
     */
    private array $plugins = [];

    /**
     * @var array<string, PluginStatus> 运行时状态映射
     */
    private array $pluginStatus = [];

    private ?LoggerInterface $logger;

    public function __construct(
        App $app,
        ?HookManager $hookManager = null,
        ?DependencyManager $dependencyManager = null,
        ?PluginInstallationRepository $installationRepository = null,
        ?LoggerInterface $logger = null
    ) {
        $this->app = $app;
        $this->hookManager = $hookManager ?? HookManager::instance();
        $this->dependencyManager = $dependencyManager ?? new DependencyManager($app);
        $this->installationRepository = $installationRepository ?? new PluginInstallationRepository();
        $this->logger = $logger;
    }

    /**
     * 从配置与插件包表加载插件，并按 enabled 列表默认启用
     */
    public function loadFromConfig(): void
    {
        $config = Config::get('plugin', []);
        $plugins = $config['plugins'] ?? [];
        $enabled = $config['enabled'] ?? [];

        // 1) 显式配置注册
        foreach ($plugins as $item) {
            $key = (string)($item['key'] ?? '');
            $class = (string)($item['class'] ?? '');
            $manifest = (array)($item['manifest'] ?? []);
            if ($key === '' || $class === '') {
                continue;
            }

            $this->safeRegister($key, $class, $manifest);
        }

        // 2) 插件包表注册（如已执行扫描落库）
        try {
            $packageRepo = new PluginPackageRepository();
            $packages = $packageRepo->all();
            foreach ($packages as $package) {
                $key = (string)$package['plugin_key'];
                $class = (string)$package['main_class'];
                $manifest = (array)($package['manifest'] ?? []);
                $this->safeRegister($key, $class, $manifest);
            }
        } catch (\Throwable $e) {
            Log::info('插件包表不可用，跳过包表注册', ['error' => $e->getMessage()]);
        }

        // 3) 默认启用列表
        foreach ($enabled as $key) {
            try {
                $this->enable((string)$key);
            } catch (\Throwable $e) {
                Log::warning('默认启用插件失败', [
                    'plugin_key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 注册插件（显式调用）
     */
    public function register(string $key, string $class, array $manifest = []): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("插件类不存在：{$class}");
        }

        $plugin = new $class($key, $manifest);
        if (!$plugin instanceof CorePluginBaseService) {
            throw new \InvalidArgumentException("插件必须继承 CorePluginBaseService：{$class}");
        }

        $this->plugins[$key] = $plugin;

        // 同步持久化状态（如表不可用则保持默认未安装）
        $status = $this->syncInstallationState($key, 0, 0, $plugin);
        $this->pluginStatus[$key] = $status;
    }

    /**
     * 获取插件实例
     */
    public function get(string $key): ?CorePluginBaseService
    {
        return $this->plugins[$key] ?? null;
    }

    /**
     * 获取全部已注册插件
     *
     * @return array<string, CorePluginBaseService>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * 启用插件：安装（如必要）+ 注册 Hook/Provider + 状态落库
     */
    public function enable(string $key, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);

        // 持久化状态同步
        $this->syncInstallationState($key, $tenantId, $siteId, $plugin);

        $plugin->enable($this->hookManager, $this->dependencyManager);
        $this->pluginStatus[$key] = PluginStatus::ENABLED;
        $this->persistEnable($key, $tenantId, $siteId, $plugin);
    }

    /**
     * 禁用插件：标记停用并尝试卸载相关 Hook
     */
    public function disable(string $key, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);

        $plugin->disable();
        $this->pluginStatus[$key] = PluginStatus::DISABLED;

        if (method_exists($this->hookManager, 'removePluginHooks')) {
            $this->hookManager->removePluginHooks($key);
        }

        $this->persistDisable($key, $tenantId, $siteId);
    }

    /**
     * 卸载插件：标记卸载并尝试清理 Hook
     */
    public function uninstall(string $key, bool $keepData = false, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);

        $plugin->uninstall($keepData);
        $plugin->hydrateState(false, false);
        $this->pluginStatus[$key] = PluginStatus::UNINSTALLED;

        if (method_exists($this->hookManager, 'removePluginHooks')) {
            $this->hookManager->removePluginHooks($key);
        }

        $this->persistUninstall($key, $tenantId, $siteId);
    }

    /**
     * 调用 Action 钩子
     */
    public function doAction(string $tag, mixed ...$args): void
    {
        $this->hookManager->doAction($tag, ...$args);
    }

    /**
     * 应用 Filter 钩子
     */
    public function applyFilter(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $this->hookManager->applyFilter($tag, $value, ...$args);
    }

    /**
     * 获取插件当前状态
     */
    public function getStatus(string $key): PluginStatus
    {
        return $this->pluginStatus[$key] ?? PluginStatus::UNINSTALLED;
    }

    /**
     * 安全注册：捕获异常并记录
     */
    private function safeRegister(string $key, string $class, array $manifest): void
    {
        try {
            if (!isset($this->plugins[$key])) {
                $this->register($key, $class, $manifest);
            }
        } catch (\Throwable $e) {
            Log::warning('注册插件失败，已跳过', [
                'plugin_key' => $key,
                'class' => $class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 从安装表同步状态；表不可用时返回未安装
     */
    private function syncInstallationState(string $key, int $tenantId, int $siteId, CorePluginBaseService $plugin): PluginStatus
    {
        try {
            $row = $this->installationRepository->find($tenantId, $siteId, $key);
            if ($row === null) {
                return PluginStatus::UNINSTALLED;
            }

            $statusString = (string)($row['status'] ?? 'uninstalled');
            $status = match ($statusString) {
                'enabled' => PluginStatus::ENABLED,
                'disabled' => PluginStatus::DISABLED,
                'installed' => PluginStatus::INSTALLED,
                default => PluginStatus::UNINSTALLED,
            };

            $plugin->hydrateState(
                $status !== PluginStatus::UNINSTALLED,
                $status === PluginStatus::ENABLED
            );

            return $status;
        } catch (\Throwable $e) {
            Log::info('插件安装状态表不可用，保持内存状态', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
            // 表不可用时，返回当前运行时状态（避免误报为未安装）
            if ($plugin->isEnabled()) {
                return PluginStatus::ENABLED;
            }
            if ($plugin->isInstalled()) {
                return PluginStatus::INSTALLED;
            }
            return PluginStatus::UNINSTALLED;
        }
    }

    /**
     * 持久化启用状态（表缺失时忽略）
     */
    private function persistEnable(string $key, int $tenantId, int $siteId, CorePluginBaseService $plugin): void
    {
        try {
            $version = (string)($plugin->getManifest()['version'] ?? '0.0.0');
            $this->installationRepository->enable($tenantId, $siteId, $key, $version);
        } catch (\Throwable $e) {
            Log::info('插件启用状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
            $this->logger?->warning('插件启用状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 持久化禁用状态
     */
    private function persistDisable(string $key, int $tenantId, int $siteId): void
    {
        try {
            $this->installationRepository->disable($tenantId, $siteId, $key);
        } catch (\Throwable $e) {
            Log::info('插件禁用状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
            $this->logger?->warning('插件禁用状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 持久化卸载状态
     */
    private function persistUninstall(string $key, int $tenantId, int $siteId): void
    {
        try {
            $this->installationRepository->uninstall($tenantId, $siteId, $key);
        } catch (\Throwable $e) {
            Log::info('插件卸载状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
            $this->logger?->warning('插件卸载状态未能落库（将继续运行）', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 保证插件存在并返回
     */
    private function requirePlugin(string $key): CorePluginBaseService
    {
        $plugin = $this->get($key);
        if ($plugin === null) {
            throw new \InvalidArgumentException("插件未注册：{$key}");
        }

        return $plugin;
    }
}
