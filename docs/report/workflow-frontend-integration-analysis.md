# AlkaidSYS 工作流前端集成分析报告

> **文档版本**: v1.0
> **生成日期**: 2025-12-15
> **分析工具**: Sequential Thinking + Serena + Context7
> **分析范围**: 前端工作流设计器与管理界面集成方案

---

## 📋 执行摘要

本报告针对 AlkaidSYS 项目工作流引擎（T-034）的**前端集成方案**进行全面分析，涵盖技术选型、架构设计、实施计划和风险评估。

### 核心结论

| 评估维度 | 结论 | 置信度 |
|---------|------|--------|
| **流程图库选择** | LogicFlow（滴滴开源） | 95% |
| **架构兼容性** | 与 Vue Vben Admin 5.x 高度兼容 | 90% |
| **实施周期** | 4 周（与后端 5 周计划配合） | 85% |
| **前端准备度** | 85% 就绪 | 90% |

---

## 1. 项目前端现状分析

### 1.1 技术栈概览

```
┌─────────────────────────────────────────────────────────────┐
│                   Vue Vben Admin 5.x                        │
├─────────────────────────────────────────────────────────────┤
│  Framework    │ Vue 3.4+ (Composition API)                  │
│  Language     │ TypeScript 5.x                              │
│  Build Tool   │ Vite 5.x                                    │
│  UI Library   │ Ant Design Vue 4.x                          │
│  State        │ Pinia 2.x                                   │
│  Router       │ Vue Router 4.x                              │
│  Package Mgr  │ pnpm (Monorepo)                             │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 Monorepo 结构分析

```
frontend/
├── apps/                         # 应用目录
│   ├── web-antd/                 # Ant Design Vue 版本 ✓ 主应用
│   ├── web-ele/                  # Element Plus 版本
│   ├── web-naive/                # Naive UI 版本
│   ├── web-tdesign/              # TDesign 版本
│   └── backend-mock/             # Mock 服务
├── packages/                     # 共享包
│   ├── @core/                    # 核心包
│   │   ├── base/                 # 基础工具
│   │   ├── composables/          # 组合式函数
│   │   ├── preferences/          # 偏好设置
│   │   └── ui-kit/               # UI 组件套件
│   │       ├── form-ui/          # 表单组件 ✓ 可复用
│   │       ├── layout-ui/        # 布局组件
│   │       ├── menu-ui/          # 菜单组件
│   │       ├── popup-ui/         # 弹窗组件
│   │       ├── tabs-ui/          # 标签页组件
│   │       └── shadcn-ui/        # shadcn 组件
│   ├── constants/                # 常量定义
│   ├── effects/                  # 副作用
│   ├── icons/                    # 图标
│   ├── locales/                  # 国际化
│   ├── stores/                   # 状态管理
│   ├── styles/                   # 样式
│   ├── types/                    # 类型定义
│   └── utils/                    # 工具函数
└── internal/                     # 内部配置
```

### 1.3 现有组件复用分析

| 组件/模块 | 复用价值 | 说明 |
|----------|---------|------|
| `@core/ui-kit/form-ui` | ⭐⭐⭐⭐⭐ | 节点属性配置面板可复用 |
| `@core/composables` | ⭐⭐⭐⭐ | 通用组合式函数可复用 |
| `@vben/request` | ⭐⭐⭐⭐⭐ | API 请求封装直接复用 |
| `@vben/stores` | ⭐⭐⭐⭐ | 状态管理模式可参考 |
| `@vben/hooks` | ⭐⭐⭐⭐ | 通用 hooks 可复用 |
| `@vben/common-ui` | ⭐⭐⭐ | 部分 UI 组件可复用 |

---

## 2. 流程图库技术选型

### 2.1 候选方案对比

| 特性 | AntV X6 | LogicFlow | Vue Flow |
|-----|---------|-----------|----------|
| **开源方 ** | 蚂蚁集团 | 滴滴出行 | 社区 |
| **Star 数** | 5.5k+ | 7k+ | 3.5k+ |
| **BPMN 支持** | 需扩展 | ✅ 原生支持 | 需扩展 |
| **Vue 3 支持** | ✅ @antv/x6-vue-shape | ✅ @logicflow/vue-node-registry | ✅ 原生 |
| **学习曲线** | 陡峭 | 中等 | 平缓 |
| **文档质量** | 优秀 | 优秀（中文） | 良好 |
| **插件生态** | 丰富 | 丰富 | 一般 |
| **适用场景** | 复杂图编辑 | 工作流设计 | 通用流程图 |
| **包大小** | ~300KB | ~200KB | ~150KB |

### 2.2 技术选型决策

**推荐方案：LogicFlow**

**选择理由**：

1. **BPMN 2.0 原生支持**
   - 内置 BpmnElement 和 BpmnXmlAdapter 插件
   - 与后端 Ingenious 的 BPMN 模型完美匹配
   - 支持 BPMN XML 导入导出

2. **Vue 3 集成成熟**
   ```typescript
   // LogicFlow Vue 3 集成示例
   import LogicFlow from '@logicflow/core'
   import { register, getTeleport } from '@logicflow/vue-node-registry'

   // 注册自定义 Vue 节点
   register({
     type: 'approval-node',
     component: ApprovalNode
   }, lf)
   ```

3. **滴滴开源背书**
   - 生产环境验证
   - 活跃的中文社区
   - 完善的中文文档

4. **与后端设计匹配**
   - 节点模型与 Ingenious 一致
   - 数据格式兼容
   - 减少前后端转换成本

### 2.3 依赖包清单

```json
{
  "dependencies": {
    "@logicflow/core": "^2.0.0",
    "@logicflow/extension": "^2.0.0",
    "@logicflow/vue-node-registry": "^2.0.0"
  }
}
```

---

## 3. 前端架构设计

### 3.1 新增包结构

```
frontend/packages/@core/
└── workflow-ui/                      # 新增：工作流 UI 包
    ├── src/
    │   ├── designer/                 # 流程设计器
    │   │   ├── WorkflowDesigner.vue  # 设计器主组件
    │   │   ├── Toolbar.vue           # 工具栏
    │   │   ├── PropertyPanel.vue     # 属性面板
    │   │   ├── NodePalette.vue       # 节点面板
    │   │   └── MiniMap.vue           # 缩略图
    │   ├── nodes/                    # 自定义节点
    │   │   ├── base/                 # 基础节点
    │   │   │   ├── StartNode.vue     # 开始节点
    │   │   │   ├── EndNode.vue       # 结束节点
    │   │   │   └── BaseNode.vue      # 节点基类
    │   │   ├── automation/           # 自动化节点
    │   │   │   ├── HttpRequestNode.vue
    │   │   │   ├── ConditionNode.vue
    │   │   │   ├── DelayNode.vue
    │   │   │   └── ScriptNode.vue
    │   │   └── approval/             # 审批节点
    │   │       ├── ApprovalNode.vue
    │   │       ├── CountersignNode.vue
    │   │       └── NotifyNode.vue
    │   ├── renderer/                 # 流程渲染器
    │   │   ├── WorkflowRenderer.vue  # 只读渲染
    │   │   └── ExecutionTracker.vue  # 执行轨迹
    │   ├── monitor/                  # 流程监控
    │   │   ├── InstanceList.vue
    │   │   ├── InstanceDetail.vue
    │   │   └── ExecutionLog.vue
    │   ├── task/                     # 任务审批
    │   │   ├── TaskList.vue
    │   │   ├── TaskDetail.vue
    │   │   └── ApprovalForm.vue
    │   ├── composables/              # 组合式函数
    │   │   ├── useWorkflowDesigner.ts
    │   │   ├── useNodeDrag.ts
    │   │   └── useWorkflowValidation.ts
    │   ├── types/                    # 类型定义
    │   │   ├── workflow.ts
    │   │   ├── node.ts
    │   │   ├── edge.ts
    │   │   └── trigger.ts
    │   ├── constants/                # 常量
    │   │   ├── node-types.ts
    │   │   └── trigger-types.ts
    │   └── index.ts                  # 导出入口
    ├── package.json
    ├── build.config.ts
    └── README.md
