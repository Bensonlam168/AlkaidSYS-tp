<template>
  <div class="workflow-designer" :style="containerStyle">
    <!-- 工具栏 -->
    <WorkflowToolbar
      v-if="!readonly"
      :visible="showToolbar"
      :tools="toolbarTools"
      @click="handleToolbarClick"
    />

    <!-- 主要设计区域 -->
    <div class="designer-canvas" ref="canvasRef">
      <!-- LogicFlow画布 -->
      <div
        ref="lfContainer"
        class="lf-container"
        :style="canvasStyle"
      />

      <!-- 属性面板 -->
      <PropertyPanel
        v-if="showPropertyPanel"
        :selected="selectedElement"
        :schema="propertySchema"
        :readonly="readonly"
        @change="handlePropertyChange"
        @save="handleSave"
      />

      <!-- 节点选择器 -->
      <NodeSelector
        v-if="!readonly && showNodeSelector"
        :node-types="availableNodeTypes"
        :searchable="true"
        @select="handleNodeSelect"
        @item-click="handleNodeClick"
      />

      <!-- 小地图 -->
      <MiniMap
        v-if="enableMiniMap"
        :width="120"
        :height="80"
        position="bottom-right"
        header="导航"
      />

      <!-- 控制面板 -->
      <ControlPanel
        v-if="enableControlPanel"
        :data="controlPanelData"
        header="组件库"
        @item-click="handleControlPanelClick"
      />
    </div>

    <!-- 状态栏 -->
    <div class="designer-status-bar" v-if="showStatusBar">
      <span class="status-item">
        <i class="status-icon">📍</i>
        位置: ({{ currentPosition.x }}, {{ currentPosition.y }})
      </span>
      <span class="status-item">
        <i class="status-icon">🔍</i>
        缩放: {{ (zoomLevel * 100).toFixed(0) }}%
      </span>
      <span class="status-item">
        <i class="status-icon">📊</i>
        节点: {{ nodeCount }} | 边: {{ edgeCount }}
      </span>
    </div>

    <!-- 导出对话框 -->
    <ExportDialog
      v-model:visible="showExportDialog"
      :format="exportFormat"
      @export="handleExport"
    />

    <!-- 导入对话框 -->
    <ImportDialog
      v-model:visible="showImportDialog"
      @import="handleImport"
      @error="handleImportError"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import type { Ref } from 'vue'
import LogicFlow from '@logicflow/core'
import { ControlPanel, Menu, SelectionSelect, Snapshot, MiniMap } from '@logicflow/extension'

import type {
  DesignerProps,
  DesignerInstance,
  WorkflowDefinition
} from '../../types/vue'

import WorkflowToolbar from '../common/WorkflowToolbar.vue'
import PropertyPanel from '../common/PropertyPanel.vue'
import NodeSelector from '../common/NodeSelector.vue'
import MiniMap from '../common/MiniMap.vue'
import ControlPanel from '../common/ControlPanel.vue'
import ExportDialog from '../common/ExportDialog.vue'
import ImportDialog from '../common/ImportDialog.vue'

import { useWorkflowEngine } from '../../core/WorkflowEngine'
import { useEventEmitter } from '../../utils/event'
import { defaultConfig } from '../../index'

// Props定义
const props = withDefaults(defineProps<DesignerProps>(), {
  readonly: false,
  showToolbar: true,
  showPropertyPanel: true,
  showNodeSelector: true,
  showStatusBar: true,
  enableMiniMap: true,
  enableControlPanel: true,
  height: 600
})

// Emits定义
const emit = defineEmits<{
  change: [data: any]
  save: [data: any]
  validate: [data: any] => boolean | string
  error: [error: Error]
  'node-click': [node: any]
  'node-contextmenu': [node: any, event: MouseEvent]
  'node-doubleclick': [node: any]
  'edge-click': [edge: any]
  'edge-contextmenu': [edge: any, event: MouseEvent]
  'canvas-click': [event: MouseEvent]
  'canvas-contextmenu': [event: MouseEvent]
  'canvas-drop': [event: DragEvent]
}>()

// 响应式数据
const canvasRef = ref<HTMLElement>()
const lfContainer = ref<HTMLElement>()
const lfInstance = ref<LogicFlow | null>(null)
const selectedElement = ref<any>(null)
const currentPosition = ref({ x: 0, y: 0 })
const zoomLevel = ref(1)
const nodeCount = ref(0)
const edgeCount = ref(0)

// 对话框状态
const showExportDialog = ref(false)
const showImportDialog = ref(false)
const exportFormat = ref<'png' | 'jpg' | 'svg' | 'pdf' | 'json'>('png')

