<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Contract;

use Infrastructure\Plugin\Enum\PluginStatus;
use Infrastructure\Plugin\Enum\PluginType;

/**
 * 插件接口 / Plugin interface
 *
 * 定义所有插件必须实现的基础接口 / Defines the base interface that all plugins must implement
 *
 * @package Infrastructure\Plugin\Contract
 */
interface PluginInterface
{
    /**
     * 获取插件路径 / Get plugin path
     *
     * @return string 插件路径 / Plugin path
     */
    public function getPath(): string;

    /**
     * 获取插件入口文件 / Get plugin entry file
     *
     * @return string 入口文件路径 / Entry file path
     */
    public function getEntryFile(): string;

    /**
     * 获取插件网站 / Get plugin website
     *
     * @return string 插件网站URL / Plugin website URL
     */
    public function getWebsite(): string;

    /**
     * 获取插件权限 / Get plugin permissions
     *
     * @return array<string> 权限列表 / Permission list
     */
    public function getPermissions(): array;

    /**
     * 获取插件标识符 / Get plugin identifier
     *
     * @return string 插件唯一标识符 / Unique plugin identifier
     */
    public function getIdentifier(): string;

    /**
     * 获取插件名称 / Get plugin name
     *
     * @return string 插件显示名称 / Plugin display name
     */
    public function getName(): string;

    /**
     * 获取插件版本 / Get plugin version
     *
     * @return string 插件版本号 / Plugin version number
     */
    public function getVersion(): string;

    /**
     * 获取插件描述 / Get plugin description
     *
     * @return string 插件描述信息 / Plugin description
     */
    public function getDescription(): string;

    /**
     * 获取插件作者 / Get plugin author
     *
     * @return string 插件作者 / Plugin author
     */
    public function getAuthor(): string;

    /**
     * 获取插件类型 / Get plugin type
     *
     * @return PluginType 插件类型 / Plugin type
     */
    public function getType(): PluginType;

    /**
     * 获取插件依赖 / Get plugin dependencies
     *
     * @return array 依赖列表 / List of dependencies
     */
    public function getDependencies(): array;

    /**
     * 获取插件状态 / Get plugin status
     *
     * @return PluginType 插件状态 / Plugin status
     */
    public function getStatus(): PluginStatus;

    /**
     * 设置插件状态 / Set plugin status
     *
     * @param PluginStatus $status 插件状态 / Plugin status
     * @return bool 设置是否成功 / Whether setting was successful
     */
    public function setStatus(PluginStatus $status): bool;

    /**
     * 安装插件 / Install plugin
     *
     * @param array $config 安装配置 / Installation configuration
     * @return bool 安装是否成功 / Whether installation was successful
     * @throws \Exception 安装失败时抛出异常 / Throws exception when installation fails
     */
    public function install(array $config = []): bool;

    /**
     * 激活插件 / Activate plugin
     *
     * @param array $config 激活配置 / Activation configuration
     * @return bool 激活是否成功 / Whether activation was successful
     * @throws \Exception 激活失败时抛出异常 / Throws exception when activation fails
     */
    public function activate(array $config = []): bool;

    /**
     * 停用插件 / Deactivate plugin
     *
     * @return bool 停用是否成功 / Whether deactivation was successful
     * @throws \Exception 停用失败时抛出异常 / Throws exception when deactivation fails
     */
    public function deactivate(): bool;

    /**
     * 卸载插件 / Uninstall plugin
     *
     * @param bool $removeData 是否删除数据 / Whether to remove data
     * @return bool 卸载是否成功 / Whether uninstallation was successful
     * @throws \Exception 卸载失败时抛出异常 / Throws exception when uninstallation fails
     */
    public function uninstall(bool $removeData = false): bool;

    /**
     * 获取插件配置 / Get plugin configuration
     *
     * @return array 插件配置 / Plugin configuration
     */
    public function getConfig(): array;

