<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Enum;

/**
 * 插件类型枚举 / Plugin type enumeration
 *
 * 定义系统支持的三种插件类型 / Defines three types of plugins supported by the system
 *
 * @package Infrastructure\Plugin\Enum
 */
enum PluginType: string
{
    /**
     * 钩子插件类型 / Hook plugin type
     * 通过钩子系统与核心功能交互 / Interacts with core functionality through hook system
     */
    case HOOK = 'hook';

    /**
     * iframe插件类型 / Iframe plugin type
     * 通过iframe嵌入外部页面 / Embeds external pages through iframe
     */
    case IFRAME = 'iframe';

    /**
     * 组件插件类型 / Component plugin type
     * 提供前端组件 / Provides frontend components
     */
    case COMPONENT = 'component';

    /**
     * 获取类型的中文名称 / Get Chinese name of type
     */
    public function getChineseName(): string
    {
        return match($this) {
            self::HOOK => '钩子插件',
            self::IFRAME => 'iframe插件',
            self::COMPONENT => '组件插件',
        };
    }

    /**
     * 获取类型的描述 / Get description of type
     */
    public function getDescription(): string
    {
        return match($this) {
            self::HOOK => '通过钩子系统与核心功能交互的插件',
            self::IFRAME => '通过iframe嵌入外部页面的插件',
            self::COMPONENT => '提供前端组件的插件',
        };
    }

    /**
     * 检查是否为钩子类型 / Check if type is hook
     */
    public function isHook(): bool
    {
        return $this === self::HOOK;
    }

    /**
     * 检查是否为iframe类型 / Check if type is iframe
     */
    public function isIframe(): bool
    {
        return $this === self::IFRAME;
    }

    /**
     * 检查是否为组件类型 / Check if type is component
     */
    public function isComponent(): bool
    {
        return $this === self::COMPONENT;
    }

    /**
     * 获取该类型插件的主要文件 / Get main files for this plugin type
     */
    public function getMainFiles(): array
    {
        return match($this) {
            self::HOOK => ['hooks.php', 'src/Hooks.php', 'config/hooks.php'],
            self::IFRAME => ['index.html', 'iframe.html', 'web/index.html'],
            self::COMPONENT => ['components', 'src/Components', 'vue/components'],
        };
    }

    /**
     * 获取该类型插件的配置键 / Get configuration keys for this plugin type
     */
    public function getConfigKeys(): array
    {
        return match($this) {
            self::HOOK => ['hooks', 'hook_priorities'],
            self::IFRAME => ['entry', 'url', 'width', 'height'],
            self::COMPONENT => ['components', 'component_types'],
        };
    }
}
