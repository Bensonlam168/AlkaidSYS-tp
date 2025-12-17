# AlkaidSYS 项目概览

## 项目简介
**AlkaidSYS（瑶光系统）** 是一个强大、现代、低代码的企业级 SAAS 系统框架，融合了 ThinkPHP 8.0、Swoole 协程和 Vue Vben Admin 5.x 的优势，旨在为企业提供快速开发能力和高性能架构。

## 技术栈
### 后端
- **框架**: ThinkPHP 8.0
- **运行时**: Swoole 协程
- **语言**: PHP 8.1+
- **数据库**: MySQL/PostgreSQL
- **缓存**: Redis

### 前端
- **框架**: Vue Vben Admin 5.x
- **构建工具**: Vite
- **包管理**: pnpm
- **UI组件库**: 支持多种UI库（Ant Design、Element Plus、Naive UI、TDesign）

## 项目架构

### 7层整体架构
从上到下依次为：
1. **表现层 (Presentation Layer)** - 前端界面和API接口
2. **应用层 (Application Layer)** - 业务逻辑编排
3. **领域层 (Domain Layer)** - 核心业务逻辑
4. **基础设施层 (Infrastructure Layer)** - 技术实现
5. **数据访问层 (Data Access Layer)** - 数据库操作
6. **中间件层 (Middleware Layer)** - 请求处理
7. **服务层 (Service Layer)** - 公共服务

### 应用与插件架构
创新性地采用应用（Application）和插件（Plugin）两层架构：
- **应用层**: 完整的业务功能模块
- **插件层**: 可复用的功能组件
- 支持应用市场和插件市场生态系统

## 核心功能模块

### 1. 多租户系统
- **位置**: `domain/Tenant/`, `infrastructure/Tenant/`
- **中间件**: `app/middleware/TenantIdentify.php`
- **功能**: 支持多租户隔离，每个租户独立数据空间

### 2. 低代码引擎
核心组件位于 `domain/Lowcode/` 和 `infrastructure/Lowcode/`

#### Collection（集合/数据表）管理
- **控制器**: `app/controller/lowcode/CollectionController.php`
- **服务**: `infrastructure/Lowcode/Collection/Service/CollectionManager.php`
- **仓储**: `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php`
- **模型**: `domain/Lowcode/Collection/Model/Collection.php`
- **功能**: 动态创建和管理数据表结构

#### Field（字段）系统
- **位置**: `infrastructure/Lowcode/Collection/Field/`
- **抽象基类**: `AbstractField.php`
- **支持的字段类型**:
  - StringField - 字符串
  - IntegerField - 整数
  - BigintField - 大整数
  - DecimalField - 小数
  - BooleanField - 布尔值
  - DateField - 日期
  - DatetimeField - 日期时间
  - TimestampField - 时间戳
  - TextField - 文本
  - JsonField - JSON
  - FileField - 文件
  - ImageField - 图片
  - SelectField - 下拉选择
  - RadioField - 单选
  - CheckboxField - 复选框

#### Relationship（关系）管理
- **仓储**: `infrastructure/Lowcode/Collection/Repository/RelationshipRepository.php`
- **服务**: `infrastructure/Lowcode/Collection/Service/RelationshipManager.php`
- **模型**: `domain/Lowcode/Collection/Model/Relationship.php`
- **枚举**: `domain/Lowcode/Collection/Enum/RelationType.php`

#### Form Designer（表单设计器）
- **位置**: `infrastructure/Lowcode/FormDesigner/`
- **服务**:
  - `FormSchemaManager.php` - 表单结构管理
  - `FormDataManager.php` - 表单数据管理
  - `FormValidatorManager.php` - 表单验证管理
  - `FormValidatorGenerator.php` - 验证规则生成

### 3. Schema Builder（数据库结构构建器）
- **位置**: `infrastructure/Schema/SchemaBuilder.php`
- **接口**: `domain/Schema/Interfaces/SchemaBuilderInterface.php`
- **功能**:
  - 动态创建/删除数据表
  - 动态添加/删除字段
  - DDL操作安全防护
  - 支持多租户数据库隔离

### 4. 用户与权限系统
- **用户模型**: `domain/User/Model/User.php`
- **用户仓储**: `infrastructure/User/Repository/UserRepository.php`
- **权限模型**: `domain/Permission/Model/Permission.php`, `Role.php`
- **认证服务**: `infrastructure/Auth/JwtService.php`
- **中间件**: 
  - `app/middleware/Auth.php` - 身份认证
  - `app/middleware/Permission.php` - 权限验证

### 5. 事件系统
- **位置**: `domain/Event/`
- **组件**:
  - `EventService.php` - 事件服务
  - `EventLogger.php` - 事件日志
  - `AsyncEventJob.php` - 异步事件任务

### 6. 验证器系统
- **接口**: `domain/Validator/Interfaces/ValidatorGeneratorInterface.php`
- **实现**: `infrastructure/Validator/JsonSchemaValidatorGenerator.php`
- **功能**: 基于JSON Schema的动态验证规则生成

### 7. 依赖注入容器
- **服务提供者**: `domain/DI/ServiceProvider.php`
- **依赖管理**: `infrastructure/DI/DependencyManager.php`

## 中间件系统
位于 `app/middleware/`：
- **Auth.php** - 身份认证
- **Permission.php** - 权限验证
- **TenantIdentify.php** - 租户识别
- **SiteIdentify.php** - 站点识别
- **RateLimit.php** - 限流
- **AccessLog.php** - 访问日志
- **Trace.php** - 链路追踪

## 前端应用
位于 `frontend/apps/`：
- **web-antd** - Ant Design版本
- **web-ele** - Element Plus版本
- **web-naive** - Naive UI版本
- **web-tdesign** - TDesign版本
- **backend-mock** - 后端Mock服务

## 项目目录结构
```
AlkaidSYS-tp/
├── app/                    # 应用层
│   ├── controller/         # 控制器
│   ├── middleware/         # 中间件
│   ├── model/             # 模型
│   └── command/           # 命令行工具
├── domain/                # 领域层
│   ├── Lowcode/          # 低代码领域
│   ├── Tenant/           # 租户领域
│   ├── User/             # 用户领域
│   ├── Permission/       # 权限领域
│   └── Event/            # 事件领域
├── infrastructure/        # 基础设施层
│   ├── Lowcode/          # 低代码基础设施
│   ├── Schema/           # 数据库结构
│   ├── Auth/             # 认证服务
│   └── Validator/        # 验证器
├── frontend/             # 前端项目
│   ├── apps/            # 应用
│   └── packages/        # 公共包
├── config/              # 配置文件
├── route/               # 路由定义
├── database/            # 数据库迁移
└── tests/               # 测试文件
```

## 开发特性
- ✅ DDD（领域驱动设计）架构
- ✅ 多租户支持
- ✅ 低代码开发能力
- ✅ 动态表单设计器
- ✅ 强大的字段类型系统
- ✅ 关系管理（一对一、一对多、多对多）
- ✅ 事件驱动架构
- ✅ JWT认证
- ✅ RBAC权限控制
- ✅ 链路追踪
- ✅ 限流保护
- ✅ 缓存机制

## 仓库信息
- **项目主页**: https://github.com/Bensonlam168/AlkaidSYS-tp
- **许可证**: MIT License
