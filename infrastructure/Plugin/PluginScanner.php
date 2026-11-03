<?php

declare(strict_types=1);

namespace Infrastructure\Plugin;

/**
 * PluginScanner | 插件扫描器
 *
 * 负责遍历插件目录，读取 plugin.json，返回规范化后的清单。
 */
class PluginScanner
{
    /**
     * 扫描插件目录
     *
     * @param string $basePath 插件基路径（绝对路径）
     * @param string|null $relativeTo 相对路径基准（默认使用 $basePath；落库建议传项目根路径 root_path()）
     * @return array<int, array<string,mixed>>
     */
    public function scan(string $basePath, ?string $relativeTo = null): array
    {
        $manifests = [];

        $basePath = $this->ensureTrailingSeparator($basePath);
        $relativeTo = $this->ensureTrailingSeparator($relativeTo ?? $basePath);

        if (!is_dir($basePath)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getFilename() !== 'plugin.json') {
                continue;
            }

            $manifestPath = $file->getPathname();
            $content = file_get_contents($manifestPath);
            if ($content === false) {
                continue;
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }

            $key = (string)($decoded['key'] ?? '');
            if ($key === '' || !$this->isValidPluginKey($key)) {
                continue;
            }

            $manifestHash = hash('sha256', $content);
            $manifests[] = $this->normalizeManifest($decoded, $manifestPath, $relativeTo, $manifestHash);
        }

        return $manifests;
    }

    /**
     * 规范化 manifest 数据
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    protected function normalizeManifest(
        array $manifest,
        string $manifestPath,
        string $relativeTo,
        string $manifestHash
    ): array {
        $key = (string)($manifest['key'] ?? '');
        $version = (string)($manifest['version'] ?? '0.0.0');
        $category = $manifest['category'] ?? null;
        $appKey = $manifest['app_key'] ?? null;

        $dir = dirname($manifestPath);
        $relativeBase = rtrim($this->relativePath($dir, $relativeTo), '/');
        $relativeManifest = $this->relativePath($manifestPath, $relativeTo);

        // 主类优先读取 plugin.json 的 class 字段，否则按规范回退：plugin\{plugin_key}\Plugin
        $mainClass = $manifest['class'] ?? sprintf('plugin\\%s\\Plugin', $key);
        if (!is_string($mainClass) || $mainClass === '') {
            $mainClass = sprintf('plugin\\%s\\Plugin', $key);
        }

        return [
            'plugin_key' => $key,
            'name' => (string)($manifest['name'] ?? $key),
            'version' => $version,
            'category' => is_string($category) ? $category : null,
            'app_key' => is_string($appKey) ? $appKey : null,
            'main_class' => (string)$mainClass,
            'base_path' => $relativeBase,
            'manifest_path' => $relativeManifest,
            'manifest_hash' => $manifestHash,
            'manifest' => $manifest,
        ];
    }

    /**
     * 计算相对路径（统一使用 / 作为分隔符）
     */
    protected function relativePath(string $path, string $basePath): string
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedBase = $this->normalizePath($this->ensureTrailingSeparator($basePath));

        if (str_starts_with($normalizedPath, $normalizedBase)) {
            return ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        }

        return ltrim($normalizedPath, '/');
    }

    /**
     * 确保路径末尾带分隔符（用于前缀匹配）
     */
    protected function ensureTrailingSeparator(string $path): string
    {
        return rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 将路径统一为 / 分隔符，便于跨平台处理
     */
    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * 校验插件 key 是否可用于命名空间（最小约束：字母/数字/下划线，且不以数字开头）
     */
    protected function isValidPluginKey(string $key): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
    }
}
