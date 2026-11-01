# AlkaidSYS 多站点架构设计

> ⚠️ **命名空间声明**：本文示例代码使用**设计阶段的命名**（如 `app\common\*`、`alkaid\lowcode\*`），
> 与最终实现结构不同。**请勿直接照抄**，实际命名映射见
> [`../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md`](../../docs/technical-specs/lowcode/lowcode-architecture-guidelines.md) §5.3。

## 📋 文档信息

| 项目 | 内容 |
|------|------|
| **文档名称** | AlkaidSYS 多站点架构设计 |
| **文档版本** | v1.0 |
| **创建日期** | 2025-01-19 |

> 🔶 **实现状态**：**部分实现** —— 已实现：`sites` / `sites.tenant_id` 表结构与多站点维度建模、
> 入口站点识别与**站点归属校验**（`app/middleware/SiteIdentify.php` + `infrastructure/Tenant/Repository/SiteRepository.php`：
> 校验 `X-Site-ID` 指向的站点**存在 + 属于当前租户 + status = active**，否则 403/2002）、
> `Request::siteId()` / `siteIdOrNull()` 的站点维度解析、`BaseModel::scopeSite()` 的 `site_id` 过滤。
> 未实现：**域名识别**（`Site::where('domain', $host)`）、`X-Site-Code` 识别、**默认站点解析**
> （`slug = 'default'` 回落）、站点配置服务（`SiteConfigService`）、站点管理接口与站点复制，
> 以及 Nginx 侧的站点域名绑定配置 —— 本文这些章节仍是设计稿，代码里没有对应实现。
> 实际命名与本文示例的映射见 §「站点识别机制 · 实现对照」。

## 🎯 多站点设计目标

1. **独立性** - 每个站点独立域名、独立配置
2. **隔离性** - 站点间数据完全隔离
3. **灵活性** - 支持站点动态创建和管理
4. **可扩展** - 支持站点数量水平扩展

## 🏗️ 多站点架构图

```mermaid
graph TB
    subgraph "域名层"
        A1[www.site1.com]
        A2[www.site2.com]
        A3[www.site3.com]
    end
    
    subgraph "站点路由层"
        B[站点路由器<br/>Site Router]
    end
    
    subgraph "站点应用层"
        C1[站点 1 应用]
        C2[站点 2 应用]
        C3[站点 3 应用]
    end
    
    subgraph "数据隔离层"
        D1[站点 1 数据<br/>site_id=1]
        D2[站点 2 数据<br/>site_id=2]
        D3[站点 3 数据<br/>site_id=3]
    end
    
    A1 --> B
    A2 --> B
    A3 --> B
    B --> C1 & C2 & C3
    C1 --> D1
    C2 --> D2
    C3 --> D3
```

## 📊 站点与租户的关系

### 关系模型

```mermaid
erDiagram
    TENANT ||--o{ SITE : has
    SITE ||--o{ USER : has
    SITE ||--o{ ORDER : has
    
    TENANT {
        bigint id PK
        string name
        string code
        string isolation_mode
    }
    
    SITE {
        bigint id PK
        bigint tenant_id FK
        string name
        string domain
        json settings
    }
    
    USER {
        bigint id PK
        bigint tenant_id FK
        bigint site_id FK
        string username
    }
    
    ORDER {
        bigint id PK
        bigint tenant_id FK
        bigint site_id FK
        string order_no
    }
```

### 数据库设计

```sql
-- 站点表
CREATE TABLE `alkaid_sites` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '站点ID',
    `tenant_id` BIGINT UNSIGNED NOT NULL COMMENT '租户ID',
    `name` VARCHAR(100) NOT NULL COMMENT '站点名称',
    `code` VARCHAR(50) NOT NULL COMMENT '站点代码',
    `domain` VARCHAR(100) UNIQUE COMMENT '独立域名',
    `logo` VARCHAR(255) COMMENT 'Logo',
    `settings` JSON COMMENT '站点配置',
    `status` ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_tenant_id` (`tenant_id`),
    INDEX `idx_domain` (`domain`),
    UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`),
    
    FOREIGN KEY (`tenant_id`) REFERENCES `alkaid_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点表';

