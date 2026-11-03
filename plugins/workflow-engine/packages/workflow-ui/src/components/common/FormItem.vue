<template>
  <div class="form-item">
    <label class="form-label" :class="{ 'form-label-required': required }">
      {{ label }}
    </label>

    <div class="form-control">
      <!-- 输入框 -->
      <input
        v-if="type === 'input'"
        type="text"
        class="form-input"
        :placeholder="placeholder"
        :disabled="disabled"
        :value="modelValue"
        @input="handleInput"
      />

      <!-- 文本域 -->
      <textarea
        v-else-if="type === 'textarea'"
        class="form-textarea"
        :placeholder="placeholder"
        :disabled="disabled"
        :value="modelValue"
        @input="handleInput"
        rows="3"
      />

      <!-- 数字输入 -->
      <input
        v-else-if="type === 'number'"
        type="number"
        class="form-input"
        :placeholder="placeholder"
        :disabled="disabled"
        :value="modelValue"
        @input="handleInput"
      />

      <!-- 选择框 -->
      <select
        v-else-if="type === 'select'"
        class="form-select"
        :disabled="disabled"
        :value="modelValue"
        @change="handleSelect"
      >
        <option value="">请选择...</option>
        <option
          v-for="option in options"
          :key="option.value"
          :value="option.value"
        >
          {{ option.label }}
        </option>
      </select>

      <!-- 复选框 -->
      <label v-else-if="type === 'checkbox'" class="form-checkbox">
        <input
          type="checkbox"
          :disabled="disabled"
          :checked="modelValue"
          @change="handleCheckbox"
        />
        <span>{{ placeholder }}</span>
      </label>

      <!-- 开关 -->
      <label v-else-if="type === 'switch'" class="form-switch">
        <input
          type="checkbox"
          :disabled="disabled"
          :checked="modelValue"
          @change="handleCheckbox"
        />
        <span class="switch-slider"></span>
      </label>

      <!-- 日期选择 -->
      <input
        v-else-if="type === 'date'"
        type="date"
        class="form-input"
        :disabled="disabled"
        :value="modelValue"
        @input="handleInput"
      />

      <!-- 未知类型 -->
      <input
        v-else
        type="text"
        class="form-input"
        :placeholder="placeholder"
        :disabled="disabled"
        :value="modelValue"
        @input="handleInput"
      />
    </div>

    <!-- 帮助文本 -->
    <div v-if="helpText" class="form-help">
      {{ helpText }}
    </div>

    <!-- 错误信息 -->
    <div v-if="error" class="form-error">
      {{ error }}
    </div>
  </div>
</template>

<script setup lang="ts">
import type { FormItemProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<FormItemProps & {
  type?: 'input' | 'textarea' | 'number' | 'select' | 'checkbox' | 'switch' | 'date'
  modelValue?: any
}>(), {
  type: 'input',
  modelValue: ''
})

// Emits定义
const emit = defineEmits<{
  'update:modelValue': [value: any]
  change: [value: any]
}>()

// 事件处理
const handleInput = (event: Event) => {
  const target = event.target as HTMLInputElement
  const value = props.type === 'number' ? Number(target.value) : target.value
  emit('update:modelValue', value)
  emit('change', value)
}

const handleSelect = (event: Event) => {
  const target = event.target as HTMLSelectElement
  const value = target.value
  emit('update:modelValue', value)
  emit('change', value)
}

const handleCheckbox = (event: Event) => {
  const target = event.target as HTMLInputElement
  const value = target.checked
  emit('update:modelValue', value)
  emit('change', value)
}
</script>

<style scoped>
.form-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 14px;
  color: #333;
  font-weight: 500;
}

.form-label-required::after {
  content: ' *';
  color: #ff4d4f;
}

.form-control {
  position: relative;
}

.form-input,
.form-textarea,
.form-select {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-size: 14px;
  transition: border-color 0.2s, box-shadow 0.2s;
  box-sizing: border-box;
}

.form-input:focus,
.form-textarea:focus,
.form-select:focus {
  outline: none;
  border-color: #1890ff;
  box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1);
}

.form-input:disabled,
.form-textarea:disabled,
.form-select:disabled {
  background: #f5f5f5;
  cursor: not-allowed;
  color: #999;
}

.form-textarea {
  resize: vertical;
  min-height: 60px;
  font-family: inherit;
}

.form-checkbox,
.form-switch {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  user-select: none;
}

.form-checkbox input[type="checkbox"],
.form-switch input[type="checkbox"] {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.form-switch {
  position: relative;
}

.form-switch input[type="checkbox"] {
  opacity: 0;
  width: 0;
  height: 0;
  position: absolute;
}

.switch-slider {
  position: relative;
  display: inline-block;
  width: 40px;
  height: 20px;
  background: #d9d9d9;
  border-radius: 10px;
  transition: background 0.2s;
}

.switch-slider::before {
  content: '';
  position: absolute;
  width: 16px;
  height: 16px;
  left: 2px;
  top: 2px;
  background: white;
  border-radius: 50%;
  transition: transform 0.2s;
}

.form-switch input:checked + .switch-slider {
  background: #1890ff;
}

.form-switch input:checked + .switch-slider::before {
  transform: translateX(20px);
}

.form-help {
  font-size: 12px;
  color: #999;
}

.form-error {
  font-size: 12px;
  color: #ff4d4f;
}
</style>