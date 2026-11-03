<template>
  <div
    class="workflow-node task-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      {{ taskIcon }}
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '任务' }}
    </div>

    <!-- 任务类型标签 -->
    <div v-if="taskType" class="task-type-badge" :class="`type-${taskType}`">
      {{ taskTypeText }}
    </div>

    <!-- 分配人信息 -->
    <div v-if="assignee" class="assignee-info">
      👤 {{ assigneeName || assignee }}
    </div>

    <!-- 优先级指示 -->
    <div v-if="priority" class="priority-indicator" :class="`priority-${priorityLevel}`">
      {{ priority }}
    </div>

    <!-- 节点属性 -->
    <div v-if="nodeData.properties" class="node-properties">
      <span v-if="nodeData.properties.description" class="node-description">
        {{ nodeData.properties.description }}
      </span>
    </div>

    <!-- 锚点 -->
    <div
      v-for="anchor in anchors"
      :key="anchor.id"
      class="node-anchor"
      :class="`anchor-${anchor.position}`"
      :style="getAnchorStyle(anchor)"
      @mousedown.stop="handleAnchorMouseDown(anchor, $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { NodeComponentProps } from '../../types/vue'

// Props定义
const props = defineProps<NodeComponentProps>()

// Emits定义
const emit = defineEmits<{
  select: [id: string]
  update: [id: string, data: any]
  delete: [id: string]
  contextmenu: [id: string, event: MouseEvent]
}>()

// 节点数据
const nodeData = computed(() => props.data || {})

// 任务类型
const taskType = computed(() => {
  return nodeData.value.properties?.taskType || 'user_task'
})

// 任务图标
const taskIcon = computed(() => {
  const iconMap = {
    user_task: '👤',
    system_task: '⚙️',
    service_task: '🔧',
    approval: '✅',
    countersign: '👥',
    notify: '📢'
  }
  return iconMap[taskType.value] || '📋'
})

// 任务类型文本
const taskTypeText = computed(() => {
  const typeMap = {
    user_task: '用户任务',
    system_task: '系统任务',
    service_task: '服务任务',
    approval: '审批',
    countersign: '会签',
    notify: '通知'
  }
  return typeMap[taskType.value] || '任务'
})

// 分配人
const assignee = computed(() => {
  return nodeData.value.properties?.assignee
})

const assigneeName = computed(() => {
  return nodeData.value.properties?.assigneeName
})

// 优先级
const priority = computed(() => {
  return nodeData.value.properties?.priority
})

const priorityLevel = computed(() => {
  if (!priority.value) return 'normal'
  if (priority.value >= 80) return 'high'
  if (priority.value >= 50) return 'medium'
  return 'normal'
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '120px',
    height: props.height ? `${props.height}px` : '80px',
    left: `${props.x - (props.width || 120) / 2}px`,
    top: `${props.y - (props.height || 80) / 2}px`,
    background: selected.value ? '#e6f7ff' : '#f0f5ff',
    border: selected.value ? '2px solid #1890ff' : '2px solid #1890ff',
    borderRadius: '6px'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置
const anchors = computed(() => [
  {
    id: 'left',
    position: 'left',
    x: props.x - (props.width || 120) / 2,
    y: props.y
  },
  {
    id: 'right',
    position: 'right',
    x: props.x + (props.width || 120) / 2,
    y: props.y
  },
  {
    id: 'top',
    position: 'top',
    x: props.x,
    y: props.y - (props.height || 80) / 2
  },
  {
    id: 'bottom',
    position: 'bottom',
    x: props.x,
    y: props.y + (props.height || 80) / 2
  }
])

// 获取锚点样式
const getAnchorStyle = (anchor: any) => {
  return {
    left: `${anchor.x - 4}px`,
    top: `${anchor.y - 4}px`,
    width: '8px',
    height: '8px'
  }
}

// 事件处理
const handleClick = (event: MouseEvent) => {
  event.stopPropagation()
  emit('select', props.id)
}

const handleContextMenu = (event: MouseEvent) => {
  event.preventDefault()
  event.stopPropagation()
  emit('contextmenu', props.id, event)
}

const handleAnchorMouseDown = (anchor: any, event: MouseEvent) => {
  event.stopPropagation()
  // LogicFlow会处理连线逻辑
}
</script>

<style scoped>
.workflow-node {
  position: absolute;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  user-select: none;
  font-size: 12px;
  color: #333;
  padding: 8px;
  box-sizing: border-box;
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-1px);
}

.workflow-node.node-selected {
  box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.2);
}

.node-icon {
  font-size: 16px;
  margin-bottom: 4px;
  line-height: 1;
}

.node-text {
  font-weight: 600;
  text-align: center;
  line-height: 1.2;
  color: #1890ff;
  font-size: 12px;
  margin-bottom: 4px;
}

.task-type-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  padding: 2px 6px;
  border-radius: 10px;
  font-size: 8px;
  font-weight: bold;
  color: white;
  white-space: nowrap;
}

.task-type-badge.type-user_task {
  background: #1890ff;
}

.task-type-badge.type-system_task {
  background: #52c41a;
}

.task-type-badge.type-service_task {
  background: #722ed1;
}

.task-type-badge.type-approval {
  background: #fa8c16;
}

.task-type-badge.type-countersign {
  background: #eb2f96;
}

.task-type-badge.type-notify {
  background: #13c2c2;
}

.assignee-info {
  font-size: 10px;
  color: #666;
  margin-top: 2px;
  text-align: center;
}

.priority-indicator {
  position: absolute;
  bottom: -6px;
  left: -6px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 8px;
  font-weight: bold;
  color: white;
}

.priority-indicator.priority-high {
  background: #ff4d4f;
}

.priority-indicator.priority-medium {
  background: #faad14;
}

.priority-indicator.priority-normal {
  background: #52c41a;
}

.node-properties {
  margin-top: 4px;
  font-size: 10px;
  color: #666;
  text-align: center;
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.node-description {
  opacity: 0.8;
}

.node-anchor {
  position: absolute;
  background: #1890ff;
  border: 2px solid #fff;
  border-radius: 50%;
  cursor: crosshair;
  z-index: 10;
  transition: all 0.2s ease;
}

.node-anchor:hover {
  width: 12px !important;
  height: 12px !important;
  left: -2px !important;
  top: -2px !important;
  background: #40a9ff;
  box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.3);
}

.anchor-left {
  left: -6px;
}

.anchor-right {
  right: -6px;
}

.anchor-top {
  top: -6px;
}

.anchor-bottom {
  bottom: -6px;
}
</style>