<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use RuntimeException;

/**
 * 性能报告生成器
 * Performance Reporter
 *
 * 生成 Markdown 格式的性能测试报告。
 * Generate Markdown format performance test reports.
 *
 * 功能 | Features:
 * - 生成性能测试报告
 * - Generate performance test reports
 * - 包含当前性能、历史趋势、性能对比
 * - Include current performance, historical trends, performance comparison
 * - 支持 ASCII 图表
 * - Support ASCII charts
 *
 * 报告目录（R-性能套件修复）：
 * 默认写到 `runtime/performance-reports/`，**不再写进 `docs/report/`**。
 * 原因：`docs/report/` 是版本库目录，性能套件每跑一次就会生成/覆盖
 * `casbin-performance-benchmark-<date>.md`，于是「跑一次性能套件」
 * 会稳定地在工作区留下未提交改动，与「测试不改仓库」的约定冲突；
 * 而 `runtime/` 已被 `.gitignore` 忽略，产物天然不入库。
 *
 * 构造参数仍可覆盖目录（现有显式传目录的调用方行为不变）。
 */
class PerformanceReporter
{
    /**
     * 性能基准管理器
     * Performance benchmark manager
     */
    protected PerformanceBenchmark $benchmark;

    /**
     * 报告输出目录
     * Report output directory
     */
    protected string $reportDir;

    /**
     * 默认报告目录（相对项目根）
     * Default report directory (relative to the project root)
     *
     * `runtime/` 已被 `.gitignore` 忽略，报告产物不会污染 `git status`。
     */
    protected const DEFAULT_REPORT_DIR = __DIR__ . '/../../../runtime/performance-reports';

    /**
     * 构造函数
     * Constructor
     *
     * @param PerformanceBenchmark $benchmark 性能基准管理器 | Performance benchmark manager
     * @param string|null $reportDir 报告输出目录 | Report output directory
     */
    public function __construct(PerformanceBenchmark $benchmark, ?string $reportDir = null)
    {
        $this->benchmark = $benchmark;
        $this->reportDir = $reportDir ?? self::DEFAULT_REPORT_DIR;

        // 确保目录存在：并发/只读文件系统等场景下 mkdir 可能失败，
        // 失败时立刻抛出可定位的异常，而不是等到 file_put_contents 报一条含混的 warning。
        if (!is_dir($this->reportDir) && !mkdir($this->reportDir, 0755, true) && !is_dir($this->reportDir)) {
            throw new RuntimeException(sprintf(
                '无法创建性能报告目录：「%s」。请检查目录权限，或用构造参数指定一个可写目录。',
                $this->reportDir
            ));
        }
    }

    /**
     * 生成性能报告
     * Generate performance report
     *
     * @param string $filename 文件名 | Filename
     * @return string 报告文件路径 | Report file path
     */
    public function generate(string $filename = 'performance-report.md'): string
    {
        $report = $this->buildReport();
        $filepath = $this->reportDir . '/' . $filename;

        file_put_contents($filepath, $report);

        return $filepath;
    }

    /**
     * 构建报告内容
     * Build report content
     *
     * @return string
     */
    protected function buildReport(): string
    {
        $report = "# Performance Test Report\n\n";
        $report .= '**Generated**: ' . date('Y-m-d H:i:s') . "\n\n";

        $benchmarks = $this->benchmark->getAllBenchmarks();

        if (empty($benchmarks)) {
            $report .= "No performance data available.\n";
            return $report;
        }

        // 总览
        // Overview
        $report .= "## Overview\n\n";
        $report .= "| Test Name | Execution Time | Memory Usage | Status |\n";
        $report .= "|-----------|----------------|--------------|--------|\n";

        foreach ($benchmarks as $testName => $data) {
            $regression = $this->benchmark->detectRegression($testName, $data['execution_time_ms']);
            $status = $regression['has_regression'] ? '❌ Regression' : '✅ OK';

            $report .= sprintf(
                "| %s | %.2f ms | %.2f MB | %s |\n",
                $testName,
                $data['execution_time_ms'],
                $data['memory_usage_mb'],
                $status
            );
        }

        $report .= "\n";

        // 详细信息
        // Details
        $report .= "## Details\n\n";

        foreach ($benchmarks as $testName => $data) {
            $report .= $this->buildTestDetail($testName, $data);
        }

        return $report;
    }