```

### 3.2 应用页面结构

```
frontend/apps/web-antd/src/
├── views/
│   └── workflow/                     # 工作流模块
│       ├── definition/               # 流程定义
│       │   ├── index.vue             # 定义列表
│       │   ├── create.vue            # 创建流程
│       │   └── [id].vue              # 编辑流程
│       ├── instance/                 # 流程实例
│       │   ├── index.vue             # 实例列表
│       │   └── [id].vue              # 实例详情
│       ├── task/                     # 任务管理
│       │   ├── todo.vue              # 待办任务
│       │   ├── done.vue              # 已办任务
│       │   └── [id].vue              # 任务详情
│       └── monitor/                  # 流程监控
│           └── index.vue             # 监控面板
├── api/
│   └── workflow/                     # API 接口
│       ├── definition.ts
│       ├── instance.ts
│       ├── task.ts
│       └── types.ts
├── store/
│   └── workflow.ts                   # 状态管理
└── router/
    └── modules/
        └── workflow.ts               # 路由配置
```

### 3.3 组件架构图

```mermaid
graph TB
    subgraph "页面层 (Views)"
        V1[流程定义页]
        V2[流程实例页]
        V3[任务审批页]
        V4[流程监控页]
    end

    subgraph "@core/workflow-ui"
        subgraph "设计器"
            D1[WorkflowDesigner]
            D2[Toolbar]
            D3[PropertyPanel]
            D4[NodePalette]
        end

        subgraph "节点组件"
            N1[StartNode]
            N2[EndNode]
            N3[ApprovalNode]
            N4[ConditionNode]
            N5[HttpRequestNode]
        end

        subgraph "渲染器"
            R1[WorkflowRenderer]
            R2[ExecutionTracker]
        end

        subgraph "任务组件"
            T1[TaskList]
            T2[TaskDetail]
            T3[ApprovalForm]
        end
    end

    subgraph "核心依赖"
        L1[LogicFlow Core]
        L2[LogicFlow Extension]
        L3[Vue Node Registry]
    end

    subgraph "状态管理"
        S1[workflowStore]
    end

    subgraph "API 层"
        A1[workflowApi]
    end

    V1 --> D1
    V2 --> R1
    V3 --> T1
    V4 --> R2

    D1 --> L1
    D1 --> N1
    D1 --> N2
    D1 --> N3
    D1 --> N4
    D1 --> N5

    D1 --> S1
    T1 --> S1
    R1 --> S1

    S1 --> A1