-- 用户表（带 site_id）
CREATE TABLE `alkaid_users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` BIGINT UNSIGNED NOT NULL COMMENT '租户ID',
    `site_id` BIGINT UNSIGNED NOT NULL COMMENT '站点ID',
    `username` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `status` TINYINT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_tenant_site` (`tenant_id`, `site_id`),
    UNIQUE KEY `uk_site_username` (`site_id`, `username`),
    UNIQUE KEY `uk_site_email` (`site_id`, `email`),
    
    FOREIGN KEY (`tenant_id`) REFERENCES `alkaid_tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`site_id`) REFERENCES `alkaid_sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';
```

## 🔧 站点识别机制

### 实现对照（代码为准，2026-12-20）

本文下面的 `SiteIdentify` 是**设计稿**（域名识别 + `X-Site-Code` + 默认站点回落 + 状态校验）。
仓库里实际实现的只有「按 `X-Site-ID` 请求头识别 + 归属校验」，且**刻意没有**域名/默认站点解析：

| 设计稿里的能力 | 实现状态 | 实际位置 |
| --- | --- | --- |
| 从 `X-Site-ID` 解析站点维度 | ✅ 已实现 | `app/middleware/SiteIdentify.php`（全局中间件，`TenantIdentify` 之后） |
| 校验站点**存在** | ✅ 已实现（403/2002，reason `site_not_found`） | 同上 + `Infrastructure\Tenant\Repository\SiteRepository::findContextById()` |
| 校验站点**属于当前租户** | ✅ 已实现（403/2002，reason `site_tenant_mismatch`） | 同上（租户内查询命中不到时才做诊断读 `findOwnershipHintUnscoped()`） |
| 校验站点**状态可用** | ✅ 已实现（只放行 `active`，reason `site_unusable`） | 同上（`inactive` / `maintenance` / 枚举外取值一律拒绝） |
| `site_id = 0` 全局桶 | ✅ 已实现（放行且**不查库**） | 同上 + `app/Request::siteId()` 回落 0 |
| 无请求头 → 默认站点解析 | ❌ 未实现（当前按全局桶 0 处理，只记 `[SITE_IDENTIFY_DEFAULT]`） | — |
| 按域名识别站点（`domain` 列） | ❌ 未实现（`sites.domain` 有列、有唯一索引，但没有解析代码） | — |
| 按 `X-Site-Code` / `slug` 识别 | ❌ 未实现（`sites.slug` 有列、有唯一索引，但没有解析代码） | — |
| 站点配置服务 / 站点管理接口 | ❌ 未实现（`sites.config` / `sites.theme` 目前只有列与默认值） | — |

两点实现取舍（与设计稿不同，读设计稿时务必知道）：

1. **站点标识非法 / 站点不可用都是「入口拒绝」，不是「回落默认站点」。** 设计稿在识别失败时
   会回落到 `code = 'default'` 的默认站点；实现选择了 fail-closed —— 因为「租户 A 请求里写了
   租户 B 的 site_id」若被静默改写成「本站点」，调用方拿到的数据与他要的不是一回事，
   而且这类越权不会留下任何痕迹。现在它会 403 并记 `[SITE_IDENTIFY_REJECTED]`（含 reason）。
2. **不存在与属于别的租户对外文案一致。** 两者都是 403/2002，响应正文逐字节相同，
   只有服务端日志 tag 不同（`[SITE_CONTEXT_SITE_NOT_FOUND]` / `[SITE_CONTEXT_SITE_TENANT_MISMATCH]`），
   避免把入口变成站点 id 存在性探测器（依据：`docs/technical-specs/multi-tenant/tenant-parameter-contract.md` §3.3）。

### 站点识别中间件（设计稿，命名与实现不同）

```php
<?php
// /app/common/middleware/SiteIdentify.php

namespace app\common\middleware;

use app\common\model\Site;
use Closure;

class SiteIdentify
{
    public function handle($request, Closure $next)
    {
        $siteId = 0;
        $tenantId = $request->tenantId();
        
        // 1. 通过域名识别
        $host = $request->host();
        $site = Site::where('domain', $host)
            ->where('tenant_id', $tenantId)
            ->find();
        
        if ($site) {
            $siteId = $site->id;
        } else {
            // 2. 通过 Header 识别
            $siteCode = $request->header('X-Site-Code');
            if ($siteCode) {
                $site = Site::where('code', $siteCode)
                    ->where('tenant_id', $tenantId)
                    ->find();
                if ($site) {
                    $siteId = $site->id;
                }
            }
        }
        
        // 3. 使用默认站点
        if ($siteId === 0 && $tenantId > 0) {
            $site = Site::where('tenant_id', $tenantId)
                ->where('code', 'default')
                ->find();
            if ($site) {
                $siteId = $site->id;
            }
        }
        
        // 设置站点 ID
        $request->siteId($siteId);
        
        // 验证站点状态
        if ($siteId > 0 && $site) {
            if ($site->status !== 'active') {
                return json(['code' => 403, 'message' => '站点已被禁用']);
            }
        }
        
        return $next($request);
    }
}
```

