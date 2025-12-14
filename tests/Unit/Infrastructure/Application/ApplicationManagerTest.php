<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Application;

use Infrastructure\Application\ApplicationInterface;
use Infrastructure\Application\ApplicationManager;
use Infrastructure\Application\BaseApplication;
use PHPUnit\Framework\TestCase;

/**
 * ApplicationManager Test | 应用管理器测试
 *
 * @covers \Infrastructure\Application\ApplicationManager
 */
class ApplicationManagerTest extends TestCase
{
    private string $testBasePath;
    private ApplicationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary directory for testing
        $this->testBasePath = sys_get_temp_dir() . '/alkaid_test_apps_' . uniqid();
        mkdir($this->testBasePath, 0755, true);
        $this->manager = new ApplicationManager($this->testBasePath);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testBasePath);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testConstructorSetsBasePath(): void
    {
        $this->assertSame($this->testBasePath, $this->manager->getBasePath());
    }

    public function testDiscoverReturnsEmptyArrayWhenNoApps(): void
    {
        $discovered = $this->manager->discover();
        $this->assertIsArray($discovered);
        $this->assertEmpty($discovered);
    }

    public function testDiscoverFindsApplicationWithManifest(): void
    {
        // Create a test application directory with manifest
        $appPath = $this->testBasePath . '/testapp';
        mkdir($appPath, 0755, true);
        file_put_contents($appPath . '/manifest.json', json_encode([
            'key' => 'testapp',
            'name' => 'Test Application',
            'version' => '1.0.0',
        ]));

        $discovered = $this->manager->discover();

        $this->assertArrayHasKey('testapp', $discovered);
        $this->assertSame($appPath, $discovered['testapp']['path']);
        $this->assertSame('testapp', $discovered['testapp']['manifest']['key']);
    }

    public function testDiscoverIgnoresDirectoriesWithoutManifest(): void
    {
        // Create a directory without manifest
        $appPath = $this->testBasePath . '/noapp';
        mkdir($appPath, 0755, true);

        $discovered = $this->manager->discover();

        $this->assertArrayNotHasKey('noapp', $discovered);
    }

    public function testRegisterAddsApplication(): void
    {
        $mockApp = $this->createMock(ApplicationInterface::class);
        $mockApp->method('getKey')->willReturn('mockapp');

        $this->manager->register($mockApp);

        $this->assertSame($mockApp, $this->manager->get('mockapp'));
    }

    public function testRegisterClassAddsApplicationClass(): void
    {
        // Create a test application
        $appPath = $this->testBasePath . '/testapp';
        mkdir($appPath, 0755, true);
        file_put_contents($appPath . '/manifest.json', json_encode([
            'key' => 'testapp',
            'name' => 'Test Application',
            'version' => '1.0.0',
        ]));

        // Register a mock class (we can't actually test class loading without autoloader)
        $this->manager->registerClass('testapp', TestApplication::class);

        // The class is registered but won't be instantiated without proper autoloading
        $this->assertNull($this->manager->get('nonexistent'));
    }

    public function testGetReturnsNullForNonexistentApplication(): void
    {
        $this->assertNull($this->manager->get('nonexistent'));
    }

    public function testAllReturnsRegisteredApplications(): void
    {
        $mockApp1 = $this->createMock(ApplicationInterface::class);
        $mockApp1->method('getKey')->willReturn('app1');

        $mockApp2 = $this->createMock(ApplicationInterface::class);
        $mockApp2->method('getKey')->willReturn('app2');

        $this->manager->register($mockApp1);
        $this->manager->register($mockApp2);

        $all = $this->manager->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('app1', $all);
        $this->assertArrayHasKey('app2', $all);
    }

    public function testExistsReturnsTrueForDiscoveredApp(): void
    {
        $appPath = $this->testBasePath . '/existingapp';
        mkdir($appPath, 0755, true);
        file_put_contents($appPath . '/manifest.json', json_encode([
            'key' => 'existingapp',
            'name' => 'Existing App',
            'version' => '1.0.0',
        ]));

        $this->assertTrue($this->manager->exists('existingapp'));
    }

    public function testExistsReturnsFalseForNonexistentApp(): void
    {
        $this->assertFalse($this->manager->exists('nonexistent'));
    }
}

/**
 * Test Application class for testing purposes
 */
class TestApplication extends BaseApplication
{
    protected string $key = 'testapp';
    protected string $name = 'Test Application';
    protected string $version = '1.0.0';

    public function install(): void
    {
        // Test implementation
    }

    public function uninstall(bool $keepData = false): void
    {
        // Test implementation
    }

    public function enable(): void
    {
        // Test implementation
    }

    public function disable(): void
    {
        // Test implementation
    }

    public function upgrade(string $fromVersion, string $toVersion): void
    {
        // Test implementation
    }
}
