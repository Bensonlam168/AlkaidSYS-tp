<template>
  <div
    class="workflow-node custom-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      {{ customIcon }}
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || componentName || '自定义' }}
    </div>

    <!-- 组件名称 -->
    <div v-if="componentName" class="component-name">
      {{ componentName }}
    </div>

    <!-- 自定义配置指示 -->
    <div v-if="config && Object.keys(config).length > 0" class="config-indicator">
      <span class="config-icon">⚙️</span>
      <span class="config-count">{{ Object.keys(config).length }}</span>
    </div>

    <!-- 自定义属性展示 -->
    <div v-if="customProperties && Object.keys(customProperties).length > 0" class="custom-properties">
      <div
        v-for="(value, key) in visibleCustomProperties"
        :key="key"
        class="property-item"
      >
        <span class="property-key">{{ key }}:</span>
        <span class="property-value">{{ formatPropertyValue(value) }}</span>
      </div>
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

// 组件名称
const componentName = computed(() => {
  return nodeData.value.properties?.componentName
})

// 自定义配置
const config = computed(() => {
  return nodeData.value.properties?.config || {}
})

// 自定义属性
const customProperties = computed(() => {
  return nodeData.value.properties?.customProperties || {}
})

// 自定义图标
const customIcon = computed(() => {
  return nodeData.value.properties?.icon || '🔧'
})

// 可见的自定义属性（最多显示3个）
const visibleCustomProperties = computed(() => {
  const props = customProperties.value
  const keys = Object.keys(props)
  return keys.slice(0, 3).reduce((result, key) => {
    result[key] = props[key]
    return result
  }, {} as Record<string, any>)
})

// 格式化属性值
const formatPropertyValue = (value: any) => {
  if (typeof value === 'string' && value.length > 10) {
    return value.substring(0, 10) + '...'
  }
  return String(value)
}

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '120px',
    height: props.height ? `${props.height}px` : '80px',
    left: `${props.x - (props.width || 120) / 2}px`,
    top: `${props.y - (props.height || 80) / 2}px`,
    background: selected.value ? '#f0f5ff' : '#f6f9ff',
    border: selected.value ? '2px solid #531dab' : '2px solid #531dab',
    borderRadius: '8px',
    borderStyle: 'dashed'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置（自定义节点：灵活配置，默认4个锚点）
const anchors = computed(() => [
  {
    id: 'top',
    position: 'top',
    x: props.x,
    y: props.y - (props.height || 80) / 2
  },
  {
    id: 'right',
    position: 'right',
    x: props.x + (props.width || 120) / 2,
    y: props.y
  },
  {
    id: 'bottom',
    position: 'bottom',
    x: props.x,
    y: props.y + (props.height || 80) / 2
  },
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
  padding: 8px;
  box-sizing: border-box;
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-1px);
}

.workflow-node.node-selected {
  box-shadow: 0 0 0 3px rgba(83, 29, 171, 0.2);
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
  color: #531dab;
  font-size: 12px;
  margin-bottom: 4px;
}

.component-name {
  position: absolute;
  top: -8px;
  right: -8px;
  background: #531dab;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
  max-width: 60px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.config-indicator {
  position: absolute;
  bottom: -8px;
  left: -8px;
  display: flex;
  align-items: center;
  gap: 2px;
  background: #531dab;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.config-icon {
  font-size: 8px;
}

.config-count {
  font-size: 8px;
}

.custom-properties {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 4px;
  max-height: 30px;
  overflow: hidden;
}

.property-item {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: 8px;
  color: #666;
  white-space: nowrap;
}

.property-key {
  font-weight: 500;
  color: #531dab;
}

.property-value {
  color: #999;
  max-width: 50px;
  overflow: hidden;
  text-overflow: ellipsis;
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
  background: #531dab;
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
  background: #722ed1;
  box-shadow: 0 0 0 3px rgba(83, 29, 171, 0.3);
}

.anchor-top {
  top: -6px;
  left: 50%;
  transform: translateX(-50%);
}

.anchor-right {
  right: -6px;
}

.anchor-bottom {
  bottom: -6px;
  left: 50%;
  transform: translateX(-50%);
}

.anchor-left {
  left: -6px;
}
</style>