<?php
declare(strict_types=1);

namespace Infrastructure\Plugin\Exception;

/**
 * 插件依赖异常 / Plugin dependency exception
 * 
 * 插件依赖解析过程中发生的异常 / Exception that occurs during plugin dependency resolution
 * 
 * @package Infrastructure\Plugin\Exception
 */
class PluginDependencyException extends PluginException
{
    /**
     * 构造函数 / Constructor
     * 
     * @param string $message 错误消息 / Error message
     * @param int $code 错误代码 / Error code
     * @param \Exception|null $previous 前一个异常 / Previous exception
     * @param string|null $pluginId 插件ID / Plugin ID
     */
    public function __construct(string $message = "", int $code = 0, ?\Exception $previous = null, ?string $pluginId = null)
    {
        parent::__construct($message, $code, $previous, $pluginId);
    }
}