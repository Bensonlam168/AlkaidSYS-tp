<template>
  <div
    class="workflow-node decision-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      🔀
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '决策' }}
    </div>

    <!-- 条件表达式 -->
    <div v-if="condition" class="condition-expression">
      <span class="expression-label">条件:</span>
      <span class="expression-value">{{ displayCondition }}</span>
    </div>

    <!-- 默认分支指示 -->
    <div v-if="isDefault" class="default-indicator">
      默认
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

// 条件表达式
const condition = computed(() => {
  return nodeData.value.properties?.condition
})

// 是否为默认分支
const isDefault = computed(() => {
  return nodeData.value.properties?.default === true
})

// 显示的条件表达式（简化显示）
const displayCondition = computed(() => {
  if (!condition.value) return ''
  const conditionStr = String(condition.value)
  // 如果条件太长，截断显示
  if (conditionStr.length > 20) {
    return conditionStr.substring(0, 20) + '...'
  }
  return conditionStr
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '100px',
    height: props.height ? `${props.height}px` : '100px',
    left: `${props.x - (props.width || 100) / 2}px`,
    top: `${props.y - (props.height || 100) / 2}px`,
    background: selected.value ? '#fff7e6' : '#fffbe6',
    border: selected.value ? '2px solid #fa8c16' : '2px solid #fa8c16',
    borderRadius: '0',
    transform: 'rotate(45deg)'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置（决策节点通常有4个锚点）
const anchors = computed(() => [
  {
    id: 'top',
    position: 'top',
    x: props.x,
    y: props.y - (props.height || 100) / 2
  },
  {
    id: 'right',
    position: 'right',
    x: props.x + (props.width || 100) / 2,
    y: props.y
  },
  {
    id: 'bottom',
    position: 'bottom',
    x: props.x,
    y: props.y + (props.height || 100) / 2
  },
  {
    id: 'left',
    position: 'left',
    x: props.x - (props.width || 100) / 2,
    y: props.y
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
  padding: 16px;
  box-sizing: border-box;
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: rotate(45deg) translateY(-1px);
}

.workflow-node.node-selected {
  box-shadow: 0 0 0 3px rgba(250, 140, 22, 0.2);
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.node-icon {
  font-size: 16px;
  margin-bottom: 4px;
  line-height: 1;
  transform: rotate(-45deg);
}

.node-text {
  font-weight: 600;
  text-align: center;
  line-height: 1.2;
  color: #fa8c16;
  font-size: 12px;
  margin-bottom: 4px;
  transform: rotate(-45deg);
}

.condition-expression {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 4px;
  transform: rotate(-45deg);
  max-width: 80px;
}

.expression-label {
  font-size: 9px;
  color: #666;
  margin-bottom: 2px;
}

.expression-value {
  font-size: 8px;
  color: #999;
  text-align: center;
  word-break: break-all;
  max-height: 20px;
  overflow: hidden;
  line-height: 1.2;
}

.default-indicator {
  position: absolute;
  top: -8px;
  right: -8px;
  background: #fa8c16;
  color: white;
  font-size: 8px;
  padding: 2px 4px;
  border-radius: 8px;
  font-weight: bold;
  transform: rotate(-45deg);
}

.node-properties {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
  transform: rotate(-45deg);
  text-align: center;
}

.node-description {
  font-size: 8px;
  color: #666;
  opacity: 0.8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.node-anchor {
  position: absolute;
  background: #fa8c16;
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
  background: #ffc53d;
  box-shadow: 0 0 0 3px rgba(250, 140, 22, 0.3);
}

.anchor-top {
  top: -6px;
}

.anchor-right {
  right: -6px;
}

.anchor-bottom {
  bottom: -6px;
}

.anchor-left {
  left: -6px;
}
</style>