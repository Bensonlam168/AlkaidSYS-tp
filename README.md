# AlkaidSYS（瑶光系统）

<div align="center">

**强大、现代、低代码的企业级 SAAS 系统框架**

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.txt)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8.0-brightgreen)](https://www.thinkphp.cn/)
[![Swoole](https://img.shields.io/badge/Swoole-5.0+-blue)](https://www.swoole.com/)
[![Vue](https://img.shields.io/badge/Vue-3.x-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?logo=typescript&logoColor=white)](https://www.typescriptlang.org/)

简体中文 | English（英文版尚未提供）

</div>

---

## 📖 项目简介

**AlkaidSYS（瑶光系统）** 是一个强大、现代、低代码的企业级 SAAS 系统框架，融合了 ThinkPHP 8.0、Swoole 协程和 Vue Vben Admin 5.x 的优势，旨在为企业提供快速开发能力和高性能架构。

**瑶光（Alkaid）** 是北斗七星斗柄最末端的恒星，象征着：
- ✨ **开创** - 开创新的企业级 SAAS 框架
- 🔄 **变动** - 灵活的架构设计，适应业务变化
- 🚀 **冒险** - 勇于采用最新技术，突破传统框架限制
- 💡 **创新** - 融合业界最佳实践并持续创新

### 核心特性

- 🚀 **快速开发能力** - 低代码设计，提高开发效率 10 倍
- ⚡ **高性能架构** - 基于 Swoole 协程，支持 10K+ 并发
- 🔌 **灵活扩展性** - 插件化架构，易于添加新功能
- 🏢 **完善的多租户** - 支持共享数据库和独立数据库模式
- 💎 **现代化前端** - 直接使用 Vue Vben Admin 5.x
- 📱 **多端支持** - 覆盖 Admin、PC、小程序、App、H5 所有终端

### 从这里开始

- 🚀 **想先跑起来** → [快速开始](#-快速开始)
- 🛠️ **想改代码** → [CONTRIBUTING.md](CONTRIBUTING.md)：环境要求、测试、代码风格、提交与 PR 约定
- 🐛 **遇到问题** → [GitHub Issues](https://github.com/Bensonlam168/AlkaidSYS-tp/issues)

---

## 🏗️ 技术栈

### 后端技术栈

| 技术 | 版本 | 用途 |
|------|------|------|
| **PHP** | 8.2+ | 开发语言 |
| **ThinkPHP** | 8.0+ | 核心框架 |
| **Swoole** | 5.0+ | 高性能引擎（协程支持）|
| **MySQL** | 8.0+ | 主数据库 |
| **Redis** | 6.0+ | 缓存 + 队列 |
| **RabbitMQ** | 3.12+ | 消息队列 |
| **PHP-Casbin** | 3.x | 权限管理 |

### 前端技术栈

#### Admin 管理端
| 技术 | 版本 | 用途 |
|------|------|------|
| **Vue Vben Admin** | 5.x | 管理后台框架 |
| **Vue** | 3.x | 前端框架 |
| **TypeScript** | 5.x | 开发语言 |
| **Vite** | 5.x | 构建工具 |
| **Ant Design Vue** | 4.x | UI 组件库 |
| **Pinia** | 2.x | 状态管理 |

#### PC 客户端 & 移动端
| 技术 | 版本 | 用途 |
|------|------|------|
| **Vue** | 3.x | 前端框架 |
| **UniApp** | 3.x | 跨端框架（小程序/App/H5）|
| **uView UI** | 3.x | 移动端 UI 组件库 |

---

## 🌟 架构特性

### 1. 7层整体架构

AlkaidSYS 采用**7层整体架构**，从上到下依次为：

1. **客户端层（Client Layer）** - 多终端用户界面（Admin管理端、PC客户端、移动端）
2. **API网关层（Gateway Layer）** - 请求路由、负载均衡、限流认证（Nginx + Swoole）
3. **应用层（Application Layer）** - 完整的业务应用模块（电商、OA、CRM、ERP、CMS、AI、低代码管理应用）
4. **插件层（Plugin Layer）** - 功能扩展模块（通用插件、应用专属插件、低代码插件）
5. **低代码基础层（Lowcode Foundation）** - 低代码核心服务（Schema Manager、Collection Manager、Field Type Registry、Validator Generator）
6. **核心服务层（Core Services）** - 框架核心服务（用户、租户、权限、应用、插件）
7. **数据层（Data Layer）** - 数据存储和缓存（MySQL、Redis、RabbitMQ）

### 2. 多租户架构

支持三种隔离模式，灵活适应不同业务场景：

| 模式 | 适用场景 | 数据量 | 成本 | 性能 |
|------|---------|--------|------|------|
| **共享数据库** | 中小型租户 | <100万/租户 | 低 | 中 |
| **独立数据库** | 大型租户 | >100万/租户 | 高 | 高 |
| **混合模式** | 灵活组合 | 不限 | 中 | 高 |

多租户的字段与隔离约定见 [数据库设计规范](docs/technical-specs/database/database-guidelines.zh-CN.md)。

### 3. 应用和插件两层架构

**AlkaidSYS 创新性地采用应用（Application）和插件（Plugin）两层架构**：

#### 应用层（Applications）
完整的业务模块，可以独立安装、卸载、启用、禁用：
- 📦 **电商应用** - 商城、拼团、秒杀
- 📋 **OA 应用** - 审批、考勤、任务
- 👥 **CRM 应用** - 客户、线索、商机
- 📊 **ERP 应用** - 采购、库存、财务
- 📰 **CMS 应用** - 文章、页面、媒体
- 🤖 **AI 应用** - 智能客服、数据分析

#### 插件层（Plugins）
功能扩展模块，为应用或框架提供额外功能：
- 🔌 **通用插件** - 支付网关、短信服务、存储服务
- ⚙️ **应用专属插件** - 优惠券、审批流、客户画像

插件系统的落实现状与清单约定见 [插件系统基础](docs/technical-specs/plugin/plugin-system-foundation.md)。

### 4. 应用市场和插件市场

**完整的应用市场和插件市场生态系统**：

- 🏪 **应用市场** - 6 大分类，浏览、搜索、购买、评价
- 🔌 **插件市场** - 通用插件和应用专属插件分类
- 👨‍💻 **开发者中心** - 应用管理、收益管理、数据统计
- 🎓 **开发者等级体系** - 4 级等级（普通/认证/金牌/钻石）
- 💰 **阶梯式分成** - 根据价格和等级动态调整（70%-95%）

### 5. 低代码能力（开发者工具）

AlkaidSYS 提供强大的低代码能力，作为**开发者工具**帮助快速开发应用和插件：

- ✅ **Schema 驱动 UI** - 基于 JSON Schema 定义表单和数据结构
- ✅ **前后端验证统一** - 自动生成前后端验证规则
- ✅ **CLI 工具集成** - 快速创建数据模型、表单、工作流
- ✅ **可视化设计器** - 拖拽式表单设计器和工作流设计器
- ✅ **Swoole 异步工作流** - 基于协程的高性能工作流引擎

**效率提升**：

| 场景 | 传统开发 | 使用低代码 | 效率提升 |
|------|---------|-----------| ---------|
| 创建数据模型 | 2 小时 | 2 分钟 | **60 倍** |
| 创建表单 | 4 小时 | 5 分钟 | **48 倍** |
| 创建工作流 | 8 小时 | 10 分钟 | **48 倍** |
| 生成 CRUD | 6 小时 | 5 分钟 | **72 倍** |

低代码的开发约定见 [低代码开发规范](docs/technical-specs/lowcode/lowcode-guidelines.zh-CN.md)。

---

## 🚀 快速开始

### 环境要求

- PHP 8.2+
- MySQL 8.0+
- Redis 6.0+
- Composer 2.0+
- Swoole 5.0+（**必需**：`topthink/think-swoole` 要求 `ext-swoole`，缺失时 `composer install` 会因平台检查失败）
- Node.js 20.10+ & pnpm 9.12+（前端开发，版本取自 `frontend/package.json` 的 `engines`）

### 安装步骤

#### 1. 克隆项目

```bash
git clone https://github.com/Bensonlam168/AlkaidSYS-tp.git
cd AlkaidSYS-tp
```

#### 2. 配置环境

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑 .env 文件，配置数据库、Redis 等信息
vim .env
```

> 在宿主机直连本地 MySQL / Redis 时，请把 `DB_HOST` 改为 `127.0.0.1`，并补一行 `REDIS_HOST = 127.0.0.1`
> —— `.env.example` 里的 `mysql` / `redis` 是 docker compose 的服务名，且 `.env.example` 本身不含 `REDIS_HOST`。

#### 3. 安装后端依赖

```bash
# --no-scripts：composer.json 的 post-autoload-dump 会执行 php think service:discover，
# 等同于启动应用（含 Redis 自检）；依赖装完后按第 4 步手动执行一次即可。
composer install --no-scripts
```

#### 4. 服务发现

```bash
# 生成 vendor/services.php，注册 migrate / queue / swoole 等由 composer 包提供的命令
php think service:discover
```

#### 5. 数据库初始化

```bash
# 执行数据库迁移（请先自行创建 .env 中 DB_NAME 指定的数据库，迁移不会建库）
php think migrate:run

# 填充初始数据（可选）
php think seed:run
```

#### 6. 启动服务

**内置服务器**：
```bash
php think run          # 默认 0.0.0.0:8000
```

**高性能模式（Swoole）**：
```bash
php think swoole       # 默认 0.0.0.0:8080（端口见 config/swoole.php）
```

#### 7. 访问项目

- 后端 API: http://localhost:8000（`php think run`）或 http://localhost:8080（`php think swoole`）
- Admin 管理端: http://localhost:5666（前端需单独启动，见下）

### 前端开发

前端工程说明见 [`frontend/README.md`](frontend/README.md)（Vue Vben Admin 5.x monorepo）。

```bash
cd frontend
corepack enable
pnpm install
pnpm dev:antd          # 开发服务器，默认 http://localhost:5666
```

---

## 📚 文档导航

> ⚠️ **README 描述的是设计目标，不代表当前实现进度**；实际情况以源码与测试为准。

### 技术规范文档

技术规范文档位于 [`docs/technical-specs/`](docs/technical-specs/) 目录：

- [技术规范总览](docs/technical-specs/README.md) - 技术规范总览与导航
- [Git 工作流与提交规范](docs/technical-specs/git/git-workflow-guidelines.md) - 分支模型与提交规范
- [API 规范](docs/technical-specs/api/) - RESTful API 设计规范
- [安全指南](docs/technical-specs/security/) - 安全最佳实践
- [代码风格](docs/technical-specs/code-style/) - 编码规范和最佳实践
- [部署与环境验证](docs/technical-specs/deployment/) - 部署流程与环境变量校验

### 开发者与使用者文档

- [开发者指南](docs/developer-guides/) - 代码评审清单、语言包贡献、PHP 现代特性
- [用户手册](docs/user-guide/) - 系统管理、权限监控
- [测试说明](docs/testing/) - 测试隔离与性能测试
- [参与贡献（CONTRIBUTING）](CONTRIBUTING.md) - 环境、测试、代码风格、提交与 PR 约定

---

## 📁 项目结构

```
AlkaidSYS-tp/
├── app/                    # 应用目录
│   ├── admin/             # 管理端应用
│   ├── api/               # API 应用
│   ├── common/            # 公共模块
│   └── [应用名]/          # 其他业务应用
├── config/                 # 配置文件
├── database/              # 数据库文件
│   ├── migrations/        # 数据库迁移文件
│   └── seeds/            # 数据填充文件
├── docs/                  # 技术规范文档
│   └── technical-specs/   # 技术规范
│       ├── api/          # API 规范
│       ├── security/     # 安全指南
│       └── code-style/   # 代码风格
├── extend/                # 扩展类库
├── plugins/               # 插件目录
├── public/                # 公共资源
├── runtime/               # 运行时文件
├── vendor/                # Composer 依赖
├── .env.example           # 环境配置示例
├── composer.json          # Composer 配置
└── think                  # 命令行工具
```

---

## 🛠️ 开发指南

### 创建新应用

`php think list` 的 `make:` 分组只有 controller / model / service 等代码生成器，**没有应用脚手架命令**。
应用以清单文件声明：复制模板目录 `addons/apps/_template`（含 `manifest.json`、`config/menu.php`、
`sql/install.sql` / `sql/uninstall.sql`），改 `manifest.json` 里的 `key` / `name` 即可起步
（装载逻辑扫描 `addons/apps/*/manifest.json`，见 `infrastructure/Application/ApplicationManager.php`）。

### 开发插件

插件相关命令只有 `list` / `scan` / `enable` / `disable`（见 `config/console.php`），没有创建或安装插件的命令。
插件以 `addons/plugins/<key>/plugin.json` 为清单（约定见 [`addons/plugins/README.md`](addons/plugins/README.md)），
把插件目录放进去后用下列命令管理：

```bash
# 扫描插件目录，把清单写入插件包表（需先完成数据库迁移）
php think plugin:scan

# 查看已加载的插件（当前仓库未随附插件，输出 No plugins loaded）
php think plugin:list

# 启用 / 禁用，{key} 取自 plugin.json 的 plugin_key
php think plugin:enable {key}
php think plugin:disable {key}
```

### 使用低代码工具

低代码能力内置在框架里，不需要额外安装插件。`php think list` 的 `lowcode:` 分组提供：

```bash
# 创建数据模型
php think lowcode:create-model Product \
  --fields="name:string,price:decimal,stock:integer"

# 创建表单
php think lowcode:create-form product_form \
  --title="商品表单" \
  --collection=Product

# 生成 CRUD 代码
php think lowcode:generate crud Product
```

详细的开发指南请参考：[开发者指南](docs/developer-guides/)

---

## 💡 核心创新点

### 1. ThinkPHP 8.0 + Swoole 微服务架构
- ✅ 使用 Swoole HTTP Server 替代传统 PHP-FPM
- ✅ 使用协程实现高并发（10K+ 并发）
- ✅ 使用连接池优化数据库和 Redis 连接
- ✅ 使用 Swoole Table 实现共享内存缓存

### 2. 混合多租户模式
- ✅ 支持共享数据库模式（成本低）
- ✅ 支持独立数据库模式（性能高）
- ✅ 支持混合模式（灵活组合）
- ✅ 租户级别的资源配额管理

### 3. 应用和插件两层架构
- ✅ 明确区分应用和插件
- ✅ 完整的生命周期管理
- ✅ 强大的钩子系统（Action/Filter/Event Hooks）
- ✅ 插件热更新支持

### 4. 完整的市场生态
- ✅ 应用市场和插件市场
- ✅ 开发者等级体系和收益分成
- ✅ 两阶段审核机制
- ✅ 完整的开发者工具（CLI、SDK、文档）

### 5. 低代码开发者工具
- ✅ Schema 驱动 UI
- ✅ 前后端验证统一
- ✅ CLI 工具集成
- ✅ 可视化设计器
- ✅ 效率提升 40-72 倍

---

## 📊 系统能力指标

| 指标 | 目标值 | 说明 |
|------|--------|------|
| **租户规模** | 1000+ | 支持同时在线租户数 |
| **并发用户** | 10000+ | 单租户并发用户数 |
| **可用性** | 99.9% | 年度可用性保证 |
| **响应时间** | <500ms | P95 响应时间 |
| **QPS** | >1000 | 每秒查询数 |
| **数据隔离** | 100% | 租户间数据完全隔离 |

---

## 🤝 贡献指南

我们欢迎各种形式的贡献！改代码前请先读 **[CONTRIBUTING.md](CONTRIBUTING.md)** —— 环境准备、测试命令、代码风格、提交信息与 PR 要求都写在那里，本文不再重复。

- **提交信息** - 遵循 [Conventional Commits](https://www.conventionalcommits.org/)：`<type>(<scope>): <subject>`，需带 scope，header 不超过 72 字符。
- **代码风格** - PHP 遵循 PSR-12（配置见 `.php-cs-fixer.php`），前端遵循 ESLint（`cd frontend && pnpm lint`）。

---

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE.txt)。

---

## 👥 联系我们

- **项目主页**: https://github.com/Bensonlam168/AlkaidSYS-tp
- **问题反馈**: [GitHub Issues](https://github.com/Bensonlam168/AlkaidSYS-tp/issues)
- **讨论区**: [GitHub Discussions](https://github.com/Bensonlam168/AlkaidSYS-tp/discussions)
- **文档网站**: https://docs.alkaidsys.com (建设中)

---

<div align="center">

**让企业级 SAAS 开发变得简单高效！**

Made with ❤️ by AlkaidSYS Team

</div>