    /**
     * 设置插件配置 / Set plugin configuration
     *
     * @param array $config 插件配置 / Plugin configuration
     * @return bool 设置是否成功 / Whether setting was successful
     */
    public function setConfig(array $config): bool;

    /**
     * 验证插件配置 / Validate plugin configuration
     *
     * @param array $config 插件配置 / Plugin configuration
     * @return array 验证结果 / Validation result
     */
    public function validateConfig(array $config): array;

    /**
     * 获取插件元数据 / Get plugin metadata
     *
     * @return array 插件元数据 / Plugin metadata
     */
    public function getMetadata(): array;

    /**
     * 检查插件是否已安装 / Check if plugin is installed
     *
     * @return bool 是否已安装 / Whether installed
     */
    public function isInstalled(): bool;

    /**
     * 检查插件是否已激活 / Check if plugin is activated
     *
     * @return bool 是否已激活 / Whether activated
     */
    public function isActivated(): bool;

    /**
     * 获取插件入口点 / Get plugin entry points
     *
     * @return array 入口点列表 / List of entry points
     */
    public function getEntryPoints(): array;

    /**
     * 执行插件生命周期钩子 / Execute plugin lifecycle hook
     *
     * @param string $hook 钩子名称 / Hook name
     * @param array $args 钩子参数 / Hook arguments
     * @return mixed 钩子执行结果 / Hook execution result
     */
    public function executeHook(string $hook, array $args = []): mixed;

    /**
     * 获取插件资源路径 / Get plugin resource paths
     *
     * @return array 资源路径 / Resource paths
     */
    public function getResourcePaths(): array;

    /**
     * 获取插件权限要求 / Get plugin permission requirements
     *
     * @return array 权限要求 / Permission requirements
     */
    public function getPermissionRequirements(): array;

    /**
     * 检查插件权限 / Check plugin permissions
     *
     * @param array $userPermissions 用户权限 / User permissions
     * @return bool 是否有权限 / Whether has permissions
     */
    public function checkPermissions(array $userPermissions): bool;

    /**
     * 获取插件数据库迁移 / Get plugin database migrations
     *
     * @return array 迁移列表 / List of migrations
     */
    public function getMigrations(): array;

    /**
     * 获取插件菜单项 / Get plugin menu items
     *
     * @return array 菜单项 / Menu items
     */
    public function getMenuItems(): array;

    /**
     * 获取插件API路由 / Get plugin API routes
     *
     * @return array API路由 / API routes
     */
    public function getApiRoutes(): array;

    /**
     * 获取插件前端组件 / Get plugin frontend components
     *
     * @return array 前端组件 / Frontend components
     */
    public function getFrontendComponents(): array;

    /**
     * 获取插件钩子注册 / Get plugin hook registrations
     *
     * @return array 钩子注册 / Hook registrations
     */
    public function getHookRegistrations(): array;

    /**
     * 获取插件服务提供者 / Get plugin service providers
     *
     * @return array 服务提供者 / Service providers
     */
    public function getServiceProviders(): array;

    /**
     * 获取插件中间件 / Get plugin middleware
     *
     * @return array 中间件 / Middleware
     */
    public function getMiddleware(): array;

    /**
     * 获取插件事件监听器 / Get plugin event listeners
     *
     * @return array 事件监听器 / Event listeners
     */
    public function getEventListeners(): array;

    /**
     * 获取插件计划任务 / Get plugin scheduled tasks
     *
     * @return array 计划任务 / Scheduled tasks
     */
    public function getScheduledTasks(): array;

    /**
     * 获取插件缓存键 / Get plugin cache keys
     *
     * @return array 缓存键 / Cache keys
     */
    public function getCacheKeys(): array;

    /**
     * 清理插件缓存 / Clear plugin cache
     *
     * @return bool 清理是否成功 / Whether clearing was successful
     */
    public function clearCache(): bool;

    /**
     * 获取插件日志级别 / Get plugin log level
     *
     * @return string 日志级别 / Log level
     */
    public function getLogLevel(): string;

