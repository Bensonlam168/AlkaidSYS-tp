# Deployment Guide

## 1. Overview
This guide details the deployment process for AlkaidSYS, covering system requirements, environment setup, and deployment strategies (Docker, K8s).

## 2. System Requirements

- **OS**: Ubuntu 22.04 LTS / CentOS 8+
- **PHP**: >= 8.2
- **Swoole**: >= 5.0
- **MySQL**: >= 8.0
- **Redis**: >= 6.0
- **Nginx**: >= 1.20

## 3. Environment Setup

### 3.1 PHP & Extensions
```bash
sudo apt install php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
    php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd
pecl install swoole
```

### 3.2 Database & Cache
- Ensure MySQL 8.0+ is running with `utf8mb4` support.
- Ensure Redis 6.0+ is running (password protection recommended).

### 3.3 Swoole Configuration

**Configuration File**: `config/swoole.php`

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

**Systemd Service**: `/etc/systemd/system/alkaid-swoole.service`

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

## 4. Deployment Strategies

### 4.1 Single Server (Swoole + Nginx)
1. **Clone Code**: `git clone ...`
2. **Install Dependencies**: `composer install --no-dev`
3. **Configure**: Copy `.env.example` to `.env` and update credentials.
4. **Migrate**: `php think migrate:run`
5. **Start Swoole**: `php think swoole start`
6. **Configure Nginx**: Proxy traffic to Swoole port (default 9501).

### 4.2 Docker
- Use provided `Dockerfile` and `docker-compose.yml`.
- **Build**: `docker build -t alkaid/sys .`
- **Run**: `docker run -d -p 9501:9501 alkaid/sys`

### 4.3 Kubernetes
- Use `Deployment` and `Service` manifests.
- Ensure `DB_HOST` and `REDIS_HOST` env vars point to valid services.
- Configure `Liveness` and `Readiness` probes.

### 4.4 Environment Variables Reference

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_ENV` | Yes | `development` | Environment (development/stage/production) |
| `APP_DEBUG` | Yes | `true` | Debug mode (MUST be `false` in production) |
| `DB_HOST` | Yes | `127.0.0.1` | Database host |
| `DB_DATABASE` | Yes | - | Database name |
| `DB_USERNAME` | Yes | - | Database username |
| `DB_PASSWORD` | Yes | - | Database password |
| `REDIS_HOST` | Yes | `127.0.0.1` | Redis host |
| `REDIS_PORT` | No | `6379` | Redis port |
| `REDIS_PASSWORD` | No | - | Redis password (REQUIRED in production) |
| `REDIS_DB` | No | `0` | Redis database number |
| `JWT_SECRET` | Yes | - | JWT signing secret (MUST be strong random value) |
| `JWT_ISSUER` | Yes | - | JWT issuer (e.g., `https://api.alkaidsys.com`) |
| `CACHE_DRIVER` | No | `redis` | Cache driver (MUST be `redis` in production) |

**Multi-Environment Examples**:

```bash
# Development (.env.local)
APP_ENV=development
APP_DEBUG=true
CACHE_DRIVER=redis
REDIS_HOST=localhost

# Production (.env.production)
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
REDIS_HOST=redis.internal.example.com
REDIS_PASSWORD=***strong-password***
JWT_SECRET=***generate-with-openssl***
JWT_ISSUER=https://api.alkaidsys.com
```

## 5. CI/CD
- **GitHub Actions** workflows provided for automated testing and deployment.
- Steps:
  1. Checkout code
  2. Setup PHP
  3. Install dependencies
  4. Run tests
  5. Build Docker image
  6. Deploy to target environment

## 6. Health Checks

### 6.1 Health Check Endpoint

**Route**: `GET /health`

**Response** (Success):
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

**Kubernetes Probes**:
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

## 7. Production Checklist

### 7.1 Configuration
- [ ] `APP_ENV` set to `production`
- [ ] `APP_DEBUG` set to `false`
- [ ] `CACHE_DRIVER` set to `redis`
- [ ] `JWT_SECRET` is a strong random value (min 32 characters)
- [ ] `JWT_ISSUER` correctly configured for environment
- [ ] Database backups configured
- [ ] Redis password authentication enabled
- [ ] Redis enforced for sessions (no file-based sessions)

### 7.2 Security
- [ ] SSL certificates installed and valid
- [ ] Firewall rules configured (allow 80/443, block 9501 external access)
- [ ] Sensitive configs removed from code, using environment variables
- [ ] All default passwords changed

### 7.3 Performance & Monitoring
- [ ] Swoole worker processes optimized (default: CPU cores × 2)
- [ ] Health check endpoint responding
- [ ] Logging configured
- [ ] Monitoring/alerting system deployed

### 7.4 Data Layer
- [ ] Database migrations tested
- [ ] Database indexes verified
- [ ] Query performance validated

> **Reference**: Complete deployment requirements in `design/05-deployment-testing/14-deployment-guide.md`
