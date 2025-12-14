<?php

declare(strict_types=1);

namespace Infrastructure\Application;

/**
 * Application Interface | 应用接口
 *
 * Defines the contract for all applications in the AlkaidSYS ecosystem.
 * 定义 AlkaidSYS 生态系统中所有应用的契约。
 *
 * Applications are complete business modules that can be independently
 * installed, configured, enabled/disabled, upgraded, and uninstalled.
 * 应用是完整的业务模块，可以独立安装、配置、启用/禁用、升级和卸载。
 *
 * @package Infrastructure\Application
 * @see design/02-app-plugin-ecosystem/06-1-application-system-design.md
 */
interface ApplicationInterface
{
    /**
     * Get the unique key of the application | 获取应用的唯一标识
     *
     * @return string Application key (e.g., 'ecommerce', 'oa', 'crm')
     */
    public function getKey(): string;

    /**
     * Get the display name of the application | 获取应用的显示名称
     *
     * @return string Application name
     */
    public function getName(): string;

    /**
     * Get the version of the application | 获取应用版本
     *
     * @return string Semantic version (e.g., '1.0.0')
     */
    public function getVersion(): string;

    /**
     * Get the root path of the application | 获取应用根目录路径
     *
     * @return string Absolute path to the application directory
     */
    public function getPath(): string;

    /**
     * Install the application | 安装应用
     *
     * This method should:
     * - Execute SQL installation scripts
     * - Register menus and permissions
     * - Initialize configuration
     * - Create default data
     * - Trigger ApplicationInstalled event
     *
     * @return void
     * @throws \RuntimeException If installation fails
     */
    public function install(): void;

    /**
     * Uninstall the application | 卸载应用
     *
     * This method should:
     * - Check for dependent plugins
     * - Execute SQL uninstallation scripts
     * - Remove menus and permissions
     * - Clear configuration
     * - Trigger ApplicationUninstalled event
     *
     * @param bool $keepData Whether to keep application data | 是否保留应用数据
     * @return void
     * @throws \RuntimeException If uninstallation fails
     */
    public function uninstall(bool $keepData = false): void;

    /**
     * Enable the application | 启用应用
     *
     * This method should:
     * - Enable routes
     * - Enable menus
     * - Enable scheduled tasks
     * - Trigger ApplicationEnabled event
     *
     * @return void
     * @throws \RuntimeException If enabling fails
     */
    public function enable(): void;

    /**
     * Disable the application | 禁用应用
     *
     * This method should:
     * - Disable routes
     * - Disable menus
     * - Disable scheduled tasks
     * - Trigger ApplicationDisabled event
     *
     * @return void
     * @throws \RuntimeException If disabling fails
     */
    public function disable(): void;

    /**
     * Upgrade the application | 升级应用
     *
     * This method should:
     * - Backup data
     * - Execute upgrade scripts
     * - Update menus and permissions
     * - Update configuration
     * - Trigger ApplicationUpgraded event
     *
     * @param string $fromVersion Current version | 当前版本
     * @param string $toVersion   Target version | 目标版本
     * @return void
     * @throws \RuntimeException If upgrade fails
     */
    public function upgrade(string $fromVersion, string $toVersion): void;

    /**
     * Get the application manifest | 获取应用清单
     *
     * Returns the parsed manifest.json content.
     * 返回解析后的 manifest.json 内容。
     *
     * @return array<string, mixed> Manifest data
     */
    public function getManifest(): array;

    /**
     * Check if the application is installed | 检查应用是否已安装
     *
     * @return bool
     */
    public function isInstalled(): bool;

    /**
     * Check if the application is enabled | 检查应用是否已启用
     *
     * @return bool
     */
    public function isEnabled(): bool;
}
