<template>
  <div
    class="workflow-node end-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      🏁
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '结束' }}
    </div>

    <!-- 结束类型标识 -->
    <div v-if="endType" class="end-type-indicator" :class="`type-${endType}`">
      {{ endTypeText }}
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

// 结束类型
const endType = computed(() => {
  return nodeData.value.properties?.endType || 'normal'
})

// 结束类型文本
const endTypeText = computed(() => {
  const typeMap = {
    normal: '正常',
    error: '错误',
    cancel: '取消'
  }
  return typeMap[endType.value] || '正常'
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '120px',
    height: props.height ? `${props.height}px` : '60px',
    left: `${props.x - (props.width || 120) / 2}px`,
    top: `${props.y - (props.height || 60) / 2}px`,
    background: selected.value ? '#fff2f0' : '#fff1f0',
    border: selected.value ? '2px solid #ff4d4f' : '2px solid #ff4d4f',
    borderRadius: '30px'
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
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-1px);
}

.workflow-node.node-selected {
  box-shadow: 0 0 0 3px rgba(255, 77, 79, 0.2);
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
  color: #ff4d4f;
  font-size: 13px;
}

.end-type-indicator {
  position: absolute;
  top: -8px;
  right: -8px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 8px;
  font-weight: bold;
  color: white;
}

.end-type-indicator.type-normal {
  background: #52c41a;
}

.end-type-indicator.type-error {
  background: #ff4d4f;
}

.end-type-indicator.type-cancel {
  background: #faad14;
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
  background: #ff4d4f;
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
  background: #ff7875;
  box-shadow: 0 0 0 3px rgba(255, 77, 79, 0.3);
}

.anchor-left {
  left: -6px;
}
</style>