    /**
     * 设置插件日志级别 / Set plugin log level
     *
     * @param string $level 日志级别 / Log level
     * @return bool 设置是否成功 / Whether setting was successful
     */
    public function setLogLevel(string $level): bool;

    /**
     * 记录插件日志 / Log plugin message
     *
     * @param string $level 日志级别 / Log level
     * @param string $message 日志消息 / Log message
     * @param array $context 上下文信息 / Context information
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * 获取插件性能指标 / Get plugin performance metrics
     *
     * @return array 性能指标 / Performance metrics
     */
    public function getPerformanceMetrics(): array;

    /**
     * 重置插件性能指标 / Reset plugin performance metrics
     *
     * @return bool 重置是否成功 / Whether reset was successful
     */
    public function resetPerformanceMetrics(): bool;

    /**
     * 获取插件健康状态 / Get plugin health status
     *
     * @return array 健康状态 / Health status
     */
    public function getHealthStatus(): array;

    /**
     * 执行插件健康检查 / Execute plugin health check
     *
     * @return bool 健康检查是否通过 / Whether health check passed
     */
    public function healthCheck(): bool;

    /**
     * 获取插件备份信息 / Get plugin backup information
     *
     * @return array 备份信息 / Backup information
     */
    public function getBackupInfo(): array;

    /**
     * 创建插件备份 / Create plugin backup
     *
     * @param string $backupPath 备份路径 / Backup path
     * @return bool 备份是否成功 / Whether backup was successful
     */
    public function createBackup(string $backupPath): bool;

    /**
     * 恢复插件备份 / Restore plugin backup
     *
     * @param string $backupPath 备份路径 / Backup path
     * @return bool 恢复是否成功 / Whether restore was successful
     */
    public function restoreBackup(string $backupPath): bool;

    /**
     * 获取插件更新信息 / Get plugin update information
     *
     * @return array 更新信息 / Update information
     */
    public function getUpdateInfo(): array;

    /**
     * 检查插件更新 / Check plugin updates
     *
     * @return array 更新检查结果 / Update check result
     */
    public function checkUpdates(): array;

    /**
     * 执行插件更新 / Execute plugin update
     *
     * @param string $newVersion 新版本 / New version
     * @param string $updateFile 更新文件 / Update file
     * @return bool 更新是否成功 / Whether update was successful
     */
    public function update(string $newVersion, string $updateFile): bool;

    /**
     * 获取插件统计信息 / Get plugin statistics
     *
     * @return array 统计信息 / Statistics
     */
    public function getStatistics(): array;

    /**
     * 重置插件统计信息 / Reset plugin statistics
     *
     * @return bool 重置是否成功 / Whether reset was successful
     */
    public function resetStatistics(): bool;

    /**
     * 获取插件错误信息 / Get plugin error information
     *
     * @return array 错误信息 / Error information
     */
    public function getErrors(): array;

    /**
     * 清理插件错误 / Clear plugin errors
     *
     * @return bool 清理是否成功 / Whether clearing was successful
     */
    public function clearErrors(): bool;

    /**
     * 获取插件调试信息 / Get plugin debug information
     *
     * @return array 调试信息 / Debug information
     */
    public function getDebugInfo(): array;

    /**
     * 启用插件调试模式 / Enable plugin debug mode
     *
     * @param bool $enabled 是否启用 / Whether to enable
     * @return bool 设置是否成功 / Whether setting was successful
     */
    public function enableDebugMode(bool $enabled): bool;

    /**
     * 检查插件调试模式是否启用 / Check if plugin debug mode is enabled
     *
     * @return bool 是否启用 / Whether enabled
     */
    public function isDebugEnabled(): bool;

    /**
     * 获取插件提供的服务列表
     * Get services provided by plugin
     *
     * @return array<string, mixed> 服务名 => 服务实例的映射
     */
    public function getServices(): array;
}
