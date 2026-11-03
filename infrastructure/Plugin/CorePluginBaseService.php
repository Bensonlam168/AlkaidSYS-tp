<?php

declare(strict_types=1);

namespace Infrastructure\Plugin;

use Infrastructure\DI\DependencyManager;
use Infrastructure\Plugin\Hook\HookManager;
use think\facade\Log;

/**
 * CorePluginBaseService | 插件基类（Phase 2 - 基础能力）
 *
 * 目标：
 * - 提供统一的插件生命周期方法：install/enable/disable/uninstall/upgrade
 * - 提供统一的扩展点：registerHooks / getServiceProviders
 * - 与 DependencyManager 联动（注册插件 ServiceProvider）
 */
abstract class CorePluginBaseService
{
    protected string $key;

    /**
     * @var array<string, mixed> 插件清单信息（可从 plugin.json 读取）
     */
    protected array $manifest = [];

    /**
     * @var bool 是否已安装（运行时状态；持久化由后续任务扩展）
     */
    protected bool $installed = false;

    /**
     * @var bool 是否启用（运行时状态；持久化由后续任务扩展）
     */
    protected bool $enabled = false;

    public function __construct(string $key, array $manifest = [])
    {
        $this->key = $key;
        $this->manifest = $manifest;
    }

    /**
     * 获取插件 key
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * 获取插件唯一标识（兼容 getIdentifier 用法）
     */
    public function getIdentifier(): string
    {
        return $this->key;
    }

    /**
     * 获取插件清单信息
     *
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * 是否已安装
     */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 同步持久化状态（由管理器调用）
     */
    public function hydrateState(bool $installed, bool $enabled): void
    {
        $this->installed = $installed;
        $this->enabled = $enabled;
    }

    /**
     * 安装插件（默认仅标记状态，具体数据迁移由插件自行实现）
     */
    public function install(): void
    {
        $this->installed = true;
        event('PluginInstalled', ['plugin_key' => $this->key]);
    }

    /**
     * 卸载插件
     *
     * @param bool $keepData 是否保留数据（插件自行决定如何处理）
     */
    public function uninstall(bool $keepData = false): void
    {
        $this->enabled = false;
        $this->installed = false;
        event('PluginUninstalled', ['plugin_key' => $this->key, 'keep_data' => $keepData]);
    }

    /**
     * 启用插件：注册 Hooks + Provider
     */
    public function enable(HookManager $hookManager, DependencyManager $dependencyManager): void
    {
        if (!$this->installed) {
            $this->install();
        }

        try {
            $this->registerHooks($hookManager);
            $dependencyManager->registerServiceProviders($this->getServiceProviders());
            $dependencyManager->boot();
            $this->enabled = true;

            event('PluginEnabled', ['plugin_key' => $this->key]);
        } catch (\Throwable $e) {
            Log::error('Enable plugin failed', [
                'plugin_key' => $this->key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 禁用插件：当前仅标记状态（卸载 hooks 的能力作为后续增强）
     */
    public function disable(): void
    {
        $this->enabled = false;
        event('PluginDisabled', ['plugin_key' => $this->key]);
    }

    /**
     * 升级插件（默认仅触发事件，具体升级逻辑由插件自行实现）
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        event('PluginUpgraded', [
            'plugin_key' => $this->key,
            'from' => $fromVersion,
            'to' => $toVersion,
        ]);
    }

    /**
     * 插件需要注册的 ServiceProvider 列表
     *
     * @return array<class-string>
     */
    public function getServiceProviders(): array
    {
        return [];
    }

    /**
     * 注册插件钩子（由插件实现）
     */
    abstract protected function registerHooks(HookManager $hookManager): void;
}
