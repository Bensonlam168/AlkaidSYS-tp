<?php

declare(strict_types=1);

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Guard test to prevent new code from using Legacy Collection stack.
 *
 * Legacy stack classes:
 * - Domain\\Model\\Collection
 * - Infrastructure\\Collection\\CollectionManager
 * - Infrastructure\\Field\\FieldTypeRegistry
 *
 * This test scans the codebase and fails if these classes are referenced
 * outside of a small, explicit allowlist (the legacy implementations
 * themselves and this guard test).
 */
class LegacyCollectionUsageGuardTest extends TestCase
{
    public function testNoNewReferencesToLegacyCollectionStack(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        // Legacy 文件不应再存在
        $legacyFiles = [
            'infrastructure/Collection/CollectionManager.php',
            'domain/Model/Collection.php',
            'infrastructure/Field/FieldTypeRegistry.php',
        ];
        foreach ($legacyFiles as $legacyFile) {
            $this->assertFileDoesNotExist($projectRoot . DIRECTORY_SEPARATOR . $legacyFile, "Legacy file {$legacyFile} should be removed");
        }

        $legacyClasses = [
            'Domain\\Model\\Collection',
            'Infrastructure\\Collection\\CollectionManager',
            'Infrastructure\\Field\\FieldTypeRegistry',
        ];

        // Relative paths from project root where legacy references are allowed
        $allowList = [
            'tests/Legacy/LegacyCollectionUsageGuardTest.php',
        ];

        // 扩展扫描范围，覆盖脚本/扩展/插件等目录，避免新增 Legacy 依赖漏网
        $dirsToScan = [
            'app',
            'domain',
            'infrastructure',
            'tests',
            'config',
            'database',
            'scripts',
            'extend',
            'route',
            'addons',
            'plugins',
            'deploy',
            'public',
            'view',
        ];

        $violations = [];
        $scanFile = static function (string $fullPath, string $relativePath) use ($legacyClasses, &$violations): void {
            $contents = @file_get_contents($fullPath);
            if ($contents === false) {
                return;
            }

            foreach ($legacyClasses as $class) {
                if (strpos($contents, $class) !== false) {
                    $violations[] = [$relativePath, $class];
                }
            }
        };

        foreach ($dirsToScan as $dir) {
            $baseDir = $projectRoot . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($baseDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());

                if (in_array($relativePath, $allowList, true)) {
                    continue;
                }

                $scanFile($file->getPathname(), $relativePath);
            }
        }

        // 扫描仓库根目录下的 PHP 脚本，避免调试脚本遗漏
        foreach (new \DirectoryIterator($projectRoot) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = $file->getFilename();
            if (in_array($relativePath, $allowList, true)) {
                continue;
            }

            $scanFile($file->getPathname(), $relativePath);
        }

        if (!empty($violations)) {
            $messages = array_map(
                static fn (array $v): string => sprintf('%s referenced in %s', $v[1], $v[0]),
                $violations
            );

            $this->fail("Legacy Collection stack must not be referenced by new code. Found:\n" . implode("\n", $messages));
        }

        $this->assertTrue(true); // Explicit assertion for clarity
    }
}