// 计算属性
const containerStyle = computed(() => ({
  width: props.width ? `${props.width}px` : '100%',
  height: props.height ? `${props.height}px` : '600px',
  border: '1px solid #d9d9d9',
  borderRadius: '4px',
  overflow: 'hidden',
  position: 'relative'
}))

const canvasStyle = computed(() => ({
  width: '100%',
  height: '100%',
  position: 'relative'
}))

// 工具栏配置
const toolbarTools = computed(() => [
  {
    key: 'undo',
    icon: '↶',
    text: '撤销',
    tooltip: '撤销上一步操作 (Ctrl+Z)',
    disabled: false
  },
  {
    key: 'redo',
    icon: '↷',
    text: '重做',
    tooltip: '重做下一步操作 (Ctrl+Y)',
    disabled: false
  },
  { key: 'separator', type: 'separator' },
  {
    key: 'zoom-in',
    icon: '+',
    text: '放大',
    tooltip: '放大画布 (Ctrl++)',
    disabled: false
  },
  {
    key: 'zoom-out',
    icon: '-',
    text: '缩小',
    tooltip: '缩小画布 (Ctrl+-)',
    disabled: false
  },
  {
    key: 'fit-view',
    icon: '⤢',
    text: '适配视图',
    tooltip: '适应窗口大小',
    disabled: false
  },
  { key: 'separator', type: 'separator' },
  {
    key: 'export',
    icon: '💾',
    text: '导出',
    tooltip: '导出工作流',
    disabled: false
  },
  {
    key: 'import',
    icon: '📂',
    text: '导入',
    tooltip: '导入工作流',
    disabled: false
  },
  {
    key: 'save',
    icon: '💿',
    text: '保存',
    tooltip: '保存工作流 (Ctrl+S)',
    disabled: false
  }
])

// 可用节点类型
const availableNodeTypes = [
  {
    type: 'start',
    name: '开始节点',
    icon: '🚀',
    description: '流程起始节点',
    category: '基础节点'
  },
  {
    type: 'end',
    name: '结束节点',
    icon: '🏁',
    description: '流程结束节点',
    category: '基础节点'
  },
  {
    type: 'task',
    name: '任务节点',
    icon: '📋',
    description: '执行具体任务的节点',
    category: '任务节点'
  },
  {
    type: 'decision',
    name: '决策节点',
    icon: '🔀',
    description: '条件判断节点',
    category: '控制节点'
  },
  {
    type: 'fork',
    name: '分支节点',
    icon: '🌿',
    description: '并行分支节点',
    category: '控制节点'
  },
  {
    type: 'join',
    name: '汇聚节点',
    icon: '🔗',
    description: '并行汇聚节点',
    category: '控制节点'
  }
]

// 属性面板Schema
const propertySchema = computed(() => {
  if (!selectedElement.value) return []

  const schema = []

  // 通用属性
  schema.push({
    prop: 'text',
    label: '节点名称',
    type: 'input',
    required: true
  })

  if (selectedElement.value.type === 'task') {
    schema.push({
      prop: 'taskType',
      label: '任务类型',
      type: 'select',
      options: [
        { label: '用户任务', value: 'user_task' },
        { label: '系统任务', value: 'system_task' },
        { label: '服务任务', value: 'service_task' }
      ]
    })
  }

  if (selectedElement.value.type === 'decision') {
    schema.push({
      prop: 'condition',
      label: '条件表达式',
      type: 'textarea',
      placeholder: '请输入条件表达式'
    })
  }

  return schema
})

// 控制面板数据
const controlPanelData = [
  {
    name: '基础节点',
    children: [
      { name: '开始', type: 'start', icon: '🚀' },
      { name: '结束', type: 'end', icon: '🏁' }
    ]
  },
  {
    name: '任务节点',
    children: [
      { name: '任务', type: 'task', icon: '📋' }
    ]
  },
  {
    name: '控制节点',
    children: [
      { name: '决策', type: 'decision', icon: '🔀' },
      { name: '分支', type: 'fork', icon: '🌿' },
      { name: '汇聚', type: 'join', icon: '🔗' }
    ]
  }
]

// 事件发射器
const eventEmitter = useEventEmitter()

// 工作流引擎
const workflowEngine = useWorkflowEngine()

/**
 * 初始化LogicFlow
 */
const initLogicFlow = async () => {
  if (!lfContainer.value) return

  try {
    // 注册插件
    LogicFlow.use(ControlPanel)
    LogicFlow.use(Menu)
    LogicFlow.use(SelectionSelect)
    LogicFlow.use(Snapshot)
    LogicFlow.use(MiniMap)

    // 创建实例
    lfInstance.value = new LogicFlow({
      container: lfContainer.value,
      width: props.width || 800,
      height: props.height || 600,
      ...defaultConfig
    })

    // 注册自定义节点
    await registerCustomNodes()

    // 绑定事件
    bindLogicFlowEvents()

    // 设置初始数据
    if (props.definition) {
      lfInstance.value.render(props.definition)
    }

    console.log('LogicFlow初始化成功')
  } catch (error) {
    console.error('LogicFlow初始化失败:', error)
    emit('error', error as Error)
  }
}

