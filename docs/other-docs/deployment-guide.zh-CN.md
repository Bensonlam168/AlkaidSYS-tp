# 部署指南

## 1. 概述
本指南详细说明了 AlkaidSYS 的部署流程，包括系统要求、环境设置和部署策略（Docker、Kubernetes）。

## 2. 系统要求

- **操作系统**：Ubuntu 22.04 LTS / CentOS 8+
- **PHP**：>= 8.2
- **Swoole**：>= 5.0
- **MySQL**：>= 8.0
- **Redis**：>= 6.0
- **Nginx**：>= 1.20

## 3. 环境搭建

### 3.1 PHP 和扩展安装
```bash
sudo apt install php8.2-fpm php8.2-cli  php8.2-mysql php8.2-redis \
    php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd
pecl install swoole
```

### 3.2 数据库和缓存
- 确保 MySQL 8.0+ 运行并支持 `utf8mb4` 字符集
- 确保 Redis 6.0+ 运行（建议启用密码保护）

### 3.3 Swoole 配置

**配置文件**: `config/swoole.php`

```php
return [
    'http' => [
        'host' => '0.0.0.0',
        'port' => 9501,
        'worker_num' => swoole_cpu_num() * 2,
        'task_worker_num' => swoole_cpu_num() * 2,
        'max_request' => 10000,
        'enable_static_handler' => true,
        'document_root' => root_path('public'),
    ],
    'websocket' => [
        'enable' => false,
    ],
    'task' => [
        'enable' => true,
    ],
];
```

**Systemd 服务**: `/etc/systemd/system/alkaid-swoole.service`

```ini
[Unit]
Description=AlkaidSYS Swoole Server
After=network.target mysql.service redis.service

[Service]
Type=forking
User=www-data
Group=www-data
WorkingDirectory=/var/www/alkaid
ExecStart=/usr/bin/php /var/www/alkaid/think swoole start -d
ExecReload=/usr/bin/php /var/www/alkaid/think swoole reload
ExecStop=/usr/bin/php /var/www/alkaid/think swoole stop
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## 4. 部署策略

### 4.1 单服务器部署（Swoole + Nginx）
1. **克隆代码**：`git clone ...`
2. **安装依赖**：`composer install --no-dev`
3. **配置环境**：复制 `.env.example` 为 `.env` 并更新配置
4. **数据库迁移**：`php think migrate:run`
5. **启动 Swoole**：`php think swoole start`
6. **配置 Nginx**：将流量代理到 Swoole 端口（默认 9501）

### 4.2 Docker 部署
- 使用提供的 `Dockerfile` 和 `docker-compose.yml`
- **构建镜像**：`docker build -t alkaid/sys .`
- **运行容器**：`docker run -d -p 9501:9501 alkaid/sys`

### 4.3 Kubernetes 部署
- 使用 `Deployment` 和 `Service` 清单文件
- 确保 `DB_HOST` 和 `REDIS_HOST` 环境变量指向有效的服务
- 配置 `Liveness` 和 `Readiness` 探针

### 4.4 环境变量参考

| 变量 | 必需 | 默认值 | 描述 |
|------|------|--------|------|
| `APP_ENV` | 是 | `development` | 运行环境（development/stage/production） |
| `APP_DEBUG` | 是 | `true` | 调试模式（生产环境必须为 `false`） |
| `DB_HOST` | 是 | `127.0.0.1` | 数据库主机 |
| `DB_DATABASE` | 是 | - | 数据库名称 |
| `DB_USERNAME` | 是 | - | 数据库用户名 |
| `DB_PASSWORD` | 是 | - | 数据库密码 |
| `REDIS_HOST` | 是 | `127.0.0.1` | Redis 主机 |
| `REDIS_PORT` | 否 | `6379` | Redis 端口 |
| `REDIS_PASSWORD` | 否 | - | Redis 密码（生产环境必需） |
| `REDIS_DB` | 否 | `0` | Redis 数据库编号 |
| `JWT_SECRET` | 是 | - | JWT 签名密钥（必须为强随机值） |
| `JWT_ISSUER` | 是 | - | JWT 签发者（如：`https://api.alkaidsys.com`） |
| `CACHE_DRIVER` | 否 | `redis` | 缓存驱动（生产环境必须为 `redis`） |

**多环境配置示例**：

```bash
# 开发环境 (.env.local)
APP_ENV=development
APP_DEBUG=true
CACHE_DRIVER=redis
REDIS_HOST=localhost

# 生产环境 (.env.production)
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
REDIS_HOST=redis.internal.example.com
REDIS_PASSWORD=***strong-password***
JWT_SECRET=***generate-with-openssl***
JWT_ISSUER=https://api.alkaidsys.com
```

## 5. CI/CD 流程
- 提供了 **GitHub Actions** 工作流用于自动化测试和部署
- 步骤：
  1. 检出代码
  2. 设置 PHP 环境
  3. 安装依赖
  4. 运行测试
  5. 构建 Docker 镜像
  6. 部署到目标环境

## 6. 健康检查

### 6.1 健康检查端点

**路由**: `GET /health`

**响应**（成功）：
```json
{
  "status": "ok",
  "timestamp": 1705651200,
  "services": {
    "database": "connected",
    "redis": "connected"
  }
}
```

**Kubernetes 探针**：
```yaml
livenessProbe:
  httpGet:
    path: /health
    port: 9501
  initialDelaySeconds: 30
  periodSeconds: 10

readinessProbe:
  httpGet:
    path: /health
    port: 9501
  initialDelaySeconds: 5
  periodSeconds: 5
```

## 7. 生产环境检查清单

### 7.1 配置检查
- [ ] `APP_ENV` 设置为 `production`
- [ ] `APP_DEBUG` 设置为 `false`
- [ ] `CACHE_DRIVER` 设置为 `redis`
- [ ] `JWT_SECRET` 是强随机值（最少 32 个字符）
- [ ] `JWT_ISSUER` 针对环境正确配置
- [ ] 数据库备份已配置
- [ ] Redis 已启用密码认证
- [ ] Redis 强制用于会话（禁止基于文件的会话）

### 7.2 安全检查
- [ ] SSL 证书已安装且有效
- [ ] 防火墙规则已配置（开放 80/443，阻止 9501 外部访问）
- [ ] 敏感配置已从代码中移除，使用环境变量
- [ ] 所有默认密码已更改

### 7.3 性能和监控
- [ ] Swoole 工作进程已优化（默认：CPU 核心数 × 2）
- [ ] 健康检查端点响应正常
- [ ] 日志已配置
- [ ] 监控/告警系统已部署

### 7.4 数据层
- [ ] 数据库迁移已测试
- [ ] 数据库索引已验证
- [ ] 查询性能已验证

> **参考**：完整部署要求见 `design/05-deployment-testing/14-deployment-guide.md`
