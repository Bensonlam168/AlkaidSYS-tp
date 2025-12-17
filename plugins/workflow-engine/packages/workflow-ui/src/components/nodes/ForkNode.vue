<template>
  <div
    class="workflow-node fork-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      🌿
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '分支' }}
    </div>

    <!-- 分支数量指示 -->
    <div v-if="branchCount > 0" class="branch-count">
      {{ branchCount }} 条分支
    </div>

    <!-- 分支列表 -->
    <div v-if="branches && branches.length > 0" class="branches-list">
      <div
        v-for="(branch, index) in visibleBranches"
        :key="branch.id || index"
        class="branch-item"
      >
        <span class="branch-icon">→</span>
        <span class="branch-name">{{ branch.name || `分支${index + 1}` }}</span>
        <span v-if="branch.condition" class="branch-condition">
          {{ branch.condition }}
        </span>
      </div>
    </div>

    <!-- 并行执行指示 -->
    <div class="parallel-indicator">
      并行执行
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

// 分支列表
const branches = computed(() => {
  return nodeData.value.properties?.branches || []
})

// 分支数量
const branchCount = computed(() => {
  return branches.value.length
})

// 可见的分支（最多显示3个）
const visibleBranches = computed(() => {
  return branches.value.slice(0, 3)
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '120px',
    height: props.height ? `${props.height}px` : '80px',
    left: `${props.x - (props.width || 120) / 2}px`,
    top: `${props.y - (props.height || 80) / 2}px`,
    background: selected.value ? '#f9f0ff' : '#f3f0ff',
    border: selected.value ? '2px solid #722ed1' : '2px solid #722ed1',
    borderRadius: '8px'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置（分支节点：1个输入，多个输出）
const anchors = computed(() => [
  {
    id: 'top',
    position: 'top',
    x: props.x,
    y: props.y - (props.height || 80) / 2
  },
  ...branches.value.slice(0, 3).map((branch, index) => ({
    id: `bottom-${index}`,
    position: 'bottom',
    x: props.x - 40 + (index * 40),
    y: props.y + (props.height || 80) / 2
  }))
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
  box-shadow: 0 0 0 3px rgba(114, 46, 209, 0.2);
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
  color: #722ed1;
  font-size: 12px;
  margin-bottom: 4px;
}

.branch-count {
  position: absolute;
  top: -6px;
  right: -6px;
  background: #722ed1;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.branches-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 4px;
  max-height: 40px;
  overflow: hidden;
}

.branch-item {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: 9px;
  color: #666;
  white-space: nowrap;
}

.branch-icon {
  color: #722ed1;
  font-weight: bold;
}

.branch-name {
  font-weight: 500;
  max-width: 50px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.branch-condition {
  font-size: 8px;
  color: #999;
  max-width: 40px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.parallel-indicator {
  font-size: 8px;
  color: #722ed1;
  opacity: 0.8;
  font-weight: 500;
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
  background: #722ed1;
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
  background: #9254de;
  box-shadow: 0 0 0 3px rgba(114, 46, 209, 0.3);
}

.anchor-top {
  top: -6px;
  left: 50%;
  transform: translateX(-50%);
}

.anchor-bottom {
  bottom: -6px;
}
</style>