```

---

## 4. 核心组件设计

### 4.1 工作流设计器组件

```vue
<!-- WorkflowDesigner.vue -->
<template>
  <div class="workflow-designer">
    <!-- 工具栏 -->
    <Toolbar
      @save="handleSave"
      @publish="handlePublish"
      @undo="handleUndo"
      @redo="handleRedo"
      @zoom-in="handleZoomIn"
      @zoom-out="handleZoomOut"
    />

    <div class="designer-main">
      <!-- 节点面板 -->
      <NodePalette
        :nodes="availableNodes"
        @drag-start="handleDragStart"
      />

      <!-- 画布 -->
      <div ref="canvasRef" class="designer-canvas">
        <TeleportContainer :flow-id="flowId" />
      </div>

      <!-- 属性面板 -->
      <PropertyPanel
        v-if="selectedNode"
        :node="selectedNode"
        @update="handleNodeUpdate"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import LogicFlow from '@logicflow/core'
import { BpmnElement, BpmnXmlAdapter } from '@logicflow/extension'
import { register, getTeleport } from '@logicflow/vue-node-registry'
import { useWorkflowStore } from '@/store/workflow'
import { Toolbar, NodePalette, PropertyPanel } from './components'
import { registerCustomNodes } from './nodes'

const props = defineProps<{
  workflowId?: string
}>()

const emit = defineEmits<{
  save: [data: WorkflowData]
  publish: [id: string]
}>()

const canvasRef = ref<HTMLDivElement>()
const lfRef = ref<LogicFlow>()
const flowId = ref('')
const selectedNode = ref<NodeData | null>(null)

const workflowStore = useWorkflowStore()
const TeleportContainer = getTeleport()

// 初始化 LogicFlow
onMounted(async () => {
  if (!canvasRef.value) return

  const lf = new LogicFlow({
    container: canvasRef.value,
    grid: true,
    plugins: [BpmnElement, BpmnXmlAdapter],
    // 配置项
    nodeTextEdit: false,
    edgeTextEdit: true,
    isSilentMode: false,
  })

  // 注册自定义节点
  registerCustomNodes(lf)

  // 监听事件
  lf.on('node:click', ({ data }) => {
    selectedNode.value = data
  })

  lf.on('blank:click', () => {
    selectedNode.value = null
  })

  lf.on('graph:rendered', ({ graphModel }) => {
    flowId.value = graphModel.flowId!
  })

  // 加载已有流程
  if (props.workflowId) {
    const workflow = await workflowStore.loadWorkflow(props.workflowId)
    lf.render(workflow.graphData)
  } else {
    lf.render({})
  }

  lfRef.value = lf
})

// 保存流程
const handleSave = async () => {
  if (!lfRef.value) return

  const graphData = lfRef.value.getGraphData()
  const bpmnXml = lfRef.value.adapterOut(graphData)

  emit('save', {
    graphData,
    bpmnXml,
  })
}
</script>
```

### 4.2 自定义审批节点

```vue
<!-- ApprovalNode.vue -->
<template>
  <div class="approval-node" :class="{ selected: isSelected }">
    <div class="node-header">
      <UserOutlined class="node-icon" />
      <span class="node-title">{{ properties.title || '审批节点' }}</span>
    </div>
    <div class="node-body">
      <div class="assignee">
        <span class="label">审批人：</span>
        <span class="value">{{ assigneeDisplay }}</span>
      </div>
      <div v-if="properties.timeout" class="timeout">
        <ClockCircleOutlined />
        <span>{{ properties.timeout }}小时</span>
      </div>
    </div>
    <div class="node-footer">
      <Tag :color="statusColor">{{ statusText }}</Tag>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, inject } from 'vue'