### Request 扩展

```php
<?php
// /app/Request.php

namespace app;

use think\Request as BaseRequest;

/**
 * 应用全局 Request 扩展
 *
 * 注意：在 Swoole 协程环境下，禁止使用 static 属性保存租户/站点信息，
 * 必须使用实例属性，确保每个请求上下文互不干扰。
 */
class Request extends BaseRequest
{
    protected int $tenantId = 0;
    protected int $siteId   = 0;
    protected ?int $userId  = null;

    /**
     * 设置/获取租户 ID
     */
    public function tenantId(?int $tenantId = null): int
    {
        if ($tenantId !== null) {
            $this->tenantId = $tenantId;
        }
        return $this->tenantId;
    }

    /**
     * 设置/获取站点 ID
     */
    public function siteId(?int $siteId = null): int
    {
        if ($siteId !== null) {
            $this->siteId = $siteId;
        }
        return $this->siteId;
    }

    /**
     * 设置/获取用户 ID
     */
    public function userId(?int $userId = null): ?int
    {
        if ($userId !== null) {
            $this->userId = $userId;
        }
        return $this->userId;
    }
}
```

## 🔧 站点数据隔离

### 模型基类

```php
<?php
// /app/common/model/BaseModel.php

namespace app\common\model;

use think\Model;

abstract class BaseModel extends Model
{
    protected $autoWriteTimestamp = true;
    protected string $tenantField = 'tenant_id';
    protected string $siteField = 'site_id';
    
    public static function init(): void
    {
        // 插入前自动添加租户 ID 和站点 ID
        static::beforeInsert(function ($model) {
            if (!isset($model->{$model->tenantField})) {
                $model->{$model->tenantField} = app('request')->tenantId();
            }
            if (!isset($model->{$model->siteField})) {
                $model->{$model->siteField} = app('request')->siteId();
            }
        });
    }
    
    /**
     * 全局查询作用域 - 站点隔离
     */
    public function scopeSite($query)
    {
        $tenantId = app('request')->tenantId();
        $siteId = app('request')->siteId();
        
        if ($tenantId > 0) {
            $query->where($this->tenantField, $tenantId);
        }
        if ($siteId > 0) {
            $query->where($this->siteField, $siteId);
        }
        
        return $query;
    }
}
```

### 使用示例

```php
<?php
// 查询当前站点的用户
$users = User::select();
// SQL: SELECT * FROM alkaid_users WHERE tenant_id = 1 AND site_id = 1

// 创建用户（自动添加 tenant_id 和 site_id）
$user = User::create([
    'username' => 'john',
    'email' => 'john@example.com',
    'password' => password_hash('123456', PASSWORD_DEFAULT),
]);
```

## 🔧 站点配置管理

### 站点配置结构

```json
{
  "basic": {
    "title": "站点标题",
    "keywords": "关键词",
    "description": "站点描述",
    "icp": "ICP备案号"
  },
  "theme": {
    "primary_color": "#1890ff",
    "logo": "/uploads/logo.png",
    "favicon": "/uploads/favicon.ico"
  },
  "seo": {
    "title_suffix": " - 站点名称",
    "keywords": "关键词1,关键词2",
    "description": "站点描述"
  },
  "payment": {
    "alipay": {
      "enabled": true,
      "app_id": "xxx",
      "private_key": "xxx"
    },
    "wechat": {
      "enabled": true,
      "app_id": "xxx",
      "secret": "xxx"
    }
  },
  "sms": {
    "provider": "aliyun",
    "access_key": "xxx",
    "secret_key": "xxx"
  }
}
```

### 站点配置服务

