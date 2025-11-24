# AlkaidSYS 低代码能力概述

> **文档版本**：v1.0  
> **创建日期**：2025-01-20  
> **最后更新**：2025-01-20  
> **作者**：AlkaidSYS 架构团队

---

## 📋 目录

- [1. 低代码能力定位](#1-低代码能力定位)
- [2. 目标用户](#2-目标用户)
- [3. 核心能力清单](#3-核心能力清单)
- [4. 架构设计](#4-架构设计)
- [5. 与 NocoBase 的对比优势](#5-与-nocobase-的对比优势)
- [6. 使用场景和案例](#6-使用场景和案例)
- [7. 技术亮点](#7-技术亮点)

---

## 1. 低代码能力定位

### 1.1 核心定位

AlkaidSYS 低代码能力定位为**开发者工具**，而非独立的低代码平台。其核心目标是：

> **帮助使用 AlkaidSYS 框架的应用/插件开发者快速开发应用和插件，提升开发效率 50%+，降低开发成本 30%+。**

### 1.2 设计理念

1. **插件化核心引擎**：低代码核心能力以插件形式提供，所有应用都可以调用
2. **可选管理界面**：提供可视化管理界面，但不强制使用
3. **多种使用方式**：支持 CLI 工具、可视化界面、API 三种使用方式
4. **与框架深度集成**：与 AlkaidSYS 框架无缝集成，而非独立系统
5. **聚焦核心 20%**：实现核心 20% 功能，满足 80% 需求

### 1.3 与传统低代码平台的区别

| 对比维度 | 传统低代码平台 | AlkaidSYS 低代码能力 |
|---------|--------------|-------------------|
| **定位** | 独立的应用开发平台 | 开发者工具 |
| **目标用户** | 业务人员 + 开发者 | 开发者 |
| **使用方式** | 主要通过可视化界面 | CLI + 界面 + API |
| **集成方式** | 独立系统 | 框架深度集成 |
| **功能范围** | 全功能（100%） | 核心功能（20%） |
| **学习曲线** | 陡峭 | 平缓 |

---

## 2. 目标用户

### 2.1 主要用户群体

#### 用户群体 1：使用 AlkaidSYS 框架的应用/插件开发者

**需求**：
- 快速开发应用和插件
- 减少重复代码编写
- 提升开发效率

**使用方式**：
```bash
# 通过 CLI 工具快速创建应用
alkaid init app my-ecommerce --with-lowcode

# 创建数据模型
alkaid lowcode:create-model Product --fields="name:string,price:decimal"

# 创建表单
alkaid lowcode:create-form product_form --title="商品表单"

# 生成 CRUD 代码
alkaid lowcode:generate crud Product
```

#### 用户群体 2：购买了 AlkaidSYS 成品系统的企业开发人员

**需求**：
- 在已有系统基础上快速扩展功能
- 无需深入了解系统架构
- 快速响应业务需求

**使用方式**：
```bash
# 访问低代码管理界面
http://your-domain.com/lowcode/form-designer

# 拖拽组件，设计表单，保存
# 无需编写代码，即可扩展功能
```

#### 用户群体 3：AlkaidSYS 核心开发团队

**需求**：
- 快速开发示例应用
- 验证框架功能
- 提供最佳实践

**使用方式**：
```php
// 通过 API 编程方式使用低代码能力
use plugin\lowcode\service\FormDesignerService;

$formDesigner = app(FormDesignerService::class);
$schema = $formDesigner->getSchema('product_form');
```

### 2.2 用户画像

| 用户类型 | 技术水平 | 使用频率 | 主要使用方式 | 占比 |
|---------|---------|---------|------------|------|
| **应用开发者** | 高 | 高 | CLI + API | 60% |
| **企业开发人员** | 中 | 中 | 界面 + CLI | 30% |
| **核心团队** | 高 | 高 | API + CLI | 10% |

---

## 3. 核心能力清单

### 3.1 数据建模（Data Modeling）

**能力描述**：动态创建数据表和字段，支持 15+ 种字段类型，支持关系建模

**核心功能**：
- ✅ 可视化表设计
- ✅ 15+ 种字段类型（string、integer、decimal、boolean、date、datetime、json、text、file、image、select、radio、checkbox、cascader、tree-select）
- ✅ 关系建模（1对1、1对多、多对多）
- ✅ 数据迁移和版本管理
- ✅ Collection 抽象层

**使用示例**：
```bash
# CLI 方式
alkaid lowcode:create-model Product \
  --fields="name:string,price:decimal,stock:integer,status:select"

# API 方式
$collectionManager->create(new Collection('products', [
    'fields' => [
        new StringField('name'),
        new DecimalField('price'),
        new IntegerField('stock'),
    ]
]));
```

**业务价值**：⭐⭐⭐⭐⭐（极高）

---

### 3.2 表单设计器（Form Designer）

**能力描述**：基于 Schema 的表单设计和渲染，支持拖拽式设计

**核心功能**：
- ✅ 拖拽式表单设计
- ✅ 15+ 种表单组件（基于 Ant Design Vue）
- ✅ 表单验证（前后端统一）
- ✅ 表单布局（单列、双列、三列）
- ✅ 条件显示/隐藏
- ✅ 表单联动

**使用示例**：
```bash
# CLI 方式
alkaid lowcode:create-form product_form --title="商品表单"

# 可视化界面方式
# 访问 http://localhost:8000/lowcode/form-designer
# 拖拽组件，设计表单，保存
```

**业务价值**：⭐⭐⭐⭐⭐（极高）

---

### 3.3 工作流引擎（Workflow Engine）

**能力描述**：可视化工作流设计，支持 10+ 种节点类型，支持异步执行

**核心功能**：
- ✅ 可视化工作流设计器
- ✅ 10+ 种触发器（表单提交、数据创建、数据更新、定时触发、手动触发等）
- ✅ 10+ 种节点类型（条件判断、数据查询、数据创建、数据更新、发送通知、HTTP 请求、延迟执行等）
- ✅ 条件分支和循环
- ✅ 变量系统
- ✅ 异步执行和队列

**使用示例**：
```bash
# CLI 方式
alkaid lowcode:create-workflow order_workflow --title="订单工作流"

# 可视化界面方式
# 访问 http://localhost:8000/lowcode/workflow-designer
# 拖拽节点，设计工作流，保存
```

**业务价值**：⭐⭐⭐⭐⭐（极高）

---

### 3.4 Schema 驱动 UI（Schema-Driven UI）

**能力描述**：基于 JSON Schema 动态渲染 UI 组件

**核心功能**：
- ✅ JSON Schema 定义
- ✅ 动态组件渲染
- ✅ 组件库扩展
- ✅ 主题定制

**使用示例**：
```vue
<template>
  <FormRenderer :schema="schema" @submit="handleSubmit" />
</template>

<script setup lang="ts">
import { ref } from 'vue';
import FormRenderer from '@/components/FormRenderer.vue';

const schema = ref({
  type: 'object',
  properties: {
    name: {
      type: 'string',
      title: '商品名称',
      'x-component': 'Input',
      'x-decorator': 'FormItem',
    },
    price: {
      type: 'number',
      title: '商品价格',
      'x-component': 'InputNumber',
      'x-decorator': 'FormItem',
    },
  },
});
</script>
```

**业务价值**：⭐⭐⭐⭐（高）

---

## 4. 架构设计

### 4.1 混合方案（方案 4）

AlkaidSYS 低代码能力采用**混合方案**：

```
AlkaidSYS 框架
├── 核心框架层（Framework Core）
│   ├── Container（依赖注入容器）
│   ├── Event（事件系统）
│   ├── Cache（缓存系统）
│   ├── Queue（队列系统）
│   └── Log（日志系统）
├── 低代码基础层（Lowcode Foundation）
│   ├── Schema Manager（Schema 管理器）
│   ├── Collection Manager（Collection 管理器）
│   ├── Field Type Registry（字段类型注册表）
│   ├── Relationship Manager（关系管理器）
│   └── Validator Generator（验证器生成器）
├── 低代码插件层（Lowcode Plugins）⭐ 核心引擎（必须安装）
│   ├── lowcode-data-modeling（数据建模插件）
│   ├── lowcode-form-designer（表单设计器插件）
│   ├── lowcode-workflow（工作流引擎插件）
│   └── lowcode-schema-parser（Schema 解析器插件）
└── 低代码应用层（Lowcode Application）⭐ 管理界面（可选安装）
    └── lowcode-management-app（低代码管理应用）
```

### 4.2 核心理念

**分离关注点**：
- **插件层**：提供低代码核心引擎（API 和服务），所有应用都可以调用
- **应用层**：提供低代码管理界面（可视化操作），可选安装

**优势**：
- ✅ 核心引擎插件化：所有应用都可以调用
- ✅ 提供可视化界面：开发者可以通过界面操作
- ✅ 灵活性极高：支持界面、CLI、API 三种使用方式
- ✅ 可选择性安装：核心引擎必须安装，管理界面可选

---

## 5. 与 NocoBase 的对比优势

| 对比维度 | NocoBase | AlkaidSYS 低代码能力 | 优势 |
|---------|----------|-------------------|------|
| **技术栈** | Node.js + React | PHP + Vue 3 + Ant Design Vue | ✅ 与 AlkaidSYS 技术栈一致 |
| **定位** | 独立低代码平台 | 开发者工具 | ✅ 更符合开发者需求 |
| **集成方式** | 独立系统 | 框架深度集成 | ✅ 无缝集成 |
| **使用方式** | 主要通过界面 | CLI + 界面 + API | ✅ 更灵活 |
| **学习曲线** | 陡峭 | 平缓 | ✅ 易于上手 |
| **性能** | 中等 | 高（Swoole 加持） | ✅ 更高性能 |
| **功能范围** | 全功能（100%） | 核心功能（20%） | ✅ 聚焦核心需求 |
| **CLI 工具** | 基础 CLI | 完整 CLI 集成 | ✅ 开发者友好 |

---

## 6. 使用场景和案例

### 场景 1：快速开发电商应用

**需求**：开发一个电商应用，包含商品管理、订单管理、用户管理

**传统方式**：
- 手写数据表设计（2 天）
- 手写模型代码（2 天）
- 手写表单代码（3 天）
- 手写 CRUD 代码（3 天）
- **总计：10 天**

**使用低代码**：
```bash
# 1. 创建应用（自动安装低代码插件）
alkaid init app my-ecommerce --with-lowcode

# 2. 创建数据模型
alkaid lowcode:create-model Product --fields="name:string,price:decimal,stock:integer"
alkaid lowcode:create-model Order --fields="order_no:string,total:decimal,status:select"
alkaid lowcode:create-model User --fields="username:string,email:string,phone:string"

# 3. 创建表单
alkaid lowcode:create-form product_form --title="商品表单"
alkaid lowcode:create-form order_form --title="订单表单"

# 4. 生成 CRUD 代码
alkaid lowcode:generate crud Product
alkaid lowcode:generate crud Order

# 总计：2 小时
```

**效率提升**：**40 倍**（10 天 → 2 小时）

---

### 场景 2：扩展 OA 系统的请假功能

**需求**：在已有 OA 系统基础上，添加请假功能

**传统方式**：
- 了解系统架构（1 天）
- 设计数据表（1 天）
- 编写代码（3 天）
- 测试（1 天）
- **总计：6 天**

**使用低代码**：
```bash
# 1. 访问低代码管理界面
http://your-oa-system.com/lowcode/form-designer

# 2. 拖拽组件，设计请假表单
# - 请假类型（下拉选择）
# - 请假开始时间（日期选择）
# - 请假结束时间（日期选择）
# - 请假原因（文本域）

# 3. 保存表单，自动生成数据表和 CRUD 代码

# 总计：1 小时
```

**效率提升**：**48 倍**（6 天 → 1 小时）

---

## 7. 技术亮点

### 7.1 插件化核心引擎

**亮点**：低代码核心能力以插件形式提供，所有应用都可以调用

**技术实现**：
- 基于服务提供者机制注册服务
- 通过事件系统协调插件间通信
- 懒加载机制，性能影响 < 5%

### 7.2 多级缓存

**亮点**：Swoole Table + Redis 多级缓存，极致性能

**技术实现**：
```php
// 1. 先从 Swoole Table 获取（内存缓存，最快）
$schema = SwooleTable::get('schema:' . $id);

// 2. 再从 Redis 获取（分布式缓存）
if (!$schema) {
    $schema = Redis::get('schema:' . $id);
}

// 3. 最后从数据库获取
if (!$schema) {
    $schema = Db::name('schemas')->find($id);
}
```

### 7.3 前后端验证规则统一

**亮点**：基于 JSON Schema 的验证规则，前后端完全一致

**技术实现**：
```json
{
  "type": "string",
  "title": "商品名称",
  "required": true,
  "minLength": 2,
  "maxLength": 50,
  "x-error-message": "商品名称长度必须在 2-50 个字符之间"
}
```

### 7.4 CLI 工具深度集成

**亮点**：完整的 CLI 工具集成，开发者友好

**技术实现**：
```bash
alkaid lowcode:install          # 安装低代码插件
alkaid lowcode:create-form      # 创建表单
alkaid lowcode:create-model     # 创建数据模型
alkaid lowcode:create-workflow  # 创建工作流
alkaid lowcode:generate         # 生成代码
```

### 7.5 Prompt 模板库与入口（新增）

- 模板位置：`docs/prompt-templates/`（按应用/插件/CRUD/API/测试分类，YAML 描述）
- 推荐入口：MCP TemplateGeneratorTool（根据自然语言识别并填充模板参数）

使用示例：
```bash
# 通过 MCP 识别需求并给出后续 CLI 建议
alkaid mcp:template --prompt "生成一个商城应用的三级分销插件"

# 按推荐结果执行（示例）
alkaid plugin:create distribution --type=app-specific --app=ecommerce
alkaid lowcode:create-model Distributor --fields="user_id:integer,parent_id:integer,status:string"
```

模板索引：
- docs/prompt-templates/README.md
- docs/prompt-templates/plugin/distribution-template.yaml
- docs/prompt-templates/crud/basic-template.yaml
- docs/prompt-templates/api/restful-template.yaml

**与开发者指南的关系：** 本文聚焦低代码能力的定位、架构视图和典型使用场景；具体“如何在实际项目中使用低代码能力开发应用/插件”的落地流程，以《应用开发者指南》（`../08-developer-guides/31-application-development-guide.md`）和《插件开发者指南》（`../08-developer-guides/32-plugin-development-guide.md`）为主，这两份文档会把 CLI 命令、低代码数据建模/表单/工作流与应用/插件开发流程串成端到端路径。
---

**文档结束**