import { UserOutlined, ClockCircleOutlined } from '@ant-design/icons-vue'
import { Tag } from 'ant-design-vue'

// 注入 LogicFlow 节点实例
const getNode = inject('getNode')
const getGraph = inject('getGraph')

const node = getNode?.()
const graph = getGraph?.()

const properties = computed(() => node?.properties || {})
const isSelected = computed(() => node?.isSelected)

const assigneeDisplay = computed(() => {
  const { assigneeType, assignee } = properties.value
  switch (assigneeType) {
    case 'user': return assignee?.name || '指定用户'
    case 'role': return assignee?.name || '指定角色'
    case 'department': return assignee?.name || '部门负责人'
    case 'initiator': return '发起人'
    default: return '未配置'
  }
})

const statusColor = computed(() => {
  const status = properties.value.executionStatus
  const colors = {
    pending: 'orange',
    running: 'blue',
    completed: 'green',
    rejected: 'red',
  }
  return colors[status] || 'default'
})

const statusText = computed(() => {
  const status = properties.value.executionStatus
  const texts = {
    pending: '待审批',
    running: '审批中',
    completed: '已通过',
    rejected: '已拒绝',
  }
  return texts[status] || '未执行'
})
</script>

<style scoped lang="scss">
.approval-node {
  width: 180px;
  background: #fff;
  border: 2px solid #1890ff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);

  &.selected {
    border-color: #722ed1;
    box-shadow: 0 0 0 2px rgba(114, 46, 209, 0.2);
  }

  .node-header {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: #e6f7ff;
    border-radius: 6px 6px 0 0;

    .node-icon {
      color: #1890ff;
      margin-right: 8px;
    }

    .node-title {
      font-weight: 500;
      color: #333;
    }
  }

  .node-body {
    padding: 12px;

    .assignee {
      display: flex;
      font-size: 12px;
      color: #666;

      .label {
        color: #999;
      }
    }

    .timeout {
      display: flex;
      align-items: center;
      margin-top: 8px;
      font-size: 12px;
      color: #faad14;

      .anticon {
        margin-right: 4px;
      }
    }
  }

  .node-footer {
    padding: 8px 12px;
    border-top: 1px solid #f0f0f0;
    text-align: center;
  }
}
</style>
```

### 4.3 节点注册配置

```typescript
// nodes/index.ts
import LogicFlow from '@logicflow/core'
import { register } from '@logicflow/vue-node-registry'

// 基础节点
import StartNode from './base/StartNode.vue'
import EndNode from './base/EndNode.vue'

// 自动化节点
import ConditionNode from './automation/ConditionNode.vue'
import HttpRequestNode from './automation/HttpRequestNode.vue'
import DelayNode from './automation/DelayNode.vue'
import ScriptNode from './automation/ScriptNode.vue'

// 审批节点
import ApprovalNode from './approval/ApprovalNode.vue'
import CountersignNode from './approval/CountersignNode.vue'
import NotifyNode from './approval/NotifyNode.vue'

export const nodeTypes = {
  // 基础节点
  start: { component: StartNode, label: '开始', icon: 'PlayCircleOutlined' },
  end: { component: EndNode, label: '结束', icon: 'StopOutlined' },

  // 自动化节点
  condition: { component: ConditionNode, label: '条件判断', icon: 'BranchesOutlined' },
  httpRequest: { component: HttpRequestNode, label: 'HTTP请求', icon: 'ApiOutlined' },
  delay: { component: DelayNode, label: '延时', icon: 'ClockCircleOutlined' },
  script: { component: ScriptNode, label: '脚本', icon: 'CodeOutlined' },

  // 审批节点
  approval: { component: ApprovalNode, label: '审批', icon: 'AuditOutlined' },
  countersign: { component: CountersignNode, label: '会签', icon: 'TeamOutlined' },
  notify: { component: NotifyNode, label: '通知', icon: 'NotificationOutlined' },
}

export function registerCustomNodes(lf: LogicFlow) {
  Object.entries(nodeTypes).forEach(([type, config]) => {
    register({
      type,
      component: config.component,
    }, lf)
  })
}

// 节点面板配置
export const nodePaletteConfig = [
  {
    category: '基础节点',
    nodes: ['start', 'end'],
  },
  {
    category: '审批节点',
    nodes: ['approval', 'countersign', 'notify'],
  },
  {
    category: '自动化节点',
    nodes: ['condition', 'httpRequest', 'delay', 'script'],
  },
]
```

---

## 5. API 对接设计

### 5.1 API 接口定义

```typescript
// api/workflow/types.ts
export interface WorkflowDefinition {
  id: string
  name: string
  title: string
  description?: string
  type: 'approval' | 'automation' | 'hybrid'
  trigger: TriggerConfig
  nodes: NodeConfig[]
  edges: EdgeConfig[]
  variables?: Record<string, any>
  version: number
  isActive: boolean
  isPublished: boolean
  createdAt: string
  updatedAt: string
}

