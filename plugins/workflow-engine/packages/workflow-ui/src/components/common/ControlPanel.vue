<template>
  <div class="control-panel" :class="{ collapsed }">
    <div class="panel-header" v-if="showHeader">
      <h3 class="panel-title">{{ header }}</h3>
      <button class="collapse-button" @click="collapsed = !collapsed">
        {{ collapsed ? '▶' : '▼' }}
      </button>
    </div>

    <div class="panel-content" v-if="!collapsed">
      <div
        v-for="group in data"
        :key="group.name"
        class="panel-group"
      >
        <h4 class="group-title" @click="toggleGroup(group.name)">
          {{ group.name }}
          <span class="toggle-icon">{{ expandedGroups.has(group.name) ? '▼' : '▶' }}</span>
        </h4>
        <div class="group-content" v-if="expandedGroups.has(group.name)">
          <div
            v-for="item in group.children"
            :key="item.name"
            class="panel-item"
            @click="handleItemClick(item)"
          >
            <span class="item-icon">{{ item.icon }}</span>
            <span class="item-name">{{ item.name }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { ControlPanelProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<ControlPanelProps>(), {
  showHeader: true,
  header: '控制面板',
  collapsed: false,
  defaultOpenGroups: () => []
})

// Emits定义
const emit = defineEmits<{
  collapse: [collapsed: boolean]
  'item-click': [item: any]
  'group-toggle': [group: string, expanded: boolean]
}>()

// 响应式数据
const collapsed = ref(props.collapsed)
const expandedGroups = ref(new Set(props.defaultOpenGroups))

// 事件处理
const toggleGroup = (groupName: string) => {
  if (expandedGroups.value.has(groupName)) {
    expandedGroups.value.delete(groupName)
  } else {
    expandedGroups.value.add(groupName)
  }
  emit('group-toggle', groupName, expandedGroups.value.has(groupName))
}

const handleItemClick = (item: any) => {
  emit('item-click', item)
}

// 监听折叠状态变化
import { watch } from 'vue'
watch(collapsed, (newVal) => {
  emit('collapse', newVal)
})
</script>

<style scoped>
.control-panel {
  width: 250px;
  background: #fff;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.panel-header {
  padding: 12px 16px;
  background: #fafafa;
  border-bottom: 1px solid #d9d9d9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.panel-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #333;
}

.collapse-button {
  width: 20px;
  height: 20px;
  border: none;
  background: transparent;
  border-radius: 2px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  transition: background 0.2s;
}

.collapse-button:hover {
  background: #e6e6e6;
}

.panel-content {
  padding: 8px;
  max-height: 400px;
  overflow-y: auto;
}

.panel-group {
  margin-bottom: 8px;
}

.group-title {
  margin: 0;
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 600;
  color: #666;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 4px;
  transition: background 0.2s;
}

.group-title:hover {
  background: #f5f5f5;
}

.toggle-icon {
  font-size: 10px;
  color: #999;
}

.group-content {
  padding: 4px 0;
}

.panel-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  margin: 2px 8px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
}

.panel-item:hover {
  background: #f5f5f5;
}

.item-icon {
  font-size: 16px;
  width: 20px;
  text-align: center;
}

.item-name {
  font-size: 13px;
  color: #333;
}

.collapsed {
  width: auto;
}

.collapsed .panel-content {
  display: none;
}
</style>