# AlkaidSYS Augment 使用示例

本文档提供了使用 Augment AI 辅助开发 AlkaidSYS 的实际示例。

## 📚 目录

1. [初始化项目](#初始化项目)
2. [创建低代码数据模型](#创建低代码数据模型)
3. [生成 CRUD API](#生成-crud-api)
4. [开发自定义功能](#开发自定义功能)
5. [调试和优化](#调试和优化)

---

## 1. 初始化项目

### 场景：首次设置开发环境

```bash
# 方式 1: 使用 auggie 命令行
auggie --print "运行 lowcode-init 命令，初始化低代码开发环境"

# 方式 2: 直接对话
auggie
> 帮我初始化 AlkaidSYS 低代码开发环境，包括数据库迁移和示例数据
```

**预期结果**：
- ✅ 数据库迁移完成
- ✅ 创建默认租户和管理员用户
- ✅ 创建示例 Collection（Product、Category、Order）
- ✅ 缓存初始化完成

---

## 2. 创建低代码数据模型

### 场景 A：创建简单的商品 Collection

```bash
auggie --print "使用 create-collection skill 创建 Product Collection，
包含以下字段：
- name: 商品名称（string，必填）
- price: 价格（decimal，必填）
- stock: 库存（integer，默认0）
- status: 状态（select，选项：上架/下架）
- description: 描述（text，可选）"
```

### 场景 B：创建带关系的订单 Collection

```bash
auggie
> 使用 lowcode-developer 创建 Order Collection，包含：
> 1. 字段：order_no（订单号）、user_id（用户ID）、total_amount（总金额）、status（状态）
> 2. 关系：belongsTo User、hasMany OrderItem
> 3. 自动生成迁移文件和测试用例
```

### 场景 C：批量创建多个 Collection

```bash
auggie
> 帮我创建一个完整的电商数据模型，包括：
> 1. Product（商品）- name, price, stock, category_id
> 2. Category（分类）- name, parent_id, sort_order
> 3. Order（订单）- order_no, user_id, total_amount, status
> 4. OrderItem（订单项）- order_id, product_id, quantity, price
> 5. 建立它们之间的关系
```

---

## 3. 生成 CRUD API

### 场景 A：为已有 Collection 生成完整 CRUD

```bash
auggie --print "为 Product Collection 生成完整的 CRUD 代码，
包括控制器、路由、验证器、测试用例和 API 文档"
```

**生成的文件**：
- `app/controller/lowcode/ProductController.php`
- `route/lowcode.php`（已更新）
- `app/validate/lowcode/ProductValidate.php`
- `tests/Feature/Lowcode/ProductCrudTest.php`

### 场景 B：生成只读 API

```bash
auggie
> 为 Category Collection 生成只读 API，
> 只需要 index（列表）和 show（详情）两个接口
```

### 场景 C：生成带权限控制的 API

```bash
auggie
> 为 Order Collection 生成 CRUD API，
> 要求：
> 1. 所有接口需要认证
> 2. 创建和删除需要 order:write 权限
> 3. 查询需要 order:read 权限
> 4. 支持按用户ID过滤订单
```

---

## 4. 开发自定义功能

### 场景 A：添加自定义业务逻辑

```bash
auggie
> 我需要在 Product Collection 中添加库存扣减功能：
> 1. 创建 decreaseStock 方法
> 2. 支持事务处理
> 3. 库存不足时抛出异常
> 4. 记录库存变更日志
> 请帮我实现这个功能
```

### 场景 B：实现复杂查询

```bash
auggie
> 帮我实现一个商品搜索 API：
> 1. 支持按名称模糊搜索
> 2. 支持按分类筛选
> 3. 支持按价格区间筛选
> 4. 支持按销量排序
> 5. 支持分页
> 请使用 api-developer 实现
```

### 场景 C：添加字段到现有 Collection

```bash
auggie
> 使用 lowcode-developer 为 Product Collection 添加以下字段：
> 1. images（图片数组，JSON 类型）
> 2. tags（标签，多选）
> 3. created_by（创建人，关联 User）
> 并生成相应的数据库迁移
```

---

## 5. 调试和优化

### 场景 A：修复 Bug

```bash
auggie
> 我的 Product API 在查询时报错：
> "Call to undefined method Collection::getFields()"
> 请帮我定位和修复这个问题
```

### 场景 B：性能优化

```bash
auggie
> Product 列表查询很慢（响应时间 > 2秒），
> 请帮我分析性能瓶颈并优化：
> 1. 检查数据库查询
> 2. 添加必要的索引
> 3. 实现缓存策略
> 4. 优化 N+1 查询问题
```

### 场景 C：代码审查

```bash
auggie
> 请审查 app/controller/lowcode/ProductController.php，
> 重点检查：
> 1. 是否符合 PSR-12 规范
> 2. 是否有安全漏洞
> 3. 是否有性能问题
> 4. 测试覆盖率是否足够
```

---

## 6. 高级用例

### 场景 A：实现工作流

```bash
auggie
> 使用 lowcode-developer 创建一个订单审批工作流：
> 1. 触发器：订单创建时
> 2. 节点：条件判断（金额 > 1000）→ 人工审批 → 通知节点
> 3. 变量：订单数据、审批人、审批结果
> 4. 基于 Swoole 协程异步执行
```

### 场景 B：生成前端表单

```bash
auggie
> 基于 Product Collection 生成前端表单配置：
> 1. 使用 Vben Admin 的 Form Schema
> 2. 包含所有字段的表单项
> 3. 添加验证规则
> 4. 支持图片上传
> 5. 生成 TypeScript 类型定义
```

### 场景 C：批量数据导入

```bash
auggie
> 帮我实现一个 Excel 批量导入商品的功能：
> 1. 解析 Excel 文件
> 2. 验证数据格式
> 3. 批量插入到 Product Collection
> 4. 返回导入结果（成功/失败数量）
> 5. 支持异步处理大文件
```

---

## 7. 团队协作场景

### 场景 A：代码规范检查

```bash
auggie
> 检查整个项目的代码规范：
> 1. PHP 代码是否符合 PSR-12
> 2. 注释是否使用中英文双语
> 3. 所有公共方法是否有 PHPDoc
> 4. 生成规范检查报告
```

### 场景 B：生成 API 文档

```bash
auggie
> 为所有低代码 API 生成 OpenAPI 文档：
> 1. 扫描所有 lowcode 控制器
> 2. 生成 OpenAPI 3.0 规范
> 3. 包含请求/响应示例
> 4. 输出到 public/api-docs.json
```

### 场景 C：编写测试用例

```bash
auggie
> 为 ProductController 编写完整的测试用例：
> 1. 单元测试（每个方法）
> 2. 集成测试（完整流程）
> 3. 边界测试（异常情况）
> 4. 性能测试（响应时间）
> 目标覆盖率：90%+
```

---

## 💡 最佳实践

### 1. 使用专门的 Subagent

针对不同任务使用对应的 Subagent：
- 低代码功能 → `lowcode-developer`
- API 开发 → `api-developer`
- 数据库操作 → `database-expert`

### 2. 明确需求

提供详细的需求描述，包括：
- 功能描述
- 字段定义
- 业务规则
- 性能要求

### 3. 验证结果

生成代码后务必：
- 运行测试用例
- 检查代码规范
- 验证功能正确性
- 测试性能

### 4. 迭代优化

遇到问题时：
- 提供详细的错误信息
- 说明预期行为
- 让 AI 分析并修复

---

## 📞 获取帮助

如果遇到问题，可以：

1. 查看文档：`.augment/README.md`
2. 查看配置：`.augment/config.yaml`
3. 查看示例：本文件
4. 直接询问 Auggie：
   ```bash
   auggie
   > 我不知道如何使用 create-collection skill，请给我一个详细的示例
   ```

---

**祝您使用愉快！🚀**

