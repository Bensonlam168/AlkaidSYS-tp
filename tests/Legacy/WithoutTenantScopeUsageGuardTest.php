<?php

declare(strict_types=1);

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Guard test to prevent unreviewed usage of BaseModel::withoutTenantScope.
 *
 * 本测试用于扫描代码仓库，禁止在未显式评审/白名单的情况下
 * 直接调用 BaseModel::withoutTenantScope（包括实例调用和静态调用）。
 */
final class WithoutTenantScopeUsageGuardTest extends TestCase
{
    public function testNoDirectUsageOfWithoutTenantScopeInCodebase(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        $dirsToScan = [
            'app',
            'domain',
            'infrastructure',
            'tests',
            'plugins',
        ];

        // Relative paths from project root where patterns are allowed (this guard file itself)
        $allowList = [
            'tests/Legacy/WithoutTenantScopeUsageGuardTest.php',
        ];

        $patterns = [
            '->withoutTenantScope(',
            '::withoutTenantScope(',
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

                foreach ($patterns as $pattern) {
                    if (strpos($contents, $pattern) !== false) {
                        $violations[] = [$relativePath, $pattern];
                        break;
                    }
                }
            }
        }

        if (!empty($violations)) {
            $messages = array_map(
                static fn (array $v): string => sprintf('%s found in %s', $v[1], $v[0]),
                $violations
            );

            $this->fail(
                "BaseModel::withoutTenantScope is considered a high-risk capability and must not be used " .
                "without explicit design review and whitelisting. Found:\n" . implode("\n", $messages)
            );
        }

        $this->assertTrue(true); // Explicit assertion for clarity
    }
}

