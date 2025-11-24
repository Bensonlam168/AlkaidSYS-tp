# AlkaidSYS（瑶光系统）

<div align="center">

**强大、现代、低代码的企业级 SAAS 系统框架**

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8.0-brightgreen)](https://www.thinkphp.cn/)
[![Swoole](https://img.shields.io/badge/Swoole-5.0+-blue)](https://www.swoole.com/)
[![Vue](https://img.shields.io/badge/Vue-3.x-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?logo=typescript&logoColor=white)](https://www.typescriptlang.org/)

[简体中文](README.md) | [English](README.en.md)

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

详细架构设计请参考：[架构设计文档](design/01-architecture-design/02-architecture-design.md)

### 2. 多租户架构

支持三种隔离模式，灵活适应不同业务场景：

| 模式 | 适用场景 | 数据量 | 成本 | 性能 |
|------|---------|--------|------|------|
| **共享数据库** | 中小型租户 | <100万/租户 | 低 | 中 |
| **独立数据库** | 大型租户 | >100万/租户 | 高 | 高 |
| **混合模式** | 灵活组合 | 不限 | 中 | 高 |

详细设计请参考：[多租户设计文档](design/01-architecture-design/04-multi-tenant-design.md)

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

详细设计请参考：[应用插件系统设计文档](design/01-architecture-design/06-application-plugin-system-design.md)

### 4. 应用市场和插件市场

**完整的应用市场和插件市场生态系统**：

- 🏪 **应用市场** - 6 大分类，浏览、搜索、购买、评价
- 🔌 **插件市场** - 通用插件和应用专属插件分类
- 👨‍💻 **开发者中心** - 应用管理、收益管理、数据统计
- 🎓 **开发者等级体系** - 4 级等级（普通/认证/金牌/钻石）
- 💰 **阶梯式分成** - 根据价格和等级动态调整（70%-95%）

详细设计请参考：[应用插件生态设计文档](design/02-app-plugin-ecosystem/)

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

详细设计请参考：[低代码框架设计文档](design/09-lowcode-framework/)

---

## 🚀 快速开始

### 环境要求

- PHP 8.2+
- MySQL 8.0+
- Redis 6.0+
- Composer 2.0+
- Node.js 18+ & pnpm 8+（前端开发）
- Swoole 5.0+（可选，用于高性能模式）

### 安装步骤

#### 1. 克隆项目

```bash
git clone https://github.com/your-org/AlkaidSYS-tp.git
cd AlkaidSYS-tp
```

#### 2. 安装后端依赖

```bash
composer install
```

#### 3. 配置环境

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑 .env 文件，配置数据库、Redis 等信息
vim .env
```

#### 4. 数据库初始化

```bash
# 创建数据库
php think migrate:run

# 填充初始数据（可选）
php think seed:run
```

#### 5. 启动服务

**传统模式（PHP-FPM）**：
```bash
php think run
```

**高性能模式（Swoole）**：
```bash
php think swoole:server start
```

#### 6. 访问项目

- 后端 API: http://localhost:8000
- Admin 管理端: http://localhost:3000（需单独启动前端项目）

### 前端开发

详细的前端开发指南请参考：[前端开发文档](design/06-frontend-design/)

---

## 📚 文档导航

### 设计文档

完整的设计文档位于 [`design/`](design/) 目录，推荐阅读顺序：

1. [设计文档总览](design/README.md) - 设计文档导航和推荐阅读顺序
2. [系统概览](design/00-core-planning/01-alkaid-system-overview.md) - 项目整体介绍
3. [技术选型确认](design/00-core-planning/02-TECHNOLOGY-SELECTION-CONFIRMATION.md) - 技术栈选型说明
4. [架构设计](design/01-architecture-design/02-architecture-design.md) - 整体架构设计
5. [多租户设计](design/01-architecture-design/04-multi-tenant-design.md) - 多租户架构设计
6. [应用插件系统](design/01-architecture-design/06-application-plugin-system-design.md) - 应用和插件架构
7. [低代码框架](design/09-lowcode-framework/) - 低代码能力设计

### 技术规范文档

技术规范文档位于 [`docs/technical-specs/`](docs/technical-specs/) 目录：

- [API 规范](docs/technical-specs/api/) - RESTful API 设计规范
- [安全指南](docs/technical-specs/security/) - 安全最佳实践
- [代码风格](docs/technical-specs/code-style/) - 编码规范和最佳实践

### 开发者指南

- [应用开发指南](design/08-developer-guides/) - 如何开发应用
- [插件开发指南](design/08-developer-guides/) - 如何开发插件
- [低代码工具使用](design/09-lowcode-framework/45-lowcode-cli-integration.md) - CLI 工具使用指南

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
├── design/                # 设计文档（30+ 份设计文档）
│   ├── 00-core-planning/  # 核心规划
│   ├── 01-architecture-design/ # 架构设计
│   ├── 02-app-plugin-ecosystem/ # 应用插件生态
│   ├── 03-data-layer/     # 数据层设计
│   ├── 04-security-performance/ # 安全与性能
│   ├── 05-deployment-testing/ # 部署与测试
│   ├── 06-frontend-design/ # 前端设计
│   ├── 07-integration-ops/ # 集成与运维
│   ├── 08-developer-guides/ # 开发者指南
│   ├── 09-lowcode-framework/ # 低代码框架
│   └── 10-batch-summaries/ # 批次总结
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

```bash
# 使用 CLI 工具创建应用
php think make:app shop

# 创建集成低代码的应用
php think make:app oa --with-lowcode
```

### 开发插件

```bash
# 创建插件
php think make:plugin payment-wechat

# 安装插件
php think plugin:install payment-wechat

# 启用插件
php think plugin:enable payment-wechat
```

### 使用低代码工具

```bash
# 安装低代码插件
php think lowcode:install

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

详细的开发指南请参考：[开发者指南](design/08-developer-guides/)

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

我们欢迎各种形式的贡献！

### 如何贡献

1. Fork 本仓库
2. 创建您的特性分支 (`git checkout -b feature/amazing-feature`)
3. 提交您的更改 (`git commit -m 'Add some amazing feature'`)
4. 推送到分支 (`git push origin feature/amazing-feature`)
5. 打开一个 Pull Request

### 代码规范

- **PHP 代码** - 遵循 PSR-12 编码规范
- **TypeScript 代码** - 遵循 ESLint 配置
- **提交信息** - 遵循 [Conventional Commits](https://www.conventionalcommits.org/) 规范
- **注释** - 使用中英文双语注释

详细的代码规范请参考：[代码风格指南](docs/technical-specs/code-style/)

---

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE)。

---

## 👥 联系我们

- **项目主页**: https://github.com/your-org/AlkaidSYS-tp
- **问题反馈**: [GitHub Issues](https://github.com/your-org/AlkaidSYS-tp/issues)
- **讨论区**: [GitHub Discussions](https://github.com/your-org/AlkaidSYS-tp/discussions)
- **文档网站**: https://docs.alkaidsys.com (建设中)

---

<div align="center">

**让企业级 SAAS 开发变得简单高效！**

Made with ❤️ by AlkaidSYS Team

</div>