export interface NodeConfig {
  id: string
  type: string
  x: number
  y: number
  properties: Record<string, any>
}

export interface EdgeConfig {
  id: string
  sourceNodeId: string
  targetNodeId: string
  properties?: Record<string, any>
}

export interface TriggerConfig {
  type: 'manual' | 'webhook' | 'schedule' | 'data_event'
  config: Record<string, any>
}

export interface WorkflowInstance {
  id: string
  workflowId: string
  workflowName: string
  workflowVersion: number
  triggerType: string
  triggerData: Record<string, any>
  status: 'running' | 'completed' | 'failed' | 'cancelled' | 'pending'
  currentNodeId?: string
  variables: Record<string, any>
  errorMessage?: string
  startedAt: string
  completedAt?: string
}

export interface WorkflowTask {
  id: string
  instanceId: string
  nodeId: string
  taskType: 'approval' | 'countersign'
  title: string
  description?: string
  formData?: Record<string, any>
  assignee: string
  status: 'pending' | 'completed' | 'rejected' | 'cancelled'
  result?: Record<string, any>
  completedAt?: string
  createdAt: string
}
```

### 5.2 API 服务封装

```typescript
// api/workflow/definition.ts
import { request } from '@vben/request'
import type { WorkflowDefinition, CreateWorkflowDto, UpdateWorkflowDto } from './types'

export const workflowDefinitionApi = {
  // 创建流程定义
  create(data: CreateWorkflowDto) {
    return request.post<WorkflowDefinition>('/workflow/definitions', data)
  },

  // 更新流程定义
  update(id: string, data: UpdateWorkflowDto) {
    return request.put<WorkflowDefinition>(`/workflow/definitions/${id}`, data)
  },

  // 获取流程定义列表
  list(params?: { page?: number; pageSize?: number; type?: string; status?: string }) {
    return request.get<PaginatedResponse<WorkflowDefinition>>('/workflow/definitions', { params })
  },

  // 获取流程定义详情
  get(id: string) {
    return request.get<WorkflowDefinition>(`/workflow/definitions/${id}`)
  },

  // 删除流程定义
  delete(id: string) {
    return request.delete(`/workflow/definitions/${id}`)
  },

  // 发布流程
  publish(id: string) {
    return request.post(`/workflow/definitions/${id}/publish`)
  },

  // 停用流程
  deactivate(id: string) {
    return request.post(`/workflow/definitions/${id}/deactivate`)
  },

  // 获取版本历史
  getVersions(id: string) {
    return request.get<WorkflowDefinition[]>(`/workflow/definitions/${id}/versions`)
  },
}

// api/workflow/instance.ts
export const workflowInstanceApi = {
  // 执行流程
  execute(workflowId: string, data?: Record<string, any>) {
    return request.post<WorkflowInstance>(`/workflow/execute/${workflowId}`, data)
  },

  // 获取实例列表
  list(params?: { workflowId?: string; status?: string; page?: number; pageSize?: number }) {
    return request.get<PaginatedResponse<WorkflowInstance>>('/workflow/instances', { params })
  },

  // 获取实例详情
  get(id: string) {
    return request.get<WorkflowInstance>(`/workflow/instances/${id}`)
  },

  // 获取执行记录
  getExecutions(id: string) {
    return request.get<NodeExecution[]>(`/workflow/instances/${id}/executions`)
  },

  // 取消实例
  cancel(id: string) {
    return request.post(`/workflow/instances/${id}/cancel`)
  },
}

// api/workflow/task.ts
export const workflowTaskApi = {
  // 获取待办任务
  getTodoList(params?: { page?: number; pageSize?: number }) {
    return request.get<PaginatedResponse<WorkflowTask>>('/workflow/tasks/todo', { params })
  },

  // 获取已办任务
  getDoneList(params?: { page?: number; pageSize?: number }) {
    return request.get<PaginatedResponse<WorkflowTask>>('/workflow/tasks/done', { params })
  },

  // 获取任务详情
  get(id: string) {
    return request.get<WorkflowTask>(`/workflow/tasks/${id}`)
  },

  // 完成任务（审批通过）
  complete(id: string, data: { comment?: string; formData?: Record<string, any> }) {
    return request.post(`/workflow/tasks/${id}/complete`, data)
  },

  // 拒绝任务
  reject(id: string, data: { comment: string }) {
    return request.post(`/workflow/tasks/${id}/reject`, data)
  },

  // 转办任务
  transfer(id: string, data: { targetUserId: string; comment?: string }) {
    return request.post(`/workflow/tasks/${id}/transfer`, data)
  },
}
```

### 5.3 状态管理设计

```typescript
// store/workflow.ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { workflowDefinitionApi, workflowInstanceApi, workflowTaskApi } from '@/api/workflow'
import type { WorkflowDefinition, WorkflowInstance, WorkflowTask, NodeConfig, EdgeConfig } from '@/api/workflow/types'

