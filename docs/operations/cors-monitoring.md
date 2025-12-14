# CORS 监控与告警指南 | CORS Monitoring and Alerting Guide

## 概述 | Overview

本文档详细说明 AlkaidSYS 项目的 CORS 监控策略、日志分析方法和告警规则配置。

This document details CORS monitoring strategy, log analysis methods, and alerting rule configuration for AlkaidSYS project.

---

## 监控架构 | Monitoring Architecture

```
CORS 中间件 | CORS Middleware
    ↓
    ↓ 记录拒绝事件 | Log Rejection Events
    ↓
CORS 日志文件 | CORS Log Files
(runtime/log/cors/cors-YYYYMMDD.log)
    ↓
    ↓ 日志采集 | Log Collection
    ↓
日志分析系统 | Log Analysis System
(ELK / Loki / CloudWatch Logs)
    ↓
    ↓ 指标计算 | Metrics Calculation
    ↓
监控告警系统 | Monitoring & Alerting System
(Prometheus / Grafana / CloudWatch Alarms)
```

---

## 日志格式 | Log Format

### CORS 拒绝日志 | CORS Rejection Log

**文件位置** | File Location: `runtime/log/cors/cors-YYYYMMDD.log`

**日志格式** | Log Format:
```json
{
  "timestamp": "2025-11-25T10:30:00+00:00",
  "env": "production",
  "trace_id": "abc123def456...",
  "event": "cors_rejected",
  "origin": "https://unknown.example.com",
  "method": "POST",
  "path": "/v1/auth/login",
  "client_ip": "1.2.3.4",
  "user_agent": "Mozilla/5.0...",
  "allowed_origins": [
    "https://app.alkaidsys.com",
    "https://admin.alkaidsys.com"
  ]
}
```

### CORS 配置警告日志 | CORS Configuration Warning Log

```json
{
  "timestamp": "2025-11-25T10:00:00+00:00",
  "level": "WARNING",
  "event": "cors_config_warning",
  "message": "CORS configuration warning: Using localhost origins in production environment",
  "context": {
    "env": "production",
    "localhost_origins": ["http://localhost:5666"]
  }
}
```

---

## 监控指标 | Monitoring Metrics

### 1. CORS 拒绝率 | CORS Rejection Rate

**定义** | Definition:
```
CORS 拒绝率 = (CORS 拒绝请求数 / 总请求数) × 100%
CORS Rejection Rate = (CORS Rejected Requests / Total Requests) × 100%
```

**数据源** | Data Source:
- CORS 拒绝数：`runtime/log/cors/cors-YYYYMMDD.log` 中 `event=cors_rejected` 的条目数
- 总请求数：`runtime/log/access/access-YYYYMMDD.log` 的总条目数

**正常范围** | Normal Range:
- 开发环境：< 5%
- 测试环境：< 2%
- 生产环境：< 0.1%

**告警阈值** | Alert Threshold:
- Warning: > 1%
- Critical: > 5%

### 2. 被拒绝的源分布 | Rejected Origin Distribution

**定义** | Definition:
统计被拒绝的 Origin 及其出现频率

Count rejected origins and their frequencies

**用途** | Purpose:
- 识别潜在的合法源（需要加入白名单）
- 识别恶意攻击源
- 发现配置错误

**分析方法** | Analysis Method:
```bash
# 统计被拒绝的源 Top 10
# Count top 10 rejected origins
cat runtime/log/cors/cors-*.log | \
  jq -r 'select(.event=="cors_rejected") | .origin' | \
  sort | uniq -c | sort -rn | head -10
```

### 3. CORS 预检请求响应时间 | CORS Preflight Response Time

**定义** | Definition:
OPTIONS 请求的平均响应时间

Average response time for OPTIONS requests

**数据源** | Data Source:
- Nginx 访问日志：`/var/log/nginx/alkaid_api_access.log`
- 过滤条件：`method=OPTIONS`

**正常范围** | Normal Range:
- Nginx 层处理：< 10ms
- 应用层处理：< 50ms

**告警阈值** | Alert Threshold:
- Warning: > 100ms
- Critical: > 500ms

### 4. 配置警告频率 | Configuration Warning Frequency

**定义** | Definition:
CORS 配置警告日志的出现频率

Frequency of CORS configuration warning logs

**告警规则** | Alert Rule:
- 生产环境出现任何配置警告 → 立即告警
- Any configuration warning in production → Immediate alert

---

## 日志分析 | Log Analysis

### 使用 jq 分析日志 | Analyze Logs with jq

#### 1. 统计每小时的 CORS 拒绝数 | Count CORS Rejections per Hour

```bash
cat runtime/log/cors/cors-$(date +%Y%m%d).log | \
  jq -r 'select(.event=="cors_rejected") | .timestamp' | \
  cut -d'T' -f2 | cut -d':' -f1 | \
  sort | uniq -c
```

#### 2. 查找特定源的拒绝记录 | Find Rejections for Specific Origin

```bash
cat runtime/log/cors/cors-*.log | \
  jq 'select(.event=="cors_rejected" and .origin=="https://unknown.example.com")'
```

#### 3. 统计被拒绝的路径分布 | Count Rejected Path Distribution

```bash
cat runtime/log/cors/cors-*.log | \
  jq -r 'select(.event=="cors_rejected") | .path' | \
  sort | uniq -c | sort -rn
```

#### 4. 查找配置警告 | Find Configuration Warnings

