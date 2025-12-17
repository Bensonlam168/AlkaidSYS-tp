<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use Infrastructure\Plugin\PluginScanner;
use PHPUnit\Framework\TestCase;

/**
 * PluginScanner 单元测试
 */
class PluginScannerTest extends TestCase
{
    public function testScanParsesManifestAndRelativePaths(): void
    {
        $basePath = dirname(__DIR__, 2) . '/Fixtures/plugins/';
        $scanner = new PluginScanner();

        $manifests = $scanner->scan($basePath);
        $this->assertNotEmpty($manifests);

        $keys = array_column($manifests, 'plugin_key');
        $this->assertContains('demo_plugin', $keys);
        // 无效 key（包含 -）应被跳过
        $this->assertNotContains('invalid-plugin', $keys);

        $demo = null;
        foreach ($manifests as $item) {
            if (($item['plugin_key'] ?? '') === 'demo_plugin') {
                $demo = $item;
                break;
            }
        }
        $this->assertIsArray($demo);
        $this->assertSame('demo_plugin', $demo['plugin_key']);
        $this->assertSame('1.2.3', $demo['version']);
        $this->assertSame('universal', $demo['category']);
        $this->assertStringContainsString('demo/plugin.json', $demo['manifest_path']);
        $this->assertStringContainsString('demo', $demo['base_path']);
        $this->assertNotEmpty($demo['manifest_hash']);
    }

    public function testScanSupportsRelativeToProjectRoot(): void
    {
        $basePath = dirname(__DIR__, 2) . '/Fixtures/plugins/';
        $projectRoot = dirname(__DIR__, 3);
        $scanner = new PluginScanner();

        $manifests = $scanner->scan($basePath, $projectRoot);
        $this->assertNotEmpty($manifests);

        $demo = null;
        foreach ($manifests as $item) {
            if (($item['plugin_key'] ?? '') === 'demo_plugin') {
                $demo = $item;
                break;
            }
        }
        $this->assertIsArray($demo);
        $this->assertStringContainsString('tests/Fixtures/plugins/demo/plugin.json', $demo['manifest_path']);
        $this->assertStringContainsString('tests/Fixtures/plugins/demo', $demo['base_path']);
    }
}
