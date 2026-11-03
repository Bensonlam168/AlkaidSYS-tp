# @core/workflow-ui

AlkaidSYS 工作流设计器 UI 组件包，基于 Vue 3 + TypeScript + LogicFlow 构建。

## 特性

- 🎨 基于 Vue 3 Composition API
- 🔷 完整的 TypeScript 类型支持
- 🎯 支持 BPMN 2.0 标准
- 📦 组件化设计，易于扩展
- 🎨 支持主题定制
- 📱 响应式设计
- ✅ 完整的验证系统
- 📚 丰富的工具函数

## 安装

```bash
npm install @core/workflow-ui
# 或
yarn add @core/workflow-ui
# 或
pnpm add @core/workflow-ui
```

## 使用

### 基础用法

```vue
<template>
  <div style="height: 600px;">
    <WorkflowDesigner
      v-model:definition="workflowDefinition"
      :config="designerConfig"
      @change="handleChange"
      @save="handleSave"
      @error="handleError"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { WorkflowDesigner } from '@core/workflow-ui'
import type { WorkflowDefinition } from '@core/workflow-ui'

const workflowDefinition = ref<WorkflowDefinition>({
  id: 'workflow-1',
  name: '我的工作流',
  displayName: '我的工作流',
  version: 1,
  status: 'draft',
  nodes: [],
  connections: [],
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
  createdBy: 1
})

const designerConfig = {
  grid: {
    enabled: true,
    cell: 20
  },
  background: {
    color: '#ffffff'
  },
  readonly: false
}

const handleChange = (data: any) => {
  console.log('工作流变更:', data)
}

const handleSave = (data: any) => {
  console.log('保存工作流:', data)
}

const handleError = (error: Error) => {
  console.error('工作流错误:', error)
}
</script>
```

### 高级用法

```vue
<template>
  <WorkflowDesigner
    ref="designerRef"
    :definition="workflowDefinition"
    :config="designerConfig"
    :show-toolbar="true"
    :show-property-panel="true"
    :show-node-selector="true"
    :show-status-bar="true"
    :enable-mini-map="true"
    :enable-control-panel="true"
    @change="handleChange"
    @save="handleSave"
    @node-click="handleNodeClick"
    @edge-click="handleEdgeClick"
  />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { WorkflowDesigner, type DesignerInstance } from '@core/workflow-ui'

const designerRef = ref<DesignerInstance>()

onMounted(() => {
  // 通过 ref 访问实例方法
  if (designerRef.value) {
    // 导出 PNG
    const pngData = designerRef.value.exportPNG()
    console.log('PNG 数据:', pngData)

    // 适应视图
    designerRef.value.fitView()

    // 添加节点
    designerRef.value.addNode({
      id: 'node-1',
      type: 'task',
      x: 100,
      y: 100,
      text: '新任务'
    })
  }
})
</script>
```

## 组件

### WorkflowDesigner

主要的设计器组件。

#### Props

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| definition | WorkflowDefinition | - | 工作流定义数据 |
| config | DesignerConfig | {} | 设计器配置 |
| readonly | boolean | false | 是否只读模式 |
| width | number/string | - | 容器宽度 |
| height | number/string | 600 | 容器高度 |
| showToolbar | boolean | true | 是否显示工具栏 |
| showPropertyPanel | boolean | true | 是否显示属性面板 |
| showNodeSelector | boolean | true | 是否显示节点选择器 |
| showStatusBar | boolean | true | 是否显示状态栏 |
| enableMiniMap | boolean | true | 是否启用小地图 |
| enableControlPanel | boolean | true | 是否启用控制面板 |

#### Events

| 事件名 | 参数 | 说明 |
|--------|------|------|
| change | data: any | 工作流变更时触发 |
| save | data: any | 保存时触发 |
| validate | data: any => boolean/string | 验证时触发 |
| error | error: Error | 错误时触发 |
| node-click | node: any | 节点点击时触发 |
| node-contextmenu | node: any, event: MouseEvent | 节点右键菜单时触发 |
| node-doubleclick | node: any | 节点双击时触发 |
| edge-click | edge: any | 边点击时触发 |
| canvas-click | event: MouseEvent | 画布点击时触发 |