/**
 * 注册自定义节点
 */
const registerCustomNodes = async () => {
  if (!lfInstance.value) return

  const lf = lfInstance.value

  try {
    // 注册开始节点
    lf.register('start', {
      type: 'start',
      width: 120,
      height: 60,
      model: {
        getStyle() {
          return {
            fill: '#f6ffed',
            stroke: '#52c41a',
            strokeWidth: 2,
            radius: 30
          }
        }
      }
    })

    // 注册结束节点
    lf.register('end', {
      type: 'end',
      width: 120,
      height: 60,
      model: {
        getStyle() {
          return {
            fill: '#fff1f0',
            stroke: '#ff4d4f',
            strokeWidth: 2,
            radius: 30
          }
        }
      }
    })

    // 注册任务节点
    lf.register('task', {
      type: 'task',
      width: 120,
      height: 80,
      model: {
        getStyle() {
          return {
            fill: '#f0f5ff',
            stroke: '#1890ff',
            strokeWidth: 2,
            radius: 6
          }
        }
      }
    })

    // 注册决策节点
    lf.register('decision', {
      type: 'decision',
      width: 100,
      height: 100,
      model: {
        getStyle() {
          return {
            fill: '#fffbe6',
            stroke: '#fa8c16',
            strokeWidth: 2,
            radius: 0,
            transform: 'rotate(45deg)'
          }
        }
      }
    })

    // 注册分支节点
    lf.register('fork', {
      type: 'fork',
      width: 120,
      height: 80,
      model: {
        getStyle() {
          return {
            fill: '#f3f0ff',
            stroke: '#722ed1',
            strokeWidth: 2,
            radius: 8
          }
        }
      }
    })

    // 注册汇聚节点
    lf.register('join', {
      type: 'join',
      width: 100,
      height: 100,
      model: {
        getStyle() {
          return {
            fill: '#fff0f6',
            stroke: '#eb2f96',
            strokeWidth: 2,
            radius: 50
          }
        }
      }
    })

    // 注册自定义节点
    lf.register('custom', {
      type: 'custom',
      width: 120,
      height: 80,
      model: {
        getStyle() {
          return {
            fill: '#f6f9ff',
            stroke: '#531dab',
            strokeWidth: 2,
            radius: 8,
            strokeDasharray: '5,5'
          }
        }
      }
    })

    // 注册子流程节点
    lf.register('sub_process', {
      type: 'sub_process',
      width: 140,
      height: 90,
      model: {
        getStyle() {
          return {
            fill: '#fffbe6',
            stroke: '#fa8c16',
            strokeWidth: 3,
            radius: 8
          }
        }
      }
    })

    console.log('自定义节点注册成功')
  } catch (error) {
    console.error('注册自定义节点失败:', error)
  }
}

/**
 * 绑定LogicFlow事件
 */
const bindLogicFlowEvents = () => {
  if (!lfInstance.value) return

  const lf = lfInstance.value

  // 节点事件
  lf.on('node:click', (e) => {
    selectedElement.value = e.node
    emit('node-click', e.node)
  })

  lf.on('node:contextmenu', (e) => {
    emit('node-contextmenu', e.node, e.e)
  })

  lf.on('node:dblclick', (e) => {
    emit('node-doubleclick', e.node)
  })

  // 边事件
  lf.on('edge:click', (e) => {
    selectedElement.value = e.edge
    emit('edge-click', e.edge)
  })

  lf.on('edge:contextmenu', (e) => {
    emit('edge-contextmenu', e.edge, e.e)
  })

  // 画布事件
  lf.on('canvas:click', (e) => {
    selectedElement.value = null
    emit('canvas-click', e.e)
  })

  lf.on('canvas:contextmenu', (e) => {
    emit('canvas-contextmenu', e.e)
  })

  lf.on('canvas:drop', (e) => {
    emit('canvas-drop', e.e)
  })

  // 视图变化事件
  lf.on('history:change', () => {
    updateViewStats()
  })

  // 鼠标移动事件
  lf.on('node:mousemove', (e) => {
    currentPosition.value = { x: e.node.x, y: e.node.y }
  })
}

/**
 * 更新视图统计
 */
const updateViewStats = () => {
  if (!lfInstance.value) return

  const graphData = lfInstance.value.getGraphData()
  nodeCount.value = graphData.nodes?.length || 0
  edgeCount.value = graphData.edges?.length || 0
}