export const useWorkflowStore = defineStore('workflow', () => {
  // 设计器状态
  const currentWorkflow = ref<WorkflowDefinition | null>(null)
  const nodes = ref<NodeConfig[]>([])
  const edges = ref<EdgeConfig[]>([])
  const selectedNodeId = ref<string | null>(null)
  const isDirty = ref(false)

  // 实例状态
  const instances = ref<WorkflowInstance[]>([])
  const currentInstance = ref<WorkflowInstance | null>(null)

  // 任务状态
  const todoTasks = ref<WorkflowTask[]>([])
  const doneTasks = ref<WorkflowTask[]>([])
  const todoCount = ref(0)

  // Getters
  const selectedNode = computed(() =>
    nodes.value.find(n => n.id === selectedNodeId.value)
  )

  const canSave = computed(() => isDirty.value && nodes.value.length > 0)

  // Actions - 设计器
  async function loadWorkflow(id: string) {
    const workflow = await workflowDefinitionApi.get(id)
    currentWorkflow.value = workflow
    nodes.value = workflow.nodes
    edges.value = workflow.edges
    isDirty.value = false
    return workflow
  }

  async function saveWorkflow() {
    if (!currentWorkflow.value) return

    const data = {
      ...currentWorkflow.value,
      nodes: nodes.value,
      edges: edges.value,
    }

    if (currentWorkflow.value.id) {
      await workflowDefinitionApi.update(currentWorkflow.value.id, data)
    } else {
      const result = await workflowDefinitionApi.create(data)
      currentWorkflow.value = result
    }

    isDirty.value = false
  }

  function addNode(node: NodeConfig) {
    nodes.value.push(node)
    isDirty.value = true
  }

  function updateNode(id: string, data: Partial<NodeConfig>) {
    const index = nodes.value.findIndex(n => n.id === id)
    if (index !== -1) {
      nodes.value[index] = { ...nodes.value[index], ...data }
      isDirty.value = true
    }
  }

  function removeNode(id: string) {
    nodes.value = nodes.value.filter(n => n.id !== id)
    edges.value = edges.value.filter(e => e.sourceNodeId !== id && e.targetNodeId !== id)
    if (selectedNodeId.value === id) {
      selectedNodeId.value = null
    }
    isDirty.value = true
  }

  function addEdge(edge: EdgeConfig) {
    edges.value.push(edge)
    isDirty.value = true
  }

  function removeEdge(id: string) {
    edges.value = edges.value.filter(e => e.id !== id)
    isDirty.value = true
  }

  // Actions - 任务
  async function fetchTodoTasks() {
    const { items, total } = await workflowTaskApi.getTodoList()
    todoTasks.value = items
    todoCount.value = total
  }

  async function completeTask(taskId: string, data: { comment?: string; formData?: Record<string, any> }) {
    await workflowTaskApi.complete(taskId, data)
    await fetchTodoTasks()
  }

  async function rejectTask(taskId: string, comment: string) {
    await workflowTaskApi.reject(taskId, { comment })
    await fetchTodoTasks()
  }

  return {
    // State
    currentWorkflow,
    nodes,
    edges,
    selectedNodeId,
    isDirty,
    instances,
    currentInstance,
    todoTasks,
    doneTasks,
    todoCount,

    // Getters
    selectedNode,
    canSave,

    // Actions
    loadWorkflow,
    saveWorkflow,
    addNode,
    updateNode,
    removeNode,
    addEdge,
    removeEdge,
    fetchTodoTasks,
    completeTask,
    rejectTask,
  }
})
```

---

## 6. 路由配置设计

```typescript
// router/modules/workflow.ts
import type { RouteRecordRaw } from 'vue-router'

