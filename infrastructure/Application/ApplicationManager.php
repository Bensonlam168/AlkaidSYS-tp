<?php

declare(strict_types=1);

namespace Infrastructure\Application;

use think\facade\Db;

/**
 * Application Manager | 应用管理器
 *
 * Manages application lifecycle including discovery, registration,
 * installation, enabling/disabling, and uninstallation.
 * 管理应用生命周期，包括发现、注册、安装、启用/禁用和卸载。
 *
 * @package Infrastructure\Application
 * @see design/02-app-plugin-ecosystem/06-1-application-system-design.md
 */
class ApplicationManager
{
    /**
     * Base path for applications | 应用基础路径
     */
    protected string $basePath;

    /**
     * Registered applications | 已注册的应用
     *
     * @var array<string, ApplicationInterface>
     */
    protected array $applications = [];

    /**
     * Application class cache | 应用类缓存
     *
     * @var array<string, class-string<ApplicationInterface>>
     */
    protected array $applicationClasses = [];

    /**
     * Constructor | 构造函数
     *
     * @param string $basePath Base path for applications (default: addons/apps)
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath ?: root_path('addons/apps');
    }

    /**
     * Discover all applications in the base path | 发现基础路径中的所有应用
     *
     * Scans the addons/apps directory for valid applications.
     * 扫描 addons/apps 目录以查找有效应用。
     *
     * @return array<string, array<string, mixed>> Array of discovered applications
     */
    public function discover(): array
    {
        $discovered = [];

        if (!is_dir($this->basePath)) {
            return $discovered;
        }

        $directories = scandir($this->basePath);
        if ($directories === false) {
            return $discovered;
        }

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $appPath = $this->basePath . '/' . $dir;
            if (!is_dir($appPath)) {
                continue;
            }

            $manifestPath = $appPath . '/manifest.json';
            if (!file_exists($manifestPath)) {
                continue;
            }

            $content = file_get_contents($manifestPath);
            if ($content === false) {
                continue;
            }

            $manifest = json_decode($content, true);
            if (!is_array($manifest) || !isset($manifest['key'])) {
                continue;
            }

            $discovered[$manifest['key']] = [
                'path' => $appPath,
                'manifest' => $manifest,
            ];
        }

