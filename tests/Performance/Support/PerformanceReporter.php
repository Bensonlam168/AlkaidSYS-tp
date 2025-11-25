<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

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
     * 构造函数
     * Constructor
     *
     * @param PerformanceBenchmark $benchmark 性能基准管理器 | Performance benchmark manager
     * @param string|null $reportDir 报告输出目录 | Report output directory
     */
    public function __construct(PerformanceBenchmark $benchmark, ?string $reportDir = null)
    {
        $this->benchmark = $benchmark;
        $this->reportDir = $reportDir ?? __DIR__ . '/../../../docs/report';

        // 确保目录存在
        // Ensure directory exists
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0755, true);
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
        $report .= "**Generated**: " . date('Y-m-d H:i:s') . "\n\n";

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
        $detail .= "- Status: " . ($regression['has_regression'] ? '❌ Regression Detected' : '✅ OK') . "\n";
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

        $chart = "";
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

