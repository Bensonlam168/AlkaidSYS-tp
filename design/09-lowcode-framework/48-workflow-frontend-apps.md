# AlkaidSYS 工作流前端应用详细设计

> **文档版本**：v2.0
> **创建日期**：2025-01-20
> **最后更新**：2025-01-20
> **作者**：AlkaidSYS 架构团队

---

## 📋 目录

- [1. 架构概述](#1-架构概述)
- [2. 共享组件库设计](#2-共享组件库设计)
- [3. 审批流应用设计](#3-审批流应用设计)
- [4. 自动化工作流应用设计](#4-自动化工作流应用设计)
- [5. 综合工作流应用设计](#5-综合工作流应用设计)
- [6. 代码复用策略（包结构与目录规划）](#6-代码复用策略包结构与目录规划)
- [7. 入口文件与集成示例](#7-入口文件与集成示例)

## 🔗 关联设计文档

- [术语表（Glossary）](../00-core-planning/99-GLOSSARY.md)
- [低代码工作流引擎插件设计](44-lowcode-workflow.md)
- [表单 → 数据建模 → 工作流端到端集成设计](51-form-collection-workflow-end-to-end.md)
- [可观测性与运维设计](../04-security-performance/15-observability-and-ops-design.md)


---

## 1. 架构概述

### 1.1 整体架构

```
前端应用层：
├── alkaid/lowcode-workflow-approval（审批流应用插件）
│   ├── 审批流设计器（只显示审批流相关节点）
│   ├── 待办任务管理
│   └── 审批历史查询
│
├── alkaid/lowcode-workflow-automation（自动化工作流应用插件）
│   ├── 自动化流程设计器（只显示自动化节点）
│   ├── 执行日志和监控
│   └── 错误处理和重试
│
└── alkaid/lowcode-workflow-hybrid（综合工作流应用插件）
    ├── 完整的流程设计器（显示所有节点类型）
    ├── 统一的流程管理
    └── 高级功能（子流程、并行执行、条件分支）

共享组件库：
└── @alkaid/lowcode-workflow-components（共享组件库）
    ├── WorkflowDesigner（流程设计器核心组件）
    ├── NodePalette（节点面板）
    ├── NodeConfigPanel（节点配置面板）
    ├── VariableSelector（变量选择器）
    ├── ExpressionEditor（表达式编辑器）
    └── 节点配置组件（HumanTaskNodeConfig、HttpRequestNodeConfig 等）
```

### 1.2 技术栈

- **前端框架**：Vue 3 + TypeScript 5.x
- **构建工具**：Vite 5.x
- **状态管理**：Pinia
- **UI 组件库**：Ant Design Vue 4.x
- **流程设计器**：LogicFlow 1.x（最终选型,AntV X6 为早期调研备选）
- **HTTP 客户端**：Axios
- **路由**：Vue Router 4.x

### 1.3 设计原则

1. ✅ **单一职责**：每个应用专注一个场景
2. ✅ **代码复用**：共享组件库提供通用组件
3. ✅ **一致性**：统一的 API、数据模型、设计规范
4. ✅ **可扩展**：易于添加新的应用和节点类型
5. ✅ **用户友好**：不同场景看到不同界面，降低学习成本

---

## 2. 共享组件库设计

### 2.1 组件库结构

```
@alkaid/lowcode-workflow-components/
├── src/
│   ├── components/
│   │   ├── WorkflowDesigner/          # 流程设计器核心组件
│   │   ├── NodePalette/               # 节点面板
│   │   ├── NodeConfigPanel/           # 节点配置面板
│   │   ├── VariableSelector/          # 变量选择器
│   │   ├── ExpressionEditor/          # 表达式编辑器
│   │   └── nodes/                     # 节点配置组件
│   │       ├── HumanTaskNodeConfig.vue
│   │       ├── HttpRequestNodeConfig.vue
│   │       ├── ConditionNodeConfig.vue
│   │       └── ...
│   ├── composables/                   # 组合式函数
│   │   ├── useWorkflowAPI.ts
│   │   ├── useNodeRegistry.ts
│   │   └── useExpressionEngine.ts
│   ├── types/                         # TypeScript 类型定义
│   │   ├── workflow.ts
│   │   ├── node.ts
│   │   └── trigger.ts
│   └── index.ts                       # 导出入口
├── package.json
└── README.md
```

### 2.2 核心组件

**1. WorkflowDesigner（流程设计器核心组件）**

```vue
<template>
  <div class="workflow-designer">
    <div class="designer-canvas" ref="canvasRef"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import LogicFlow from '@logicflow/core'
import '@logicflow/core/dist/style/index.css'
import type { WorkflowDefinition, NodeConfig } from '../types'

interface Props {
  workflow: WorkflowDefinition
  nodeTypes: NodeConfig[]
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  readonly: false
})

const emit = defineEmits<{
  'update:workflow': [workflow: WorkflowDefinition]
  'node-click': [nodeId: string]
  'node-add': [node: NodeConfig]
  'node-delete': [nodeId: string]
}>()

const canvasRef = ref<HTMLElement>()
let lf: LogicFlow

onMounted(() => {
  // 初始化 LogicFlow
  lf = new LogicFlow({
    container: canvasRef.value!,
    grid: true,
    keyboard: {
      enabled: true
    }
  })

  // 注册节点类型
  registerNodeTypes()

  // 渲染工作流
  renderWorkflow()

  // 监听事件
  lf.on('node:click', ({ data }) => {
    emit('node-click', data.id)
  })

  lf.on('node:add', ({ data }) => {
    emit('node-add', data)
  })

  lf.on('node:delete', ({ data }) => {
    emit('node-delete', data.id)
  })
})

function registerNodeTypes() {
  props.nodeTypes.forEach(nodeType => {
    // 注册自定义节点
    lf.register(nodeType)
  })
}

function renderWorkflow() {
  if (props.workflow) {
    lf.render({
      nodes: props.workflow.nodes,
      edges: props.workflow.edges
    })
  }
}

watch(() => props.workflow, () => {
  renderWorkflow()
}, { deep: true })
</script>

<style scoped>
.workflow-designer {
  width: 100%;
  height: 100%;
}

.designer-canvas {
  width: 100%;
  height: 100%;
}
</style>
```

**2. NodePalette（节点面板）**

```vue
<template>
  <div class="node-palette">
    <a-collapse v-model:activeKey="activeKey" :bordered="false">
      <a-collapse-panel
        v-for="category in categories"
        :key="category.key"
        :header="category.name"
      >
        <div class="node-list">
          <div
            v-for="node in category.nodes"
            :key="node.type"
            class="node-item"
            draggable="true"
            @dragstart="handleDragStart(node)"
          >
            <a-space>
              <component :is="node.icon" />
              <span>{{ node.name }}</span>
            </a-space>
          </div>
        </div>
      </a-collapse-panel>
    </a-collapse>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { NodeConfig } from '../types'

interface Props {
  nodes: NodeConfig[]
}

const props = defineProps<Props>()

const activeKey = ref(['basic', 'automation', 'approval'])

const categories = computed(() => {
  const categoryMap = new Map()

  props.nodes.forEach(node => {
    const category = node.category || 'other'
    if (!categoryMap.has(category)) {
      categoryMap.set(category, {
        key: category,
        name: getCategoryName(category),
        nodes: []
      })
    }
    categoryMap.get(category).nodes.push(node)
  })

  return Array.from(categoryMap.values())
})

function getCategoryName(category: string): string {
  const names: Record<string, string> = {
    basic: '基础节点',
    automation: '自动化节点',
    approval: '审批节点',
    other: '其他'
  }
  return names[category] || category
}

function handleDragStart(node: NodeConfig) {
  // 设置拖拽数据
  event.dataTransfer?.setData('application/json', JSON.stringify(node))
}
</script>

<style scoped>
.node-palette {
  width: 240px;
  height: 100%;
  border-right: 1px solid #e8e8e8;
  overflow-y: auto;
}

.node-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.node-item {
  padding: 8px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  cursor: move;
  transition: all 0.3s;
}

.node-item:hover {
  border-color: #1890ff;
  background-color: #e6f7ff;
}
</style>
```

**3. NodeConfigPanel（节点配置面板）**

```vue
<template>
  <div class="node-config-panel">
    <a-drawer
      v-model:open="visible"
      title="节点配置"
      placement="right"
      :width="480"
    >
      <component
        v-if="node"
        :is="getNodeConfigComponent(node.type)"
        v-model:config="node.config"
        :workflow="workflow"
      />
    </a-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import type { NodeConfig, WorkflowDefinition } from '../types'
import HumanTaskNodeConfig from './nodes/HumanTaskNodeConfig.vue'
import HttpRequestNodeConfig from './nodes/HttpRequestNodeConfig.vue'
import ConditionNodeConfig from './nodes/ConditionNodeConfig.vue'

interface Props {
  node: NodeConfig | null
  workflow: WorkflowDefinition
}

const props = defineProps<Props>()

const visible = ref(false)

watch(() => props.node, (newNode) => {
  visible.value = !!newNode
})

function getNodeConfigComponent(type: string) {
  const components: Record<string, any> = {
    human_task: HumanTaskNodeConfig,
    http_request: HttpRequestNodeConfig,
    condition: ConditionNodeConfig
    // ... 其他节点配置组件
  }
  return components[type] || null
}
</script>
```

**4. VariableSelector（变量选择器）**

```vue
<template>
  <a-select
    v-model:value="selectedVariable"
    placeholder="选择变量"
    show-search
    :options="variableOptions"
    @change="handleChange"
  >
    <template #option="{ label, value, group }">
      <div class="variable-option">
        <span class="variable-group">{{ group }}</span>
        <span class="variable-label">{{ label }}</span>
      </div>
    </template>
  </a-select>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { WorkflowDefinition } from '../types'

interface Props {
  workflow: WorkflowDefinition
  modelValue?: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const selectedVariable = ref(props.modelValue)

const variableOptions = computed(() => {
  const options = []

  // 触发器变量
  options.push({
    label: 'trigger.data',
    value: '{{trigger.data}}',
    group: '触发器'
  })

  // 工作流变量
  options.push({
    label: 'workflow.id',
    value: '{{workflow.id}}',
    group: '工作流'
  })

  // 节点输出变量
  props.workflow.nodes.forEach(node => {
    options.push({
      label: `nodes.${node.id}`,
      value: `{{nodes.${node.id}}}`,
      group: '节点输出'
    })
  })

  return options
})

function handleChange(value: string) {
  emit('update:modelValue', value)
}
</script>
```

**5. ExpressionEditor（表达式编辑器）**

```vue
<template>
  <div class="expression-editor">
    <a-input
      v-model:value="expression"
      placeholder="输入表达式，例如：{{trigger.data.total > 1000}}"
      @change="handleChange"
    >
      <template #prefix>
        <FunctionOutlined />
      </template>
      <template #suffix>
        <a-tooltip title="表达式帮助">
          <QuestionCircleOutlined @click="showHelp" />
        </a-tooltip>
      </template>
    </a-input>

    <a-modal
      v-model:open="helpVisible"
      title="表达式语法帮助"
      :footer="null"
      width="600px"
    >
      <a-tabs>
        <a-tab-pane key="variables" tab="变量引用">
          <pre>{{trigger.data.id}}
{{nodes.node_001.response.status}}
{{workflow.name}}</pre>
        </a-tab-pane>

        <a-tab-pane key="conditions" tab="条件表达式">
          <pre>{{trigger.data.total > 1000}}
{{trigger.data.status == 'pending'}}
{{trigger.data.total > 1000 and trigger.data.status == 'pending'}}</pre>
        </a-tab-pane>

        <a-tab-pane key="functions" tab="函数调用">
          <pre>{{upper(trigger.data.name)}}
{{length(trigger.data.items)}}
{{now()}}</pre>
        </a-tab-pane>
      </a-tabs>
    </a-modal>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { FunctionOutlined, QuestionCircleOutlined } from '@ant-design/icons-vue'

interface Props {
  modelValue?: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const expression = ref(props.modelValue || '')
const helpVisible = ref(false)

function handleChange() {
  emit('update:modelValue', expression.value)
}

function showHelp() {
  helpVisible.value = true
}
</script>
```

---

## 3. 审批流应用设计

### 3.1 应用信息

- **应用名称**：lowcode-workflow-approval
- **应用标识**：`alkaid/lowcode-workflow-approval`
- **目标用户**：需要创建审批流程的业务人员
- **核心功能**：审批流程设计器、待办任务管理、审批历史查询

### 3.2 节点类型

**只显示审批流相关节点**：

```typescript
const approvalNodes: NodeConfig[] = [
  { type: 'start', name: '开始', icon: 'PlayCircleOutlined', category: 'basic' },
  { type: 'end', name: '结束', icon: 'CheckCircleOutlined', category: 'basic' },
  { type: 'human_task', name: '人工任务', icon: 'UserOutlined', category: 'approval' },
  { type: 'approval', name: '审批', icon: 'AuditOutlined', category: 'approval' },
  { type: 'countersign', name: '会签', icon: 'TeamOutlined', category: 'approval' },
  { type: 'condition', name: '条件判断', icon: 'BranchesOutlined', category: 'basic' },
  { type: 'parallel', name: '并行网关', icon: 'ForkOutlined', category: 'basic' },
  { type: 'exclusive', name: '排他网关', icon: 'SwapOutlined', category: 'basic' }
]
```

### 3.3 界面设计

```vue
<template>
  <a-layout class="approval-workflow-app">
    <!-- 顶部导航 -->
    <a-layout-header>
      <a-menu v-model:selectedKeys="selectedKeys" mode="horizontal">
        <a-menu-item key="workflows">
          <router-link to="/workflows">流程管理</router-link>
        </a-menu-item>
        <a-menu-item key="tasks">
          <router-link to="/tasks">待办任务</router-link>
        </a-menu-item>
        <a-menu-item key="history">
          <router-link to="/history">审批历史</router-link>
        </a-menu-item>
      </a-menu>
    </a-layout-header>

    <!-- 主内容区 -->
    <a-layout-content>
      <router-view />
    </a-layout-content>
  </a-layout>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const selectedKeys = ref(['workflows'])
</script>
```

---

## 4. 自动化工作流应用设计

### 4.1 应用信息

- **应用名称**：lowcode-workflow-automation
- **应用标识**：`alkaid/lowcode-workflow-automation`
- **目标用户**：需要创建自动化流程的技术人员
- **核心功能**：自动化流程设计器、执行日志和监控、错误处理和重试

### 4.2 节点类型

**显示所有自动化节点**：

```typescript
const automationNodes: NodeConfig[] = [
  { type: 'start', name: '开始', icon: 'PlayCircleOutlined', category: 'basic' },
  { type: 'end', name: '结束', icon: 'CheckCircleOutlined', category: 'basic' },
  { type: 'condition', name: '条件判断', icon: 'BranchesOutlined', category: 'basic' },
  { type: 'http_request', name: 'HTTP 请求', icon: 'ApiOutlined', category: 'automation' },
  { type: 'delay', name: '延迟', icon: 'ClockCircleOutlined', category: 'automation' },
  { type: 'loop', name: '循环', icon: 'ReloadOutlined', category: 'automation' },
  { type: 'script', name: '脚本执行', icon: 'CodeOutlined', category: 'automation' },
  { type: 'data_query', name: '数据查询', icon: 'SearchOutlined', category: 'automation' },
  { type: 'data_create', name: '数据创建', icon: 'PlusOutlined', category: 'automation' },
  { type: 'data_update', name: '数据更新', icon: 'EditOutlined', category: 'automation' },
  { type: 'data_delete', name: '数据删除', icon: 'DeleteOutlined', category: 'automation' },
  { type: 'notification', name: '发送通知', icon: 'BellOutlined', category: 'automation' },
  { type: 'parallel', name: '并行执行', icon: 'ForkOutlined', category: 'basic' },
  { type: 'subprocess', name: '子流程', icon: 'ApartmentOutlined', category: 'basic' }
]
```

---

## 5. 综合工作流应用设计

### 5.1 应用信息

- **应用名称**：lowcode-workflow-hybrid
- **应用标识**：`alkaid/lowcode-workflow-hybrid`
- **目标用户**：需要创建复杂流程的高级用户
- **核心功能**：完整的流程设计器、统一的流程管理、高级功能

### 5.2 节点类型

**显示所有节点类型**（审批流节点 + 自动化节点）

---

## 6. 代码复用策略（包结构与目录规划）

### 6.1 Monorepo 包结构总览

```text
packages/
  lowcode-workflow-components/        # 共享组件库(@alkaid/lowcode-workflow-components)
  workflow-designer/                  # 通用流程设计器壳应用(组合共享组件)
  workflow-approval-app/              # 审批流场景应用
  workflow-automation-app/            # 自动化场景应用
  workflow-hybrid-app/                # 综合场景应用
```

> 说明：所有 workflow-* 应用都依赖 `@alkaid/lowcode-workflow-components`，自身只负责：路由/布局、节点子集选择、业务页面(待办、历史等)。

### 6.2 packages/workflow-designer 目录结构

```text
packages/workflow-designer/
  package.json
  vite.config.ts
  tsconfig.json
  src/
    main.ts                 # 应用入口
    App.vue                 # 根组件，挂载全局布局与路由
    router/
      index.ts              # /designer、/preview 等路由
    pages/
      WorkflowDesignerPage.vue   # 主设计器页面(三栏布局)
      WorkflowPreviewPage.vue    # 流程预览/只读查看
    configs/
      node-types.ts         # 设计器可用节点类型配置(组合审批+自动化节点)
    api/
      workflow.ts           # 封装对后端 /lowcode_workflows 等接口的调用
```

#### 6.2.1 package.json 草案

> 版本号示例，最终以《03-PROJECT-DEPENDENCIES.md》中前端依赖清单为准。

```json
{
  "name": "@alkaid/workflow-designer",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "test": "vitest",
    "lint": "eslint . --ext .ts,.tsx,.vue"
  },
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.2.0",
    "pinia": "^2.1.0",
    "ant-design-vue": "^4.1.0",
    "@ant-design/icons-vue": "^7.0.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.0",
    "@logicflow/core": "^1.2.0",
    "@logicflow/extension": "^1.2.0",
    "@alkaid/lowcode-workflow-components": "^0.1.0"
  },
  "devDependencies": {
    "typescript": "^5.3.0",
    "vite": "^5.0.0",
    "@vitejs/plugin-vue": "^5.0.0",
    "@vitejs/plugin-vue-jsx": "^3.1.0",
    "vue-tsc": "^1.8.0",
    "vitest": "^1.2.0",
    "@vue/test-utils": "^2.4.0",
    "jsdom": "^24.0.0",
    "eslint": "^8.56.0",
    "eslint-plugin-vue": "^9.20.0",
    "@typescript-eslint/parser": "^6.19.0",
    "@typescript-eslint/eslint-plugin": "^6.19.0",
    "prettier": "^3.2.0",
    "eslint-config-prettier": "^9.1.0",
    "eslint-plugin-prettier": "^5.1.0"
  }
}
```

#### 6.2.2 vite.config.ts 草案

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src')
    }
  },
  server: {
    port: 4173
  },
  build: {
    outDir: 'dist'
  }
})
```

### 6.3 packages/workflow-approval-app 目录结构

```text
packages/workflow-approval-app/
  package.json
  vite.config.ts
  tsconfig.json
  src/
    main.ts                      # 应用入口
    App.vue                      # 顶层布局(菜单: 流程管理/待办/历史)
    router/
      index.ts                   # /workflows、/tasks、/history 等路由
    pages/
      ApprovalWorkflowList.vue   # 审批流程列表与管理
      ApprovalWorkflowDesignerPage.vue  # 嵌入 WorkflowDesigner, 仅审批节点
      TaskListPage.vue           # 待办任务列表
      TaskDetailPage.vue         # 单条任务处理页
      HistoryListPage.vue        # 审批历史列表
    configs/
      node-types.ts              # 审批场景下的节点子集(复用设计文档中的 approvalNodes)
    api/
      workflow.ts                # 审批流程相关接口封装
      tasks.ts                   # 任务/审批操作接口封装
```

#### 6.3.1 package.json 草案

> 版本号示例，最终以《03-PROJECT-DEPENDENCIES.md》中前端依赖清单为准。

```json
{
  "name": "@alkaid/workflow-approval-app",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "test": "vitest",
    "lint": "eslint . --ext .ts,.tsx,.vue"
  },
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.2.0",
    "pinia": "^2.1.0",
    "ant-design-vue": "^4.1.0",
    "@ant-design/icons-vue": "^7.0.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.0",
    "nprogress": "^0.2.0",
    "mitt": "^3.0.0",
    "@alkaid/lowcode-workflow-components": "^0.1.0"
  },
  "devDependencies": {
    "typescript": "^5.3.0",
    "vite": "^5.0.0",
    "@vitejs/plugin-vue": "^5.0.0",
    "@vitejs/plugin-vue-jsx": "^3.1.0",
    "vue-tsc": "^1.8.0",
    "vitest": "^1.2.0",
    "@vue/test-utils": "^2.4.0",
    "jsdom": "^24.0.0",
    "eslint": "^8.56.0",
    "eslint-plugin-vue": "^9.20.0",
    "@typescript-eslint/parser": "^6.19.0",
    "@typescript-eslint/eslint-plugin": "^6.19.0",
    "prettier": "^3.2.0",
    "eslint-config-prettier": "^9.1.0",
    "eslint-plugin-prettier": "^5.1.0"
  }
}
```

#### 6.3.2 vite.config.ts 草案

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src')
    }
  },
  server: {
    port: 4174
  },
  build: {
    outDir: 'dist'
  }
})
```

### 6.4 packages/workflow-automation-app 目录结构

```text
packages/workflow-automation-app/
  package.json
  vite.config.ts
  tsconfig.json
  src/
    main.ts                      # 应用入口
    App.vue                      # 顶层布局(菜单: 流程管理/执行日志/监控)
    router/
      index.ts                   # /workflows、/executions、/logs 等路由
    pages/
      AutomationWorkflowList.vue       # 自动化流程列表与管理
      AutomationWorkflowDesignerPage.vue  # 嵌入 WorkflowDesigner, 自动化节点子集
      ExecutionListPage.vue            # 执行记录列表
      ExecutionDetailPage.vue          # 单次执行详情与错误信息
    configs/
      node-types.ts                    # 自动化场景下的节点子集(复用设计文档中的 automationNodes)
    api/
      workflow.ts                      # 自动化流程相关接口封装
      executions.ts                    # 执行记录/重试等接口封装
```

#### 6.4.1 package.json 草案

> 版本号示例，最终以《03-PROJECT-DEPENDENCIES.md》中前端依赖清单为准。

```json
{
  "name": "@alkaid/workflow-automation-app",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "test": "vitest",
    "lint": "eslint . --ext .ts,.tsx,.vue"
  },
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.2.0",
    "pinia": "^2.1.0",
    "ant-design-vue": "^4.1.0",
    "@ant-design/icons-vue": "^7.0.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.0",
    "nprogress": "^0.2.0",
    "mitt": "^3.0.0",
    "@alkaid/lowcode-workflow-components": "^0.1.0"
  },
  "devDependencies": {
    "typescript": "^5.3.0",
    "vite": "^5.0.0",
    "@vitejs/plugin-vue": "^5.0.0",
    "@vitejs/plugin-vue-jsx": "^3.1.0",
    "vue-tsc": "^1.8.0",
    "vitest": "^1.2.0",
    "@vue/test-utils": "^2.4.0",
    "jsdom": "^24.0.0",
    "eslint": "^8.56.0",
    "eslint-plugin-vue": "^9.20.0",
    "@typescript-eslint/parser": "^6.19.0",
    "@typescript-eslint/eslint-plugin": "^6.19.0",
    "prettier": "^3.2.0",
    "eslint-config-prettier": "^9.1.0",
    "eslint-plugin-prettier": "^5.1.0"
  }
}
```

#### 6.4.2 vite.config.ts 草案

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src')
    }
  },
  server: {
    port: 4175
  },
  build: {
    outDir: 'dist'
  }
})
```

### 6.5 packages/workflow-hybrid-app 目录结构

```text
packages/workflow-hybrid-app/
  package.json
  vite.config.ts
  tsconfig.json
  src/
    main.ts                      # 应用入口
    App.vue                      # 顶层布局(菜单: 综合流程/任务/执行记录)
    router/
      index.ts                   # /workflows、/tasks、/executions 等路由
    pages/
      HybridWorkflowList.vue           # 综合流程列表与管理
      HybridWorkflowDesignerPage.vue   # 嵌入 WorkflowDesigner, 全量节点集合
      HybridTaskListPage.vue           # 综合任务列表(审批+自动化)
      HybridExecutionListPage.vue      # 综合执行记录列表
    configs/
      node-types.ts                    # 综合场景下的节点集合(审批+自动化节点)
    api/
      workflow.ts                      # 综合流程相关接口封装
      tasks.ts                         # 任务接口封装
      executions.ts                    # 执行记录接口封装
