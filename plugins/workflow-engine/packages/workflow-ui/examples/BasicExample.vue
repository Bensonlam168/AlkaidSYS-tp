<template>
  <div class="example-container">
    <h2>基础示例 - 工作流设计器</h2>

    <div class="controls">
      <button @click="handleSave" class="btn">保存工作流</button>
      <button @click="handleExport" class="btn">导出 PNG</button>
      <button @click="handleUndo" class="btn">撤销</button>
      <button @click="handleRedo" class="btn">重做</button>
      <button @click="handleFitView" class="btn">适应视图</button>
    </div>

    <div class="designer-wrapper">
      <WorkflowDesigner
        ref="designerRef"
        v-model:definition="workflowDefinition"
        :config="designerConfig"
        :show-toolbar="true"
        :show-property-panel="true"
        :show-node-selector="true"
        :show-status-bar="true"
        :enable-mini-map="true"
        :enable-control-panel="true"
        @change="handleChange"
        @save="handleSave"
        @error="handleError"
        @node-click="handleNodeClick"
        @edge-click="handleEdgeClick"
      />
    </div>

    <div class="info-panel">
      <h3>工作流信息</h3>
      <p><strong>名称:</strong> {{ workflowDefinition?.name || '未命名' }}</p>
      <p><strong>版本:</strong> {{ workflowDefinition?.version || 1 }}</p>
      <p><strong>状态:</strong> {{ workflowDefinition?.status || 'draft' }}</p>
      <p><strong>节点数量:</strong> {{ workflowDefinition?.nodes?.length || 0 }}</p>
      <p><strong>连接数量:</strong> {{ workflowDefinition?.connections?.length || 0 }}</p>
    </div>

    <div class="log-panel">
      <h3>事件日志</h3>
      <div class="log-list">
        <div v-for="(log, index) in logs" :key="index" class="log-item">
          <span class="log-time">{{ log.time }}</span>
          <span class="log-type">{{ log.type }}</span>
          <span class="log-message">{{ log.message }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { WorkflowDesigner } from '../src/index'
import type { WorkflowDefinition, DesignerInstance } from '../src/index'

const designerRef = ref<DesignerInstance>()

// 工作流定义
const workflowDefinition = ref<WorkflowDefinition>({
  id: 'example-workflow-1',
  name: '示例工作流',
  displayName: '示例工作流',
  description: '这是一个基础示例工作流',
  type: 'approval',
  version: 1,
  status: 'draft',
  nodes: [
    {
      id: 'start-node',
      name: '开始',
      type: 'start',
      x: 200,
      y: 100
    },
    {
      id: 'task-node-1',
      name: '任务1',
      type: 'task',
      x: 400,
      y: 100,
      properties: {
        taskType: 'user_task',
        label: '用户任务',
        assignee: 1
      }
    },
    {
      id: 'decision-node',
      name: '决策',
      type: 'decision',
      x: 600,
      y: 100,
      properties: {
        label: '是否通过',
        condition: '${approved == true}'
      }
    },
    {
      id: 'end-node-1',
      name: '结束',
      type: 'end',
      x: 800,
      y: 50
    },
    {
      id: 'end-node-2',
      name: '拒绝结束',
      type: 'end',
      x: 800,
      y: 150
    }
  ],
  connections: [
    {
      id: 'conn-1',
      source: 'start-node',
      target: 'task-node-1'
    },
    {
      id: 'conn-2',
      source: 'task-node-1',
      target: 'decision-node'
    },
    {
      id: 'conn-3',
      source: 'decision-node',
      target: 'end-node-1',
      properties: {
        label: '通过',
        condition: '${approved == true}'
      }
    },
    {
      id: 'conn-4',
      source: 'decision-node',
      target: 'end-node-2',
      properties: {
        label: '拒绝',
        condition: '${approved == false}'
      }
    }
  ],
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
  createdBy: 1
})

// 设计器配置
const designerConfig = {
  grid: {
    enabled: true,
    cell: 20,
    type: 'dot' as const
  },
  background: {
    color: '#ffffff'
  },
  keyboard: {
    enabled: true
  },
  autoAlign: true,
  theme: {
    baseNode: {
      fill: '#f5f5f5',
      stroke: '#d9d9d9',
      strokeWidth: 1
    },
    baseEdge: {
      stroke: '#555555',
      strokeWidth: 2
    },
    anchor: {
      fill: '#ffffff',
      stroke: '#1890ff',
      strokeWidth: 1
    }
  }
}

// 日志
const logs = reactive<Array<{
  time: string
  type: string
  message: string
}>>([])

// 事件处理函数
const addLog = (type: string, message: string) => {
  logs.unshift({
    time: new Date().toLocaleTimeString(),
    type,
    message
  })

  // 限制日志数量
  if (logs.length > 50) {
    logs.pop()
  }
}

const handleChange = (data: any) => {
  addLog('CHANGE', `工作流已变更`)
}

const handleSave = (data: any) => {
  addLog('SAVE', '工作流已保存')
  console.log('保存的工作流数据:', data)
}

const handleError = (error: Error) => {
  addLog('ERROR', error.message)
  console.error('工作流错误:', error)
}

const handleNodeClick = (node: any) => {
  addLog('NODE', `点击节点: ${node.text || node.id}`)
}

const handleEdgeClick = (edge: any) => {
  addLog('EDGE', `点击边: ${edge.id}`)
}

// 按钮事件
const handleExport = () => {
  if (designerRef.value) {
    const pngData = designerRef.value.exportPNG('#ffffff')
    addLog('EXPORT', '已导出 PNG')

    // 创建下载链接
    const link = document.createElement('a')
    link.href = pngData
    link.download = 'workflow.png'
    link.click()
  }
}

const handleUndo = () => {
  if (designerRef.value) {
    designerRef.value.undo()
    addLog('UNDO', '已撤销操作')
  }
}

const handleRedo = () => {
  if (designerRef.value) {
    designerRef.value.redo()
    addLog('REDO', '已重做操作')
  }
}

const handleFitView = () => {
  if (designerRef.value) {
    designerRef.value.fitView()
    addLog('VIEW', '已适应视图')
  }
}
</script>

<style scoped>
.example-container {
  padding: 20px;
  background: #f5f5f5;
  min-height: 100vh;
}

h2 {
  margin: 0 0 20px 0;
  color: #333;
}

.controls {
  margin-bottom: 20px;
  display: flex;
  gap: 10px;
}

.btn {
  padding: 8px 16px;
  background: #1890ff;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  transition: background 0.2s;
}

.btn:hover {
  background: #40a9ff;
}

.designer-wrapper {
  height: 500px;
  margin-bottom: 20px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  overflow: hidden;
}

.info-panel,
.log-panel {
  background: white;
  padding: 16px;
  border-radius: 4px;
  margin-bottom: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.info-panel h3,
.log-panel h3 {
  margin: 0 0 12px 0;
  color: #333;
  font-size: 16px;
}

.info-panel p {
  margin: 8px 0;
  color: #666;
}

.log-list {
  max-height: 200px;
  overflow-y: auto;
}

.log-item {
  padding: 8px;
  border-bottom: 1px solid #f0f0f0;
  display: flex;
  gap: 10px;
  font-size: 12px;
}

.log-item:last-child {
  border-bottom: none;
}

.log-time {
  color: #999;
  min-width: 60px;
}

.log-type {
  color: #1890ff;
  font-weight: 500;
  min-width: 60px;
}

.log-message {
  color: #333;
}
</style>