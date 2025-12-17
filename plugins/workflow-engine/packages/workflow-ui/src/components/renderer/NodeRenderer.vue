<template>
  <div
    class="node-renderer"
    :class="`node-${nodeType}`"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点内容渲染 -->
    <div class="node-content">
      <!-- 默认渲染 -->
      <template v-if="!hasCustomRender">
        <div class="node-icon">
          {{ defaultIcon }}
        </div>
        <div class="node-text">
          {{ nodeData.text || nodeType }}
        </div>
      </template>

      <!-- 自定义渲染 -->
      <slot name="custom" :data="nodeData" :selected="selected">
        <div class="node-icon">
          {{ defaultIcon }}
        </div>
        <div class="node-text">
          {{ nodeData.text || nodeType }}
        </div>
      </slot>
    </div>

    <!-- 状态指示器 -->
    <div v-if="status" class="status-indicator" :class="`status-${status}`">
      <span class="status-icon">{{ statusIcon }}</span>
    </div>

    <!-- 错误指示器 -->
    <div v-if="hasError" class="error-indicator">
      ⚠️
    </div>

    <!-- 选中状态指示 -->
    <div v-if="selected" class="selection-indicator">
      <div class="selection-border"></div>
    </div>

    <!-- 拖拽手柄 -->
    <div
      v-if="draggable"
      class="drag-handle"
      @mousedown="handleDragStart"
    >
      ⋮⋮
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { LFNodeData } from '../../types/logicflow'

// Props定义
interface Props {
  data: LFNodeData
  selected?: boolean
  draggable?: boolean
  customRender?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  selected: false,
  draggable: true,
  customRender: false
})

// Emits定义
const emit = defineEmits<{
  click: [data: LFNodeData]
  contextmenu: [data: LFNodeData, event: MouseEvent]
  dragstart: [data: LFNodeData]
}>()

// 节点数据
const nodeData = computed(() => props.data || {})

// 节点类型
const nodeType = computed(() => {
  return nodeData.value.type || 'default'
})

// 是否有自定义渲染
const hasCustomRender = computed(() => {
  return props.customRender || nodeData.value.customRender
})

// 状态
const status = computed(() => {
  return nodeData.value.status || 'normal'
})

// 是否有错误
const hasError = computed(() => {
  return nodeData.value.error || false
})

// 默认图标
const defaultIcon = computed(() => {
  const iconMap: Record<string, string> = {
    start: '🚀',
    end: '🏁',
    task: '📋',
    decision: '🔀',
    fork: '🌿',
    join: '🔗',
    custom: '🔧',
    sub_process: '📦'
  }
  return iconMap[nodeType.value] || '📄'
})

// 状态图标
const statusIcon = computed(() => {
  const iconMap: Record<string, string> = {
    normal: '✓',
    running: '⚡',
    completed: '✅',
    failed: '❌',
    pending: '⏳',
    cancelled: '⏹️'
  }
  return iconMap[status.value] || '✓'
})

