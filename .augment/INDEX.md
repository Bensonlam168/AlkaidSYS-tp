# AlkaidSYS Augment 配置索引

本文档提供了所有 Augment 配置文件的快速索引和概览。

## 📁 文件结构

```
.augment/
├── 📄 config.yaml              # 主配置文件
├── 📄 README.md               # 完整文档
├── 📄 QUICKSTART.md           # 快速入门指南
├── 📄 INDEX.md                # 本文件（索引）
├── 📄 .augmentignore          # 忽略文件配置
│
├── 🤖 subagents/              # 子代理配置目录
│   ├── lowcode-developer.yaml    # 低代码开发专家
│   └── api-developer.yaml        # API 开发专家
│
├── 🛠️ skills/                 # 技能配置目录
│   ├── create-collection.yaml    # 创建 Collection
│   └── create-api-endpoint.yaml  # 创建 API 端点
│
├── 📋 commands/               # 命令配置目录
│   ├── lowcode-init.yaml         # 初始化低代码环境
│   └── generate-crud.yaml        # 生成 CRUD 代码
│
└── 📚 examples/               # 示例目录
    └── usage-examples.md         # 使用示例
```

---

## 🤖 Subagents（子代理）

### 1. lowcode-developer.yaml
**专长**：低代码功能开发
- Collection/Field/Relationship 数据建模
- 表单设计器开发
- Schema 驱动 UI 实现
- 工作流引擎开发

**使用场景**：
- 创建 Collection
- 添加字段
- 建立关系
- 开发低代码功能

**示例**：
```bash
auggie --print "使用 lowcode-developer 创建 Product Collection"
```

---

### 2. api-developer.yaml
**专长**：RESTful API 开发
- API 设计和实现
- 路由和中间件配置
- 请求验证和响应格式化
- API 文档生成

**使用场景**：
- 创建 RESTful API
- 实现 CRUD 操作
- 添加权限控制
- 生成 API 文档

**示例**：
```bash
auggie --print "使用 api-developer 创建用户管理 API"
```

---

## 🛠️ Skills（技能）

### 1. create-collection.yaml
**功能**：创建新的 Collection（低代码数据模型）

**参数**：
- `collection_name` - Collection 名称（必填）
- `title` - 显示标题（必填）
- `fields` - 字段定义列表（必填）
- `table_name` - 数据库表名（可选）
- `description` - 描述（可选）

**输出**：
- 数据库迁移文件
- 领域模型（可选）
- 测试用例
- Collection ID

**示例**：
```bash
auggie --print "使用 create-collection skill 创建 Product Collection，
包含字段：name(string)、price(decimal)、stock(integer)"
```

---

### 2. create-api-endpoint.yaml
**功能**：创建新的 RESTful API 端点

**参数**：
- `resource_name` - 资源名称（必填）
- `controller_name` - 控制器名称（必填）
- `operations` - 操作列表（必填）
- `api_version` - API 版本（可选，默认 v1）
- `middleware` - 中间件列表（可选）
- `with_validation` - 是否生成验证器（可选）
- `with_tests` - 是否生成测试（可选）

**输出**：
- 控制器文件
- 路由配置
- 验证器（可选）
- 测试用例（可选）

**示例**：
```bash
auggie --print "使用 create-api-endpoint skill 创建 products API"
```

---

## 📋 Commands（命令）

### 1. lowcode-init.yaml
**功能**：初始化 AlkaidSYS 低代码开发环境

**参数**：
- `with_examples` - 是否创建示例（可选，默认 true）
- `skip_migration` - 是否跳过迁移（可选，默认 false）

**执行步骤**：
1. 检查环境配置
2. 运行数据库迁移
3. 填充初始数据
4. 创建示例 Collection
5. 初始化缓存
6. 验证安装

**输出**：
- 数据库表已创建
- 默认管理员：admin / admin123
- 示例 Collection：Product、Category、Order

**示例**：
```bash
auggie --print "运行 lowcode-init 命令"
```

---

### 2. generate-crud.yaml
**功能**：基于 Collection 自动生成完整的 CRUD 代码

**参数**：
- `collection_name` - Collection 名称（必填）
- `api_version` - API 版本（可选，默认 v1）
- `with_validation` - 是否生成验证器（可选，默认 true）
- `with_tests` - 是否生成测试（可选，默认 true）
- `with_docs` - 是否生成文档（可选，默认 true）

**执行步骤**：
1. 加载 Collection 定义
2. 生成控制器
3. 生成路由
4. 生成验证器
5. 生成测试用例
6. 生成 API 文档

**输出**：
- 控制器文件
- 路由配置
- 验证器
- 测试用例
- API 文档

**示例**：
```bash
auggie --print "为 Product Collection 生成完整的 CRUD 代码"
```

---

## 📚 文档文件

### config.yaml
**内容**：项目主配置文件
- 项目信息
- 技术栈
- 代码规范
- 架构规范
- Subagents/Skills/Commands 配置
- 上下文文件
- 测试配置
- 工作流定义

### README.md
**内容**：完整的使用文档
- 目录结构
- Subagents 详细说明
- Skills 详细说明
- Commands 详细说明
- 快速开始指南
- 编写规范
- 贡献指南

### QUICKSTART.md
**内容**：快速入门指南
- 5 分钟快速开始
- 核心概念
- 常见任务
- 学习资源
- 最佳实践
- 故障排除

### examples/usage-examples.md
**内容**：实际使用示例
- 初始化项目
- 创建数据模型
- 生成 CRUD API
- 开发自定义功能
- 调试和优化
- 高级用例
- 团队协作场景

### .augmentignore
**内容**：忽略文件配置
- 依赖目录
- 构建产物
- 日志文件
- 缓存文件
- 环境配置
- IDE 配置

---

## 🚀 快速导航

### 我想...

**初始化项目**
→ 查看 [QUICKSTART.md](QUICKSTART.md#步骤-1-初始化环境)
→ 使用 [lowcode-init](commands/lowcode-init.yaml)

**创建数据模型**
→ 查看 [usage-examples.md](examples/usage-examples.md#2-创建低代码数据模型)
→ 使用 [create-collection](skills/create-collection.yaml)
→ 调用 [lowcode-developer](subagents/lowcode-developer.yaml)

**生成 API**
→ 查看 [usage-examples.md](examples/usage-examples.md#3-生成-crud-api)
→ 使用 [generate-crud](commands/generate-crud.yaml)
→ 调用 [api-developer](subagents/api-developer.yaml)

**学习如何使用**
→ 阅读 [QUICKSTART.md](QUICKSTART.md)
→ 查看 [usage-examples.md](examples/usage-examples.md)

**了解配置**
→ 查看 [config.yaml](config.yaml)
→ 阅读 [README.md](README.md)

**解决问题**
→ 查看 [QUICKSTART.md#故障排除](QUICKSTART.md#🔧-故障排除)
→ 询问 Auggie

---

## 📊 统计信息

- **Subagents**: 2 个
- **Skills**: 2 个
- **Commands**: 2 个
- **示例场景**: 20+ 个
- **文档页数**: 6 个

---

**最后更新**: 2024-11-24
**维护者**: AlkaidSYS Team