const workflowRoutes: RouteRecordRaw[] = [
  {
    path: '/workflow',
    name: 'Workflow',
    redirect: '/workflow/definition',
    meta: {
      title: '工作流管理',
      icon: 'mdi:workflow',
      order: 50,
    },
    children: [
      {
        path: 'definition',
        name: 'WorkflowDefinition',
        component: () => import('@/views/workflow/definition/index.vue'),
        meta: {
          title: '流程定义',
          icon: 'mdi:file-document-outline',
        },
      },
      {
        path: 'definition/create',
        name: 'WorkflowDefinitionCreate',
        component: () => import('@/views/workflow/definition/create.vue'),
        meta: {
          title: '创建流程',
          hideInMenu: true,
        },
      },
      {
        path: 'definition/:id',
        name: 'WorkflowDefinitionEdit',
        component: () => import('@/views/workflow/definition/[id].vue'),
        meta: {
          title: '编辑流程',
          hideInMenu: true,
        },
      },
      {
        path: 'instance',
        name: 'WorkflowInstance',
        component: () => import('@/views/workflow/instance/index.vue'),
        meta: {
          title: '流程实例',
          icon: 'mdi:play-circle-outline',
        },
      },
      {
        path: 'instance/:id',
        name: 'WorkflowInstanceDetail',
        component: () => import('@/views/workflow/instance/[id].vue'),
        meta: {
          title: '实例详情',
          hideInMenu: true,
        },
      },
      {
        path: 'task/todo',
        name: 'WorkflowTaskTodo',
        component: () => import('@/views/workflow/task/todo.vue'),
        meta: {
          title: '待办任务',
          icon: 'mdi:clipboard-check-outline',
        },
      },
      {
        path: 'task/done',
        name: 'WorkflowTaskDone',
        component: () => import('@/views/workflow/task/done.vue'),
        meta: {
          title: '已办任务',
          icon: 'mdi:clipboard-check',
        },
      },
      {
        path: 'task/:id',
        name: 'WorkflowTaskDetail',
        component: () => import('@/views/workflow/task/[id].vue'),
        meta: {
          title: '任务详情',
          hideInMenu: true,
        },
      },
      {
        path: 'monitor',
        name: 'WorkflowMonitor',
        component: () => import('@/views/workflow/monitor/index.vue'),
        meta: {
          title: '流程监控',
          icon: 'mdi:monitor-dashboard',
        },
      },
    ],
  },
]

export default workflowRoutes
```

---

## 7. 实施计划

### 7.1 分阶段实施（4 周）

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          前端工作流实施计划（4周）                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  第1周：基础设施搭建                                                         │
│  ├─ Day 1-2: 创建 @core/workflow-ui 包结构                                  │
│  ├─ Day 3-4: 集成 LogicFlow，配置 TypeScript                                │
│  └─ Day 5: 创建基础节点组件模板                                              │
│                                                                             │
│  第2周：设计器核心开发                                                       │
│  ├─ Day 1-2: 工作流设计器主组件                                              │
│  ├─ Day 2-3: 节点拖拽面板和工具栏                                            │
│  ├─ Day 4: 属性配置面板                                                      │
│  └─ Day 5: 连线和验证逻辑                                                    │
│                                                                             │
│  第3周：业务页面开发                                                         │
│  ├─ Day 1: 流程定义管理页面                                                  │
│  ├─ Day 2: 任务审批页面                                                      │
│  ├─ Day 3: 流程实例监控页面                                                  │
│  ├─ Day 4: API 对接和状态管理                                                │
│  └─ Day 5: 与后端 API 联调                                                   │
│                                                                             │
│  第4周：集成测试和优化                                                       │
│  ├─ Day 1-2: 功能测试和 Bug 修复                                             │
│  ├─ Day 3: 性能优化                                                          │
│  ├─ Day 4: E2E 测试                                                          │
│  └─ Day 5: 文档编写和代码审查                                                │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.2 里程碑定义

| 里程碑 | 时间 | 交付物 | 验收标准 |
|-------|------|--------|---------|
| M1 | Week 1 | 基础架构 | LogicFlow 集成成功，可渲染基础节点 |
| M2 | Week 2 | 设计器核心 | 可拖拽创建流程，配置节点属性 |
| M3 | Week 3 | 业务功能 | 完成所有页面，API 对接成功 |
| M4 | Week 4 | 生产就绪 | 测试通过，文档完整 |

### 7.3 资源需求

| 角色 | 人数 | 职责 |
|-----|------|------|
| 前端开发工程师 | 1-2 | 组件开发、页面实现 |
| UI 设计师 | 0.5 | 节点样式、交互设计 |
| 测试工程师 | 0.5 | 功能测试、E2E 测试 |

---

## 8. 风险评估与缓解

### 8.1 技术风险

| 风险 | 等级 | 影响 | 缓解措施 |
|-----|------|------|---------|
| LogicFlow 与 Vue Vben Admin 兼容性问题 | 中 | 开发延迟 | 提前进行 POC 验证 |
| 自定义节点与 form-ui 集成复杂 | 中 | 工作量增加 | 设计统一的属性面板接口 |
| 大流程图性能问题 | 低 | 用户体验下降 | 使用虚拟渲染、懒加载 |
| 后端 API 准备延迟 | 中 | 联调延迟 | 建立 Mock 数据机制 |

### 8.2 缓解策略

**1. POC 验证（第 1 周前）**
```typescript
// 验证 LogicFlow + Vue 3 + Ant Design Vue 集成
const poc = {
  tasks: [
    '创建独立 demo 项目',
    '集成 LogicFlow 核心功能',
    '注册自定义 Vue 节点',
    '验证拖拽、连线、保存功能',
  ],
  successCriteria: [
    '节点渲染正常',
    '事件响应正确',
    '数据导出格式符合预期',
  ],
}
```

**2. Mock 数据机制**
```typescript
// api/workflow/mock.ts
import { defineFakeRoute } from '@vben/mock-service'

