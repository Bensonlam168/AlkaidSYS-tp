<template>
  <g
    class="edge-renderer"
    :class="edgeClasses"
    @click="handleClick"
    @contextmenu="handleContextMenu"
  >
    <!-- 边的路径 -->
    <path
      ref="pathRef"
      :d="pathData"
      :stroke="edgeColor"
      :stroke-width="strokeWidth"
      :stroke-dasharray="dashArray"
      :marker-end="markerEnd"
      fill="none"
      class="edge-path"
    />

    <!-- 边文本标签 -->
    <g v-if="edgeData.text" class="edge-text-group">
      <rect
        :x="textBackground.x"
        :y="textBackground.y"
        :width="textBackground.width"
        :height="textBackground.height"
        :fill="textBackground.fill"
        :rx="textBackground.radius"
        class="text-background"
      />
      <text
        :x="textPosition.x"
        :y="textPosition.y"
        text-anchor="middle"
        dominant-baseline="middle"
        class="edge-text"
        :fill="textColor"
      >
        {{ edgeData.text }}
      </text>
    </g>

    <!-- 条件表达式显示 -->
    <g v-if="condition" class="condition-group">
      <rect
        :x="conditionBackground.x"
        :y="conditionBackground.y"
        :width="conditionBackground.width"
        :height="conditionBackground.height"
        fill="#fff"
        stroke="#d9d9d9"
        stroke-width="1"
        rx="4"
        class="condition-background"
      />
      <text
        :x="conditionPosition.x"
        :y="conditionPosition.y"
        text-anchor="middle"
        dominant-baseline="middle"
        class="condition-text"
        fill="#666"
      >
        {{ displayCondition }}
      </text>
    </g>

    <!-- 默认分支指示 -->
    <g v-if="isDefault" class="default-indicator">
      <circle
        :cx="defaultIndicator.x"
        :cy="defaultIndicator.y"
        r="8"
        fill="#52c41a"
        class="default-circle"
      />
      <text
        :x="defaultIndicator.x"
        :y="defaultIndicator.y"
        text-anchor="middle"
        dominant-baseline="middle"
        class="default-text"
        fill="white"
        font-size="8"
      >
        D
      </text>
    </g>

    <!-- 选中状态指示 -->
    <g v-if="selected" class="selection-overlay">
      <path
        :d="pathData"
        stroke="#1890ff"
        stroke-width="4"
        stroke-opacity="0.3"
        fill="none"
        class="selection-path"
      />
    </g>

    <!-- 悬停状态指示 -->
    <g v-if="hovered" class="hover-overlay">
      <path
        :d="pathData"
        stroke="#40a9ff"
        stroke-width="6"
        stroke-opacity="0.2"
        fill="none"
        class="hover-path"
      />
    </g>
  </g>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, nextTick } from 'vue'
import type { LFEdgeData } from '../../types/logicflow'

// Props定义
interface Props {
  data: LFEdgeData
  source?: any
  target?: any
  selected?: boolean
  hovered?: boolean
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  selected: false,
  hovered: false,
  readonly: false
})

// Emits定义
const emit = defineEmits<{
  click: [data: LFEdgeData]
  contextmenu: [data: LFEdgeData, event: MouseEvent]
  mouseenter: [data: LFEdgeData]
  mouseleave: [data: LFEdgeData]
}>()

// 引用
const pathRef = ref<SVGPathElement>()

// 边数据
const edgeData = computed(() => props.data || {})

// 边类型
const edgeType = computed(() => {
  return edgeData.value.type || 'line'
})

// 条件表达式
const condition = computed(() => {
  return edgeData.value.properties?.condition
})

// 是否为默认分支
const isDefault = computed(() => {
  return edgeData.value.properties?.default === true
})

// 边样式属性
const strokeWidth = computed(() => {
  if (props.selected) return 3
  if (props.hovered) return 3
  return 2
})

const dashArray = computed(() => {
  const edgeType = edgeData.value.properties?.type
  if (edgeType === 'dashed') return '5,5'
  if (edgeType === 'dotted') return '2,2'
  return ''
})

// 边颜色
const edgeColor = computed(() => {
  if (props.selected) return '#1890ff'
  if (props.hovered) return '#40a9ff'

  const status = edgeData.value.properties?.status
  const colorMap: Record<string, string> = {
    active: '#52c41a',
    pending: '#faad14',
    completed: '#52c41a',
    failed: '#ff4d4f',
    cancelled: '#8c8c8c'
  }
  return colorMap[status] || '#555555'
})

// 箭头标记
const markerEnd = computed(() => {
  return `url(#arrowhead-${edgeType.value})`
})

// 边类名
const edgeClasses = computed(() => [
  `edge-${edgeType.value}`,
  {
    'edge-selected': props.selected,
    'edge-hovered': props.hovered,
    'edge-readonly': props.readonly
  }
])

// 计算路径数据
const pathData = computed(() => {
  const { startPoint, endPoint } = edgeData.value

  if (!startPoint || !endPoint) {
    return ''
  }

  const x1 = startPoint.x
  const y1 = startPoint.y
  const x2 = endPoint.x
  const y2 = endPoint.y

  // 根据边类型生成不同的路径
  switch (edgeType.value) {
    case 'bezier':
      return generateBezierPath(x1, y1, x2, y2)
    case 'polyline':
      return generatePolylinePath(x1, y1, x2, y2)
    case 'step':
      return generateStepPath(x1, y1, x2, y2)
    default:
      return generateLinePath(x1, y1, x2, y2)
  }
})

// 生成直线路径
const generateLinePath = (x1: number, y1: number, x2: number, y2: number) => {
  return `M ${x1} ${y1} L ${x2} ${y2}`
}

