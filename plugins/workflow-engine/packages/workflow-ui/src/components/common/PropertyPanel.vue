<template>
  <div class="property-panel">
    <div class="panel-header" v-if="selected && showHeader">
      <h3 class="panel-title">属性面板</h3>
      <div class="panel-actions">
        <button class="action-button" @click="handleSave" title="保存">
          💾
        </button>
        <button class="action-button" @click="handleCancel" title="取消">
          ❌
        </button>
      </div>
    </div>

    <div class="panel-content">
      <template v-if="selected">
        <div class="property-group" v-for="group in propertyGroups" :key="group.name">
          <h4 class="group-title">{{ group.name }}</h4>
          <div class="group-content">
            <FormItem
              v-for="item in group.items"
              :key="item.prop"
              :schema="item"
              :model-value="selected.data[item.prop]"
              :readonly="readonly"
              @change="(value) => handleChange(item.prop, value)"
            />
          </div>
        </div>
      </template>

      <div v-else class="no-selection">
        <p>请选择节点或边以编辑属性</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { PropertyPanelProps } from '../../types/vue'
import FormItem from './FormItem.vue'

// Props定义
const props = defineProps<PropertyPanelProps>()

// Emits定义
const emit = defineEmits<{
  change: [key: string, value: any]
  save: []
  cancel: []
}>()

// 是否显示头部
const showHeader = true

// 属性分组
const propertyGroups = computed(() => {
  if (!props.schema || props.schema.length === 0) {
    // 默认属性分组
    return [
      {
        name: '基本属性',
        items: [
          {
            prop: 'text',
            label: '名称',
            type: 'input',
            required: true
          },
          {
            prop: 'description',
            label: '描述',
            type: 'textarea'
          }
        ]
      }
    ]
  }

  // 按类型分组
  const groups: Record<string, any[]> = {
    '基本属性': [],
    '高级属性': []
  }

  props.schema.forEach(item => {
    if (['text', 'name', 'label'].includes(item.prop)) {
      groups['基本属性'].push(item)
    } else {
      groups['高级属性'].push(item)
    }
  })

  return Object.entries(groups)
    .filter(([_, items]) => items.length > 0)
    .map(([name, items]) => ({ name, items }))
})

// 事件处理
const handleChange = (key: string, value: any) => {
  emit('change', key, value)
}

const handleSave = () => {
  emit('save')
}

const handleCancel = () => {
  emit('cancel')
}
</script>

<style scoped>
.property-panel {
  width: 300px;
  background: #fff;
  border-left: 1px solid #d9d9d9;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.panel-header {
  padding: 16px;
  border-bottom: 1px solid #d9d9d9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.panel-title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #333;
}

.panel-actions {
  display: flex;
  gap: 8px;
}

.action-button {
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  transition: background 0.2s;
}

.action-button:hover {
  background: #f5f5f5;
}

.panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.property-group {
  margin-bottom: 24px;
}

.group-title {
  margin: 0 0 12px 0;
  font-size: 14px;
  font-weight: 600;
  color: #333;
  padding-bottom: 8px;
  border-bottom: 1px solid #f0f0f0;
}

.group-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.no-selection {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #999;
  text-align: center;
}

.no-selection p {
  margin: 0;
  font-size: 14px;
}
</style>