```

#### 6.5.1 package.json 草案

> 版本号示例，最终以《03-PROJECT-DEPENDENCIES.md》中前端依赖清单为准。

```json
{
  "name": "@alkaid/workflow-hybrid-app",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "test": "vitest",
    "lint": "eslint . --ext .ts,.tsx,.vue"
  },
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.2.0",
    "pinia": "^2.1.0",
    "ant-design-vue": "^4.1.0",
    "@ant-design/icons-vue": "^7.0.0",
    "axios": "^1.6.0",
    "dayjs": "^1.11.0",
    "nprogress": "^0.2.0",
    "mitt": "^3.0.0",
    "@alkaid/lowcode-workflow-components": "^0.1.0"
  },
  "devDependencies": {
    "typescript": "^5.3.0",
    "vite": "^5.0.0",
    "@vitejs/plugin-vue": "^5.0.0",
    "@vitejs/plugin-vue-jsx": "^3.1.0",
    "vue-tsc": "^1.8.0",
    "vitest": "^1.2.0",
    "@vue/test-utils": "^2.4.0",
    "jsdom": "^24.0.0",
    "eslint": "^8.56.0",
    "eslint-plugin-vue": "^9.20.0",
    "@typescript-eslint/parser": "^6.19.0",
    "@typescript-eslint/eslint-plugin": "^6.19.0",
    "prettier": "^3.2.0",
    "eslint-config-prettier": "^9.1.0",
    "eslint-plugin-prettier": "^5.1.0"
  }
}
```

#### 6.5.2 vite.config.ts 草案

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src')
    }
  },
  server: {
    port: 4176
  },
  build: {
    outDir: 'dist'
  }
})
```

