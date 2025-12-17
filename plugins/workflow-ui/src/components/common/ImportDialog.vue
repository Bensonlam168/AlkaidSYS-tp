<template>
  <Teleport to="body">
    <div v-if="visible" class="dialog-overlay" @click="handleOverlayClick">
      <div class="dialog-content" @click.stop>
        <div class="dialog-header">
          <h3 class="dialog-title">导入工作流</h3>
          <button class="close-button" @click="handleClose">×</button>
        </div>

        <div class="dialog-body">
          <div class="upload-area" @drop="handleDrop" @dragover.prevent>
            <input
              ref="fileInput"
              type="file"
              :accept="accept"
              style="display: none"
              @change="handleFileSelect"
            />
            <div class="upload-content">
              <div class="upload-icon">📁</div>
              <p class="upload-text">拖拽文件到此处或</p>
              <button class="btn-upload" @click="triggerFileSelect">
                选择文件
              </button>
              <p class="upload-hint">
                支持格式：{{ accept }}
                <br />
                最大文件大小：{{ maxSize }}MB
              </p>
            </div>
          </div>

          <div v-if="selectedFile" class="file-info">
            <div class="file-details">
              <span class="file-name">{{ selectedFile.name }}</span>
              <span class="file-size">{{ formatFileSize(selectedFile.size) }}</span>
            </div>
            <button class="btn-remove" @click="removeFile">×</button>
          </div>
        </div>

        <div class="dialog-footer">
          <button class="btn btn-secondary" @click="handleClose">取消</button>
          <button
            class="btn btn-primary"
            :disabled="!selectedFile || importing"
            @click="handleImport"
          >
            {{ importing ? '导入中...' : '导入' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { ImportDialogProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<ImportDialogProps>(), {
  visible: false,
  accept: '.json,.xml,.bpmn',
  maxSize: 10
})

// Emits定义
const emit = defineEmits<{
  'update:visible': [visible: boolean]
  import: [file: File, data: any]
  error: [error: Error]
  close: []
}>()

// 响应式数据
const fileInput = ref<HTMLInputElement>()
const selectedFile = ref<File | null>(null)
const importing = ref(false)

// 事件处理
const triggerFileSelect = () => {
  fileInput.value?.click()
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) {
    validateAndSelectFile(file)
  }
}

const handleDrop = (event: DragEvent) => {
  event.preventDefault()
  const file = event.dataTransfer?.files[0]
  if (file) {
    validateAndSelectFile(file)
  }
}

const validateAndSelectFile = (file: File) => {
  // 检查文件大小
  if (file.size > props.maxSize * 1024 * 1024) {
    emit('error', new Error(`文件大小不能超过 ${props.maxSize}MB`))
    return
  }

  // 检查文件类型
  const fileExt = '.' + file.name.split('.').pop()?.toLowerCase()
  const acceptExts = props.accept.split(',')
  if (!acceptExts.includes(fileExt)) {
    emit('error', new Error('不支持的文件格式'))
    return
  }

  selectedFile.value = file
}

const removeFile = () => {
  selectedFile.value = null
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const handleImport = async () => {
  if (!selectedFile.value) return

  importing.value = true

  try {
    const text = await selectedFile.value.text()
    let data

    try {
      data = JSON.parse(text)
    } catch (e) {
      // 如果是XML或BPMN文件，尝试解析XML
      if (selectedFile.value.name.endsWith('.xml') || selectedFile.value.name.endsWith('.bpmn')) {
        // 这里应该使用XML解析器
        // 目前作为示例，先作为文本处理
        data = { xml: text }
      } else {
        throw new Error('文件格式错误')
      }
    }

    emit('import', selectedFile.value, data)
    handleClose()
  } catch (error) {
    emit('error', error as Error)
  } finally {
    importing.value = false
  }
}

const handleClose = () => {
  emit('update:visible', false)
  emit('close')
  removeFile()
}

const handleOverlayClick = () => {
  handleClose()
}

const formatFileSize = (bytes: number): string => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}
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

.upload-area {
  border: 2px dashed #d9d9d9;
  border-radius: 8px;
  padding: 40px 20px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s;
}

.upload-area:hover {
  border-color: #1890ff;
}

.upload-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.upload-icon {
  font-size: 48px;
}

.upload-text {
  margin: 0;
  font-size: 16px;
  color: #666;
}

.btn-upload {
  padding: 8px 20px;
  background: #1890ff;
  color: #fff;
  border: none;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-upload:hover {
  background: #40a9ff;
}

.upload-hint {
  margin: 0;
  font-size: 12px;
  color: #999;
  line-height: 1.5;
}

.file-info {
  margin-top: 16px;
  padding: 12px 16px;
  background: #f5f5f5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.file-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.file-name {
  font-size: 14px;
  font-weight: 500;
  color: #333;
}

.file-size {
  font-size: 12px;
  color: #999;
}

.btn-remove {
  width: 24px;
  height: 24px;
  border: none;
  background: transparent;
  border-radius: 2px;
  cursor: pointer;
  font-size: 18px;
  color: #999;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}

.btn-remove:hover {
  background: #ff4d4f;
  color: #fff;
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

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background: #1890ff;
  color: #fff;
}

.btn-primary:hover:not(:disabled) {
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