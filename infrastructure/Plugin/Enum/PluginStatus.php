<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Enum;

/**
 * 插件状态枚举 / Plugin status enumeration
 *
 * 定义插件在系统中的各种状态 / Defines various states of plugins in the system
 *
 * @package Infrastructure\Plugin\Enum
 */
enum PluginStatus: string
{
    /**
     * 未安装状态 / Uninstalled status
     */
    case UNINSTALLED = 'uninstalled';

    /**
     * 已安装状态 / Installed status
     */
    case INSTALLED = 'installed';

    /**
     * 已激活状态 / Enabled status
     */
    case ENABLED = 'enabled';

    /**
     * 已停用状态 / Disabled status
     */
    case DISABLED = 'disabled';

    /**
     * 错误状态 / Error status
     */
    case ERROR = 'error';

    /**
     * 更新中状态 / Updating status
     */
    case UPDATING = 'updating';

    /**
     * 安装中状态 / Installing status
     */
    case INSTALLING = 'installing';

    /**
     * 卸载中状态 / Uninstalling status
     */
    case UNINSTALLING = 'uninstalling';

    /**
     * 获取状态的中文名称 / Get Chinese name of status
     */
    public function getChineseName(): string
    {
        return match($this) {
            self::UNINSTALLED => '未安装',
            self::INSTALLED => '已安装',
            self::ENABLED => '已激活',
            self::DISABLED => '已停用',
            self::ERROR => '错误',
            self::UPDATING => '更新中',
            self::INSTALLING => '安装中',
            self::UNINSTALLING => '卸载中',
        };
    }

    /**
     * 检查是否为活跃状态 / Check if status is active
     */
    public function isActive(): bool
    {
        return $this === self::ENABLED;
    }

    /**
     * 检查是否为安装状态 / Check if status is installed
     */
    public function isInstalled(): bool
    {
        return match($this) {
            self::INSTALLED, self::ENABLED, self::DISABLED, self::ERROR => true,
            default => false,
        };
    }

    /**
     * 检查是否为过渡状态 / Check if status is transitional
     */
    public function isTransitional(): bool
    {
        return match($this) {
            self::INSTALLING, self::UPDATING, self::UNINSTALLING => true,
            default => false,
        };
    }

    /**
     * 获取可转换的状态 / Get transitionable statuses
     */
    public function getTransitionableStatuses(): array
    {
        return match($this) {
            self::UNINSTALLED => [self::INSTALLING],
            self::INSTALLING => [self::INSTALLED, self::ERROR],
            self::INSTALLED => [self::ENABLED, self::UNINSTALLING],
            self::ENABLED => [self::DISABLED, self::UPDATING, self::UNINSTALLING],
            self::DISABLED => [self::ENABLED, self::UNINSTALLING],
            self::UPDATING => [self::ENABLED, self::ERROR],
            self::UNINSTALLING => [self::UNINSTALLED, self::ERROR],
            self::ERROR => [self::ENABLED, self::DISABLED, self::UNINSTALLING],
        };
    }
}
