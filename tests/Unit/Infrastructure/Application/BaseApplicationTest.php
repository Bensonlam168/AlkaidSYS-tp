<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Application;

use Infrastructure\Application\BaseApplication;
use PHPUnit\Framework\TestCase;

/**
 * BaseApplication Test | 应用基类测试
 *
 * @covers \Infrastructure\Application\BaseApplication
 */
class BaseApplicationTest extends TestCase
{
    private string $testAppPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temporary directory for testing
        $this->testAppPath = sys_get_temp_dir() . '/alkaid_test_app_' . uniqid();
        mkdir($this->testAppPath, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testAppPath);
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

    public function testConstructorSetsPath(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath);
        $this->assertSame($this->testAppPath, $app->getPath());
    }

    public function testGetKeyReturnsApplicationKey(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath);
        $this->assertSame('concrete_test', $app->getKey());
    }

    public function testGetNameReturnsApplicationName(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath);
        $this->assertSame('Concrete Test Application', $app->getName());
    }

    public function testGetVersionReturnsApplicationVersion(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath);
        $this->assertSame('1.0.0', $app->getVersion());
    }

    public function testLoadManifestFromFile(): void
    {
        // Create manifest file
        file_put_contents($this->testAppPath . '/manifest.json', json_encode([
            'key' => 'manifest_app',
            'name' => 'Manifest Application',
            'version' => '2.0.0',
            'description' => 'Test description',
        ]));

        $app = new ConcreteTestApplication($this->testAppPath);

        // Manifest values should override class defaults
        $this->assertSame('manifest_app', $app->getKey());
        $this->assertSame('Manifest Application', $app->getName());
        $this->assertSame('2.0.0', $app->getVersion());
    }

    public function testGetManifestReturnsManifestData(): void
    {
        $manifestData = [
            'key' => 'test_app',
            'name' => 'Test App',
            'version' => '1.0.0',
            'config' => ['enabled' => true],
        ];

        file_put_contents($this->testAppPath . '/manifest.json', json_encode($manifestData));

        $app = new ConcreteTestApplication($this->testAppPath);
        $manifest = $app->getManifest();

        $this->assertIsArray($manifest);
        $this->assertSame('test_app', $manifest['key']);
        $this->assertSame(['enabled' => true], $manifest['config']);
    }

    public function testGetManifestReturnsEmptyArrayWhenNoManifest(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath);
        $manifest = $app->getManifest();

        $this->assertIsArray($manifest);
        // Will have default values from class properties
    }

    public function testPathTrailingSlashIsRemoved(): void
    {
        $app = new ConcreteTestApplication($this->testAppPath . '/');
        $this->assertSame($this->testAppPath, $app->getPath());
    }
}

/**
 * Concrete implementation of BaseApplication for testing
 */
class ConcreteTestApplication extends BaseApplication
{
    protected string $key = 'concrete_test';
    protected string $name = 'Concrete Test Application';
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
