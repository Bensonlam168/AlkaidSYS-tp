<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Exception;

use Exception;

/**
 * 插件异常基类 / Base plugin exception class
 *
 * 所有插件相关异常的基类 / Base class for all plugin-related exceptions
 *
 * @package Infrastructure\Plugin\Exception
 */
class PluginException extends Exception
{
    /**
     * 插件ID / Plugin ID
     */
    private ?string $pluginId;

    /**
     * 错误代码 / Error code
     */
    private int $errorCode;

    /**
     * 构造函数 / Constructor
     *
     * @param string $message 错误消息 / Error message
     * @param int $code 错误代码 / Error code
     * @param Exception|null $previous 前一个异常 / Previous exception
     * @param string|null $pluginId 插件ID / Plugin ID
     */
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, ?string $pluginId = null)
    {
        parent::__construct($message, $code, $previous);
        $this->pluginId = $pluginId;
        $this->errorCode = $code;
    }

    /**
     * 获取插件ID / Get plugin ID
     *
     * @return string|null 插件ID / Plugin ID
     */
    public function getPluginId(): ?string
    {
        return $this->pluginId;
    }

    /**
     * 获取错误代码 / Get error code
     *
     * @return int 错误代码 / Error code
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 转换为数组 / Convert to array
     *
     * @return array 异常信息 / Exception information
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'error_code' => $this->errorCode,
            'plugin_id' => $this->pluginId,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }
}
