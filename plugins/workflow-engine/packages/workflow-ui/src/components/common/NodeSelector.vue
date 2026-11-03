<template>
  <div class="node-selector">
    <div class="selector-header">
      <h3 class="selector-title">节点组件</h3>
      <button class="collapse-button" @click="collapsed = !collapsed">
        {{ collapsed ? '▶' : '▼' }}
      </button>
    </div>

    <div class="selector-content" v-if="!collapsed">
      <!-- 搜索框 -->
      <div class="search-box" v-if="searchable">
        <input
          type="text"
          v-model="searchKeyword"
          placeholder="搜索节点..."
          class="search-input"
        />
      </div>

      <!-- 节点列表 -->
      <div class="node-list">
        <div
          v-for="group in filteredGroups"
          :key="group.name"
          class="node-group"
        >
          <h4 class="group-title">{{ group.name }}</h4>
          <div class="group-nodes">
            <div
              v-for="node in group.nodes"
              :key="node.type"
              class="node-item"
              :class="{ selected: selectedNodes.includes(node.type) }"
              :title="node.description"
              @click="handleNodeClick(node)"
            >
              <span class="node-icon">{{ node.icon }}</span>
              <span class="node-name">{{ node.name }}</span>
            </div>
          </div>
        </div>

        <!-- 空状态 -->
        <div v-if="filteredGroups.length === 0" class="empty-state">
          <p>未找到匹配的节点</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { NodeSelectorProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<NodeSelectorProps>(), {
  searchable: true,
  multiple: false,
  selected: () => []
})

// Emits定义
const emit = defineEmits<{
  select: [types: string[]]
  'item-click': [type: string]
}>()

// 响应式数据
const collapsed = ref(false)
const searchKeyword = ref('')
const selectedNodes = ref<string[]>(props.selected)

// 过滤后的节点分组
const filteredGroups = computed(() => {
  if (!props.nodeTypes) return []

  // 按分类分组
  const groups: Record<string, any[]> = {}
  const allNodes = props.nodeTypes.filter(node => {
    if (!searchKeyword.value) return true
    const keyword = searchKeyword.value.toLowerCase()
    return (
      node.name.toLowerCase().includes(keyword) ||
      node.type.toLowerCase().includes(keyword) ||
      node.description?.toLowerCase().includes(keyword)
    )
  })

  allNodes.forEach(node => {
    const category = node.category || '其他'
    if (!groups[category]) {
      groups[category] = []
    }
    groups[category].push(node)
  })

  // 转换为数组格式
  return Object.entries(groups).map(([name, nodes]) => ({
    name,
    nodes
  }))
})

// 事件处理
const handleNodeClick = (node: any) => {
  if (props.multiple) {
    const index = selectedNodes.value.indexOf(node.type)
    if (index > -1) {
      selectedNodes.value.splice(index, 1)
    } else {
      selectedNodes.value.push(node.type)
    }
    emit('select', [...selectedNodes.value])
  } else {
    selectedNodes.value = [node.type]
    emit('select', [node.type])
  }

  emit('item-click', node.type)
}
</script>

<style scoped>
.node-selector {
  width: 250px;
  background: #fff;
  border-right: 1px solid #d9d9d9;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.selector-header {
  padding: 16px;
  border-bottom: 1px solid #d9d9d9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.selector-title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #333;
}

.collapse-button {
  width: 24px;
  height: 24px;
  border: none;
  background: transparent;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  transition: background 0.2s;
}

.collapse-button:hover {
  background: #f5f5f5;
}

.selector-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.search-box {
  margin-bottom: 16px;
}

.search-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-size: 14px;
  box-sizing: border-box;
}

.search-input:focus {
  outline: none;
  border-color: #1890ff;
  box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1);
}

.node-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.node-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.group-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #666;
  padding-bottom: 4px;
  border-bottom: 1px solid #f0f0f0;
}

.group-nodes {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.node-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid transparent;
}

.node-item:hover {
  background: #f5f5f5;
  border-color: #d9d9d9;
}

.node-item.selected {
  background: #e6f7ff;
  border-color: #1890ff;
}

.node-icon {
  font-size: 18px;
  width: 24px;
  text-align: center;
}

.node-name {
  font-size: 14px;
  color: #333;
}

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  color: #999;
  text-align: center;
}

.empty-state p {
  margin: 0;
  font-size: 14px;
}
</style>