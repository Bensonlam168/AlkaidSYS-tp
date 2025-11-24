# AlkaidSYS Augment 快速入门

欢迎使用 AlkaidSYS 的 Augment AI 辅助开发系统！本指南将帮助您快速上手。

## 🚀 5 分钟快速开始

### 步骤 1: 初始化环境

```bash
# 使用 auggie 命令行工具
auggie --print "运行 lowcode-init 命令"
```

这将自动完成：
- ✅ 数据库迁移
- ✅ 创建默认租户和管理员
- ✅ 创建示例 Collection
- ✅ 初始化缓存

### 步骤 2: 创建你的第一个 Collection

```bash
auggie --print "使用 create-collection skill 创建 Product Collection，
包含字段：name(string)、price(decimal)、stock(integer)"
```

### 步骤 3: 生成 CRUD API

```bash
auggie --print "为 Product Collection 生成完整的 CRUD 代码"
```

### 步骤 4: 测试 API

```bash
# 运行测试
php think test tests/Feature/Lowcode/ProductCrudTest.php

# 启动服务器
php think run

# 访问 API
curl http://localhost:8000/v1/lowcode/products
```

🎉 恭喜！您已经成功创建了第一个低代码 API！

---

## 📖 核心概念

### 1. Subagents（子代理）

专门领域的 AI 助手，具有特定的专业知识。

**可用的 Subagents**：
- `lowcode-developer` - 低代码开发专家
- `api-developer` - API 开发专家

**使用方式**：
```bash
auggie
> 使用 lowcode-developer 帮我创建一个订单管理系统
```

### 2. Skills（技能）

可复用的任务模板，用于快速完成常见任务。

**可用的 Skills**：
- `create-collection` - 创建 Collection
- `create-api-endpoint` - 创建 API 端点

**使用方式**：
```bash
auggie --print "使用 create-collection skill 创建 Order Collection"
```

### 3. Commands（命令）

完整的工作流程，包含多个步骤。

**可用的 Commands**：
- `lowcode-init` - 初始化低代码环境
- `generate-crud` - 生成 CRUD 代码

**使用方式**：
```bash
auggie --print "运行 lowcode-init 命令"
```

---

## 💡 常见任务

### 创建数据模型

```bash
auggie
> 创建一个用户 Collection，包含：
> - username（用户名，string，必填，唯一）
> - email（邮箱，string，必填，唯一）
> - phone（手机号，string，可选）
> - status（状态，select，选项：激活/禁用）
```

### 生成 API

```bash
auggie
> 为 User Collection 生成完整的 RESTful API，
> 包括认证和权限控制
```

### 添加字段

```bash
auggie
> 为 Product Collection 添加以下字段：
> - images（图片数组，JSON）
> - tags（标签，多选）
> 并生成数据库迁移
```

### 修复问题

```bash
auggie
> 我的 API 返回 500 错误，错误信息是：
> "Call to undefined method..."
> 请帮我定位和修复
```

### 优化性能

```bash
auggie
> Product 列表查询很慢，请帮我优化：
> 1. 分析性能瓶颈
> 2. 添加索引
> 3. 实现缓存
```

---

## 📚 学习资源

### 文档

- [完整 README](.augment/README.md) - 详细的配置说明
- [使用示例](.augment/examples/usage-examples.md) - 实际使用案例
- [配置文件](.augment/config.yaml) - 项目配置

### Subagents

- [lowcode-developer](.augment/subagents/lowcode-developer.yaml) - 低代码开发专家
- [api-developer](.augment/subagents/api-developer.yaml) - API 开发专家

### Skills

- [create-collection](.augment/skills/create-collection.yaml) - 创建 Collection
- [create-api-endpoint](.augment/skills/create-api-endpoint.yaml) - 创建 API

### Commands

- [lowcode-init](.augment/commands/lowcode-init.yaml) - 初始化环境
- [generate-crud](.augment/commands/generate-crud.yaml) - 生成 CRUD

---

## 🎯 最佳实践

### 1. 明确需求

❌ 不好的提问：
```
帮我创建一个商品功能
```

✅ 好的提问：
```
使用 create-collection skill 创建 Product Collection，包含：
- name: 商品名称（string，必填）
- price: 价格（decimal，必填，最小值0.01）
- stock: 库存（integer，默认0）
- status: 状态（select，选项：上架/下架）
```

### 2. 使用专门的 Subagent

针对不同任务使用对应的 Subagent：
- 低代码功能 → `lowcode-developer`
- API 开发 → `api-developer`

### 3. 验证结果

生成代码后务必：
```bash
# 运行测试
php think test

# 检查代码规范
./vendor/bin/php-cs-fixer fix --dry-run

# 静态分析
./vendor/bin/phpstan analyse
```

### 4. 迭代优化

遇到问题时提供详细信息：
```
我的 Product API 在创建商品时报错：
- 错误信息：Validation failed
- 请求数据：{"name": "测试商品", "price": 99.99}
- 预期行为：成功创建商品并返回 ID
请帮我分析并修复
```

---

## 🔧 故障排除

### 问题 1: Auggie 无法识别 Subagent

**解决方案**：
```bash
# 检查配置文件
cat .augment/config.yaml

# 确保 subagents.enabled = true
# 确保 subagent 文件存在于 .augment/subagents/ 目录
```

### 问题 2: Skill 执行失败

**解决方案**：
```bash
# 检查 skill 配置
cat .augment/skills/create-collection.yaml

# 确保所有必填参数都已提供
# 检查依赖的服务是否可用
```

### 问题 3: 生成的代码不符合规范

**解决方案**：
```bash
auggie
> 请审查刚才生成的代码，确保：
> 1. 符合 PSR-12 规范
> 2. 使用中英文双语注释
> 3. 所有方法都有 PHPDoc
> 4. 遵循 DDD 架构
```

---

## 📞 获取帮助

### 方式 1: 查看文档

```bash
# 查看完整文档
cat .augment/README.md

# 查看使用示例
cat .augment/examples/usage-examples.md
```

### 方式 2: 询问 Auggie

```bash
auggie
> 我不知道如何使用 create-collection skill，请给我一个详细示例
```

### 方式 3: 查看配置

```bash
# 查看项目配置
cat .augment/config.yaml

# 查看 Subagent 配置
cat .augment/subagents/lowcode-developer.yaml
```

---

## 🎓 进阶主题

准备好深入学习了吗？查看：

- [高级用例](.augment/examples/usage-examples.md#6-高级用例)
- [团队协作](.augment/examples/usage-examples.md#7-团队协作场景)
- [性能优化](.augment/examples/usage-examples.md#场景-b性能优化)

---

**祝您使用愉快！如有问题，随时询问 Auggie！🚀**