#### Methods

| 方法名 | 参数 | 返回值 | 说明 |
|--------|------|--------|------|
| getGraphData | - | any | 获取图形数据 |
| setGraphData | data: any | void | 设置图形数据 |
| exportPNG | background?: string | string | 导出 PNG |
| undo | - | void | 撤销 |
| redo | - | void | 重做 |
| fitView | - | void | 适应视图 |
| resetView | - | void | 重置视图 |
| addNode | config: any | void | 添加节点 |
| removeNode | id: string | void | 删除节点 |
| updateNode | id: string, config: any | void | 更新节点 |
| addEdge | config: any | void | 添加边 |
| removeEdge | id: string | void | 删除边 |
| selectNode | id: string | void | 选中节点 |
| clearSelect | - | void | 清除选择 |

## 类型

### WorkflowDefinition

工作流定义类型：

```typescript
interface WorkflowDefinition {
  id: string
  name: string
  displayName: string
  description?: string
  type: 'approval' | 'automation' | 'hybrid'
  category?: string
  version: number
  status: WorkflowStatus
  nodes: BaseNodeConfig[]
  connections: ConnectionConfig[]
  variables?: Record<string, any>
  createdAt: string
  updatedAt: string
  createdBy: number
}
```

### BaseNodeConfig

基础节点配置：

```typescript
interface BaseNodeConfig {
  id: string
  name: string
  type: NodeType
  x: number
  y: number
  width?: number
  height?: number
  properties?: Record<string, any>
}

enum NodeType {
  START = 'start',
  END = 'end',
  TASK = 'task',
  DECISION = 'decision',
  FORK = 'fork',
  JOIN = 'join',
  CUSTOM = 'custom',
  SUB_PROCESS = 'sub_process'
}
```

### ConnectionConfig

连接配置：

```typescript
interface ConnectionConfig {
  id: string
  source: string
  target: string
  sourceHandle?: string
  targetHandle?: string
  properties?: {
    label?: string
    condition?: string
    default?: boolean
  }
}
```

## 工具函数

### 验证工具

```typescript
import { validateWorkflow, type ValidationResult } from '@core/workflow-ui'

const result = validateWorkflow(workflowDefinition)

if (!result.isValid) {
  console.error('验证失败:', result.errors)
}
```

### 工作流转换

```typescript
import { workflowToLogicFlow, logicFlowToWorkflow } from '@core/workflow-ui'

// 工作流转LogicFlow格式
const lfData = workflowToLogicFlow(workflowDefinition)

// LogicFlow格式转工作流
const workflow = logicFlowToWorkflow(lfData)
```

### 事件发射器

```typescript
import { useWorkflowEvents } from '@core/workflow-ui'

const events = useWorkflowEvents()

events.on('workflow:change', (data) => {
  console.log('工作流变更:', data)
})

events.triggerCanvasClick(data)
```

## 样式

### CSS 变量

组件使用 CSS 变量进行主题定制：

```css
:root {
  --primary-color: #1890ff;
  --success-color: #52c41a;
  --warning-color: #faad14;
  --error-color: #ff4d4f;
}
```

### 引入样式

```css
@import '@core/workflow-ui/styles/workflow-designer.css';
```

## 开发

### 构建

```bash
# 安装依赖
npm install

# 开发模式
npm run dev

# 构建
npm run build

# 类型检查
npm run type-check

# 代码检查
npm run lint

# 测试
npm run test
```

### 目录结构

```
src/
├── components/          # 组件
│   ├── designer/       # 设计器组件
│   └── common/         # 通用组件
├── types/              # 类型定义
├── utils/              # 工具函数
├── core/               # 核心逻辑
└── styles/             # 样式文件
```

## 许可证

MIT

## 贡献指南

1. Fork 项目
2. 创建特性分支
3. 提交更改
4. 推送到分支
5. 创建 Pull Request

## 更新日志

### v0.1.0

- 初始版本
- 支持基础工作流设计
- 支持节点和边操作
- 支持导出/导入
- 完整的 TypeScript 类型支持