```bash
cat runtime/log/cors/cors-*.log | \
  jq 'select(.event=="cors_config_warning")'
```

### 使用 ELK Stack 分析 | Analyze with ELK Stack

#### Elasticsearch 查询示例 | Elasticsearch Query Example

```json
{
  "query": {
    "bool": {
      "must": [
        { "match": { "event": "cors_rejected" } },
        { "range": { "timestamp": { "gte": "now-1h" } } }
      ]
    }
  },
  "aggs": {
    "rejected_origins": {
      "terms": { "field": "origin.keyword", "size": 10 }
    }
  }
}
```

#### Kibana 可视化 | Kibana Visualization

1. **CORS 拒绝率趋势图** | CORS Rejection Rate Trend
   - 类型：Line Chart
   - X 轴：时间戳
   - Y 轴：拒绝率（%）

2. **被拒绝的源 Top 10** | Top 10 Rejected Origins
   - 类型：Pie Chart
   - 字段：origin.keyword

3. **CORS 拒绝地理分布** | CORS Rejection Geographic Distribution
   - 类型：Map
   - 字段：client_ip（需要 GeoIP 插件）

---

## 告警规则 | Alerting Rules

### Prometheus 告警规则示例 | Prometheus Alert Rules Example

```yaml
groups:
  - name: cors_alerts
    interval: 1m
    rules:
      # CORS 拒绝率过高
      # High CORS rejection rate
      - alert: HighCORSRejectionRate
        expr: |
          (
            sum(rate(cors_rejected_total[5m]))
            /
            sum(rate(http_requests_total[5m]))
          ) > 0.01
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High CORS rejection rate detected"
          description: "CORS rejection rate is {{ $value | humanizePercentage }} (threshold: 1%)"

      # 新的未知源频繁请求
      # Frequent requests from new unknown origin
      - alert: FrequentUnknownOrigin
        expr: |
          sum by (origin) (
            rate(cors_rejected_total{origin!~"http://localhost.*"}[5m])
          ) > 10
        for: 10m
        labels:
          severity: info
        annotations:
          summary: "Frequent requests from unknown origin: {{ $labels.origin }}"
          description: "Origin {{ $labels.origin }} has been rejected {{ $value }} times/sec"

      # 生产环境配置警告
      # Production configuration warning
      - alert: CORSConfigWarningInProduction
        expr: |
          cors_config_warning_total{env="production"} > 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "CORS configuration warning in production"
          description: "Production environment has CORS configuration warnings"
```

### CloudWatch Alarms 示例 | CloudWatch Alarms Example

```json
{
  "AlarmName": "HighCORSRejectionRate",
  "MetricName": "CORSRejectionRate",
  "Namespace": "AlkaidSYS/API",
  "Statistic": "Average",
  "Period": 300,
  "EvaluationPeriods": 2,
  "Threshold": 1.0,
  "ComparisonOperator": "GreaterThanThreshold",
  "AlarmActions": ["arn:aws:sns:region:account:topic"],
  "AlarmDescription": "CORS rejection rate exceeds 1%"
}
```

---

## 运维建议 | Operational Recommendations

### 日常监控 | Daily Monitoring

1. **每日检查 CORS 拒绝日志**
   - 查看是否有新的未知源
   - 评估是否需要加入白名单

2. **每周分析 CORS 拒绝趋势**
   - 识别异常峰值
   - 调整告警阈值

3. **每月审查 CORS 配置**
   - 移除不再使用的源
   - 更新白名单

### 应急响应 | Incident Response

#### 场景 1：CORS 拒绝率突然升高

**可能原因** | Possible Causes:
- 前端部署了新域名但未更新 CORS 配置
- 恶意攻击或爬虫
- 配置错误

**处理步骤** | Response Steps:
1. 查看 CORS 拒绝日志，识别被拒绝的源
2. 确认是否为合法源
3. 如果是合法源，更新 CORS 配置
4. 如果是攻击，考虑添加 IP 黑名单或限流规则

#### 场景 2：生产环境出现配置警告

**处理步骤** | Response Steps:
1. 立即检查 `.env` 文件中的 `CORS_ALLOWED_ORIGINS` 配置
2. 移除所有 localhost 相关的源
3. 更新为正确的生产域名
4. 重启应用
5. 验证警告是否消失

---

## 集成示例 | Integration Examples

### 与 Grafana 集成 | Integration with Grafana

创建 Grafana Dashboard 监控 CORS 指标：

1. 添加数据源（Prometheus / Loki / Elasticsearch）
2. 创建面板：
   - CORS 拒绝率趋势
   - 被拒绝的源 Top 10
   - CORS 预检请求响应时间
   - 配置警告计数

### 与 Slack 集成 | Integration with Slack

配置告警通知到 Slack：

```bash
# Prometheus Alertmanager 配置
receivers:
  - name: 'slack-cors-alerts'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL'
        channel: '#cors-alerts'
        title: 'CORS Alert: {{ .GroupLabels.alertname }}'
        text: '{{ range .Alerts }}{{ .Annotations.description }}{{ end }}'
```

---

## 总结 | Summary

通过完善的 CORS 监控和告警机制，可以：

1. ✅ 及时发现配置问题
2. ✅ 识别潜在的安全威胁
3. ✅ 优化 CORS 配置
4. ✅ 提升系统可观测性

定期检查日志和监控指标，确保 CORS 配置的安全性和有效性。

