<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\I18n;

use Infrastructure\I18n\LanguageService;
use Tests\ThinkPHPTestCase;

/**
 * LanguageService Test | LanguageService测试
 *
 * Tests for the LanguageService.
 * LanguageService的测试。
 *
 * @package Tests\Unit\Infrastructure\I18n
 */
class LanguageServiceTest extends ThinkPHPTestCase
{
    protected LanguageService $service;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LanguageService();
    }

    /**
     * Test get current language | 测试获取当前语言
     *
     * @return void
     */
    public function testGetCurrentLanguage(): void
    {
        $lang = $this->service->getCurrentLanguage();
        $this->assertContains($lang, ['zh-cn', 'en-us']);
    }

    /**
     * Test set language | 测试设置语言
     *
     * @return void
     */
    public function testSetLanguage(): void
    {
        $this->service->setLanguage('en-us');
        $this->assertEquals('en-us', $this->service->getCurrentLanguage());

        $this->service->setLanguage('zh-cn');
        $this->assertEquals('zh-cn', $this->service->getCurrentLanguage());
    }

    /**
     * Test is supported | 测试是否支持
     *
     * @return void
     */
    public function testIsSupported(): void
    {
        $this->assertTrue($this->service->isSupported('zh-cn'));
        $this->assertTrue($this->service->isSupported('en-us'));
        $this->assertFalse($this->service->isSupported('fr-fr'));
        $this->assertFalse($this->service->isSupported('invalid'));
    }

    /**
     * Test get supported languages | 测试获取支持的语言
     *
     * @return void
     */
    public function testGetSupportedLanguages(): void
    {
        $languages = $this->service->getSupportedLanguages();
        $this->assertIsArray($languages);
        $this->assertContains('zh-cn', $languages);
        $this->assertContains('en-us', $languages);
    }

    /**
     * Test trans with Chinese | 测试中文翻译
     *
     * @return void
     */
    public function testTransWithChinese(): void
    {
        $this->service->setLanguage('zh-cn');

        $message = $this->service->trans('error.unauthorized');
        $this->assertEquals('未授权', $message);

        $message = $this->service->trans('auth.login_successful');
        $this->assertEquals('登录成功', $message);

        $message = $this->service->trans('common.success');
        $this->assertEquals('成功', $message);
    }

    /**
     * Test trans with English | 测试英文翻译
     *
     * @return void
     */
    public function testTransWithEnglish(): void
    {
        $this->service->setLanguage('en-us');

        $message = $this->service->trans('error.unauthorized');
        $this->assertEquals('Unauthorized', $message);

        $message = $this->service->trans('auth.login_successful');
        $this->assertEquals('Login successful', $message);

        $message = $this->service->trans('common.success');
        $this->assertEquals('Success', $message);
    }

    /**
     * Test trans error code | 测试翻译错误码
     *
     * @return void
     */
    public function testTransError(): void
    {
        $this->service->setLanguage('zh-cn');
        $message = $this->service->transError(2001);
        $this->assertEquals('未授权：Token缺失、无效或已过期', $message);

        $this->service->setLanguage('en-us');
        $message = $this->service->transError(2001);
        $this->assertEquals('Unauthorized: Token is missing, invalid, or expired', $message);
    }

    /**
     * Test trans with specific language | 测试指定语言翻译
     *
     * @return void
     */
    public function testTransWithSpecificLanguage(): void
    {
        $this->service->setLanguage('zh-cn');

        // Translate to English without changing current language
        $message = $this->service->trans('error.unauthorized', [], 'en-us');
        $this->assertEquals('Unauthorized', $message);

        // Current language should still be Chinese
        $this->assertEquals('zh-cn', $this->service->getCurrentLanguage());
    }

    /**
     * Test trans with non-existent key | 测试翻译不存在的键
     *
     * @return void
     */
    public function testTransWithNonExistentKey(): void
    {
        $message = $this->service->trans('non.existent.key');
        // Should return the key itself when translation not found
        $this->assertEquals('non.existent.key', $message);
    }

    /**
     * Test parse Accept-Language header | 测试解析Accept-Language头
     *
     * @return void
     */
    public function testParseAcceptLanguage(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseAcceptLanguage');
        $method->setAccessible(true);

        // Test English
        $lang = $method->invoke($this->service, 'en-US,en;q=0.9');
        $this->assertEquals('en-us', $lang);

        // Test Chinese
        $lang = $method->invoke($this->service, 'zh-CN,zh;q=0.9,en;q=0.8');
        $this->assertEquals('zh-cn', $lang);

        // Test with quality values
        $lang = $method->invoke($this->service, 'en;q=0.8,zh-CN;q=0.9');
        $this->assertEquals('zh-cn', $lang);
    }

    /**
     * Clean up test | 清理测试
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
