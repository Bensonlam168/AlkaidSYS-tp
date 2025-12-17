<template>
  <div class="workflow-toolbar" v-if="visible">
    <div class="toolbar-content">
      <div
        v-for="tool in tools"
        :key="tool.key"
        class="toolbar-item"
        :class="{
          'toolbar-separator': tool.type === 'separator',
          'toolbar-button': tool.type !== 'separator',
          'toolbar-button-disabled': tool.disabled
        }"
        :title="tool.tooltip"
        @click="!tool.disabled && handleClick(tool.key)"
      >
        <template v-if="tool.type !== 'separator'">
          <i class="toolbar-icon" v-if="tool.icon">{{ tool.icon }}</i>
          <span class="toolbar-text" v-if="tool.text">{{ tool.text }}</span>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ToolbarProps } from '../../types/vue'

// Props定义
const props = defineProps<ToolbarProps>()

// Emits定义
const emit = defineEmits<{
  click: [key: string]
}>()

// 处理点击事件
const handleClick = (key: string) => {
  emit('click', key)
}
</script>

<style scoped>
.workflow-toolbar {
  height: 48px;
  background: #fff;
  border-bottom: 1px solid #d9d9d9;
  padding: 0 16px;
  display: flex;
  align-items: center;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
  z-index: 10;
}

.toolbar-content {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.toolbar-item {
  display: flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
  user-select: none;
}

.toolbar-item:hover {
  background: #f5f5f5;
}

.toolbar-button-disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.toolbar-button-disabled:hover {
  background: transparent;
}

.toolbar-separator {
  width: 1px;
  height: 24px;
  background: #d9d9d9;
  padding: 0;
  cursor: default;
}

.toolbar-separator:hover {
  background: #d9d9d9;
}

.toolbar-icon {
  font-size: 16px;
  font-style: normal;
  margin-right: 4px;
}

.toolbar-text {
  font-size: 14px;
  color: #333;
}
</style>