// 事件处理函数
const handleToolbarClick = (key: string) => {
  if (!lfInstance.value) return

  switch (key) {
    case 'undo':
      lfInstance.value.undo()
      break
    case 'redo':
      lfInstance.value.redo()
      break
    case 'zoom-in':
      lfInstance.value.zoomTo(zoomLevel.value + 0.1)
      break
    case 'zoom-out':
      lfInstance.value.zoomTo(zoomLevel.value - 0.1)
      break
    case 'fit-view':
      lfInstance.value.fitView()
      break
    case 'export':
      showExportDialog.value = true
      break
    case 'import':
      showImportDialog.value = true
      break
    case 'save':
      handleSave()
      break
  }
}

const handlePropertyChange = (key: string, value: any) => {
  if (selectedElement.value && lfInstance.value) {
    lfInstance.value.updateProperties(selectedElement.value.id, {
      [key]: value
    })

    emit('change', {
      id: selectedElement.value.id,
      key,
      value
    })
  }
}

const handleSave = () => {
  if (lfInstance.value) {
    const graphData = lfInstance.value.getGraphData()
    emit('save', graphData)
  }
}

const handleNodeSelect = (types: string[]) => {
  // 处理节点选择
  console.log('Selected node types:', types)
}

const handleNodeClick = (type: string) => {
  // 处理节点点击
  console.log('Node clicked:', type)
}

const handleControlPanelClick = (item: any) => {
  // 处理控制面板点击
  console.log('Control panel item clicked:', item)
}

const handleExport = (format: string, options: any) => {
  if (!lfInstance.value) return

  switch (format) {
    case 'png':
    case 'jpg':
      const dataURL = lfInstance.value.exportPNG(options.backgroundColor)
      // 下载图片
      downloadDataURL(dataURL, `workflow.${format}`)
      break
    case 'json':
      const jsonData = lfInstance.value.exportJson()
      downloadJSON(jsonData, 'workflow.json')
      break
  }
}

const handleImport = (file: File, data: any) => {
  if (lfInstance.value) {
    lfInstance.value.render(data)
    emit('change', data)
  }
}

const handleImportError = (error: Error) => {
  emit('error', error)
}

// 工具函数
const downloadDataURL = (dataURL: string, filename: string) => {
  const link = document.createElement('a')
  link.href = dataURL
  link.download = filename
  link.click()
}

const downloadJSON = (data: any, filename: string) => {
  const blob = new Blob([JSON.stringify(data, null, 2)], {
    type: 'application/json'
  })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

// 公开方法
const getGraphData = () => {
  return lfInstance.value?.getGraphData() || null
}

const setGraphData = (data: any) => {
  lfInstance.value?.render(data)
}

const exportPNG = (background?: string) => {
  return lfInstance.value?.exportPNG(background) || ''
}

const undo = () => {
  lfInstance.value?.undo()
}

const redo = () => {
  lfInstance.value?.redo()
}

const fitView = () => {
  lfInstance.value?.fitView()
}

const resetView = () => {
  lfInstance.value?.resetZoom()
}

// 暴露实例方法
defineExpose<DesignerInstance>({
  get lf() {
    return lfInstance.value
  },
  getGraphData,
  setGraphData,
  exportPNG,
  undo,
  redo,
  fitView,
  resetView,
  addNode: (config: any) => lfInstance.value?.addNode(config),
  removeNode: (id: string) => lfInstance.value?.removeNode(id),
  updateNode: (id: string, config: any) => lfInstance.value?.updateNode(id, config),
  addEdge: (config: any) => lfInstance.value?.addEdge(config),
  removeEdge: (id: string) => lfInstance.value?.removeEdge(id),
  selectNode: (id: string) => lfInstance.value?.selectNodeById(id),
  clearSelect: () => lfInstance.value?.clearSelectElements()
})

// 生命周期
onMounted(async () => {
  await initLogicFlow()
})

onUnmounted(() => {
  if (lfInstance.value) {
    lfInstance.value.destroy()
  }
})

// 监听属性变化
watch(
  () => props.definition,
  (newDefinition) => {
    if (newDefinition && lfInstance.value) {
      lfInstance.value.render(newDefinition)
    }
  },
  { deep: true }
)
</script>

<style scoped>
.workflow-designer {
  display: flex;
  flex-direction: column;
  background: #f5f5f5;
  position: relative;
}

.designer-canvas {
  flex: 1;
  position: relative;
  overflow: hidden;
}

.lf-container {
  width: 100%;
  height: 100%;
}

.designer-status-bar {
  height: 30px;
  background: #fff;
  border-top: 1px solid #d9d9d9;
  padding: 0 12px;
  display: flex;
  align-items: center;
  gap: 20px;
  font-size: 12px;
  color: #666;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 4px;
}

.status-icon {
  font-style: normal;
}
</style>