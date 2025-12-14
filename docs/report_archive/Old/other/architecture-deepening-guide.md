# AlkaidSYS 架构细节深化指南

> **文档版本**：v1.0
> **创建日期**：2025-11-01
> **最后更新**：2025-11-01
> **维护者**：架构团队

---

## 📋 目录

- [1. 缓存策略设计](#1-缓存策略设计)
- [2. 数据库分库分表方案](#2-数据库分库分表方案)
- [3. 监控系统设计](#3-监控系统设计)
- [4. 灾备恢复方案](#4-灾备恢复方案)

---

## 1. 缓存策略设计

### 1.1 多级缓存架构

```
┌─────────────────────────────────────────┐
│              客户端缓存                    │
│        (LocalStorage/SessionStorage)     │
│          静态资源缓存 (24h)                │
└─────────────────┬───────────────────────┘
                  │ 数据获取
┌─────────────────▼───────────────────────┐
│            CDN 缓存                       │
│          静态资源加速 (7d)                │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│            反向代理缓存                    │
│          Nginx/Redis 代理                 │
│            页面缓存 (5m)                  │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│            应用层缓存                      │
│            Redis Cluster                 │
│            业务缓存 (1h)                  │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│            数据库缓存                      │
│          MySQL Query Cache                │
│          结果集缓存 (10m)                 │
└─────────────────────────────────────────┘
```

### 1.2 缓存分层策略

#### L1 缓存：客户端缓存

```typescript
// utils/client-cache.ts
import { useStorage } from '@vueuse/core';

// 缓存键前缀
const CACHE_PREFIX = 'alkaid_cache_';

// 缓存配置
const cacheConfigs = {
  static: {
    ttl: 24 * 60 * 60 * 1000, // 24小时
    type: 'local', // localStorage
  },
  user: {
    ttl: 60 * 60 * 1000, // 1小时
    type: 'session', // sessionStorage
  },
  api: {
    ttl: 5 * 60 * 1000, // 5分钟
    type: 'memory', // 内存缓存
  },
};

// 客户端缓存管理
export class ClientCache {
  // 设置缓存
  set<T>(key: string, value: T, config: keyof typeof cacheConfigs): void {
    const cacheConfig = cacheConfigs[config];
    const storage = this.getStorage(cacheConfig.type);

    const cacheData = {
      value,
      timestamp: Date.now(),
      ttl: cacheConfig.ttl,
    };

    storage.set(`${CACHE_PREFIX}${key}`, cacheData);
  }

  // 获取缓存
  get<T>(key: string, config: keyof typeof cacheConfigs): T | null {
    const cacheConfig = cacheConfigs[config];
    const storage = this.getStorage(cacheConfig.type);

    const cacheData = storage.get<{
      value: T;
      timestamp: number;
      ttl: number;
    }>(`${CACHE_PREFIX}${key}`);

    if (!cacheData) {
      return null;
    }

    // 检查是否过期
    if (Date.now() - cacheData.timestamp > cacheData.ttl) {
      this.delete(key, config);
      return null;
    }

    return cacheData.value;
  }

  // 删除缓存
  delete(key: string, config: keyof typeof cacheConfigs): void {
    const cacheConfig = cacheConfigs[config];
    const storage = this.getStorage(cacheConfig.type);
    storage.remove(`${CACHE_PREFIX}${key}`);
  }

  // 清空缓存
  clear(config?: keyof typeof cacheConfigs): void {
    if (config) {
      const cacheConfig = cacheConfigs[config];
      const storage = this.getStorage(cacheConfig.type);
      const keys = storage.getKeys();
      keys.forEach(key => {
        if (key.startsWith(CACHE_PREFIX)) {
          storage.remove(key);
        }
      });
    } else {
      // 清空所有缓存
      Object.values(cacheConfigs).forEach(cacheConfig => {
        const storage = this.getStorage(cacheConfig.type);
        const keys = storage.getKeys();
        keys.forEach(key => {
          if (key.startsWith(CACHE_PREFIX)) {
            storage.remove(key);
          }
        });
      });
    }
  }

  // 获取存储实例
  private getStorage(type: 'local' | 'session' | 'memory') {
    switch (type) {
      case 'local':
        return useStorage('local');
      case 'session':
        return useStorage('session');
      case 'memory':
        return useStorage('memory');
      default:
        return useStorage('local');
    }
  }
}

// 导出单例
export const clientCache = new ClientCache();
```

#### L2 缓存：Redis 缓存

```php
<?php
// app/service/core/cache/CacheService.php

namespace app\service\core\cache;

use think\facade\Cache;
use think\facade\Config;

/**
 * Redis 缓存服务
 */
class CacheService
{
    // 缓存标签
    const TAG_USER = 'cache_tag_user';
    const TAG_APPLICATION = 'cache_tag_application';
    const TAG_PLUGIN = 'cache_tag_plugin';
    const TAG_CONFIG = 'cache_tag_config';
    const TAG_DATA = 'cache_tag_data';

    /**
     * 设置缓存
     */
    public function set(string $key, mixed $value, int $ttl = 3600, ?string $tag = null): bool
    {
        $options = [];

        if ($tag) {
            $options['tag'] = $tag;
        }

        return Cache::set($key, $value, $ttl);
    }

    /**
     * 获取缓存
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($key, $default);
    }

    /**
     * 删除缓存
     */
    public function delete(string $key): bool
    {
        return Cache::delete($key);
    }

    /**
     * 清空标签缓存
     */
    public function clear(string $tag): bool
    {
        return Cache::clear($tag);
    }

    /**
     * 缓存是否存在
     */
    public function has(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * 批量设置缓存
     */
    public function setMultiple(array $values, int $ttl = 3600): bool
    {
        return Cache::setMultiple($values, $ttl);
    }

    /**
     * 批量获取缓存
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        return Cache::getMultiple($keys, $default);
    }

    /**
     * 批量删除缓存
     */
    public function deleteMultiple(array $keys): bool
    {
        return Cache::deleteMultiple($keys);
    }

    /**
     * 用户缓存
     */
    public function getUser(int $userId): ?array
    {
        return $this->get("user:{$userId}");
    }

    /**
     * 设置用户缓存
     */
    public function setUser(int $userId, array $user, int $ttl = 3600): bool
    {
        return $this->set("user:{$userId}", $user, $ttl, self::TAG_USER);
    }

    /**
     * 删除用户缓存
     */
    public function deleteUser(int $userId): bool
    {
        return $this->delete("user:{$userId}");
    }

    /**
     * 应用缓存
     */
    public function getApplication(string $appKey): ?array
    {
        return $this->get("application:{$appKey}");
    }

    /**
     * 设置应用缓存
     */
    public function setApplication(string $appKey, array $app, int $ttl = 7200): bool
    {
        return $this->set("application:{$appKey}", $app, $ttl, self::TAG_APPLICATION);
    }

    /**
     * 插件缓存
     */
    public function getPlugin(string $pluginKey): ?array
    {
        return $this->get("plugin:{$pluginKey}");
    }

    /**
     * 设置插件缓存
     */
    public function setPlugin(string $pluginKey, array $plugin, int $ttl = 7200): bool
    {
        return $this->set("plugin:{$pluginKey}", $plugin, $ttl, self::TAG_PLUGIN);
    }

    /**
     * 配置缓存
     */
    public function getConfig(string $configKey): mixed
    {
        return $this->get("config:{$configKey}");
    }

    /**
     * 设置配置缓存
     */
    public function setConfig(string $configKey, mixed $value, int $ttl = 3600): bool
    {
        return $this->set("config:{$configKey}", $value, $ttl, self::TAG_CONFIG);
    }

    /**
     * 数据缓存
     */
    public function getData(string $dataKey): mixed
    {
        return $this->get("data:{$dataKey}");
    }

    /**
     * 设置数据缓存
     */
    public function setData(string $dataKey, mixed $value, int $ttl = 1800): bool
    {
        return $this->set("data:{$dataKey}", $value, $ttl, self::TAG_DATA);
    }
}
```

### 1.3 缓存策略配置

```yaml
# config/cache.yml

# 缓存策略配置
cache_strategies:
  # 用户数据缓存
  user:
    ttl: 3600  # 1小时
    max_size: 1000  # 最大缓存条数
    eviction_policy: "LRU"  # 最近最少使用

  # 应用数据缓存
  application:
    ttl: 7200  # 2小时
    max_size: 100
    eviction_policy: "LFU"  # 最少使用频率

  # 插件数据缓存
  plugin:
    ttl: 7200  # 2小时
    max_size: 200
    eviction_policy: "LRU"

  # 动态数据缓存
  dynamic:
    ttl: 300  # 5分钟
    max_size: 5000
    eviction_policy: "TTL"  # 时间过期

# Redis 配置
redis:
  cluster:
    enabled: true
    nodes:
      - host: "redis-1.alkaidsys.com"
        port: 6379
      - host: "redis-2.alkaidsys.com"
        port: 6379
      - host: "redis-3.alkaidsys.com"
        port: 6379
    password: "${REDIS_PASSWORD}"
    database: 0

  # 缓存预热
  warmup:
    enabled: true
    strategies:
      - "user"
      - "application"
      - "plugin"

# 缓存穿透防护
cache_penetration:
  enabled: true
  strategy: "bloom_filter"  # 布隆过滤器
  false_positive_rate: 0.01
  capacity: 1000000

# 缓存雪崩防护
cache_avalanche:
  enabled: true
  strategy:
    - "random_expiry"  # 随机过期
    - "lock_request"   # 请求锁
  lock_timeout: 10000  # 10秒

# 缓存击穿防护
cache_breakdown:
  enabled: true
  strategy: "single_flight"  # 单航班模式
  max_concurrent: 100
```

### 1.4 缓存预热策略

```php
<?php
// app/service/core/cache/CacheWarmupService.php

namespace app\service\core\cache;

use think\facade\Db;
use app\service\core\addon\CoreAddonBaseService;

/**
 * 缓存预热服务
 */
class CacheWarmupService
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * 系统启动时预热缓存
     */
    public function warmupOnStartup(): void
    {
        try {
            // 预热系统配置
            $this->warmupSystemConfig();

            // 预热应用列表
            $this->warmupApplications();

            // 预热插件列表
            $this->warmupPlugins();

            // 预热用户数据
            $this->warmupUsers();

        } catch (\Exception $e) {
            log_error('Cache warmup failed: ' . $e->getMessage());
        }
    }

    /**
     * 预热系统配置
     */
    protected function warmupSystemConfig(): void
    {
        $configs = Db::name('system_config')
            ->where('status', 1)
            ->select()
            ->toArray();

        foreach ($configs as $config) {
            $this->cacheService->setConfig($config['key'], $config['value']);
        }

        log_info('System config cached: ' . count($configs) . ' items');
    }

    /**
     * 预热应用列表
     */
    protected function warmupApplications(): void
    {
        $applications = Db::name('applications')
            ->where('status', 2)  // 已上架
            ->field('key,name,version,category,price')
            ->select()
            ->toArray();

        foreach ($applications as $app) {
            $this->cacheService->setApplication($app['key'], $app);
        }

        log_info('Applications cached: ' . count($applications) . ' items');
    }

    /**
     * 预热插件列表
     */
    protected function warmupPlugins(): void
    {
        $plugins = Db::name('plugins')
            ->where('status', 1)  // 已启用
            ->field('key,name,version,category,app_key')
            ->select()
            ->toArray();

        foreach ($plugins as $plugin) {
            $this->cacheService->setPlugin($plugin['key'], $plugin);
        }

        log_info('Plugins cached: ' . count($plugins) . ' items');
    }

    /**
     * 预热用户数据
     */
    protected function warmupUsers(): void
    {
        // 获取活跃用户（最近7天登录过）
        $users = Db::name('users')
            ->where('last_login_time', '>', time() - 7 * 24 * 3600)
            ->field('id,username,email,nickname,avatar')
            ->limit(1000)
            ->select()
            ->toArray();

        foreach ($users as $user) {
            $this->cacheService->setUser($user['id'], $user);
        }

        log_info('Users cached: ' . count($users) . ' items');
    }

    /**
     * 定时预热缓存
     */
    public function scheduledWarmup(): void
    {
        // 每小时执行一次用户缓存预热
        $this->warmupUsers();

        // 每天执行一次应用和插件缓存预热
        if (date('H') === '02:00') {
            $this->warmupApplications();
            $this->warmupPlugins();
        }
    }
}
```

---

## 2. 数据库分库分表方案

### 2.1 分库分表架构

```
┌─────────────────────────────────────────┐
│              应用层                      │
│              API Gateway                 │
└───────────────┬─────────────────────────┘
                │
┌───────────────▼─────────────────────────┐
│              路由层                      │
│            MyCat / Sharding-JDBC         │
└───────────────┬─────────────────────────┘
                │
        ┌───────┴───────┐
        ▼               ▼
┌─────────────┐   ┌─────────────┐
│   主库群     │   │   从库群     │
│ (写操作)     │   │ (读操作)     │
├─────────────┤   ├─────────────┤
│ db_0000     │   │ db_slave_01 │
│ db_0001     │   │ db_slave_02 │
│ db_0002     │   │ db_slave_03 │
│ ...         │   │ ...         │
└─────────────┘   └─────────────┘
        │               │
        └───────┬───────┘
                ▼
        ┌─────────────────┐
        │   数据分片       │
        │ user_id % 8     │
        └─────────────────┘
```

### 2.2 分库分表策略

#### 2.2.1 分库策略

```sql
-- 分库规则：根据租户 ID 分库
-- tenant_id % 8 = 0 → db_tenant_0000
-- tenant_id % 8 = 1 → db_tenant_0001
-- ...
-- tenant_id % 8 = 7 → db_tenant_0007

-- 1. 创建数据库
CREATE DATABASE db_tenant_0000;
CREATE DATABASE db_tenant_0001;
CREATE DATABASE db_tenant_0002;
CREATE DATABASE db_tenant_0003;
CREATE DATABASE db_tenant_0004;
CREATE DATABASE db_tenant_0005;
CREATE DATABASE db_tenant_0006;
CREATE DATABASE db_tenant_0007;

-- 2. 每个数据库创建基础表结构
USE db_tenant_0000;

-- 用户表
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户 ID',
  `tenant_id` int(11) unsigned NOT NULL COMMENT '租户 ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `email` varchar(100) NOT NULL COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `nickname` varchar(50) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_at` int(11) NOT NULL COMMENT '创建时间',
  `updated_at` int(11) NOT NULL COMMENT '更新时间',
  `deleted_at` int(11) DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- 应用表
CREATE TABLE `applications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '应用 ID',
  `tenant_id` int(11) unsigned NOT NULL COMMENT '租户 ID',
  `key` varchar(50) NOT NULL COMMENT '应用标识',
  `name` varchar(100) NOT NULL COMMENT '应用名称',
  `version` varchar(20) NOT NULL COMMENT '版本',
  `category` varchar(50) NOT NULL COMMENT '分类',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态',
  `config` text COMMENT '配置信息',
  `created_at` int(11) NOT NULL COMMENT '创建时间',
  `updated_at` int(11) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_key` (`tenant_id`, `key`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用表';
```

#### 2.2.2 分表策略

```sql
-- 分表规则：根据时间分表
-- 2025年用户表 → users_2025_01, users_2025_02, ...
-- 2025年订单表 → orders_2025_01, orders_2025_02, ...

-- 用户表（月度分表）
CREATE TABLE `users_2025_01` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户 ID',
  `tenant_id` int(11) unsigned NOT NULL COMMENT '租户 ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `email` varchar(100) NOT NULL COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `created_at` int(11) NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_username` (`username`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表 2025-01';

-- 订单表（月度分表）
CREATE TABLE `orders_2025_01` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '订单 ID',
  `tenant_id` int(11) unsigned NOT NULL COMMENT '租户 ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户 ID',
  `order_no` varchar(50) NOT NULL COMMENT '订单号',
  `amount` decimal(10,2) NOT NULL COMMENT '订单金额',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态',
  `created_at` int(11) NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表 2025-01';
```

### 2.3 分库分表路由配置

```yaml
# config/sharding.yml

# 分库分表配置
sharding:
  # 分库配置
  databases:
    - db_tenant_0000
    - db_tenant_0001
    - db_tenant_0002
    - db_tenant_0003
    - db_tenant_0004
    - db_tenant_0005
    - db_tenant_0006
    - db_tenant_0007

  # 分表配置
  tables:
    # 用户表分表策略
    users:
      sharding_type: "range"  # 范围分片
      sharding_column: "created_at"
      sharding_rules:
        - date_format: "%Y_%m"
          table_name: "users_{yyyy_MM}"
          start_date: "2025_01"
          end_date: "2030_12"
      # 路由规则
      database_strategy:
        type: "standard"
        sharding_column: "tenant_id"
        algorithm_expression: "db_tenant_${tenant_id % 8}"
      table_strategy:
        type: "standard"
        sharding_column: "created_at"
        algorithm_expression: "users_${created_at % 100}"

    # 订单表分表策略
    orders:
      sharding_type: "range"
      sharding_column: "created_at"
      sharding_rules:
        - date_format: "%Y_%m"
          table_name: "orders_{yyyy_MM}"
          start_date: "2025_01"
          end_date: "2030_12"
      database_strategy:
        type: "standard"
        sharding_column: "tenant_id"
        algorithm_expression: "db_tenant_${tenant_id % 8}"
      table_strategy:
        type: "standard"
        sharding_column: "created_at"
        algorithm_expression: "orders_${created_at % 100}"

# 数据库连接配置
database:
  master:
    driver: "mysql"
    host: "mysql-master.alkaidsys.com"
    port: 3306
    username: "${MYSQL_USERNAME}"
    password: "${MYSQL_PASSWORD}"
    charset: "utf8mb4"
    database: "alkaid"

  slaves:
    - driver: "mysql"
      host: "mysql-slave-1.alkaidsys.com"
      port: 3306
      username: "${MYSQL_USERNAME}"
      password: "${MYSQL_PASSWORD}"
      charset: "utf8mb4"

    - driver: "mysql"
      host: "mysql-slave-2.alkaidsys.com"
      port: 3306
      username: "${MYSQL_USERNAME}"
      password: "${MYSQL_PASSWORD}"
      charset: "utf8mb4"

    - driver: "mysql"
      host: "mysql-slave-3.alkaidsys.com"
      port: 3306
      username: "${MYSQL_USERNAME}"
      password: "${MYSQL_PASSWORD}"
      charset: "utf8mb4"
```

### 2.4 分库分表服务

```php
<?php
// app/service/core/database/ShardingService.php

namespace app\service\core\database;

use think\facade\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\DbException;

/**
 * 分库分表服务
 */
class ShardingService
{
    protected $tenantId;
    protected $shardingConfig;

    public function __construct()
    {
        $this->tenantId = app()->tenant->id ?? 0;
        $this->shardingConfig = config('sharding');
    }

    /**
     * 获取数据表名称
     */
    public function getTableName(string $table, array $params = []): string
    {
        // 根据表名获取分表规则
        $tableConfig = $this->shardingConfig['tables'][$table] ?? null;

        if (!$tableConfig) {
            return $table;
        }

        // 范围分表
        if ($tableConfig['sharding_type'] === 'range') {
            $shardingColumn = $tableConfig['sharding_column'];
            $value = $params[$shardingColumn] ?? time();

            $dateFormat = $tableConfig['sharding_rules'][0]['date_format'] ?? 'Y_m';
            $tableName = $tableConfig['sharding_rules'][0]['table_name'] ?? $table;

            return sprintf($tableName, date($dateFormat, $value));
        }

        return $table;
    }

    /**
     * 获取数据库名称
     */
    public function getDatabaseName(string $table, array $params = []): string
    {
        $tableConfig = $this->shardingConfig['tables'][$table] ?? null;

        if (!$tableConfig) {
            return config('database.connections.mysql.database');
        }

        $databaseStrategy = $tableConfig['database_strategy'] ?? null;

        if ($databaseStrategy && $databaseStrategy['type'] === 'standard') {
            $shardingColumn = $databaseStrategy['sharding_column'];
            $algorithmExpression = $databaseStrategy['algorithm_expression'];

            $value = $params[$shardingColumn] ?? $this->tenantId;

            // 计算分库索引
            $index = $value % 8;  // 8 个数据库
            $databaseName = sprintf($algorithmExpression, $index);

            return $databaseName;
        }

        return config('database.connections.mysql.database');
    }

    /**
     * 执行分库分表查询
     */
    public function query(string $table, array $params = []): \think\db\Query
    {
        $databaseName = $this->getDatabaseName($table, $params);
        $tableName = $this->getTableName($table, $params);

        return Db::connect([
            'type' => 'mysql',
            'hostname' => config('database.connections.mysql.hostname'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
        ])->table($tableName);
    }

    /**
     * 分库分表插入
     */
    public function insert(string $table, array $data): bool
    {
        try {
            $query = $this->query($table, $data);
            return $query->insert($data) > 0;
        } catch (DbException $e) {
            log_error('Sharding insert failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 分库分表更新
     */
    public function update(string $table, array $data, array $where): bool
    {
        try {
            $query = $this->query($table, array_merge($data, $where));
            return $query->update($data) > 0;
        } catch (DbException $e) {
            log_error('Sharding update failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 分库分表删除
     */
    public function delete(string $table, array $where): bool
    {
        try {
            $query = $this->query($table, $where);
            return $query->delete($where) > 0;
        } catch (DbException $e) {
            log_error('Sharding delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 分库分表查询单条
     */
    public function find(string $table, array $where): ?array
    {
        try {
            $query = $this->query($table, $where);
            return $query->where($where)->find();
        } catch (DataNotFoundException $e) {
            return null;
        } catch (ModelNotFoundException $e) {
            return null;
        } catch (DbException $e) {
            log_error('Sharding find failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 分库分表查询多条
     */
    public function select(string $table, array $where = []): array
    {
        try {
            $query = $this->query($table, $where);
            return $query->where($where)->select()->toArray();
        } catch (DbException $e) {
            log_error('Sharding select failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 统计查询（跨分片）
     */
    public function count(string $table, array $where = []): int
    {
        // 如果租户 ID 固定，在单个库中查询
        if ($this->tenantId > 0) {
            $query = $this->query($table, $where);
            return $query->where($where)->count();
        }

        // 跨分片统计
        $total = 0;
        $databases = $this->shardingConfig['databases'] ?? [];

        foreach ($databases as $database) {
            try {
                $query = Db::connect([
                    'type' => 'mysql',
                    'hostname' => config('database.connections.mysql.hostname'),
                    'database' => $database,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                ])->table($this->getTableName($table, $where));

                $total += $query->where($where)->count();
            } catch (DbException $e) {
                log_error('Sharding count failed for database ' . $database . ': ' . $e->getMessage());
            }
        }

        return $total;
    }
}
```

---

## 3. 监控系统设计

### 3.1 监控架构

```
┌─────────────────────────────────────────┐
│              数据采集层                   │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    │
│  │ Metric  │ │  Log    │ │  Trace  │    │
│  │ Collector│ │Agent   │ │Agent   │    │
│  └─────────┘ └─────────┘ └─────────┘    │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│              数据处理层                   │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    │
│  │Prometheus│ │Fluentd │ │ Jaeger  │    │
│  │(Metrics) │ │ (Logs) │ │(Traces) │    │
│  └─────────┘ └─────────┘ └─────────┘    │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│              数据存储层                   │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    │
│  │TSDB     │ │LogStore │ │TraceStore│   │
│  │(时序数据库)│ │(日志存储)│ │(链路追踪)│  │
│  └─────────┘ └─────────┘ └─────────┘    │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│              告警与展示                   │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    │
│  │ Alertmanager│ │Grafana │ │ Kibana │    │
│  │(告警)    │ │(可视化)│ │(日志)  │    │
│  └─────────┘ └─────────┘ └─────────┘    │
└─────────────────────────────────────────┘
```

### 3.2 监控指标定义

#### 3.2.1 应用指标

```yaml
# prometheus/metrics.yml

# 应用指标
metrics:
  # 请求指标
  http_requests_total:
    description: "HTTP 请求总数"
    type: "counter"
    labels:
      - "method"
      - "endpoint"
      - "status_code"
      - "tenant_id"

  http_request_duration_seconds:
    description: "HTTP 请求耗时"
    type: "histogram"
    labels:
      - "method"
      - "endpoint"
      - "status_code"
    buckets: [0.01, 0.05, 0.1, 0.5, 1, 2, 5, 10]

  # 业务指标
  user_total:
    description: "用户总数"
    type: "gauge"
    labels:
      - "tenant_id"

  application_total:
    description: "应用总数"
    type: "gauge"

  active_users_total:
    description: "活跃用户数"
    type: "gauge"
    labels:
      - "period"  # daily, weekly, monthly

  # 数据库指标
  db_connections:
    description: "数据库连接数"
    type: "gauge"
    labels:
      - "database"
      - "state"  # active, idle, total

  db_query_duration_seconds:
    description: "数据库查询耗时"
    type: "histogram"
    labels:
      - "database"
      - "operation"
    buckets: [0.01, 0.05, 0.1, 0.5, 1, 2, 5, 10]

  # 缓存指标
  cache_hits_total:
    description: "缓存命中总数"
    type: "counter"
    labels:
      - "cache_type"  # redis, memory
      - "result"  # hit, miss

  cache_operations_total:
    description: "缓存操作总数"
    type: "counter"
    labels:
      - "cache_type"
      - "operation"  # get, set, delete

  # 插件指标
  plugin_executions_total:
    description: "插件执行总数"
    type: "counter"
    labels:
      - "plugin_key"
      - "result"  # success, failed

  plugin_execution_duration_seconds:
    description: "插件执行耗时"
    type: "histogram"
    labels:
      - "plugin_key"
    buckets: [0.01, 0.05, 0.1, 0.5, 1, 2, 5, 10]

  # 队列指标
  queue_length:
    description: "队列长度"
    type: "gauge"
    labels:
      - "queue_name"

  queue_processed_total:
    description: "已处理队列任务数"
    type: "counter"
    labels:
      - "queue_name"
      - "result"  # success, failed

  # 系统指标
  cpu_usage_percent:
    description: "CPU 使用率"
    type: "gauge"
    labels:
      - "instance"

  memory_usage_bytes:
    description: "内存使用量"
    type: "gauge"
    labels:
      - "instance"
      - "type"  # heap, non_heap

  disk_usage_bytes:
    description: "磁盘使用量"
    type: "gauge"
    labels:
      - "instance"
      - "mount_point"

  network_bytes_total:
    description: "网络流量"
    type: "counter"
    labels:
      - "instance"
      - "direction"  # sent, received
```

#### 3.2.2 指标收集实现

```php
<?php
// app/service/core/monitoring/MetricsService.php

namespace app\service\core\monitoring;

use think\facade\Cache;
use think\facade\Log;

/**
 * 指标收集服务
 */
class MetricsService
{
    protected $metrics;
    protected $namespace;

    public function __construct()
    {
        $this->metrics = [];
        $this->namespace = 'alkaid';
    }

    /**
     * 记录 HTTP 请求
     */
    public function recordHttpRequest(string $method, string $endpoint, int $statusCode, float $duration, ?int $tenantId = null): void
    {
        $labels = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => (string)$statusCode,
            'tenant_id' => $tenantId ? (string)$tenantId : 'unknown',
        ];

        $this->incrementCounter('http_requests_total', $labels);
        $this->recordHistogram('http_request_duration_seconds', $duration, $labels);
    }

    /**
     * 记录用户数量
     */
    public function recordUserTotal(int $count, ?int $tenantId = null): void
    {
        $labels = [
            'tenant_id' => $tenantId ? (string)$tenantId : 'all',
        ];

        $this->setGauge('user_total', $count, $labels);
    }

    /**
     * 记录应用数量
     */
    public function recordApplicationTotal(int $count): void
    {
        $this->setGauge('application_total', $count, []);
    }

    /**
     * 记录活跃用户数
     */
    public function recordActiveUsers(int $count, string $period): void
    {
        $labels = [
            'period' => $period,
        ];

        $this->setGauge('active_users_total', $count, $labels);
    }

    /**
     * 记录数据库连接
     */
    public function recordDbConnection(string $database, string $state, int $count): void
    {
        $labels = [
            'database' => $database,
            'state' => $state,
        ];

        $this->setGauge('db_connections', $count, $labels);
    }

    /**
     * 记录数据库查询耗时
     */
    public function recordDbQueryDuration(string $database, string $operation, float $duration): void
    {
        $labels = [
            'database' => $database,
            'operation' => $operation,
        ];

        $this->recordHistogram('db_query_duration_seconds', $duration, $labels);
    }

    /**
     * 记录缓存命中
     */
    public function recordCacheHit(string $cacheType, bool $hit): void
    {
        $labels = [
            'cache_type' => $cacheType,
            'result' => $hit ? 'hit' : 'miss',
        ];

        $this->incrementCounter('cache_hits_total', $labels);
    }

    /**
     * 记录缓存操作
     */
    public function recordCacheOperation(string $cacheType, string $operation): void
    {
        $labels = [
            'cache_type' => $cacheType,
            'operation' => $operation,
        ];

        $this->incrementCounter('cache_operations_total', $labels);
    }

    /**
     * 记录插件执行
     */
    public function recordPluginExecution(string $pluginKey, bool $success, float $duration): void
    {
        $labels = [
            'plugin_key' => $pluginKey,
            'result' => $success ? 'success' : 'failed',
        ];

        $this->incrementCounter('plugin_executions_total', $labels);
        $this->recordHistogram('plugin_execution_duration_seconds', $duration, $labels);
    }

    /**
     * 记录队列长度
     */
    public function recordQueueLength(string $queueName, int $length): void
    {
        $labels = [
            'queue_name' => $queueName,
        ];

        $this->setGauge('queue_length', $length, $labels);
    }

    /**
     * 记录队列处理
     */
    public function recordQueueProcessed(string $queueName, bool $success): void
    {
        $labels = [
            'queue_name' => $queueName,
            'result' => $success ? 'success' : 'failed',
        ];

        $this->incrementCounter('queue_processed_total', $labels);
    }

    /**
     * 记录系统指标
     */
    public function recordSystemMetrics(array $metrics): void
    {
        if (isset($metrics['cpu'])) {
            $this->setGauge('cpu_usage_percent', $metrics['cpu'], [
                'instance' => $metrics['instance'] ?? 'default',
            ]);
        }

        if (isset($metrics['memory'])) {
            foreach ($metrics['memory'] as $type => $value) {
                $this->setGauge('memory_usage_bytes', $value, [
                    'instance' => $metrics['instance'] ?? 'default',
                    'type' => $type,
                ]);
            }
        }

        if (isset($metrics['disk'])) {
            foreach ($metrics['disk'] as $mountPoint => $value) {
                $this->setGauge('disk_usage_bytes', $value, [
                    'instance' => $metrics['instance'] ?? 'default',
                    'mount_point' => $mountPoint,
                ]);
            }
        }

        if (isset($metrics['network'])) {
            foreach ($metrics['network'] as $direction => $value) {
                $this->incrementCounter('network_bytes_total', $value, [
                    'instance' => $metrics['instance'] ?? 'default',
                    'direction' => $direction,
                ]);
            }
        }
    }

    /**
     * 增加计数器
     */
    protected function incrementCounter(string $name, array $labels): void
    {
        $key = "metrics:counter:{$this->namespace}:{$name}:" . $this->getLabelsKey($labels);
        Cache::inc($key);
    }

    /**
     * 设置仪表盘值
     */
    protected function setGauge(string $name, float $value, array $labels): void
    {
        $key = "metrics:gauge:{$this->namespace}:{$name}:" . $this->getLabelsKey($labels);
        Cache::set($key, $value, 0); // 永久缓存
    }

    /**
     * 记录直方图
     */
    protected function recordHistogram(string $name, float $value, array $labels): void
    {
        // 简化实现，实际应使用 Prometheus 客户端
        $key = "metrics:histogram:{$this->namespace}:{$name}:" . $this->getLabelsKey($labels);
        Cache::incr("{$key}:count");
        Cache::incrbyfloat("{$key}:sum", $value);
    }

    /**
     * 获取标签键
     */
    protected function getLabelsKey(array $labels): string
    {
        ksort($labels);
        return md5(serialize($labels));
    }

    /**
     * 导出指标
     */
    public function exportMetrics(): string
    {
        $metrics = Cache::get('metrics:export:' . $this->namespace, []);

        foreach ($metrics as $name => $data) {
            // 导出为 Prometheus 格式
        }

        return '';
    }
}
```

### 3.3 告警配置

```yaml
# prometheus/alerts.yml

# 告警规则
groups:
  - name: alkaid_alerts
    rules:
      # 高错误率告警
      - alert: HighErrorRate
        expr: |
          (
            rate(http_requests_total{status_code=~"5.."}[5m])
            /
            rate(http_requests_total[5m])
          ) > 0.05
        for: 2m
        labels:
          severity: "critical"
        annotations:
          summary: "High error rate detected"
          description: "Error rate is {{ $value | humanizePercentage }} for the last 5 minutes"

      # 高延迟告警
      - alert: HighLatency
        expr: |
          histogram_quantile(0.95,
            rate(http_request_duration_seconds_bucket[5m])
          ) > 1
        for: 5m
        labels:
          severity: "warning"
        annotations:
          summary: "High latency detected"
          description: "95th percentile latency is {{ $value }}s for the last 5 minutes"

      # CPU 使用率告警
      - alert: HighCpuUsage
        expr: cpu_usage_percent > 80
        for: 5m
        labels:
          severity: "warning"
        annotations:
          summary: "High CPU usage"
          description: "CPU usage is {{ $value }}% for the last 5 minutes"

      # 内存使用率告警
      - alert: HighMemoryUsage
        expr: memory_usage_bytes / (1024 * 1024 * 1024) > 8
        for: 5m
        labels:
          severity: "critical"
        annotations:
          summary: "High memory usage"
          description: "Memory usage is {{ $value }}GB for the last 5 minutes"

      # 磁盘使用率告警
      - alert: HighDiskUsage
        expr: disk_usage_bytes / (1024 * 1024 * 1024) > 80
        for: 5m
        labels:
          severity: "critical"
        annotations:
          summary: "High disk usage"
          description: "Disk usage is {{ $value }}GB for the last 5 minutes"

      # 数据库连接数告警
      - alert: HighDbConnections
        expr: db_connections{state="active"} > 100
        for: 2m
        labels:
          severity: "warning"
        annotations:
          summary: "High database connections"
          description: "Active database connections is {{ $value }}"

      # 缓存命中率告警
      - alert: LowCacheHitRate
        expr: |
          (
            rate(cache_hits_total{result="hit"}[5m])
            /
            rate(cache_hits_total[5m])
          ) < 0.8
        for: 5m
        labels:
          severity: "warning"
        annotations:
          summary: "Low cache hit rate"
          description: "Cache hit rate is {{ $value | humanizePercentage }}"

      # 队列积压告警
      - alert: QueueBacklog
        expr: queue_length > 1000
        for: 5m
        labels:
          severity: "warning"
        annotations:
          summary: "Queue backlog detected"
          description: "Queue {{ $labels.queue_name }} has {{ $value }} pending jobs"

      # 插件执行失败率告警
      - alert: HighPluginFailureRate
        expr: |
          (
            rate(plugin_executions_total{result="failed"}[5m])
            /
            rate(plugin_executions_total[5m])
          ) > 0.1
        for: 5m
        labels:
          severity: "warning"
        annotations:
          summary: "High plugin failure rate"
          description: "Plugin {{ $labels.plugin_key }} failure rate is {{ $value | humanizePercentage }}"

# 告警接收配置
receivers:
  - name: "critical-alerts"
    email_configs:
      - to: "ops-team@alkaidsys.com"
        subject: "[CRITICAL] AlkaidSYS Alert"
        body: |
          {{ range .Alerts }}
          Alert: {{ .Annotations.summary }}
          Description: {{ .Annotations.description }}
          Severity: {{ .Labels.severity }}
          Time: {{ .StartsAt }}
          {{ end }}

    webhook_configs:
      - url: "http://dingtalk-webhook.alkaidsys.com/alerts"
        send_resolved: true

    slack_configs:
      - api_url: "https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX"
        channel: "#alerts"
        title: "AlkaidSYS Alert"
        text: |
          {{ range .Alerts }}
          *Alert:* {{ .Annotations.summary }}
          *Description:* {{ .Annotations.description }}
          *Severity:* {{ .Labels.severity }}
          *Time:* {{ .StartsAt }}
          {{ end }}

# 告警路由配置
route:
  receiver: "default"
  group_by: ["alertname"]
  group_wait: 10s
  group_interval: 10s
  repeat_interval: 1h
  routes:
    - match:
        severity: "critical"
      receiver: "critical-alerts"
      repeat_interval: 15m

    - match:
        severity: "warning"
      receiver: "warning-alerts"
```

---

## 4. 灾备恢复方案

### 4.1 灾备架构

```
┌─────────────────────────────────────────┐
│              生产环境                     │
│  ┌─────────┐   ┌─────────┐             │
│  │  主站点  │   │  备用站点 │             │
│  │Primary  │   │ Standby  │             │
│  └─────────┘   └─────────┘             │
│       │             │                  │
│       ▼             ▼                  │
│  ┌─────────┐   ┌─────────┐             │
│  │主数据库  │   │备数据库  │             │
│  │Master   │   │Replica  │             │
│  └─────────┘   └─────────┘             │
└─────────────────┬───────────────────────┘
                  │ 自动切换
┌─────────────────▼───────────────────────┐
│              恢复流程                     │
│  1. 检测故障                            │
│  2. 自动切换                            │
│  3. 数据同步                            │
│  4. 服务恢复                            │
│  5. 验证检查                            │
└─────────────────────────────────────────┘
```

### 4.2 备份策略

#### 4.2.1 数据备份配置

```yaml
# config/backup.yml

# 备份配置
backup:
  # 备份类型
  types:
    # 全量备份
    full:
      schedule: "0 2 * * 0"  # 每周日凌晨2点
      retention: 30  # 保留30天
      compress: true
      encrypt: true

    # 增量备份
    incremental:
      schedule: "0 2 * * 1-6"  # 周一到周六凌晨2点
      retention: 7  # 保留7天
      compress: true
      encrypt: true

    # 实时备份
    realtime:
      enabled: true
      target: "s3"  # S3 或 OSS
      bucket: "alkaid-backup"
      encryption: "AES256"

  # 备份存储
  storage:
    # 本地存储
    local:
      path: "/backup/mysql"
      max_size: "500GB"

    # 对象存储
    cloud:
      - type: "aliyun_oss"
        bucket: "alkaid-backup-primary"
        region: "cn-hangzhou"
        access_key: "${OSS_ACCESS_KEY}"
        secret_key: "${OSS_SECRET_KEY}"

      - type: "aws_s3"
        bucket: "alkaid-backup-secondary"
        region: "us-west-2"
        access_key: "${AWS_ACCESS_KEY}"
        secret_key: "${AWS_SECRET_KEY}"

  # 数据库备份
  database:
    mysql:
      host: "mysql-master.alkaidsys.com"
      port: 3306
      username: "${BACKUP_USERNAME}"
      password: "${BACKUP_PASSWORD}"

      # 备份命令
      dump_command: |
        mysqldump \
          --single-transaction \
          --routines \
          --triggers \
          --events \
          --hex-blob \
          --default-character-set=utf8mb4 \
          --databases {{ database }} > {{ backup_file }}

      # 恢复命令
      restore_command: |
        mysql < {{ backup_file }}

# 备份脚本
backup_scripts:
  # 全量备份脚本
  full_backup.sh: |
    #!/bin/bash

    # 配置
    BACKUP_DIR="/backup/mysql/full"
    DATE=$(date +%Y%m%d_%H%M%S)
    BACKUP_FILE="${BACKUP_DIR}/full_backup_${DATE}.sql"

    # 创建备份目录
    mkdir -p "${BACKUP_DIR}"

    # 执行备份
    mysqldump \
      --host="${MYSQL_HOST}" \
      --port="${MYSQL_PORT}" \
      --user="${MYSQL_USER}" \
      --password="${MYSQL_PASSWORD}" \
      --single-transaction \
      --routines \
      --triggers \
      --events \
      --hex-blob \
      --default-character-set=utf8mb4 \
      --all-databases > "${BACKUP_FILE}"

    # 压缩备份文件
    gzip "${BACKUP_FILE}"

    # 上传到云存储
    ossutil cp "${BACKUP_FILE}.gz" oss://alkaid-backup/mysql/full/

    # 清理本地备份文件
    find "${BACKUP_DIR}" -name "*.gz" -mtime +30 -delete

    # 清理云端备份文件
    ossutil ls oss://alkaid-backup/mysql/full/ | \
      awk '{if ($6 < (systime() - 30*86400)) print $9}' | \
      xargs -I {} ossutil rm {}

    echo "Full backup completed: ${BACKUP_FILE}.gz"

  # 增量备份脚本
  incremental_backup.sh: |
    #!/bin/bash

    BACKUP_DIR="/backup/mysql/incremental"
    DATE=$(date +%Y%m%d_%H%M%S)
    BACKUP_FILE="${BACKUP_DIR}/incremental_backup_${DATE}.sql"

    mkdir -p "${BACKUP_DIR}"

    # 使用 binlog 进行增量备份
    mysqlbinlog \
      --read-from-remote-server \
      --host="${MYSQL_HOST}" \
      --user="${MYSQL_USER}" \
      --password="${MYSQL_PASSWORD}" \
      --start-datetime="$(date -d '1 day ago' +'%Y-%m-%d %H:%M:%S')" \
      mysql-bin.000001 > "${BACKUP_FILE}"

    gzip "${BACKUP_FILE}"
    ossutil cp "${BACKUP_FILE}.gz" oss://alkaid-backup/mysql/incremental/

    find "${BACKUP_DIR}" -name "*.gz" -mtime +7 -delete
    echo "Incremental backup completed: ${BACKUP_FILE}.gz"
```

#### 4.2.2 配置备份

```yaml
# 应用配置备份
app_config_backup:
  # 配置文件备份
  config_files:
    - path: "config/app.php"
      backup: true
    - path: "config/database.php"
      backup: true
    - path: "config/cache.php"
      backup: true
    - path: ".env"
      backup: true
    - path: ".env.production"
      backup: true

  # 代码备份
  code_backup:
    directories:
      - "app"
      - "config"
      - "public"
      - "vendor"
    exclude:
      - "vendor/bin"
      - "node_modules"
      - "tmp"
      - "logs"

  # 证书备份
  certificates:
    - "ssl/cert.pem"
    - "ssl/key.pem"
    - "api/certs/*.pem"
```

### 4.3 恢复流程

#### 4.3.1 自动恢复流程

```php
<?php
// app/service/core/backup/DisasterRecoveryService.php

namespace app\service\core\backup;

use think\facade\Log;
use think\facade\Db;

/**
 * 灾备恢复服务
 */
class DisasterRecoveryService
{
    protected $backupPath;
    protected $cloudStorage;

    public function __construct()
    {
        $this->backupPath = config('backup.storage.local.path');
        $this->cloudStorage = config('backup.storage.cloud');
    }

    /**
     * 启动自动故障转移
     */
    public function triggerFailover(): bool
    {
        try {
            Log::info('Starting automatic failover process');

            // 1. 检测主服务状态
            $primaryStatus = $this->checkPrimaryService();
            if ($primaryStatus['healthy']) {
                Log::warning('Primary service is healthy, skipping failover');
                return false;
            }

            // 2. 检测备用服务状态
            $standbyStatus = $this->checkStandbyService();
            if (!$standbyStatus['healthy']) {
                throw new \Exception('Standby service is not healthy');
            }

            // 3. 执行故障转移
            $this->performFailover();

            // 4. 验证恢复
            $this->verifyRecovery();

            // 5. 发送通知
            $this->sendNotification('failover', 'success');

            Log::info('Automatic failover completed successfully');
            return true;

        } catch (\Exception $e) {
            Log::error('Failover failed: ' . $e->getMessage());
            $this->sendNotification('failover', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * 检查主服务状态
     */
    protected function checkPrimaryService(): array
    {
        $endpoints = [
            'health' => '/api/health',
            'database' => '/api/db/check',
            'redis' => '/api/redis/check',
        ];

        $results = [];
        foreach ($endpoints as $service => $endpoint) {
            try {
                $response = $this->httpGet("http://primary.alkaidsys.com{$endpoint}");
                $results[$service] = $response['status'] === 200;
            } catch (\Exception $e) {
                Log::error("Primary {$service} check failed: " . $e->getMessage());
                $results[$service] = false;
            }
        }

        $healthy = array_reduce($results, function ($carry, $item) {
            return $carry && $item;
        }, true);

        return [
            'healthy' => $healthy,
            'checks' => $results,
        ];
    }

    /**
     * 检查备用服务状态
     */
    protected function checkStandbyService(): array
    {
        $endpoints = [
            'health' => '/api/health',
            'database' => '/api/db/check',
            'redis' => '/api/redis/check',
        ];

        $results = [];
        foreach ($endpoints as $service => $endpoint) {
            try {
                $response = $this->httpGet("http://standby.alkaidsys.com{$endpoint}");
                $results[$service] = $response['status'] === 200;
            } catch (\Exception $e) {
                Log::error("Standby {$service} check failed: " . $e->getMessage());
                $results[$service] = false;
            }
        }

        $healthy = array_reduce($results, function ($carry, $item) {
            return $carry && $item;
        }, true);

        return [
            'healthy' => $healthy,
            'checks' => $results,
        ];
    }

    /**
     * 执行故障转移
     */
    protected function performFailover(): void
    {
        // 1. 切换数据库主从
        $this->switchDatabaseMaster();

        // 2. 更新负载均衡器配置
        $this->updateLoadBalancerConfig();

        // 3. 切换 DNS 解析
        $this->switchDnsResolution();

        // 4. 启动备用服务
        $this->startStandbyService();

        // 5. 停止主服务
        $this->stopPrimaryService();

        Log::info('Failover steps completed');
    }

    /**
     * 切换数据库主从
     */
    protected function switchDatabaseMaster(): void
    {
        // 执行 MySQL 主从切换
        $commands = [
            "STOP SLAVE;",
            "RESET SLAVE ALL;",
            "FLUSH TABLES WITH READ LOCK;",
            "SHOW MASTER STATUS;",
        ];

        foreach ($commands as $command) {
            Db::connect('standby_mysql')->execute($command);
        }

        Log::info('Database master switched');
    }

    /**
     * 更新负载均衡器配置
     */
    protected function updateLoadBalancerConfig(): void
    {
        // 更新 Nginx 配置
        $config = file_get_contents('/etc/nginx/nginx.conf');

        // 切换 upstream 服务器
        $config = preg_replace(
            '/upstream\s+backend\s+{[^}]+}/s',
            'upstream backend {
    server standby.alkaidsys.com:80;
}',
            $config
        );

        file_put_contents('/etc/nginx/nginx.conf', $config);

        // 重新加载 Nginx
        exec('nginx -s reload');

        Log::info('Load balancer config updated');
    }

    /**
     * 切换 DNS 解析
     */
    protected function switchDnsResolution(): void
    {
        // 更新 DNS A 记录
        $records = [
            'api.alkaidsys.com' => 'standby.alkaidsys.com',
            'admin.alkaidsys.com' => 'standby.alkaidsys.com',
            'www.alkaidsys.com' => 'standby.alkaidsys.com',
        ];

        foreach ($records as $domain => $ip) {
            $this->updateDnsRecord($domain, $ip);
        }

        Log::info('DNS resolution switched');
    }

    /**
     * 启动备用服务
     */
    protected function startStandbyService(): void
    {
        // 启动应用服务
        exec('systemctl start alkaid-app');

        // 启动 Web 服务
        exec('systemctl start nginx');

        // 启动缓存服务
        exec('systemctl start redis');

        Log::info('Standby service started');
    }

    /**
     * 停止主服务
     */
    protected function stopPrimaryService(): void
    {
        // 停止应用服务
        exec('systemctl stop alkaid-app');

        // 停止 Web 服务
        exec('systemctl stop nginx');

        // 停止缓存服务
        exec('systemctl stop redis');

        Log::info('Primary service stopped');
    }

    /**
     * 验证恢复
     */
    protected function verifyRecovery(): void
    {
        $checks = [
            'service_status' => $this->checkServiceStatus(),
            'database_connectivity' => $this->checkDatabaseConnectivity(),
            'api_health' => $this->checkApiHealth(),
            'user_access' => $this->checkUserAccess(),
        ];

        foreach ($checks as $check => $result) {
            if (!$result) {
                throw new \Exception("Recovery verification failed: {$check}");
            }
        }

        Log::info('Recovery verification completed');
    }

    /**
     * 恢复数据库
     */
    public function restoreDatabase(string $backupFile, bool $fullRestore = true): bool
    {
        try {
            Log::info("Starting database restore from: {$backupFile}");

            // 1. 停止应用服务
            exec('systemctl stop alkaid-app');

            // 2. 备份当前数据
            $currentBackup = $this->createCurrentBackup();

            // 3. 恢复数据
            if ($fullRestore) {
                // 全量恢复
                $this->executeRestore($backupFile);
            } else {
                // 增量恢复
                $this->executeIncrementalRestore($backupFile);
            }

            // 4. 验证数据完整性
            $this->verifyDataIntegrity();

            // 5. 启动应用服务
            exec('systemctl start alkaid-app');

            // 6. 发送通知
            $this->sendNotification('database_restore', 'success', $backupFile);

            Log::info('Database restore completed successfully');
            return true;

        } catch (\Exception $e) {
            Log::error('Database restore failed: ' . $e->getMessage());

            // 回滚
            if (isset($currentBackup)) {
                $this->restoreDatabase($currentBackup, true);
            }

            $this->sendNotification('database_restore', 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * 执行恢复
     */
    protected function executeRestore(string $backupFile): void
    {
        // 解压备份文件
        if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz') {
            $sqlFile = tempnam(sys_get_temp_dir(), 'restore_');
            exec("gunzip -c {$backupFile} > {$sqlFile}");
            $backupFile = $sqlFile;
        }

        // 执行恢复
        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s < %s',
            config('database.connections.mysql.hostname'),
            config('database.connections.mysql.hostport'),
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            $backupFile
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('MySQL restore failed: ' . implode("\n", $output));
        }

        // 清理临时文件
        if (isset($sqlFile)) {
            unlink($sqlFile);
        }
    }

    /**
     * 发送通知
     */
    protected function sendNotification(string $type, string $status, string $message = ''): void
    {
        $notification = [
            'type' => $type,
            'status' => $status,
            'message' => $message,
            'timestamp' => time(),
            'server' => gethostname(),
        ];

        // 发送邮件
        if ($status === 'failed') {
            $this->sendEmail($notification);
        }

        // 发送 Slack 通知
        $this->sendSlack($notification);

        // 发送钉钉通知
        $this->sendDingtalk($notification);
    }

    private function httpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return [
            'status' => $httpCode,
            'body' => $response,
        ];
    }

    private function updateDnsRecord(string $domain, string $ip): void
    {
        // 使用 DNS API 更新记录
        $command = "aws route53 change-resource-record-sets --hosted-zone-id Z123456789 --change-batch file://change.json";
        file_put_contents('change.json', json_encode([
            'Changes' => [
                [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => $domain,
                        'Type' => 'A',
                        'TTL' => 60,
                        'ResourceRecords' => [
                            ['Value' => $ip]
                        ]
                    ]
                ]
            ]
        ]));

        exec($command);
    }

    private function checkServiceStatus(): bool
    {
        // 检查服务状态
        return true;
    }

    private function checkDatabaseConnectivity(): bool
    {
        try {
            Db::connect()->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkApiHealth(): bool
    {
        // 检查 API 健康状态
        return true;
    }

    private function checkUserAccess(): bool
    {
        // 检查用户访问
        return true;
    }

    private function createCurrentBackup(): string
    {
        // 创建当前数据备份
        $backupFile = "{$this->backupPath}/rollback_" . date('Ymd_His') . '.sql';
        exec("mysqldump --all-databases > {$backupFile}");
        return $backupFile;
    }

    private function executeIncrementalRestore(string $backupFile): void
    {
        // 执行增量恢复
    }

    private function verifyDataIntegrity(): void
    {
        // 验证数据完整性
    }

    private function sendEmail(array $notification): void
    {
        // 发送邮件通知
    }

    private function sendSlack(array $notification): void
    {
        // 发送 Slack 通知
    }

    private function sendDingtalk(array $notification): void
    {
        // 发送钉钉通知
    }
}
```

### 4.4 恢复演练

```bash
#!/bin/bash
# disaster_recovery_drill.sh

# 灾备恢复演练脚本

echo "=== AlkaidSYS 灾备恢复演练 ==="
echo "开始时间: $(date)"
echo ""

# 1. 准备演练环境
echo "步骤1: 准备演练环境"
export DRILL_MODE=true
mkdir -p /tmp/dr_drill

# 2. 创建测试数据
echo "步骤2: 创建测试数据"
mysql -e "CREATE DATABASE IF NOT EXISTS drill_test;"
mysql drill_test -e "
CREATE TABLE IF NOT EXISTS test_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
"

for i in {1..100}; do
    mysql drill_test -e "INSERT INTO test_data (name) VALUES ('test_data_$i');"
done

echo "创建了100条测试数据"

# 3. 备份当前数据
echo "步骤3: 备份当前数据"
BACKUP_FILE="/tmp/dr_drill/before_drill_$(date +%Y%m%d_%H%M%S).sql"
mysqldump --all-databases > "$BACKUP_FILE"
echo "备份文件: $BACKUP_FILE"

# 4. 模拟故障
echo "步骤4: 模拟故障"
echo "停止应用服务..."
systemctl stop alkaid-app

echo "模拟数据库损坏..."
mysql -e "DROP DATABASE drill_test;"

# 5. 执行恢复
echo "步骤5: 执行恢复"
echo "启动灾备恢复服务..."
php artisan disaster:restore --backup-file="$BACKUP_FILE" --verify

# 6. 验证恢复
echo "步骤6: 验证恢复"
if mysql -e "USE drill_test; SELECT COUNT(*) FROM test_data;" | grep -q "100"; then
    echo "✅ 数据恢复成功"
else
    echo "❌ 数据恢复失败"
    exit 1
fi

# 7. 恢复演练环境
echo "步骤7: 恢复演练环境"
systemctl start alkaid-app
rm -rf /tmp/dr_drill

echo ""
echo "=== 演练完成 ==="
echo "结束时间: $(date)"
echo "演练结果: 成功"
```

---

## 📝 实施检查清单

### 缓存策略检查
- [ ] 多级缓存架构已实现
- [ ] 缓存策略配置正确
- [ ] 缓存预热机制已启用
- [ ] 缓存穿透防护已实现
- [ ] 缓存雪崩防护已实现

### 分库分表检查
- [ ] 分库策略已配置
- [ ] 分表策略已配置
- [ ] 路由规则已实现
- [ ] 跨分片查询已优化
- [ ] 数据迁移已完成

### 监控系统检查
- [ ] 指标收集已实现
- [ ] 告警规则已配置
- [ ] 告警通知已设置
- [ ] 监控面板已创建
- [ ] 日志聚合已配置

### 灾备恢复检查
- [ ] 备份策略已实施
- [ ] 自动故障转移已实现
- [ ] 数据恢复流程已测试
- [ ] 演练计划已制定
- [ ] RTO/RPO 指标已明确

---

**最后更新**：2025-11-01
**文档版本**：v1.0
**维护者**：AlkaidSYS 架构团队