        return $discovered;
    }

    /**
     * Register an application | 注册应用
     *
     * @param ApplicationInterface $application Application instance | 应用实例
     * @return self
     */
    public function register(ApplicationInterface $application): self
    {
        $this->applications[$application->getKey()] = $application;
        return $this;
    }

    /**
     * Register application class for lazy loading | 注册应用类以延迟加载
     *
     * @param string                              $key   Application key | 应用标识
     * @param class-string<ApplicationInterface> $class Application class | 应用类
     * @return self
     */
    public function registerClass(string $key, string $class): self
    {
        $this->applicationClasses[$key] = $class;
        return $this;
    }

    /**
     * Get an application by key | 通过标识获取应用
     *
     * @param string $key Application key | 应用标识
     * @return ApplicationInterface|null
     */
    public function get(string $key): ?ApplicationInterface
    {
        // Return cached instance if available
        if (isset($this->applications[$key])) {
            return $this->applications[$key];
        }

        // Try to instantiate from registered class
        if (isset($this->applicationClasses[$key])) {
            $class = $this->applicationClasses[$key];
            $discovered = $this->discover();
            $path = $discovered[$key]['path'] ?? '';
            $this->applications[$key] = new $class($path);
            return $this->applications[$key];
        }

        // Try to discover and load
        $discovered = $this->discover();
        if (isset($discovered[$key])) {
            return $this->loadApplication($key, $discovered[$key]['path']);
        }

        return null;
    }

    /**
     * Get all registered applications | 获取所有已注册的应用
     *
     * @return array<string, ApplicationInterface>
     */
    public function all(): array
    {
        return $this->applications;
    }

    /**
     * Load application from path | 从路径加载应用
     *
     * @param string $key  Application key | 应用标识
     * @param string $path Application path | 应用路径
     * @return ApplicationInterface|null
     */
    protected function loadApplication(string $key, string $path): ?ApplicationInterface
    {
        $applicationFile = $path . '/Application.php';
        if (!file_exists($applicationFile)) {
            return null;
        }

        // Determine namespace from manifest or directory name
        $namespace = 'addon\\' . $key;
        $className = $namespace . '\\Application';

        // Autoload the class if not already loaded
        if (!class_exists($className)) {
            require_once $applicationFile;
        }

        if (!class_exists($className)) {
            return null;
        }

        $application = new $className($path);
        if ($application instanceof ApplicationInterface) {
            $this->applications[$key] = $application;
            return $application;
        }

        return null;
    }

    /**
     * Install an application | 安装应用
     *
     * @param string $key Application key | 应用标识
     * @return bool
     * @throws \RuntimeException If installation fails
     */
    public function install(string $key): bool
    {
        $application = $this->get($key);
        if ($application === null) {
            throw new \RuntimeException("Application not found: {$key}");
        }

        if ($application->isInstalled()) {
            throw new \RuntimeException("Application already installed: {$key}");
        }

        Db::startTrans();
        try {
            $application->install();
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \RuntimeException('Failed to install application: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Uninstall an application | 卸载应用
     *
     * @param string $key      Application key | 应用标识
     * @param bool   $keepData Whether to keep data | 是否保留数据
     * @return bool
     * @throws \RuntimeException If uninstallation fails
     */
    public function uninstall(string $key, bool $keepData = false): bool
    {
        $application = $this->get($key);
        if ($application === null) {
            throw new \RuntimeException("Application not found: {$key}");
        }

        if (!$application->isInstalled()) {
            throw new \RuntimeException("Application not installed: {$key}");
        }

        Db::startTrans();
        try {
            $application->uninstall($keepData);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \RuntimeException('Failed to uninstall application: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Enable an application | 启用应用
     *
     * @param string $key Application key | 应用标识
     * @return bool
     * @throws \RuntimeException If enabling fails
     */
    public function enable(string $key): bool
    {
        $application = $this->get($key);
        if ($application === null) {
            throw new \RuntimeException("Application not found: {$key}");
        }

        if (!$application->isInstalled()) {
            throw new \RuntimeException("Application not installed: {$key}");
        }

        if ($application->isEnabled()) {
            return true; // Already enabled
        }

        $application->enable();
        return true;
    }

    /**
     * Disable an application | 禁用应用
     *
     * @param string $key Application key | 应用标识
     * @return bool
     * @throws \RuntimeException If disabling fails
     */
    public function disable(string $key): bool
    {
        $application = $this->get($key);
        if ($application === null) {
            throw new \RuntimeException("Application not found: {$key}");
        }

        if (!$application->isEnabled()) {
            return true; // Already disabled
        }

        $application->disable();
        return true;
    }

    /**
     * Upgrade an application | 升级应用
     *
     * @param string $key       Application key | 应用标识
     * @param string $toVersion Target version | 目标版本
     * @return bool
     * @throws \RuntimeException If upgrade fails
     */
    public function upgrade(string $key, string $toVersion): bool
    {
        $application = $this->get($key);
        if ($application === null) {
            throw new \RuntimeException("Application not found: {$key}");
        }

        if (!$application->isInstalled()) {
            throw new \RuntimeException("Application not installed: {$key}");
        }

        $fromVersion = $application->getVersion();
        if (version_compare($fromVersion, $toVersion, '>=')) {
            throw new \RuntimeException('Target version must be higher than current version');
        }

        Db::startTrans();
        try {
            $application->upgrade($fromVersion, $toVersion);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \RuntimeException('Failed to upgrade application: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get installed applications from database | 从数据库获取已安装的应用
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstalled(): array
    {
        return Db::name('applications')->select()->toArray();
    }

    /**
     * Get enabled applications from database | 从数据库获取已启用的应用
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEnabled(): array
    {
        return Db::name('applications')->where('status', 1)->select()->toArray();
    }

    /**
     * Check if an application exists | 检查应用是否存在
     *
     * @param string $key Application key | 应用标识
     * @return bool
     */
    public function exists(string $key): bool
    {
        $discovered = $this->discover();
        return isset($discovered[$key]);
    }

    /**
     * Get the base path for applications | 获取应用基础路径
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