```php
<?php
// /app/common/service/SiteConfigService.php

namespace app\common\service;

use app\common\model\Site;
use think\helper\Arr;

class SiteConfigService
{
    protected Site $site;
    
    public function __construct()
    {
        $siteId = app('request')->siteId();
        $this->site = Site::find($siteId);
    }
    
    /**
     * 获取配置
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->site->settings ?? [];
        return Arr::get($settings, $key, $default);
    }
    
    /**
     * 设置配置
     */
    public function set(string $key, $value): void
    {
        $settings = $this->site->settings ?? [];
        Arr::set($settings, $key, $value);
        $this->site->settings = $settings;
        $this->site->save();
        
        // 清除缓存
        cache('site:config:' . $this->site->id, null);
    }
    
    /**
     * 批量设置配置
     */
    public function setMany(array $data): void
    {
        $settings = $this->site->settings ?? [];
        foreach ($data as $key => $value) {
            Arr::set($settings, $key, $value);
        }
        $this->site->settings = $settings;
        $this->site->save();
        
        cache('site:config:' . $this->site->id, null);
    }
}
```

## 🔧 站点路由设计

### 路由配置

```php
<?php
// /app/web/route/app.php

use think\facade\Route;

// Web 路由组
Route::group('', function () {
    // 首页
    Route::get('/', 'index/index');
    
    // 商品
    Route::group('goods', function () {
        Route::get('/', 'goods/index');
        Route::get('/:id', 'goods/detail');
    });
    
    // 订单
    Route::group('order', function () {
        Route::get('/', 'order/index');
        Route::post('/', 'order/create');
        Route::get('/:id', 'order/detail');
    })->middleware(['auth']);
    
})->middleware(['site', 'cors']);
```

## 🔧 站点域名绑定

### Nginx 配置

```nginx
# /etc/nginx/conf.d/alkaid-sites.conf

# 站点 1
server {
    listen 80;
    server_name www.site1.com;
    
    location / {
        proxy_pass http://swoole_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Site-Domain $host;
    }
}

# 站点 2
server {
    listen 80;
    server_name www.site2.com;
    
    location / {
        proxy_pass http://swoole_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Site-Domain $host;
    }
}

# 泛域名支持
server {
    listen 80;
    server_name ~^(?<subdomain>.+)\.alkaid\.com$;
    
    location / {
        proxy_pass http://swoole_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Site-Subdomain $subdomain;
    }
}
```

## 📊 站点管理功能

### 站点服务

```php
<?php
// /app/common/service/SiteService.php

namespace app\common\service;

use app\common\model\Site;

class SiteService extends BaseService
{
    /**
     * 创建站点
     */
    public function create(array $data): Site
    {
        $this->startTrans();
        try {
            $site = Site::create([
                'tenant_id' => $this->getTenantId(),
                'name' => $data['name'],
                'code' => $data['code'],
                'domain' => $data['domain'] ?? null,
                'logo' => $data['logo'] ?? null,
                'settings' => $data['settings'] ?? [],
                'status' => 'active',
            ]);
            
            // 初始化站点数据
            $this->initSiteData($site);
            
            $this->commit();
            return $site;
            
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * 初始化站点数据
     */
    protected function initSiteData(Site $site): void
    {
        // 创建默认分类
        // 创建默认页面
        // 初始化站点配置
    }
    
    /**
     * 复制站点
     */
    public function copy(int $siteId, string $newName): Site
    {
        $sourceSite = Site::find($siteId);
        
        $newSite = Site::create([
            'tenant_id' => $sourceSite->tenant_id,
            'name' => $newName,
            'code' => $sourceSite->code . '_copy',
            'settings' => $sourceSite->settings,
            'status' => 'active',
        ]);
        
        // 复制站点数据
        $this->copySiteData($sourceSite, $newSite);
        
        return $newSite;
    }
}
```

## 🆚 与 NIUCLOUD 多站点对比

| 特性 | AlkaidSYS | NIUCLOUD | 优势 |
|------|-----------|----------|------|
| **站点隔离** | tenant_id + site_id | site_id | ✅ 更严格 |
| **域名绑定** | 支持 | 支持 | ✅ 相同 |
| **站点配置** | JSON 存储 | 数据库表 | ✅ 更灵活 |
| **站点复制** | 支持 | 不支持 | ✅ 更方便 |

---

**最后更新**: 2025-01-19  
**文档版本**: v1.0  
**维护者**: AlkaidSYS 架构团队

