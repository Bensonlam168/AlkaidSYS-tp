<?php
declare(strict_types=1);

namespace Infrastructure\Plugin;

use Infrastructure\Plugin\Contract\PluginInterface;
use Infrastructure\Plugin\Enum\PluginType;
use Infrastructure\Plugin\Metadata\PluginMetadata;

/**
 * 插件类型检测器 / Plugin type detector
 * 
 * 用于检测插件的类型（hook、iframe、component） / Used to detect plugin types (hook, iframe, component)
 * 
 * @package Infrastructure\Plugin
 */
class PluginTypeDetector
{
    /**
     * 创建插件元数据 / Create plugin metadata
     * 
     * @param PluginInterface $plugin 插件实例 / Plugin instance
     * @return PluginMetadata 插件元数据 / Plugin metadata
     */
    public function createPluginMetadata(PluginInterface $plugin): PluginMetadata
    {
        $pluginType = $this->detectPluginType($plugin);
        
        return new PluginMetadata(
            $plugin->getIdentifier(),
            $plugin->getName(),
            $pluginType,
            $plugin->getPath()
        );
    }
    
    /**
     * 检测插件类型 / Detect plugin type
     * 
     * @param PluginInterface $plugin 插件实例 / Plugin instance
     * @return PluginType 插件类型 / Plugin type
     */
    public function detectPluginType(PluginInterface $plugin): PluginType
    {
        // 根据插件类名或配置检测类型 / Detect type based on plugin class name or configuration
        $className = get_class($plugin);
        
        // 检查是否为 Hook 插件 / Check if it's a Hook plugin
        if (str_contains($className, 'Hook') || $this->hasHookInterface($plugin)) {
            return PluginType::HOOK;
        }
        
        // 检查是否为 Iframe 插件 / Check if it's an Iframe plugin
        if (str_contains($className, 'Iframe') || $this->hasIframeInterface($plugin)) {
            return PluginType::IFRAME;
        }
        
        // 检查是否为 Component 插件 / Check if it's a Component plugin
        if (str_contains($className, 'Component') || $this->hasComponentInterface($plugin)) {
            return PluginType::COMPONENT;
        }
        
        // 默认为 Hook 类型 / Default to Hook type
        return PluginType::HOOK;
    }
    
    /**
     * 检查插件是否实现 Hook 接口 / Check if plugin implements Hook interface
     * 
     * @param PluginInterface $plugin 插件实例 / Plugin instance
     * @return bool 是否实现 Hook 接口 / Whether implements Hook interface
     */
    private function hasHookInterface(PluginInterface $plugin): bool
    {
        // 检查是否实现了 Hook 插件特有的接口 / Check if implements Hook-specific interfaces
        return method_exists($plugin, 'getHooks') || method_exists($plugin, 'registerHooks');
    }
    
    /**
     * 检查插件是否实现 Iframe 接口 / Check if plugin implements Iframe interface
     * 
     * @param PluginInterface $plugin 插件实例 / Plugin instance
     * @return bool 是否实现 Iframe 接口 / Whether implements Iframe interface
     */
    private function hasIframeInterface(PluginInterface $plugin): bool
    {
        // 检查是否实现了 Iframe 插件特有的接口 / Check if implements Iframe-specific interfaces
        return method_exists($plugin, 'getIframeUrl') || method_exists($plugin, 'getIframeConfig');
    }
    
    /**
     * 检查插件是否实现 Component 接口 / Check if plugin implements Component interface
     * 
     * @param PluginInterface $plugin 插件实例 / Plugin instance
     * @return bool 是否实现 Component 接口 / Whether implements Component interface
     */
    private function hasComponentInterface(PluginInterface $plugin): bool
    {
        // 检查是否实现了 Component 插件特有的接口 / Check if implements Component-specific interfaces
        return method_exists($plugin, 'getComponents') || method_exists($plugin, 'registerComponents');
    }
}