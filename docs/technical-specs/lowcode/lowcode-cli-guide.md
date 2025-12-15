# AlkaidSYS 低代码 CLI 使用指南

## 概述

AlkaidSYS 提供了一套强大的命令行工具（CLI），用于快速创建和管理低代码数据模型、表单和代码生成。本指南详细说明每个命令的使用方法、参数选项和最佳实践。

## 环境要求

### 执行环境

⚠️ **重要**：所有 CLI 命令必须在 `alkaid-backend` 容器内执行：

```bash
docker exec -it alkaid-backend php think lowcode:命令名 [参数] [选项]
```

### 权限要求

- 数据库读写权限
- 文件系统写入权限（用于生成代码文件）
- 足够的数据库连接池配额

---

## 命令索引

1. [lowcode:create-model](#lowcodecreate-model) - 创建数据模型（Collection）
2. [lowcode:create-form](#lowcodecreate-form) - 创建表单 Schema
3. [lowcode:generate](#lowcodegenerate) - 生成 CRUD 代码
4. [lowcode:migration:diff](#lowcodemigrationdiff) - 检查 Schema 差异和生成迁移

---

## lowcode:create-model

### 功能说明

创建新的数据模型（Collection）并自动生成对应的数据库表。

### 语法

```bash
php think lowcode:create-model <名称> [选项]
```

### 参数

| 参数 | 必需 | 说明 | 示例 |
|------|------|------|------|
| `name` | ✅ | Collection 名称（使用 PascalCase） | `Product`, `BlogPost`, `UserProfile` |

### 选项

| 选项 | 简写 | 类型 | 默认值 | 说明 |
|------|------|------|--------|------|
| `--fields` | `-f` | string | - | 字段定义，格式：`name:type,name:type,...` |
| `--title` | `-t` | string | 同名称 | Collection 的显示标题 |
| `--description` | `-d` | string | 空 | Collection 的描述 |
| `--table-name` | - | string | 自动生成 | 自定义数据库表名 |
| `--tenant-id` | - | int | 0 | 租户 ID（0 = 系统模板） |
| `--site-id` | - | int | 0 | 站点 ID（0 = 默认站点) |
| `--force` | - | flag | false | 强制覆盖已存在的 Collection |

### 支持的字段类型

| 类型 | 说明 | MySQL 类型 | 示例 |
|------|------|-----------|------|
| `string` | 短文本 | VARCHAR(255) | 用户名、标题 |
| `text` | 长文本 | TEXT | 文章内容、描述 |
| `integer` | 整数 | INT | 年龄、数量 |
| `bigint` | 大整数 | BIGINT | ID、时间戳 |
| `decimal` | 小数 | DECIMAL(10,2) | 价格、金额 |
| `boolean` | 布尔值 | TINYINT(1) | 是否启用 |
| `date` | 日期 | DATE | 生日、发布日期 |
| `datetime` | 日期时间 | DATETIME | 创建时间 |
| `timestamp` | 时间戳 | TIMESTAMP | 最后登录时间 |
| `json` | JSON 数据 | JSON | 配置、元数据 |
| `select` | 下拉选择 | VARCHAR | 状态、分类 |
| `radio` | 单选 | VARCHAR | 性别 |
| `checkbox` | 复选框 | VARCHAR | 标签、权限 |
| `file` | 文件 | VARCHAR | 文件路径 |
| `image` | 图片 | VARCHAR | 图片路径 |

### 使用示例

#### 示例 1：创建产品模型（基础用法）

```bash
docker exec -it alkaid-backend php think lowcode:create-model Product \
  --fields="name:string,price:decimal,stock:integer,description:text" \
  --title="产品管理" \
  --description="电商产品信息管理"
```

#### 示例 2：交互式创建博客文章模型

```bash
docker exec -it alkaid-backend php think lowcode:create-model BlogPost
# 命令会提示输入每个字段的信息
```

#### 示例 3：为特定租户创建模型

```bash
docker exec -it alkaid-backend php think lowcode:create-model CustomerOrder \
  --fields="order_no:string,total_amount:decimal,status:select" \
  --tenant-id=1 \
  --site-id=1
```

#### 示例 4：强制覆盖已存在的模型

```bash
docker exec -it alkaid-backend php think lowcode:create-model Product \
  --fields="name:string,updated_price:decimal" \
  --force
```

### 命令执行流程

```mermaid
graph TD
    A[开始] --> B{是否提供 --fields?}
    B -->|是| C[解析字段定义]
    B -->|否| D[进入交互模式]
    C --> E[验证字段类型]
    D --> E
    E --> F{Collection 是否存在?}
    F -->|是| G{是否使用 --force?}
    F -->|否| H[显示摘要]
    G -->|是| H
    G -->|否| I[提示错误并退出]
    H --> J{确认创建?}
    J -->|是| K[创建物理表]
    J -->|否| L[取消操作]
    K --> M[保存元数据]
    M --> N[缓存Collection]
    N --> O[显示成功信息和下一步建议]
    O --> P[结束]
    I --> P
    L --> P
```

### 常见问题

**Q: 字段名称有什么限制？**  
A: 必须以字母或下划线开头，只能包含字母、数字和下划线。推荐使用 snake_case 命名。

**Q: 如何添加字段的约束（如必填、唯一）？**  
A: 当前版本通过交互模式或后续通过 API 修改。未来版本将支持在命令中直接指定。

**Q: 创建失败如何回滚？**  
A: 命令使用事务机制，失败会自动回滚数据库更改。

---

## lowcode:create-form

### 功能说明

基于已存在的 Collection 创建表单 Schema，用于前端表单渲染。

### 语法

```bash
php think lowcode:create-form <表单名称> [选项]
```

### 参数

| 参数 | 必需 | 说明 | 示例 |
|------|------|------|------|
| `name` | ✅ | 表单名称 | `product_create_form`, `user_profile_edit` |

### 选项

| 选项 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `--collection` | string | - | 基于哪个 Collection 创建表单 |
| `--fields` | string | 全部 | 包含的字段（逗号分隔） |
| `--tenant-id` | int | 0 | 租户 ID |
| `--site-id` | int | 0 | 站点 ID |

### 使用示例

#### 示例 1：基于 Collection 创建完整表单

```bash
docker exec -it alkaid-backend php think lowcode:create-form product_create_form \
  --collection=Product
```

#### 示例 2：创建部分字段的表单（如编辑表单）

```bash
docker exec -it alkaid-backend php think lowcode:create-form product_edit_form \
  --collection=Product \
  --fields="name,price,stock"
```

### 生成的表单 Schema 结构

```json
{
  "name": "product_create_form",
  "collection": "Product",
  "fields": [
    {
      "name": "name",
      "label": "产品名称",
      "type": "input",
      "component": "VbenInput",
      "rules": [{"required": true, "message": "请输入产品名称"}]
    },
    {
      "name": "price",
      "label": "价格",
      "type": "number",
      "component": "VbenInputNumber",
      "componentProps": {"precision": 2}
    }
  ]
}
```

---

## lowcode:generate

### 功能说明

基于已创建的 Collection 生成 CRUD 代码，包括 Controller、Routes 和 Tests。

### 语法

```bash
php think lowcode:generate <类型> <Collection名称> [选项]
```

### 生成类型

| 类型 | 说明 | 生成内容 |
|------|------|---------|
| `crud` | 完整 CRUD | Controller + Routes + Tests |
| `controller` | 仅控制器 | Controller 文件 |
| `routes` | 仅路由 | Route 配置 |
| `tests` | 仅测试 | Feature Tests |

### 选项

| 选项 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `--namespace` | string | `app\controller\lowcode` | 控制器命名空间 |
| `--route-prefix` | string | `v1/lowcode` | 路由前缀 |
| `--force` | flag | false | 强制覆盖已存在的文件 |

### 使用示例

#### 示例 1：生成完整 CRUD

```bash
docker exec -it alkaid-backend php think lowcode:generate crud Product
```

生成的文件：
- `app/controller/lowcode/ProductController.php`
- `route/lowcode/product.php`
- `tests/Feature/Lowcode/ProductControllerTest.php`

#### 示例 2：仅生成控制器

```bash
docker exec -it alkaid-backend php think lowcode:generate controller Product \
  --namespace="app\controller\api"
```

#### 示例 3：自定义路由前缀

```bash
docker exec -it alkaid-backend php think lowcode:generate crud Product \
  --route-prefix="api/v2/products"
```

### 生成的 Controller 包含的方法

| 方法 | HTTP Method | 路由 | 功能 |
|------|-------------|------|------|
| `index()` | GET | `/products` | 列表（分页） |
| `read()` | GET | `/products/:id` | 详情 |
| `create()` | POST | `/products` | 创建 |
| `update()` | PUT | `/products/:id` | 更新 |
| `delete()` | DELETE | `/products/:id` | 删除 |

---

## lowcode:migration:diff

### 功能说明

比较 Collection Schema 与数据库实际结构，检测差异并生成迁移脚本。

### 语法

```bash
php think lowcode:migration:diff [Collection名称] [选项]
```

### 选项

| 选项 | 简写 | 类型 | 默认值 | 说明 |
|------|------|------|--------|------|
| `--all` | `-a` | flag | false | 检查所有 Collection |
| `--check` | `-c` | flag | false | 仅检查，不生成迁移 |
| `--tenant-id` | - | int | 0 | 指定租户 ID |

### 使用示例

#### 示例 1：检查单个 Collection 的差异

```bash
docker exec -it alkaid-backend php think lowcode:migration:diff Product
```

#### 示例 2：检查所有 Collection

```bash
docker exec -it alkaid-backend php think lowcode:migration:diff --all
```

#### 示例 3：仅检查不生成迁移

```bash
docker exec -it alkaid-backend php think lowcode:migration:diff --all --check
```

### 输出示例

```
=== Schema Diff Report ===

Collection: Product
Table: lc_product

Changes detected:
  + Add column: featured (boolean)
  ~ Modify column: price (decimal(8,2) -> decimal(10,2))
  - Drop column: old_price

Migration file generated: database/migrations/20251215_update_product_table.php
```

---

## 最佳实践

### 1. 命名规范

**Collection 名称**
- ✅ 推荐：`Product`, `BlogPost`, `UserProfile`
- ❌ 避免：`product`, `blog-post`, `user_profile`

**字段名称**
- ✅ 推荐：`product_name`, `created_at`, `is_active`
- ❌ 避免：`ProductName`, `创建时间`, `active?`

### 2. 字段类型选择

| 业务场景 | 推荐类型 | 说明 |
|---------|---------|------|
| 货币金额 | decimal | 使用 decimal(10,2) |
| 状态码 | string/select | 便于扩展 |
| 布尔标志 | boolean | 明确的真/假值 |
| 长文本 | text | 超过 255字符 |
| 标签/多选 | checkbox/json | 存储数组 |

### 3. 多租户使用

```bash
# 系统模板（所有租户共享）
--tenant-id=0 --site-id=0

# 特定租户
--tenant-id=1 --site-id=1
```

### 4. 版本化工作流

```bash
# 1. 创建模型
php think lowcode:create-model Product --fields="name:string,price:decimal"

# 2. 生成代码
php think lowcode:generate crud Product

# 3. 测试
docker exec alkaid-backend php think test tests/Feature/Lowcode/ProductControllerTest.php

# 4. 如有修改，使用 migration:diff 跟踪变更
php think lowcode:migration:diff Product
```

---

## 故障排查

### 问题 1：命令未找到

**症状**：`Command "lowcode:create-model" is not defined.`

**解决方案**：
```bash
# 确认在容器内执行
docker exec -it alkaid-backend bash

# 检查命令是否注册
php think list | grep lowcode
```

### 问题 2：数据库连接失败

**症状**：`SQLSTATE[HY000] [2002] Connection refused`

**解决方案**：
```bash
# 检查数据库配置
cat .env | grep DB_

# 测试数据库连接
docker exec alkaid-backend php think db:test
```

### 问题 3：权限不足

**症状**：`Permission denied when creating table`

**解决方案**：
```sql
-- 检查数据库用户权限
SHOW GRANTS FOR 'alkaid_user'@'%';

-- 授予必要权限
GRANT CREATE, ALTER, DROP ON alkaid_db.* TO 'alkaid_user'@'%';
```

### 问题 4：Collection 已存在

**症状**：`Collection 'Product' already exists`

**解决方案**：
```bash
# 方案 1：使用 --force 覆盖
php think lowcode:create-model Product --force --fields="..."

# 方案 2：删除后重建（谨慎！）
# 先删除物理表和元数据，再重新创建
```

---

## 进阶用法

### 1. 批量创建模型

```bash
#!/bin/bash
# create_ecommerce_models.sh

MODELS=(
  "Product:name:string,price:decimal,stock:integer"
  "Category:name:string,description:text"
  "Order:order_no:string,total_amount:decimal,status:select"
)

for model in "${MODELS[@]}"; do
  IFS=':' read -r name fields <<< "$model"
  docker exec alkaid-backend php think lowcode:create-model "$name" \
    --fields="$fields" \
    --tenant-id=1
done
```

### 2. 自动化测试集成

```yaml
# .github/workflows/lowcode-test.yml
name: Lowcode CLI Tests

on: [push]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Test Model Creation
        run: |
          docker exec alkaid-backend php think lowcode:create-model TestModel \
            --fields="name:string" \
            --force
          
      - name: Verify Table Exists
        run: |
          docker exec alkaid-mysql mysql -e "SHOW TABLES LIKE 'lc_test_model';"
```

---

## 相关文档

- [低代码框架设计文档](../../../design/09-lowcode-framework/40-lowcode-framework-architecture.md)
- [API 规范](../api/api-specification.zh-CN.md)
- [数据库设计指南](../database/database-guidelines.zh-CN.md)
- [多租户数据模型规范](../../../design/03-data-layer/12-multi-tenant-data-model-spec.md)

---

## 常见使用场景

### 场景 1：快速原型开发

```bash
# 5分钟构建一个博客系统

# 1. 创建文章模型
php think lowcode:create-model BlogPost \
  --fields="title:string,content:text,author:string,published_at:datetime,status:select"

# 2. 创建分类模型
php think lowcode:create-model Category \
  --fields="name:string,slug:string,description:text"

# 3. 生成 CRUD 代码
php think lowcode:generate crud BlogPost
php think lowcode:generate crud Category

# 4. 创建表单
php think lowcode:create-form blog_post_create --collection=BlogPost
php think lowcode:create-form category_create --collection=Category
```

### 场景 2：多租户SaaS应用

```bash
# 为每个租户创建独立的数据模型

# 租户 1
php think lowcode:create-model Customer \
  --fields="name:string,email:string,phone:string" \
  --tenant-id=1 \
  --site-id=1

# 租户 2
php think lowcode:create-model Customer \
  --fields="name:string,email:string,company:string" \
  --tenant-id=2 \
  --site-id=1
```

---

## 更新日志

### v1.0.0 (2025-12-15)
- ✅ 初始版本发布
- ✅ 支持基础的 CRUD 模型创建
- ✅ 支持多租户隔离
- ✅ 支持自动代码生成

### 路线图

- [ ] 支持字段级别的约束配置 (v1.1.0)
- [ ] 支持关系定义（1:1, 1:N, N:M） (v1.2.0)
- [ ] 支持 Schema 版本管理 (v1.3.0)
- [ ] 支持可视化 Schema 设计器集成 (v2.0.0)

---

## 获取帮助

如有问题，可以：

1. 查看命令的详细帮助：`php think lowcode:create-model --help`
2. 查看技术规范文档：`docs/technical-specs/lowcode/`
3. 查看源代码：`app/command/lowcode/`
4. 提交 Issue 或联系开发团队

---

**最后更新**：2025-12-15  
**文档版本**：1.0.0
