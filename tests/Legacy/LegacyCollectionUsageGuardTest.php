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

        $legacyClasses = [
            'Domain\\Model\\Collection',
            'Infrastructure\\Collection\\CollectionManager',
            'Infrastructure\\Field\\FieldTypeRegistry',
        ];

        // Relative paths from project root where legacy references are allowed
        $allowList = [
            'infrastructure/Collection/CollectionManager.php',
            'domain/Model/Collection.php',
            'infrastructure/Field/FieldTypeRegistry.php',
            'tests/Legacy/LegacyCollectionUsageGuardTest.php',
        ];

        $dirsToScan = [
            'app',
            'domain',
            'infrastructure',
            'tests',
        ];

        $violations = [];

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

                $contents = @file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                foreach ($legacyClasses as $class) {
                    if (strpos($contents, $class) !== false) {
                        $violations[] = [$relativePath, $class];
                    }
                }
            }
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

