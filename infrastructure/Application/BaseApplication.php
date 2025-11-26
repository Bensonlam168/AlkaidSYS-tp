<?php

declare(strict_types=1);

namespace Infrastructure\Application;

use think\facade\Db;

/**
 * Base Application | 应用基类
 *
 * Abstract base class for all applications in the AlkaidSYS ecosystem.
 * Provides common functionality for application lifecycle management.
 * AlkaidSYS 生态系统中所有应用的抽象基类。
 * 提供应用生命周期管理的通用功能。
 *
 * @package Infrastructure\Application
 * @see design/02-app-plugin-ecosystem/06-1-application-system-design.md
 */
abstract class BaseApplication implements ApplicationInterface
{
    /**
     * Application key | 应用标识
     */
    protected string $key = '';

    /**
     * Application name | 应用名称
     */
    protected string $name = '';

    /**
     * Application version | 应用版本
     */
    protected string $version = '1.0.0';

    /**
     * Application root path | 应用根目录
     */
    protected string $path = '';

    /**
     * Cached manifest data | 缓存的清单数据
     *
     * @var array<string, mixed>|null
     */
    protected ?array $manifest = null;

    /**
     * Constructor | 构造函数
     *
     * @param string $path Application root path | 应用根目录路径
     */
    public function __construct(string $path = '')
    {
        if ($path !== '') {
            $this->path = rtrim($path, '/\\');
        }
        $this->loadManifest();
    }

    /**
     * {@inheritdoc}
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getManifest(): array
    {
        if ($this->manifest === null) {
            $this->loadManifest();
        }
        return $this->manifest ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function isInstalled(): bool
    {
        // Check if application record exists in database
        $record = Db::name('applications')
            ->where('app_key', $this->key)
            ->find();
        return $record !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        $record = Db::name('applications')
            ->where('app_key', $this->key)
            ->where('status', 1)
            ->find();
        return $record !== null;
    }

    /**
     * Load manifest from manifest.json | 从 manifest.json 加载清单
     *
     * @return void
     */
    protected function loadManifest(): void
    {
        $manifestPath = $this->path . '/manifest.json';
        if (file_exists($manifestPath)) {
            $content = file_get_contents($manifestPath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $this->manifest = $data;
                    $this->key = $data['key'] ?? $this->key;
                    $this->name = $data['name'] ?? $this->name;
                    $this->version = $data['version'] ?? $this->version;
                }
            }
        }
    }

    /**
     * Execute SQL file | 执行 SQL 文件
     *
     * @param string $relativePath Relative path to SQL file | SQL 文件相对路径
     * @return void
     * @throws \RuntimeException If SQL file not found or execution fails
     */
    protected function executeSqlFile(string $relativePath): void
    {
        $sqlPath = $this->path . '/' . ltrim($relativePath, '/');
        if (!file_exists($sqlPath)) {
            throw new \RuntimeException("SQL file not found: {$sqlPath}");
        }
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new \RuntimeException("Failed to read SQL file: {$sqlPath}");
        }
        // Split by semicolon and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                Db::execute($statement);
            }
        }
    }

    /**
     * Install menu from config file | 从配置文件安装菜单
     *
     * @param string $relativePath Relative path to menu config | 菜单配置相对路径
     * @return void
     */
    protected function installMenu(string $relativePath = 'config/menu.php'): void
    {
        $menuPath = $this->path . '/' . ltrim($relativePath, '/');
        if (file_exists($menuPath)) {
            $menus = include $menuPath;
            if (is_array($menus)) {
                $this->registerMenus($menus);
            }
        }
    }

    /**
     * Register menus to database | 注册菜单到数据库
     *
     * @param array<int, array<string, mixed>> $menus Menu configuration | 菜单配置
     * @return void
     */
    protected function registerMenus(array $menus): void
    {
        // Implementation depends on menu system
        // This is a placeholder for the actual menu registration logic
        foreach ($menus as $menu) {
            $menu['app_key'] = $this->key;
            $menu['created_at'] = date('Y-m-d H:i:s');
            Db::name('menus')->insert($menu);
        }
    }

    /**
     * Uninstall menu | 卸载菜单
     *
     * @return void
     */
    protected function uninstallMenu(): void
    {
        Db::name('menus')->where('app_key', $this->key)->delete();
    }

    /**
     * Initialize application configuration | 初始化应用配置
     *
     * @return void
     */
    protected function initConfig(): void
    {
        $manifest = $this->getManifest();
        if (isset($manifest['config']) && is_array($manifest['config'])) {
            foreach ($manifest['config'] as $key => $value) {
                Db::name('app_configs')->insert([
                    'app_key' => $this->key,
                    'config_key' => $key,
                    'config_value' => is_array($value) ? json_encode($value) : (string) $value,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Clear application configuration | 清理应用配置
     *
     * @return void
     */
    protected function clearConfig(): void
    {
        Db::name('app_configs')->where('app_key', $this->key)->delete();
    }

    /**
     * Trigger application event | 触发应用事件
     *
     * @param string               $eventName Event name | 事件名称
     * @param array<string, mixed> $data      Event data | 事件数据
     * @return void
     */
    protected function triggerEvent(string $eventName, array $data = []): void
    {
        $data['app_key'] = $this->key;
        $data['app_version'] = $this->version;
        event($eventName, $data);
    }

    /**
     * Update application status in database | 更新数据库中的应用状态
     *
     * @param int $status Status value (0=disabled, 1=enabled) | 状态值
     * @return void
     */
    protected function updateStatus(int $status): void
    {
        Db::name('applications')
            ->where('app_key', $this->key)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Record application installation | 记录应用安装
     *
     * @return void
     */
    protected function recordInstallation(): void
    {
        Db::name('applications')->insert([
            'app_key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'status' => 1,
            'installed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove application record | 移除应用记录
     *
     * @return void
     */
    protected function removeInstallationRecord(): void
    {
        Db::name('applications')->where('app_key', $this->key)->delete();
    }
}
