<?php

declare(strict_types=1);

namespace Infrastructure\I18n;

use think\facade\Lang;
use think\facade\Request;

/**
 * Language Service | 语言服务
 *
 * Provides internationalization (i18n) support for the application.
 * 为应用程序提供国际化（i18n）支持。
 *
 * Features:
 * - Automatic language detection from Accept-Language header
 * - Support for multiple languages (zh-cn, en-us)
 * - Parameter replacement in translations
 * - Fallback to default language
 *
 * @package Infrastructure\I18n
 */
class LanguageService
{
    /**
     * Default language | 默认语言
     */
    protected const DEFAULT_LANG = 'zh-cn';

    /**
     * Supported languages | 支持的语言
     */
    protected const SUPPORTED_LANGS = ['zh-cn', 'en-us'];

    /**
     * Current language | 当前语言
     */
    protected string $currentLang;

    /**
     * Constructor | 构造函数
     */
    public function __construct()
    {
        $this->currentLang = $this->detectLanguage();
        $this->setLanguage($this->currentLang);
    }

    /**
     * Translate a message | 翻译消息
     *
     * @param string $key Translation key (e.g., 'error.unauthorized', 'auth.login_successful')
     * @param array $params Parameters to replace in the message
     * @param string|null $lang Language code (null = use current language)
     * @return string Translated message
     */
    public function trans(string $key, array $params = [], ?string $lang = null): string
    {
        if ($lang !== null && $this->isSupported($lang)) {
            $originalLang = $this->currentLang;
            $this->setLanguage($lang);
            $message = $this->translate($key, $params);
            $this->setLanguage($originalLang);
            return $message;
        }

        return $this->translate($key, $params);
    }

    /**
     * Translate error code to message | 将错误码翻译为消息
     *
     * @param int $code Error code
     * @param string|null $lang Language code (null = use current language)
     * @return string Error message
     */
    public function transError(int $code, ?string $lang = null): string
    {
        return $this->trans("error.{$code}", [], $lang);
    }

    /**
     * Get current language | 获取当前语言
     *
     * @return string Current language code
     */
    public function getCurrentLanguage(): string
    {
        return $this->currentLang;
    }

    /**
     * Set current language | 设置当前语言
     *
     * @param string $lang Language code
     * @return void
     */
    public function setLanguage(string $lang): void
    {
        if ($this->isSupported($lang)) {
            $this->currentLang = $lang;
            Lang::setLangSet($lang);
        }
    }

    /**
     * Check if language is supported | 检查语言是否支持
     *
     * @param string $lang Language code
     * @return bool Is supported
     */
    public function isSupported(string $lang): bool
    {
        return in_array($lang, self::SUPPORTED_LANGS, true);
    }

    /**
     * Get supported languages | 获取支持的语言列表
     *
     * @return array Supported language codes
     */
    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGS;
    }

    /**
     * Detect language from request | 从请求检测语言
     *
     * Priority:
     * 1. Query parameter 'lang'
     * 2. Cookie 'think_lang'
     * 3. Accept-Language header
     * 4. Default language
     *
     * @return string Detected language code
     */
    protected function detectLanguage(): string
    {
        // 1. Check query parameter | 检查查询参数
        $queryLang = Request::param('lang', '');
        if (!empty($queryLang) && $this->isSupported($queryLang)) {
            return $queryLang;
        }

        // 2. Check cookie | 检查Cookie
        $cookieLang = Request::cookie('think_lang', '');
        if (!empty($cookieLang) && $this->isSupported($cookieLang)) {
            return $cookieLang;
        }

        // 3. Check Accept-Language header | 检查Accept-Language头
        $acceptLang = Request::header('Accept-Language', '');
        if (!empty($acceptLang)) {
            $detectedLang = $this->parseAcceptLanguage($acceptLang);
            if ($this->isSupported($detectedLang)) {
                return $detectedLang;
            }
        }

        // 4. Use default language | 使用默认语言
        return self::DEFAULT_LANG;
    }

    /**
     * Parse Accept-Language header | 解析Accept-Language头
     *
     * @param string $acceptLang Accept-Language header value
     * @return string Detected language code
     */
    protected function parseAcceptLanguage(string $acceptLang): string
    {
        // Parse Accept-Language header (e.g., "en-US,en;q=0.9,zh-CN;q=0.8")
        $languages = explode(',', $acceptLang);
        $parsed = [];

        foreach ($languages as $lang) {
            $parts = explode(';', trim($lang));
            $code = strtolower(trim($parts[0]));
            $quality = 1.0;

            if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
                $quality = (float) substr($parts[1], 2);
            }

            $parsed[$code] = $quality;
        }

        // Sort by quality (highest first)
        arsort($parsed);

        // Find first supported language
        foreach (array_keys($parsed) as $code) {
            // Normalize language code (e.g., "en-US" -> "en-us", "zh-CN" -> "zh-cn")
            $normalized = strtolower($code);

            if ($this->isSupported($normalized)) {
                return $normalized;
            }

            // Try language without region (e.g., "en-US" -> "en")
            // Use match expression for cleaner code
            // 使用 match 表达式使代码更简洁
            if (str_contains($normalized, '-')) {
                $baseLang = explode('-', $normalized)[0];
                // Map base language to supported variant
                return match ($baseLang) {
                    'zh' => 'zh-cn',
                    'en' => 'en-us',
                    default => self::DEFAULT_LANG,
                };
            }
        }

        return self::DEFAULT_LANG;
    }

    /**
     * Translate with parameter replacement | 翻译并替换参数
     *
     * @param string $key Translation key
     * @param array $params Parameters to replace
     * @return string Translated message
     */
    protected function translate(string $key, array $params): string
    {
        // Try to get translation from Lang facade
        try {
            $message = Lang::get($key);

            // If translation not found, try loading from file directly
            if ($message === $key || empty($message)) {
                $message = $this->loadFromFile($key);
            }
        } catch (\Throwable $e) {
            // Fallback to loading from file
            $message = $this->loadFromFile($key);
        }

        // If still not found, return the key | 如果仍未找到，返回键名
        if ($message === $key || empty($message)) {
            return $key;
        }

        // Replace parameters | 替换参数
        if (!empty($params)) {
            foreach ($params as $name => $value) {
                $message = str_replace(":{$name}", (string) $value, $message);
            }
        }

        return $message;
    }

    /**
     * Load translation from file directly | 直接从文件加载翻译
     *
     * @param string $key Translation key (e.g., 'error.unauthorized')
     * @return string Translated message or key if not found
     */
    protected function loadFromFile(string $key): string
    {
        // Parse key (e.g., 'error.unauthorized' -> file='error', key='unauthorized')
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return $key;
        }

        [$file, $messageKey] = $parts;

        // Build file path - use absolute path
        $rootPath = dirname(__DIR__, 2); // Go up from infrastructure/I18n to project root
        $langPath = $rootPath . "/app/lang/{$this->currentLang}/{$file}.php";

        if (!file_exists($langPath)) {
            return $key;
        }

        // Load language file
        $messages = include $langPath;

        if (!is_array($messages)) {
            return $key;
        }

        // Return message or key if not found
        return $messages[$messageKey] ?? $key;
    }
}
