# AlkaidSYS Augment 配置

这个目录包含了 AlkaidSYS 项目的 Augment AI 辅助开发配置，包括 Subagents、Skills 和 Commands。

## 📁 目录结构

```
.augment/
├── config.yaml              # 主配置文件
├── README.md               # 本文件
├── subagents/              # 子代理配置
│   ├── lowcode-developer.yaml    # 低代码开发专家
│   └── api-developer.yaml        # API 开发专家
├── skills/                 # 技能配置
│   ├── create-collection.yaml    # 创建 Collection
│   └── create-api-endpoint.yaml  # 创建 API 端点
└── commands/               # 命令配置
    ├── lowcode-init.yaml         # 初始化低代码环境
    └── generate-crud.yaml        # 生成 CRUD 代码
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

