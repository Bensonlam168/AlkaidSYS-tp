# AlkaidSYS Augment 配置

这个目录包含了 AlkaidSYS 项目的 Augment AI 辅助开发配置，包括官方 Rules 和自定义工作流系统。

## 📌 重要说明

本目录包含**两类配置系统**，它们有不同的用途和格式：

### 1. 官方 Augment 配置 ✅

**位置**：`.augment/rules/`

**格式**：Markdown 文件

**用途**：
- 自动被 Augment Agent 和 Chat 识别和应用
- 定义项目的硬约束和指导原则
- 符合 Augment 官方规范（[官方文档](https://docs.augmentcode.com/setup-augment/guidelines)）

**文件**：
- `always-alkaidsys-project-rules.md` - Always 规则（自动应用于所有会话）
- `auto-alkaidsys-guidelines.md` - Auto 规则（智能检测并应用）

**特点**：
- ✅ 完全符合 Augment 官方标准
- ✅ 无需手动引用，自动生效
- ✅ 定义项目的"硬约束"和核心原则

---

### 2. 自定义工作流系统 🎯

**位置**：`config.yaml`、`subagents/`、`skills/`、`commands/`

**格式**：YAML 文件

**用途**：
- 组织和文档化项目特定的 AI 辅助工作流
- 提供标准化的提示词模板和最佳实践
- 作为团队知识库和培训材料
- 确保团队成员使用一致的 AI 辅助方式

**使用方式**：
- 通过自然语言提示词引用（如："使用 lowcode-developer 创建 Collection"）
- 不依赖 Augment 官方的 `/agents` 或 `/command` 命令
- 作为 Rules 的补充文档和参考资料

**重要**：这是项目内部约定，**不是** Augment 官方配置格式。

---

## 📁 目录结构

```
.augment/
├── rules/                  # 官方 Augment Rules ✅
│   ├── always-alkaidsys-project-rules.md  # Always 规则
│   └── auto-alkaidsys-guidelines.md       # Auto 规则
├── config.yaml             # 自定义系统配置索引 🎯
├── README.md              # 本文件
├── subagents/             # 自定义子代理配置 🎯
│   ├── lowcode-developer.yaml         # 低代码开发专家
│   ├── api-developer.yaml             # API 开发专家
│   ├── auth-security-engineer.yaml    # 认证安全工程师
│   ├── frontend-integrator.yaml       # 前端集成专家
│   └── test-migration-engineer.yaml   # 测试迁移工程师
├── skills/                # 自定义技能配置 🎯
│   ├── create-collection.yaml         # 创建 Collection
│   ├── create-field.yaml              # 创建字段
│   ├── create-api-endpoint.yaml       # 创建 API 端点
│   ├── create-form-schema.yaml        # 创建表单 Schema
│   ├── run-migration.yaml             # 运行迁移
│   ├── run-tests.yaml                 # 运行测试
│   ├── auth-permission-best-practices.yaml  # 认证权限最佳实践
│   ├── rate-limit-and-gateway-best-practices.yaml  # 限流网关最佳实践
│   └── workflow-and-plugin-architecture.yaml  # 工作流插件架构
├── commands/              # 自定义命令配置 🎯
│   ├── lowcode-init.yaml              # 初始化低代码环境
│   ├── generate-crud.yaml             # 生成 CRUD 代码
│   ├── setup-project.yaml             # 项目初始化
│   ├── deploy.yaml                    # 项目部署
│   ├── tests-and-migrations-hardening.yaml  # 测试迁移加固
│   ├── auth-permission-integration.yaml     # 权限集成
│   ├── casbin-phase2-rollout.yaml          # Casbin Phase2 部署
│   └── api-error-trace-pagination-unify.yaml  # API 错误追踪分页统一
├── examples/              # 使用示例
│   └── usage-examples.md
└── validate-config.sh     # 配置验证脚本
```

## 🤖 Subagents（子代理）

Subagents 是专门针对特定领域的 AI 助手，具有专业知识和上下文。

### 1. lowcode-developer（低代码开发专家）

**专长领域**：
- Collection/Field/Relationship 数据建模
- 表单设计器开发
- Schema 驱动 UI 实现
- 工作流引擎开发

**使用方式**：
```bash
auggie --print "使用 lowcode-developer 创建 Product Collection"
```

### 2. api-developer（API 开发专家）

**专长领域**：
- RESTful API 设计
- 路由和中间件配置
- API 文档生成
- 请求验证和响应格式化

**使用方式**：
```bash
auggie --print "使用 api-developer 创建用户管理 API"
```

## 🛠️ Skills（技能）

Skills 是可复用的任务模板，用于快速完成常见开发任务。

### 1. create-collection

创建一个新的 Collection（低代码数据模型）。

**参数**：
- `collection_name`: Collection 名称（必填）
- `title`: 显示标题（必填）
- `fields`: 字段定义列表（必填）

**示例**：
```bash
auggie --print "使用 create-collection skill 创建 Product Collection，
包含字段：name(string)、price(decimal)、stock(integer)"
```

### 2. create-api-endpoint

创建一个新的 RESTful API 端点。

**参数**：
- `resource_name`: 资源名称（必填）
- `controller_name`: 控制器名称（必填）
- `operations`: 需要实现的操作（必填）

**示例**：
```bash
auggie --print "使用 create-api-endpoint skill 创建 products API"
```

## 📋 Commands（命令）

Commands 是完整的工作流程，包含多个步骤。

### 1. lowcode-init

初始化 AlkaidSYS 低代码开发环境。

**功能**：
- 运行数据库迁移
- 填充初始数据
- 创建示例 Collection
- 初始化缓存

**使用方式**：
```bash
auggie --print "运行 lowcode-init 命令"
```

### 2. generate-crud

基于 Collection 自动生成完整的 CRUD 代码。

**功能**：
- 生成控制器
- 生成路由
- 生成验证器
- 生成测试用例
- 生成 API 文档

**使用方式**：
```bash
auggie --print "为 Product Collection 生成完整的 CRUD 代码"
```

## 🚀 快速开始

### 1. 初始化项目

```bash
# 初始化低代码环境
auggie --print "运行 lowcode-init 命令"
```

### 2. 创建 Collection

```bash
# 创建商品 Collection
auggie --print "使用 create-collection skill 创建 Product Collection，
包含字段：name(string)、price(decimal)、stock(integer)、status(select)"
```

### 3. 生成 CRUD 代码

```bash
# 为 Product 生成 CRUD
auggie --print "为 Product Collection 生成完整的 CRUD 代码"
```

### 4. 测试 API

```bash
# 运行测试
php think test tests/Feature/Lowcode/ProductCrudTest.php
```

## 📝 编写规范

### Subagent 规范

```yaml
name: subagent-name
version: 1.0.0
description: 简短描述
expertise:
  - 专长领域1
  - 专长领域2
system_prompt: |
  详细的系统提示词
skills:
  - skill-1
  - skill-2
context_files:
  - 相关文档路径
```

### Skill 规范

```yaml
name: skill-name
version: 1.0.0
description: 简短描述
parameters:
  - name: param1
    type: string
    required: true
    description: 参数描述
steps:
  - name: step1
    description: 步骤描述
    actions:
      - 动作1
      - 动作2
```

### Command 规范

```yaml
name: command-name
version: 1.0.0
description: 简短描述
parameters:
  - name: param1
    type: boolean
    default: true
steps:
  - name: step1
    description: 步骤描述
    actions:
      - 动作1
outputs:
  success_message: 成功消息
  error_message: 错误消息
```

## 🤝 贡献指南

欢迎贡献新的 Subagents、Skills 和 Commands！

1. 在对应目录创建 YAML 文件
2. 遵循上述规范
3. 添加详细的文档和示例
4. 测试验证功能
5. 提交 PR

## 📚 相关文档

- [AlkaidSYS 架构设计](../design/01-architecture-design/02-architecture-design.md)
- [低代码框架设计](../design/09-lowcode-framework/)
- [API 设计规范](../docs/technical-specs/api/)
- [开发者指南](../design/08-developer-guides/)

## 📄 许可证

MIT License

