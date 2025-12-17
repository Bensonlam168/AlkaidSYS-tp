<template>
  <div
    class="mini-map"
    :style="{
      width: width + 'px',
      height: height + 'px',
      position: 'absolute',
      [position]: '20px',
      zIndex: 100
    }"
  >
    <div class="mini-map-header" v-if="showHeader">
      <h4 class="mini-map-title">{{ header }}</h4>
    </div>
    <div class="mini-map-content" ref="mapContainer"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { MiniMapProps } from '../../types/vue'

// Props定义
const props = withDefaults(defineProps<MiniMapProps>(), {
  width: 120,
  height: 80,
  position: 'bottom-right',
  showHeader: false,
  header: '小地图'
})

// 引用
const mapContainer = ref<HTMLElement>()
</script>

<style scoped>
.mini-map {
  background: #fff;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.mini-map-header {
  padding: 8px 12px;
  background: #fafafa;
  border-bottom: 1px solid #d9d9d9;
}

.mini-map-title {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  color: #333;
}

.mini-map-content {
  width: 100%;
  height: calc(100% - 32px);
  background: #f5f5f5;
}
</style>