---

## 7. 入口文件与集成示例

### 7.1 packages/workflow-designer/src/main.ts 示例

```ts
// packages/workflow-designer/src/main.ts
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import Antd from 'ant-design-vue'
import 'ant-design-vue/dist/reset.css'

const app = createApp(App)
app.use(router)
app.use(Antd)
app.mount('#app')
```

### 7.2 审批流应用中集成 WorkflowDesigner 示例

```vue
<!-- packages/workflow-approval-app/src/pages/ApprovalWorkflowDesignerPage.vue -->
<template>
  <div class="approval-workflow-designer">
    <WorkflowDesigner
      :workflow="workflow"
      :node-types="approvalNodes"
      @update:workflow="handleWorkflowChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { WorkflowDesigner } from '@alkaid/lowcode-workflow-components'
import { approvalNodes } from '../configs/node-types'
import type { WorkflowDefinition } from '@alkaid/lowcode-workflow-components'

const workflow = ref<WorkflowDefinition>({
  id: '',
  name: '',
  nodes: [],
  edges: [],
})

function handleWorkflowChange(def: WorkflowDefinition) {
  workflow.value = def
  // TODO: 调用后端 API 保存至 lowcode_workflows / lowcode_workflow_executions
}
</script>
```

> 上述示例体现了代码复用策略：`workflow-approval-app` 不直接操作 LogicFlow，而是通过共享组件库中的 `WorkflowDesigner` 组件与类型(`WorkflowDefinition`、`NodeConfig`)进行交互，确保与后端工作流定义模型和低代码平台其余部分保持一致。

---

**最后更新**：2025-01-20
**文档版本**：v2.0
**维护者**：AlkaidSYS 架构团队
