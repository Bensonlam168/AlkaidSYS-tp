<template>
  <Teleport to="body">
    <div v-if="visible" class="dialog-overlay" @click="handleOverlayClick">
      <div class="dialog-content" @click.stop>
        <div class="dialog-header">
          <h3 class="dialog-title">导出工作流</h3>
          <button class="close-button" @click="handleClose">×</button>
        </div>

        <div class="dialog-body">
          <div class="form-group">
            <label class="form-label">导出格式</label>
            <select v-model="selectedFormat" class="form-select">
              <option value="png">PNG 图片</option>
              <option value="jpg">JPG 图片</option>
              <option value="svg">SVG 矢量图</option>
              <option value="pdf">PDF 文档</option>
              <option value="json">JSON 数据</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">文件名</label>
            <input
              v-model="filename"
              type="text"
              class="form-input"
              placeholder="请输入文件名"
            />
          </div>

          <div v-if="isImageFormat" class="form-group">
            <label class="form-label">背景色</label>
            <input
              v-model="backgroundColor"
              type="color"
              class="form-color"
            />
          </div>

          <div v-if="isImageFormat" class="form-group">
            <label class="form-label">像素比</label>
            <input
              v-model.number="pixelRatio"
              type="number"
              class="form-input"
              min="0.1"
              max="3"
              step="0.1"
            />
          </div>
        </div>

        <div class="dialog-footer">
          <button class="btn btn-secondary" @click="handleClose">取消</button>
          <button class="btn btn-primary" @click="handleExport">导出</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { ExportDialogProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<ExportDialogProps>(), {
  visible: false,
  format: 'png',
  filename: 'workflow',
  backgroundColor: '#ffffff',
  pixelRatio: 1
})

// Emits定义
const emit = defineEmits<{
  'update:visible': [visible: boolean]
  export: [format: string, options: any]
  close: []
}>()

// 响应式数据
const selectedFormat = ref(props.format)
const filename = ref(props.filename)
const backgroundColor = ref(props.backgroundColor)
const pixelRatio = ref(props.pixelRatio)

// 计算属性
const isImageFormat = computed(() => {
  return ['png', 'jpg', 'svg', 'pdf'].includes(selectedFormat.value)
})

// 事件处理
const handleExport = () => {
  emit('export', selectedFormat.value, {
    filename: filename.value,
    backgroundColor: backgroundColor.value,
    pixelRatio: pixelRatio.value
  })
  handleClose()
}

const handleClose = () => {
  emit('update:visible', false)
  emit('close')
}

const handleOverlayClick = () => {
  handleClose()
}

// 监听格式变化
watch(selectedFormat, (newFormat) => {
  if (newFormat === 'json' && !filename.value.endsWith('.json')) {
    filename.value = filename.value.replace(/\.(png|jpg|svg|pdf)$/, '') + '.json'
  }
})

// 更新文件名扩展名
watch(filename, (newFilename) => {
  if (newFilename && !newFilename.includes('.')) {
    filename.value = newFilename + '.' + selectedFormat.value
  }
})
</script>

<style scoped>
.dialog-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.dialog-content {
  width: 500px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  overflow: hidden;
}

.dialog-header {
  padding: 16px 24px;
  border-bottom: 1px solid #d9d9d9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.dialog-title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #333;
}

.close-button {
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  border-radius: 4px;
  cursor: pointer;
  font-size: 24px;
  color: #999;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}

.close-button:hover {
  background: #f5f5f5;
  color: #333;
}

.dialog-body {
  padding: 24px;
}

.form-group {
  margin-bottom: 20px;
}

.form-label {
  display: block;
  margin-bottom: 8px;
  font-size: 14px;
  font-weight: 500;
  color: #333;
}

.form-input,
.form-select,
.form-color {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-size: 14px;
  box-sizing: border-box;
  transition: border-color 0.2s;
}

.form-input:focus,
.form-select:focus {
  outline: none;
  border-color: #1890ff;
}

.form-color {
  height: 40px;
  cursor: pointer;
}

.dialog-footer {
  padding: 16px 24px;
  border-top: 1px solid #d9d9d9;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn {
  padding: 8px 20px;
  border: none;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-primary {
  background: #1890ff;
  color: #fff;
}

.btn-primary:hover {
  background: #40a9ff;
}

.btn-secondary {
  background: #f5f5f5;
  color: #333;
}

.btn-secondary:hover {
  background: #e6e6e6;
}
</style>