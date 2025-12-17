<template>
  <div
    class="workflow-node sub-process-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      📦
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '子流程' }}
    </div>

    <!-- 子流程名称 -->
    <div v-if="subProcessName" class="sub-process-name">
      {{ subProcessName }}
    </div>

    <!-- 版本信息 -->
    <div v-if="version" class="version-badge">
      v{{ version }}
    </div>

    <!-- 参数传递指示 -->
    <div v-if="hasParameters" class="parameters-indicator">
      <span class="param-icon">📝</span>
      <span class="param-count">{{ parameterCount }}</span>
    </div>

    <!-- 变量继承指示 -->
    <div v-if="inheritVariables" class="inherit-indicator">
      继承变量
    </div>

    <!-- 子流程状态 -->
    <div v-if="subProcessStatus" class="status-badge" :class="`status-${subProcessStatus}`">
      {{ statusText }}
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

// 子流程ID
const subProcessId = computed(() => {
  return nodeData.value.properties?.subProcessId
})

// 子流程名称
const subProcessName = computed(() => {
  return nodeData.value.properties?.subProcessName || subProcessId.value
})

// 版本
const version = computed(() => {
  return nodeData.value.properties?.version
})

// 参数
const parameters = computed(() => {
  return nodeData.value.properties?.parameters || {}
})

// 参数数量
const parameterCount = computed(() => {
  return Object.keys(parameters.value).length
})

// 是否有参数
const hasParameters = computed(() => {
  return parameterCount.value > 0
})

// 是否继承变量
const inheritVariables = computed(() => {
  return nodeData.value.properties?.inheritVariables === true
})

// 子流程状态
const subProcessStatus = computed(() => {
  return nodeData.value.properties?.status || 'draft'
})

// 状态文本
const statusText = computed(() => {
  const statusMap = {
    draft: '草稿',
    published: '已发布',
    archived: '已归档',
    active: '激活'
  }
  return statusMap[subProcessStatus.value] || '未知'
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '140px',
    height: props.height ? `${props.height}px` : '90px',
    left: `${props.x - (props.width || 140) / 2}px`,
    top: `${props.y - (props.height || 90) / 2}px`,
    background: selected.value ? '#fff7e6' : '#fffbe6',
    border: selected.value ? '2px solid #fa8c16' : '2px solid #fa8c16',
    borderRadius: '8px',
    borderWidth: '3px'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置（子流程节点：输入输出各1个）
const anchors = computed(() => [
  {
    id: 'left',
    position: 'left',
    x: props.x - (props.width || 140) / 2,
    y: props.y
  },
  {
    id: 'right',
    position: 'right',
    x: props.x + (props.width || 140) / 2,
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
  background-image:
    linear-gradient(45deg, transparent 40%, rgba(250, 140, 22, 0.1) 40%, rgba(250, 140, 22, 0.1) 60%, transparent 60%);
  background-size: 8px 8px;
}

.workflow-node:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-1px);
}

.workflow-node.node-selected {
  box-shadow: 0 0 0 3px rgba(250, 140, 22, 0.2);
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
  color: #fa8c16;
  font-size: 12px;
  margin-bottom: 4px;
}

.sub-process-name {
  font-size: 10px;
  color: #666;
  text-align: center;
  margin-bottom: 4px;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.version-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  background: #fa8c16;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.parameters-indicator {
  position: absolute;
  bottom: -8px;
  left: -8px;
  display: flex;
  align-items: center;
  gap: 2px;
  background: #fa8c16;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.param-icon {
  font-size: 8px;
}

.param-count {
  font-size: 8px;
}

.inherit-indicator {
  position: absolute;
  top: -6px;
  left: -6px;
  background: #52c41a;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.status-badge {
  position: absolute;
  bottom: -8px;
  right: -8px;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
  color: white;
}

.status-badge.status-draft {
  background: #8c8c8c;
}

.status-badge.status-published {
  background: #52c41a;
}

.status-badge.status-archived {
  background: #ff4d4f;
}

.status-badge.status-active {
  background: #1890ff;
}

.node-properties {
  margin-top: 4px;
  font-size: 10px;
  color: #666;
  text-align: center;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.node-description {
  opacity: 0.8;
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

.anchor-left {
  left: -6px;
}

.anchor-right {
  right: -6px;
}
</style>