// 节点样式
const nodeStyle = computed(() => {
  const style = {
    left: `${nodeData.value.x - 60}px`,
    top: `${nodeData.value.y - 40}px`,
    width: `${nodeData.value.width || 120}px`,
    height: `${nodeData.value.height || 80}px`,
    zIndex: selected.value ? 1000 : 1
  }

  // 根据节点类型应用不同样式
  const typeStyles: Record<string, any> = {
    start: {
      background: '#f6ffed',
      border: '2px solid #52c41a',
      borderRadius: '30px'
    },
    end: {
      background: '#fff1f0',
      border: '2px solid #ff4d4f',
      borderRadius: '30px'
    },
    task: {
      background: '#f0f5ff',
      border: '2px solid #1890ff',
      borderRadius: '6px'
    },
    decision: {
      background: '#fffbe6',
      border: '2px solid #fa8c16',
      borderRadius: '0',
      transform: 'rotate(45deg)'
    },
    fork: {
      background: '#f3f0ff',
      border: '2px solid #722ed1',
      borderRadius: '8px'
    },
    join: {
      background: '#fff0f6',
      border: '2px solid #eb2f96',
      borderRadius: '50%'
    },
    custom: {
      background: '#f6f9ff',
      border: '2px dashed #531dab',
      borderRadius: '8px'
    },
    sub_process: {
      background: '#fffbe6',
      border: '3px solid #fa8c16',
      borderRadius: '8px'
    }
  }

  const nodeStyle = typeStyles[nodeType.value] || {
    background: '#f5f5f5',
    border: '1px solid #d9d9d9',
    borderRadius: '4px'
  }

  // 选中状态样式
  if (selected.value) {
    nodeStyle.boxShadow = '0 0 0 3px rgba(24, 144, 255, 0.2)'
  }

  // 错误状态样式
  if (hasError.value) {
    nodeStyle.border = '2px solid #ff4d4f'
    nodeStyle.animation = 'shake 0.5s ease-in-out'
  }

  return {
    ...style,
    ...nodeStyle
  }
})

// 事件处理
const handleClick = (event: MouseEvent) => {
  event.stopPropagation()
  emit('click', nodeData.value)
}

const handleContextMenu = (event: MouseEvent) => {
  event.preventDefault()
  event.stopPropagation()
  emit('contextmenu', nodeData.value, event)
}

const handleDragStart = (event: MouseEvent) => {
  event.stopPropagation()
  emit('dragstart', nodeData.value)
}
</script>

<style scoped>
.node-renderer {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  user-select: none;
  font-size: 12px;
  color: #333;
  overflow: hidden;
}

.node-renderer:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-1px);
}

.node-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  padding: 8px;
  box-sizing: border-box;
}

.node-icon {
  font-size: 18px;
  margin-bottom: 4px;
  line-height: 1;
}

.node-text {
  font-weight: 600;
  text-align: center;
  line-height: 1.2;
  color: #333;
  font-size: 12px;
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-indicator {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: bold;
  color: white;
}

.status-indicator.status-normal {
  background: #52c41a;
}

.status-indicator.status-running {
  background: #1890ff;
  animation: pulse 1s infinite;
}

.status-indicator.status-completed {
  background: #52c41a;
}

.status-indicator.status-failed {
  background: #ff4d4f;
}

.status-indicator.status-pending {
  background: #faad14;
}

.status-indicator.status-cancelled {
  background: #8c8c8c;
}

.error-indicator {
  position: absolute;
  bottom: -6px;
  right: -6px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ff4d4f;
  color: white;
  font-size: 10px;
  animation: pulse 1s infinite;
}

.selection-indicator {
  position: absolute;
  top: -4px;
  left: -4px;
  right: -4px;
  bottom: -4px;
  pointer-events: none;
}

.selection-border {
  width: 100%;
  height: 100%;
  border: 2px solid #1890ff;
  border-radius: inherit;
  animation: selectionGlow 1s ease-in-out infinite;
}

.drag-handle {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: move;
  color: #999;
  font-size: 12px;
  opacity: 0;
  transition: opacity 0.2s;
  background: rgba(255, 255, 255, 0.8);
  border-radius: 2px;
}

.node-renderer:hover .drag-handle {
  opacity: 1;
}

.drag-handle:hover {
  color: #666;
  background: rgba(255, 255, 255, 1);
}

/* 动画 */
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-2px); }
  75% { transform: translateX(2px); }
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

@keyframes selectionGlow {
  0%, 100% { box-shadow: 0 0 0 0 rgba(24, 144, 255, 0.4); }
  50% { box-shadow: 0 0 0 4px rgba(24, 144, 255, 0.1); }
}

/* 响应式 */
@media (max-width: 768px) {
  .node-renderer {
    font-size: 10px;
  }

  .node-icon {
    font-size: 14px;
  }

  .node-text {
    font-size: 10px;
    max-width: 80px;
  }
}
</style>