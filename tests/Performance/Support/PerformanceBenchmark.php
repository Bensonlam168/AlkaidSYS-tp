<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

/**
 * 性能基准管理类
 * Performance Benchmark Manager
 *
 * 用于记录、管理和对比性能基准数据。
 * Used to record, manage, and compare performance benchmark data.
 *
 * 功能 | Features:
 * - 记录性能基准数据到 JSON 文件
 * - Record performance benchmark data to JSON file
 * - 对比当前性能与历史基准
 * - Compare current performance with historical benchmarks
 * - 检测性能退化
 * - Detect performance regression
 * - 生成性能趋势数据
 * - Generate performance trend data
 */
class PerformanceBenchmark
{
    /**
     * 基准数据存储目录
     * Benchmark data storage directory
     */
    protected string $benchmarkDir;

    /**
     * 当前测试的基准数据
     * Current test benchmark data
     *
     * @var array
     */
    protected array $benchmarks = [];

    /**
     * 性能退化阈值（百分比）
     * Performance regression threshold (percentage)
     *
     * 默认 20%，即性能下降超过 20% 视为退化
     * Default 20%, i.e., performance degradation > 20% is considered regression
     */
    protected float $regressionThreshold = 0.20;

    /**
     * 构造函数
     * Constructor
     *
     * @param string|null $benchmarkDir 基准数据目录 | Benchmark data directory
     */
    public function __construct(?string $benchmarkDir = null)
    {
        $this->benchmarkDir = $benchmarkDir ?? __DIR__ . '/../.benchmarks';

        // 确保目录存在
        // Ensure directory exists
        if (!is_dir($this->benchmarkDir)) {
            mkdir($this->benchmarkDir, 0755, true);
        }
    }

    /**
     * 记录性能基准
     * Record performance benchmark
     *
     * @param string $testName 测试名称 | Test name
     * @param float $executionTime 执行时间（毫秒）| Execution time (milliseconds)
     * @param int $memoryUsage 内存使用（字节）| Memory usage (bytes)
     * @param array $metadata 元数据 | Metadata
     * @return void
     */
    public function record(
        string $testName,
        float $executionTime,
        int $memoryUsage,
        array $metadata = []
    ): void {
        $benchmark = [
            'test_name' => $testName,
            'execution_time_ms' => round($executionTime, 2),
            'memory_usage_bytes' => $memoryUsage,
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'timestamp' => time(),
            'date' => date('Y-m-d H:i:s'),
            'environment' => $this->getEnvironmentInfo(),
            'metadata' => $metadata,
        ];

        $this->benchmarks[$testName] = $benchmark;

        // 保存到文件
        // Save to file
        $this->saveBenchmark($testName, $benchmark);
    }

    /**
     * 保存基准数据到文件
     * Save benchmark data to file
     *
     * @param string $testName 测试名称 | Test name
     * @param array $benchmark 基准数据 | Benchmark data
     * @return void
     */
    protected function saveBenchmark(string $testName, array $benchmark): void
    {
        $filename = $this->getBenchmarkFilename($testName);

        // 读取现有数据
        // Read existing data
        $history = [];
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            $history = json_decode($content, true) ?? [];
        }

        // 添加新数据
        // Add new data
        $history[] = $benchmark;

        // 只保留最近 100 条记录
        // Keep only the last 100 records
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

        // 保存
        // Save
        file_put_contents($filename, json_encode($history, JSON_PRETTY_PRINT));
    }

    /**
     * 获取基准数据文件名
     * Get benchmark data filename
     *
     * @param string $testName 测试名称 | Test name
     * @return string
     */
    protected function getBenchmarkFilename(string $testName): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $testName);
        return $this->benchmarkDir . '/' . $safeName . '.json';
    }

    /**
     * 获取历史基准数据
     * Get historical benchmark data
     *
     * @param string $testName 测试名称 | Test name
     * @param int $limit 限制数量 | Limit count
     * @return array
     */
    public function getHistory(string $testName, int $limit = 10): array
    {
        $filename = $this->getBenchmarkFilename($testName);

        if (!file_exists($filename)) {
            return [];
        }

        $content = file_get_contents($filename);
        $history = json_decode($content, true) ?? [];

        // 返回最近的 N 条记录
        // Return the last N records
        return array_slice($history, -$limit);
    }

    /**
     * 获取基准统计信息
     * Get benchmark statistics
     *
     * @param string $testName 测试名称 | Test name
     * @return array|null
     */
    public function getStatistics(string $testName): ?array
    {
        $history = $this->getHistory($testName, 100);

        if (empty($history)) {
            return null;
        }

        $executionTimes = array_column($history, 'execution_time_ms');
        $memoryUsages = array_column($history, 'memory_usage_mb');

        return [
            'count' => count($history),
            'execution_time' => [
                'min' => min($executionTimes),
                'max' => max($executionTimes),
                'avg' => round(array_sum($executionTimes) / count($executionTimes), 2),
                'median' => $this->median($executionTimes),
            ],
            'memory_usage' => [
                'min' => min($memoryUsages),
                'max' => max($memoryUsages),
                'avg' => round(array_sum($memoryUsages) / count($memoryUsages), 2),
                'median' => $this->median($memoryUsages),
            ],
        ];
    }

    /**
     * 检测性能退化
     * Detect performance regression
     *
     * @param string $testName 测试名称 | Test name
     * @param float $currentTime 当前执行时间（毫秒）| Current execution time (milliseconds)
     * @return array 退化检测结果 | Regression detection result
     */
    public function detectRegression(string $testName, float $currentTime): array
    {
        $stats = $this->getStatistics($testName);

        if ($stats === null) {
            return [
                'has_regression' => false,
                'message' => 'No historical data available',
            ];
        }

        $baseline = $stats['execution_time']['median'];
        $threshold = $baseline * (1 + $this->regressionThreshold);

        $hasRegression = $currentTime > $threshold;
        $degradation = (($currentTime - $baseline) / $baseline) * 100;

        return [
            'has_regression' => $hasRegression,
            'current_time' => round($currentTime, 2),
            'baseline_time' => round($baseline, 2),
            'threshold_time' => round($threshold, 2),
            'degradation_percent' => round($degradation, 2),
            'message' => $hasRegression
                ? "Performance regression detected: {$degradation}% slower than baseline"
                : "Performance is within acceptable range",
        ];
    }

    /**
     * 计算中位数
     * Calculate median
     *
     * @param array $values 数值数组 | Value array
     * @return float
     */
    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * 获取环境信息
     * Get environment information
     *
     * @return array
     */
    protected function getEnvironmentInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
        ];
    }

    /**
     * 设置性能退化阈值
     * Set performance regression threshold
     *
     * @param float $threshold 阈值（0.0-1.0）| Threshold (0.0-1.0)
     * @return void
     */
    public function setRegressionThreshold(float $threshold): void
    {
        $this->regressionThreshold = $threshold;
    }

    /**
     * 获取所有基准数据
     * Get all benchmark data
     *
     * @return array
     */
    public function getAllBenchmarks(): array
    {
        return $this->benchmarks;
    }
}

