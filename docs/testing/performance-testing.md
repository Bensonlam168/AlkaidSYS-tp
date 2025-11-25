# 性能测试套件使用指南

## 概述

性能测试套件提供了独立的性能测试框架，用于测试、记录和监控系统性能。

## 目录结构

```
tests/Performance/
├── Benchmark/          # 基准测试
│   └── Permission/     # 权限相关的基准测试
├── Load/               # 负载测试
├── Stress/             # 压力测试
├── Support/            # 支持类
│   ├── PerformanceBenchmark.php  # 性能基准管理
│   └── PerformanceReporter.php   # 性能报告生成
└── .benchmarks/        # 基准数据存储（JSON 文件）
```

## 运行性能测试

### 运行所有性能测试

```bash
vendor/bin/phpunit -c phpunit.performance.xml
```

### 运行特定测试套件

```bash
# 运行基准测试
vendor/bin/phpunit -c phpunit.performance.xml --testsuite=Benchmark

# 运行负载测试
vendor/bin/phpunit -c phpunit.performance.xml --testsuite=Load

# 运行压力测试
vendor/bin/phpunit -c phpunit.performance.xml --testsuite=Stress
```

## 性能基准管理

### PerformanceBenchmark 类

用于记录、管理和对比性能基准数据。

**功能**：
- 记录性能基准数据到 JSON 文件
- 对比当前性能与历史基准
- 检测性能退化
- 生成性能趋势数据

**使用方法**：

```php
use Tests\Performance\Support\PerformanceBenchmark;

class MyPerformanceTest extends ThinkPHPTestCase
{
    protected PerformanceBenchmark $benchmark;

    protected function setUp(): void
    {
        parent::setUp();
        $this->benchmark = new PerformanceBenchmark();
    }

    public function testPerformance()
    {
        $startTime = microtime(true);
        
        // 执行测试
        // ...
        
        $executionTime = (microtime(true) - $startTime) * 1000; // ms
        
        // 记录基准
        $this->benchmark->record(
            'my_test',
            $executionTime,
            memory_get_peak_usage(),
            ['iterations' => 100]
        );
        
        // 检测性能退化
        $regression = $this->benchmark->detectRegression('my_test', $executionTime);
        if ($regression['has_regression']) {
            $this->markTestIncomplete($regression['message']);
        }
    }
}
```

### 性能退化检测

性能退化阈值默认为 20%，即性能下降超过 20% 视为退化。

可以通过 `setRegressionThreshold()` 方法调整：

```php
$this->benchmark->setRegressionThreshold(0.10); // 10%
```

## 性能报告生成

### PerformanceReporter 类

生成 Markdown 格式的性能测试报告。

**功能**：
- 生成性能测试报告
- 包含当前性能、历史趋势、性能对比
- 支持 ASCII 图表

**使用方法**：

```php
use Tests\Performance\Support\PerformanceReporter;

protected function tearDown(): void
{
    $reporter = new PerformanceReporter($this->benchmark);
    $reportFile = $reporter->generate('my-performance-report.md');
    echo "\nPerformance report generated: {$reportFile}\n";
    
    parent::tearDown();
}
```

## 性能报告解读

性能报告包含以下内容：

### 1. 总览

| Test Name | Execution Time | Memory Usage | Status |
|-----------|----------------|--------------|--------|
| test_name | 10.50 ms | 5.20 MB | ✅ OK |

### 2. 详细信息

**Current Performance**:
- Execution Time: 10.50 ms
- Memory Usage: 5.20 MB

**Regression Detection**:
- Status: ✅ OK
- Current: 10.50 ms
- Baseline: 10.00 ms
- Threshold: 12.00 ms
- Degradation: 5.00%

**Historical Statistics** (last 100 runs):
- Execution Time:
  - Min: 9.50 ms
  - Max: 11.00 ms
  - Avg: 10.20 ms
  - Median: 10.00 ms

**Historical Trend** (last 10 runs):
```
 1: ████████████████████████████████████████████████ 10.50 ms
 2: ██████████████████████████████████████████████   10.20 ms
 3: ████████████████████████████████████████████████ 10.50 ms
...
```

## 最佳实践

### 1. 使用基准测试验证性能目标

```php
public function testPerformance()
{
    // 执行测试
    $executionTime = $this->measurePerformance();
    
    // 记录基准
    $this->benchmark->record('test_name', $executionTime, memory_get_peak_usage());
    
    // 验证性能目标
    $this->assertLessThan(100, $executionTime, 'Should be < 100ms');
    
    // 检测性能退化
    $regression = $this->benchmark->detectRegression('test_name', $executionTime);
    if ($regression['has_regression']) {
        $this->markTestIncomplete($regression['message']);
    }
}
```

### 2. 定期更新基准数据

基准数据会随着系统优化而变化，应定期更新：

```bash
# 删除旧的基准数据
rm -rf tests/Performance/.benchmarks/

# 重新运行性能测试建立新基准
vendor/bin/phpunit -c phpunit.performance.xml
```

### 3. 在 CI/CD 中集成性能测试

```yaml
# .github/workflows/performance.yml
name: Performance Tests

on: [push, pull_request]

jobs:
  performance:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Run performance tests
        run: vendor/bin/phpunit -c phpunit.performance.xml
      - name: Upload performance report
        uses: actions/upload-artifact@v2
        with:
          name: performance-report
          path: docs/report/
```

## 故障排查

### 问题 1：性能退化误报

**症状**：测试标记为 incomplete，但实际性能正常

**原因**：
- 测试环境性能波动
- 基准数据过时
- 退化阈值设置过严格

**解决方案**：
- 调整退化阈值：`$this->benchmark->setRegressionThreshold(0.30)`
- 更新基准数据
- 在稳定的环境中运行性能测试

### 问题 2：基准数据丢失

**症状**：`No historical data available`

**原因**：
- 基准数据文件被删除
- 测试名称更改

**解决方案**：
- 重新运行测试建立基准
- 使用一致的测试名称

## 参考

- [PHPUnit 文档](https://phpunit.de/documentation.html)
- [性能测试最佳实践](https://martinfowler.com/articles/performance-testing.html)

