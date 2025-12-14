# API Route Reference | API 路由参考

> Version: 1.0.0 | Last Updated: 2025-11-26
> Auto-generated from `php think route:list`

## Overview | 概述

This document lists all API routes in AlkaidSYS-tp.
本文档列出 AlkaidSYS-tp 中的所有 API 路由。

## Authentication Routes | 认证路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| POST | `/v1/auth/login` | AuthController@login | User login | 用户登录 |
| POST | `/v1/auth/register` | AuthController@register | User registration | 用户注册 |
| POST | `/v1/auth/refresh` | AuthController@refresh | Refresh token | 刷新令牌 |
| GET | `/v1/auth/me` | AuthController@me | Get current user | 获取当前用户 |
| GET | `/v1/auth/codes` | AuthController@codes | Get permission codes | 获取权限码 |

**Middleware**: 
- `/v1/auth/login`, `/v1/auth/register`, `/v1/auth/refresh`: No auth required
- `/v1/auth/me`, `/v1/auth/codes`: Requires `Auth` middleware

## Lowcode Collection Routes | 低代码集合路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | `/v1/lowcode/collections` | CollectionController@index | List collections | 列出集合 |
| POST | `/v1/lowcode/collections` | CollectionController@save | Create collection | 创建集合 |
| GET | `/v1/lowcode/collections/<name>` | CollectionController@read | Get collection | 获取集合 |
| PUT | `/v1/lowcode/collections/<name>` | CollectionController@update | Update collection | 更新集合 |
| DELETE | `/v1/lowcode/collections/<name>` | CollectionController@delete | Delete collection | 删除集合 |

**Middleware**: `Auth`, `Permission`

## Lowcode Field Routes | 低代码字段路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| POST | `/v1/lowcode/collections/<name>/fields` | FieldController@save | Add field | 添加字段 |
| DELETE | `/v1/lowcode/collections/<name>/fields/<field>` | FieldController@delete | Delete field | 删除字段 |

**Middleware**: `Auth`, `Permission`

## Lowcode Relationship Routes | 低代码关系路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| POST | `/v1/lowcode/collections/<name>/relationships` | RelationshipController@save | Add relationship | 添加关系 |
| DELETE | `/v1/lowcode/collections/<name>/relationships/<relationship>` | RelationshipController@delete | Delete relationship | 删除关系 |

**Middleware**: `Auth`, `Permission`

## Lowcode Form Schema Routes | 低代码表单 Schema 路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | `/v1/lowcode/forms` | FormSchemaController@index | List forms | 列出表单 |
| POST | `/v1/lowcode/forms` | FormSchemaController@save | Create form | 创建表单 |
| GET | `/v1/lowcode/forms/<name>` | FormSchemaController@read | Get form | 获取表单 |
| PUT | `/v1/lowcode/forms/<name>` | FormSchemaController@update | Update form | 更新表单 |
| DELETE | `/v1/lowcode/forms/<name>` | FormSchemaController@delete | Delete form | 删除表单 |
| POST | `/v1/lowcode/forms/<name>/duplicate` | FormSchemaController@duplicate | Duplicate form | 复制表单 |

**Middleware**: `Auth`, `Permission`

## Lowcode Form Data Routes | 低代码表单数据路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | `/v1/lowcode/forms/<name>/data` | FormDataController@index | List records | 列出记录 |
| POST | `/v1/lowcode/forms/<name>/data` | FormDataController@save | Create record | 创建记录 |
| GET | `/v1/lowcode/forms/<name>/data/<id>` | FormDataController@read | Get record | 获取记录 |
| DELETE | `/v1/lowcode/forms/<name>/data/<id>` | FormDataController@delete | Delete record | 删除记录 |

**Middleware**: `Auth`, `Permission`

## Admin Routes | 管理路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| POST | `/v1/admin/casbin/reload-policy` | CasbinController@reloadPolicy | Reload Casbin policy | 重载 Casbin 策略 |

**Middleware**: `Auth`, `Permission` (admin only)

## Debug Routes | 调试路由

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | `/debug/session-redis` | DebugController@sessionRedis | Test Redis session | 测试 Redis 会话 |

**Note**: Debug routes should be disabled in production.
**注意**: 调试路由应在生产环境禁用。

---

## Route Files | 路由文件

| File | Description |
|------|-------------|
| `route/auth.php` | Authentication routes | 认证路由 |
| `route/lowcode.php` | Lowcode API routes | 低代码 API 路由 |
| `route/admin.php` | Admin routes | 管理路由 |
| `route/app.php` | Application routes | 应用路由 |

---

## Middleware Reference | 中间件参考

| Middleware | Description |
|------------|-------------|
| `Auth` | JWT authentication | JWT 认证 |
| `Permission` | RBAC permission check | RBAC 权限检查 |
| `Trace` | Request tracing | 请求追踪 |
| `Cors` | CORS handling | 跨域处理 |
| `RateLimit` | Rate limiting | 限流 |

---

**Last Updated**: 2025-11-26