    /**
     * 构建测试详细信息
     * Build test detail
     *
     * @param string $testName 测试名称 | Test name
     * @param array $data 测试数据 | Test data
     * @return string
     */
    protected function buildTestDetail(string $testName, array $data): string
    {
        $detail = "### {$testName}\n\n";

        // 当前性能
        // Current performance
        $detail .= "**Current Performance**:\n";
        $detail .= "- Execution Time: {$data['execution_time_ms']} ms\n";
        $detail .= "- Memory Usage: {$data['memory_usage_mb']} MB\n";
        $detail .= "\n";

        // 性能退化检测
        // Regression detection
        $regression = $this->benchmark->detectRegression($testName, $data['execution_time_ms']);
        $detail .= "**Regression Detection**:\n";
        $detail .= '- Status: ' . ($regression['has_regression'] ? '❌ Regression Detected' : '✅ OK') . "\n";
        $detail .= "- Current: {$regression['current_time']} ms\n";
        $detail .= "- Baseline: {$regression['baseline_time']} ms\n";
        $detail .= "- Threshold: {$regression['threshold_time']} ms\n";
        $detail .= "- Degradation: {$regression['degradation_percent']}%\n";
        $detail .= "\n";

        // 历史统计
        // Historical statistics
        $stats = $this->benchmark->getStatistics($testName);
        if ($stats !== null) {
            $detail .= "**Historical Statistics** (last {$stats['count']} runs):\n";
            $detail .= "- Execution Time:\n";
            $detail .= "  - Min: {$stats['execution_time']['min']} ms\n";
            $detail .= "  - Max: {$stats['execution_time']['max']} ms\n";
            $detail .= "  - Avg: {$stats['execution_time']['avg']} ms\n";
            $detail .= "  - Median: {$stats['execution_time']['median']} ms\n";
            $detail .= "- Memory Usage:\n";
            $detail .= "  - Min: {$stats['memory_usage']['min']} MB\n";
            $detail .= "  - Max: {$stats['memory_usage']['max']} MB\n";
            $detail .= "  - Avg: {$stats['memory_usage']['avg']} MB\n";
            $detail .= "  - Median: {$stats['memory_usage']['median']} MB\n";
            $detail .= "\n";
        }

        // 历史趋势（ASCII 图表）
        // Historical trend (ASCII chart)
        $history = $this->benchmark->getHistory($testName, 10);
        if (!empty($history)) {
            $detail .= "**Historical Trend** (last 10 runs):\n";
            $detail .= "```\n";
            $detail .= $this->buildAsciiChart($history);
            $detail .= "```\n";
            $detail .= "\n";
        }

        $detail .= "---\n\n";

        return $detail;
    }

    /**
     * 构建 ASCII 图表
     * Build ASCII chart
     *
     * @param array $history 历史数据 | Historical data
     * @return string
     */
    protected function buildAsciiChart(array $history): string
    {
        $times = array_column($history, 'execution_time_ms');
        $max = max($times);
        $min = min($times);
        $range = $max - $min;

        if ($range == 0) {
            $range = 1;
        }

        $chart = '';
        $maxBarLength = 50;

        foreach ($times as $index => $time) {
            $normalized = ($time - $min) / $range;
            $barLength = (int) ($normalized * $maxBarLength);
            $bar = str_repeat('█', $barLength);

            $chart .= sprintf(
                "%2d: %s %.2f ms\n",
                $index + 1,
                str_pad($bar, $maxBarLength, ' '),
                $time
            );
        }

        return $chart;
    }
}
