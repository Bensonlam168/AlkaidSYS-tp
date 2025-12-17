<?php

declare(strict_types=1);

namespace Infrastructure\Plugin;

use Infrastructure\DI\DependencyManager;
use Infrastructure\Plugin\Hook\HookManager;
use Infrastructure\Plugin\Repository\PluginInstallationRepository;
use Infrastructure\Plugin\Repository\PluginPackageRepository;
use think\App;
use think\facade\Config;
use think\facade\Log;

/**
 * PluginManager | 插件管理器（T-035 基础版）
 *
 * 说明：
 * - 本版本以“最小可用”为目标：支持注册/加载、启用/禁用、生命周期调用
 * - 插件状态持久化（DB 表）与插件包签名/下载等能力不在本任务范围内
 */
class PluginManager
{
    /**
     * @var array<string, CorePluginBaseService>
     */
    protected array $plugins = [];

    protected App $app;
    protected HookManager $hookManager;
    protected DependencyManager $dependencyManager;
    protected PluginPackageRepository $packageRepo;
    protected PluginInstallationRepository $installationRepo;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->hookManager = HookManager::instance();
        $this->dependencyManager = new DependencyManager($app);
        $this->packageRepo = new PluginPackageRepository();
        $this->installationRepo = new PluginInstallationRepository();
    }

    /**
     * 从配置加载插件（config/plugin.php）
     */
    public function loadFromConfig(): void
    {
        // 1) 先从 config 注册（显式声明）
        $this->registerFromConfig();

        // 2) 尝试从 DB 注册（扫描结果）
        $this->registerFromPackages();

        // 3) 默认启用列表
        $this->enableFromConfig();
    }

    /**
     * 手动注册插件（用于测试/明确声明的插件）
     *
     * @param class-string<CorePluginBaseService> $pluginClass
     * @param array<string, mixed> $manifest
     */
    public function register(string $key, string $pluginClass, array $manifest = []): void
    {
        if (!class_exists($pluginClass)) {
            throw new \InvalidArgumentException("Plugin class not found: {$pluginClass}");
        }

        $plugin = $this->app->invokeClass($pluginClass, [$key, $manifest]);
        if (!$plugin instanceof CorePluginBaseService) {
            throw new \InvalidArgumentException('Plugin must extend CorePluginBaseService');
        }

        $this->plugins[$key] = $plugin;
        // 补充状态：若安装表存在记录则同步（表未迁移时需允许启动降级）
        try {
            $install = $this->installationRepo->find(0, 0, $key);
            if ($install) {
                $plugin->hydrateState(
                    $install['status'] !== 'uninstalled',
                    $install['status'] === 'enabled'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Hydrate plugin state failed (table may not exist)', [
                'plugin_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取插件实例
     */
    public function get(string $key): ?CorePluginBaseService
    {
        return $this->plugins[$key] ?? null;
    }

    /**
     * 启用插件
     */
    public function enable(string $key, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);
        $plugin->enable($this->hookManager, $this->dependencyManager);
        $this->installationRepo->enable($tenantId, $siteId, $key, $plugin->getManifest()['version'] ?? '0.0.0');
    }

    /**
     * 禁用插件
     */
    public function disable(string $key, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);
        $plugin->disable();
        $this->installationRepo->disable($tenantId, $siteId, $key);
    }

    /**
     * 安装插件
     */
    public function install(string $key, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);
        $plugin->install();
        $this->installationRepo->install($tenantId, $siteId, $key, $plugin->getManifest()['version'] ?? '0.0.0');
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $key, bool $keepData = false, int $tenantId = 0, int $siteId = 0): void
    {
        $plugin = $this->requirePlugin($key);
        $plugin->uninstall($keepData);
        $this->installationRepo->uninstall($tenantId, $siteId, $key);
    }

    /**
     * 对外暴露 Action Hook 触发入口（业务代码可选择直接调用 do_action() helper）
     */
    public function doAction(string $tag, mixed ...$args): void
    {
        $this->hookManager->doAction($tag, ...$args);
    }

    /**
     * 对外暴露 Filter Hook 入口
     */
    public function applyFilter(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $this->hookManager->applyFilter($tag, $value, ...$args);
    }

    /**
     * @return array<string, CorePluginBaseService>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    protected function requirePlugin(string $key): CorePluginBaseService
    {
        $plugin = $this->get($key);
        if ($plugin === null) {
            throw new \InvalidArgumentException("Plugin not registered: {$key}");
        }
        return $plugin;
    }

    /**
     * 从 config 声明注册插件
     */
    protected function registerFromConfig(): void
    {
        $items = Config::get('plugin.plugins', []);
        foreach ($items as $item) {
            $key = (string)($item['key'] ?? '');
            $class = $item['class'] ?? null;
            $manifest = is_array($item['manifest'] ?? null) ? $item['manifest'] : [];
            if ($key === '' || !is_string($class) || $class === '') {
                continue;
            }

            try {
                $this->register($key, $class, $manifest);
            } catch (\Throwable $e) {
                Log::warning('Register plugin from config failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 根据 DB 插件包表注册插件
     */
    protected function registerFromPackages(): void
    {
        $packages = [];
        try {
            $packages = $this->packageRepo->all();
        } catch (\Throwable $e) {
            Log::warning('Load plugin packages failed (table may not exist)', [
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($packages as $pkg) {
            $class = $pkg['main_class'] ?? null;
            if (!is_string($class) || $class === '') {
                continue;
            }

            // 仅当未被 config 注册时再注册，避免覆盖
            if (isset($this->plugins[$pkg['plugin_key']])) {
                continue;
            }

            try {
                $this->register($pkg['plugin_key'], $class, $pkg['manifest'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('Register plugin from package failed', [
                    'key' => $pkg['plugin_key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 根据 config enabled 列表启用插件
     */
    protected function enableFromConfig(): void
    {
        $enabled = Config::get('plugin.enabled', []);
        foreach ($enabled as $key) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            if (!isset($this->plugins[$key])) {
                continue;
            }
            try {
                $this->enable($key);
            } catch (\Throwable $e) {
                Log::warning('Auto enable plugin failed', [
                    'plugin_key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
