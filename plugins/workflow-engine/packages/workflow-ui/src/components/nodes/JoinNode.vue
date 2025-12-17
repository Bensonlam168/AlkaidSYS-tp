<template>
  <div
    class="workflow-node join-node"
    :class="{ 'node-selected': selected }"
    :style="nodeStyle"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 节点图标 -->
    <div class="node-icon">
      🔗
    </div>

    <!-- 节点文本 -->
    <div class="node-text">
      {{ nodeData.text || '汇聚' }}
    </div>

    <!-- 汇聚类型 -->
    <div v-if="joinType" class="join-type-badge" :class="`type-${joinType}`">
      {{ joinTypeText }}
    </div>

    <!-- 输入分支数量 -->
    <div v-if="inputCount > 0" class="input-count">
      {{ inputCount }} 个输入
    </div>

    <!-- 汇聚模式指示 -->
    <div class="join-mode-indicator">
      {{ joinType === 'all' ? '全部完成' : '任一完成' }}
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

// 汇聚类型
const joinType = computed(() => {
  return nodeData.value.properties?.joinType || 'all'
})

// 汇聚类型文本
const joinTypeText = computed(() => {
  const typeMap = {
    all: '全部汇聚',
    any: '任一汇聚'
  }
  return typeMap[joinType.value] || '全部汇聚'
})

// 输入分支数量（估算）
const inputCount = computed(() => {
  // 这里可以根据实际情况计算输入连接的数量
  // 暂时返回1作为默认值
  return 1
})

// 节点样式
const nodeStyle = computed(() => {
  const baseStyle = {
    width: props.width ? `${props.width}px` : '100px',
    height: props.height ? `${props.height}px` : '100px',
    left: `${props.x - (props.width || 100) / 2}px`,
    top: `${props.y - (props.height || 100) / 2}px`,
    background: selected.value ? '#fff0f6' : '#fff0f6',
    border: selected.value ? '2px solid #eb2f96' : '2px solid #eb2f96',
    borderRadius: '50%'
  }

  return baseStyle
})

// 选中状态
const selected = computed(() => props.selected === true)

// 锚点配置（汇聚节点：多个输入，1个输出）
const anchors = computed(() => [
  {
    id: 'top-left',
    position: 'top-left',
    x: props.x - 30,
    y: props.y - (props.height || 100) / 2
  },
  {
    id: 'top-right',
    position: 'top-right',
    x: props.x + 30,
    y: props.y - (props.height || 100) / 2
  },
  {
    id: 'left',
    position: 'left',
    x: props.x - (props.width || 100) / 2,
    y: props.y
  },
  {
    id: 'bottom',
    position: 'bottom',
    x: props.x,
    y: props.y + (props.height || 100) / 2
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
  box-shadow: 0 0 0 3px rgba(235, 47, 150, 0.2);
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
  color: #eb2f96;
  font-size: 12px;
  margin-bottom: 4px;
}

.join-type-badge {
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

.join-type-badge.type-all {
  background: #eb2f96;
}

.join-type-badge.type-any {
  background: #fa8c16;
}

.input-count {
  position: absolute;
  bottom: -6px;
  left: -6px;
  background: #eb2f96;
  color: white;
  font-size: 8px;
  padding: 2px 6px;
  border-radius: 10px;
  font-weight: bold;
}

.join-mode-indicator {
  font-size: 9px;
  color: #eb2f96;
  opacity: 0.8;
  font-weight: 500;
  text-align: center;
  max-width: 60px;
  line-height: 1.2;
}

.node-properties {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
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
  background: #eb2f96;
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
  box-shadow: 0 0 0 3px rgba(235, 47, 150, 0.3);
}

.anchor-top-left {
  top: -6px;
  left: 20%;
}

.anchor-top-right {
  top: -6px;
  right: 20%;
}

.anchor-left {
  left: -6px;
}

.anchor-bottom {
  bottom: -6px;
  left: 50%;
  transform: translateX(-50%);
}
</style>