// 生成贝塞尔曲线路径
const generateBezierPath = (x1: number, y1: number, x2: number, y2: number) => {
  const deltaX = Math.abs(x2 - x1)
  const deltaY = Math.abs(y2 - y1)
  const controlOffset = Math.min(deltaX, deltaY) * 0.5

  const cp1x = x1 + controlOffset
  const cp1y = y1
  const cp2x = x2 - controlOffset
  const cp2y = y2

  return `M ${x1} ${y1} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${x2} ${y2}`
}

// 生成折线路径
const generatePolylinePath = (x1: number, y1: number, x2: number, y2: number) => {
  const midX = (x1 + x2) / 2
  return `M ${x1} ${y1} L ${midX} ${y1} L ${midX} ${y2} L ${x2} ${y2}`
}

// 生成阶梯路径
const generateStepPath = (x1: number, y1: number, x2: number, y2: number) => {
  const midX = (x1 + x2) / 2
  return `M ${x1} ${y1} L ${midX} ${y1} L ${midX} ${y2} L ${x2} ${y2}`
}

// 文本位置
const textPosition = computed(() => {
  const { startPoint, endPoint } = edgeData.value
  if (!startPoint || !endPoint) {
    return { x: 0, y: 0 }
  }

  const midX = (startPoint.x + endPoint.x) / 2
  const midY = (startPoint.y + endPoint.y) / 2

  return {
    x: midX,
    y: midY - 10
  }
})

// 文本背景
const textBackground = computed(() => {
  const text = edgeData.value.text || ''
  const position = textPosition.value

  const width = Math.max(40, text.length * 8 + 16)
  const height = 20

  return {
    x: position.x - width / 2,
    y: position.y - height / 2,
    width,
    height,
    fill: 'white',
    radius: 4
  }
})

// 文本颜色
const textColor = computed(() => {
  return '#333333'
})

// 条件显示文本
const displayCondition = computed(() => {
  if (!condition.value) return ''
  const conditionStr = String(condition.value)
  if (conditionStr.length > 15) {
    return conditionStr.substring(0, 15) + '...'
  }
  return conditionStr
})

// 条件位置
const conditionPosition = computed(() => {
  const { startPoint, endPoint } = edgeData.value
  if (!startPoint || !endPoint) {
    return { x: 0, y: 0 }
  }

  const quarterX = startPoint.x + (endPoint.x - startPoint.x) * 0.25
  const quarterY = startPoint.y + (endPoint.y - startPoint.y) * 0.25

  return {
    x: quarterX,
    y: quarterY - 15
  }
})

// 条件背景
const conditionBackground = computed(() => {
  const condition = displayCondition.value
  const position = conditionPosition.value

  const width = Math.max(30, condition.length * 6 + 12)
  const height = 16

  return {
    x: position.x - width / 2,
    y: position.y - height / 2,
    width,
    height
  }
})

// 默认指示器位置
const defaultIndicator = computed(() => {
  const { startPoint, endPoint } = edgeData.value
  if (!startPoint || !endPoint) {
    return { x: 0, y: 0 }
  }

  const threeQuarterX = startPoint.x + (endPoint.x - startPoint.x) * 0.75
  const threeQuarterY = startPoint.y + (endPoint.y - startPoint.y) * 0.75

  return {
    x: threeQuarterX,
    y: threeQuarterY - 15
  }
})

// 事件处理
const handleClick = (event: MouseEvent) => {
  event.stopPropagation()
  emit('click', edgeData.value)
}

const handleContextMenu = (event: MouseEvent) => {
  event.preventDefault()
  event.stopPropagation()
  emit('contextmenu', edgeData.value, event)
}

const handleMouseEnter = () => {
  emit('mouseenter', edgeData.value)
}

const handleMouseLeave = () => {
  emit('mouseleave', edgeData.value)
}

// 创建箭头标记
onMounted(async () => {
  await nextTick()
  // 这里可以创建自定义的箭头标记
})
</script>

<style scoped>
.edge-renderer {
  cursor: pointer;
  transition: all 0.2s ease;
}

.edge-renderer.edge-readonly {
  cursor: default;
}

.edge-path {
  transition: all 0.2s ease;
}

.edge-renderer:hover .edge-path {
  stroke-width: 3;
}

.edge-text-group {
  pointer-events: none;
}

.text-background {
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.1));
}

.edge-text {
  font-size: 12px;
  font-weight: 500;
  pointer-events: none;
}

.condition-group {
  pointer-events: none;
}

.condition-background {
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.1));
}

.condition-text {
  font-size: 10px;
  pointer-events: none;
}

.default-indicator {
  pointer-events: none;
}

.default-circle {
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));
}

.default-text {
  font-weight: bold;
  pointer-events: none;
}

.selection-overlay {
  pointer-events: none;
}

.selection-path {
  animation: selectionPulse 1s ease-in-out infinite;
}

.hover-overlay {
  pointer-events: none;
}

.hover-path {
  animation: hoverGlow 1s ease-in-out infinite;
}

/* 动画 */
@keyframes selectionPulse {
  0%, 100% {
    stroke-opacity: 0.3;
  }
  50% {
    stroke-opacity: 0.6;
  }
}

@keyframes hoverGlow {
  0%, 100% {
    stroke-opacity: 0.2;
  }
  50% {
    stroke-opacity: 0.4;
  }
}

/* 不同边类型的样式 */
.edge-line .edge-path {
  stroke-linecap: round;
  stroke-linejoin: round;
}

.edge-bezier .edge-path {
  stroke-linecap: round;
  stroke-linejoin: round;
}

.edge-polyline .edge-path {
  stroke-linecap: square;
  stroke-linejoin: bevel;
}

.edge-step .edge-path {
  stroke-linecap: square;
  stroke-linejoin: miter;
}
</style>