export default defineFakeRoute([
  {
    url: '/workflow/definitions',
    method: 'get',
    response: () => ({
      code: 0,
      data: {
        items: mockWorkflowDefinitions,
        total: mockWorkflowDefinitions.length,
      },
    }),
  },
  // ... 其他 Mock 接口
])
```

---

## 9. 与后端集成点

### 9.1 数据格式对齐

**前端节点配置 → 后端节点模型**

```typescript
// 前端节点格式
interface FrontendNode {
  id: string
  type: string
  x: number
  y: number
  properties: {
    title: string
    assigneeType: 'user' | 'role' | 'department'
    assignee: { id: string; name: string }
    timeout?: number
    // ...
  }
}

// 后端节点格式（Ingenious）
interface BackendNode {
  id: string
  type: string
  name: string
  config: {
    assignee: string
    timeout: number
    // ...
  }
  inputs: string[]
  outputs: string[]
}

// 转换函数
function toBackendNode(frontendNode: FrontendNode): BackendNode {
  return {
    id: frontendNode.id,
    type: frontendNode.type,
    name: frontendNode.properties.title,
    config: {
      assignee: frontendNode.properties.assignee?.id,
      timeout: frontendNode.properties.timeout,
    },
    inputs: [],  // 从边计算
    outputs: [], // 从边计算
  }
}
```

### 9.2 实时同步设计

```typescript
// WebSocket 实时更新
const wsUrl = `${WS_BASE_URL}/workflow/instances/${instanceId}/events`

const ws = new WebSocket(wsUrl)

ws.onmessage = (event) => {
  const data = JSON.parse(event.data)

  switch (data.type) {
    case 'node:started':
      // 更新节点状态为执行中
      updateNodeStatus(data.nodeId, 'running')
      break
    case 'node:completed':
      // 更新节点状态为完成
      updateNodeStatus(data.nodeId, 'completed')
      break
    case 'node:failed':
      // 更新节点状态为失败
      updateNodeStatus(data.nodeId, 'failed')
      break
    case 'instance:completed':
      // 流程执行完成
      refreshInstance()
      break
  }
}
```

---

## 10. 质量保证

### 10.1 测试策略

| 测试类型 | 工具 | 覆盖目标 |
|---------|------|---------|
| 单元测试 | Vitest | 工具函数、Store、API |
| 组件测试 | Vue Test Utils | 节点组件、设计器组件 |
| E2E 测试 | Playwright | 关键用户流程 |
| 视觉回归 | Chromatic | UI 一致性 |

### 10.2 代码质量

```typescript
// eslint 规则扩展
module.exports = {
  extends: ['@vben/eslint-config'],
  rules: {
    // 工作流组件特定规则
    'vue/multi-word-component-names': ['error', {
      ignores: ['Toolbar', 'Canvas'],
    }],
  },
}
```

---

## 11. 总结

### 11.1 准备度评估

| 维度 | 评分 | 说明 |
|-----|------|------|
| 技术栈兼容性 | 90% | Vue 3 + TypeScript + Ant Design Vue 完全匹配 |
| 架构扩展性 | 85% | Monorepo 结构支持新增包 |
| 组件复用度 | 80% | form-ui、request、hooks 可直接复用 |
| 团队能力 | 85% | 假设具备 Vue 3 和 LogicFlow 经验 |
| **综合准备度** | **85%** | 具备启动条件 |

### 11.2 关键成功因素

1. **技术验证**：第 1 周完成 LogicFlow 集成 POC
2. **接口对齐**：与后端保持数据格式一致
3. **增量交付**：按优先级实现功能
4. **持续沟通**：前后端每日同步进度

### 11.3 下一步行动

1. ✅ 完成技术选型（LogicFlow）
2. 🔲 创建 POC 验证项目
3. 🔲 创建 @core/workflow-ui 包
4. 🔲 与后端对齐 API 接口规范
5. 🔲 开始第 1 阶段开发

---

**文档版本历史**

| 版本 | 日期 | 作者 | 变更说明 |
|-----|------|------|---------|
| v1.0 | 2025-12-15 | AI Assistant | 初始版本 |

---

*本报告由 Claude AI 基于 Sequential Thinking、Serena 和 Context7 工具自动生成*
