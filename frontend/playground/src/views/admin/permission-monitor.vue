<script lang="ts" setup>
import { h, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { RotateCw, Trash2 } from '@vben/icons';

import {
  Alert,
  Button,
  Card,
  Col,
  Descriptions,
  DescriptionsItem,
  Divider,
  Form,
  FormItem,
  Input,
  InputNumber,
  Row,
  Space,
  Statistic,
  Tag,
} from 'ant-design-vue';

import {
  checkPermission,
  clearAllCache,
  getCacheStats,
  getPerformanceMetrics,
  getUserPermissions,
} from '#/api/admin/permission-monitor';

// 响应式数据
const cacheStats = ref<any>({});
const performance = ref<any>({});
const loading = ref(false);
const refreshing = ref(false);
const userPermissions = ref<any>(null);
const permissionCheckResult = ref<any>(null);

const queryForm = reactive({
  userId: 1,
  permission: 'user:read',
});

// 加载缓存统计
const loadCacheStats = async () => {
  try {
    const data = await getCacheStats();
    cacheStats.value = data;
  } catch (error) {
    console.error('Failed to load cache stats:', error);
  }
};

// 加载性能指标
const loadPerformanceMetrics = async () => {
  try {
    const data = await getPerformanceMetrics();
    performance.value = {
      p95_latency_ms: data.cache?.overall?.p95_latency_ms ?? 0,
      hit_rate: data.cache?.overall?.hit_rate ?? 0,
      total_queries: data.cache?.overall?.total_queries ?? 0,
      avg_latency_ms: data.cache?.overall?.avg_latency_ms ?? 0,
    };
  } catch (error) {
    console.error('Failed to load performance metrics:', error);
  }
};

// 刷新统计
const refreshStats = async () => {
  refreshing.value = true;
  try {
    await Promise.all([loadCacheStats(), loadPerformanceMetrics()]);
  } finally {
    refreshing.value = false;
  }
};

// 清除所有缓存
const clearAllCacheHandler = async () => {
  loading.value = true;
  try {
    await clearAllCache();
    await refreshStats();
  } catch (error) {
    console.error('Failed to clear cache:', error);
  } finally {
    loading.value = false;
  }
};

// 查询用户权限
const searchUserPermissions = async () => {
  try {
    const data = await getUserPermissions(queryForm.userId);
    userPermissions.value = data;
    permissionCheckResult.value = null;
  } catch (error) {
    console.error('Failed to get user permissions:', error);
  }
};

// 检查权限
const checkPermissionHandler = async () => {
  try {
    const data = await checkPermission(queryForm.userId, queryForm.permission);
    permissionCheckResult.value = data;
    userPermissions.value = null;
  } catch (error) {
    console.error('Failed to check permission:', error);
  }
};

// 初始化
onMounted(async () => {
  await refreshStats();
});
</script>

<template>
  <Page auto-content-height>
    <!-- 性能概览 -->
    <Row :gutter="[16, 16]" class="mb-4">
      <Col :span="6">
        <Card>
          <Statistic
            title="P95 延迟"
            :value="performance.p95_latency_ms || 0"
            suffix="ms"
          >
            <template #prefix>
              <span
                :style="{
                  color:
                    (performance.p95_latency_ms || 0) < 100
                      ? '#52c41a'
                      : '#faad14',
                }"
              >
                ●
              </span>
            </template>
            <template #suffix>
              <span
                :style="{
                  color:
                    (performance.p95_latency_ms || 0) < 100
                      ? '#52c41a'
                      : '#faad14',
                }"
              >
                / 100ms
              </span>
            </template>
          </Statistic>
        </Card>
      </Col>
      <Col :span="6">
        <Card>
          <Statistic
            title="缓存命中率"
            :value="performance.hit_rate || 0"
            suffix="%"
          >
            <template #prefix>
              <span
                :style="{
                  color:
                    (performance.hit_rate || 0) >= 90 ? '#52c41a' : '#faad14',
                }"
              >
                ●
              </span>
            </template>
            <template #suffix>
              <span
                :style="{
                  color:
                    (performance.hit_rate || 0) >= 90 ? '#52c41a' : '#faad14',
                }"
              >
                / 90%
              </span>
            </template>
          </Statistic>
        </Card>
      </Col>
      <Col :span="6">
        <Card>
          <Statistic title="总查询数" :value="performance.total_queries || 0" />
        </Card>
      </Col>
      <Col :span="6">
        <Card>
          <Statistic
            title="平均延迟"
            :value="performance.avg_latency_ms || 0"
            suffix="ms"
          />
        </Card>
      </Col>
    </Row>

    <!-- 缓存统计 -->
    <Card title="缓存统计" class="mb-4">
      <Row :gutter="[16, 16]">
        <Col :span="12">
          <Card size="small" title="L1 缓存 (内存)">
            <Descriptions :column="1" size="small">
              <DescriptionsItem label="命中率">
                {{ cacheStats.l1_cache?.hit_rate || 0 }}%
              </DescriptionsItem>
              <DescriptionsItem label="TTL">
                {{ cacheStats.l1_cache?.ttl || 0 }}s
              </DescriptionsItem>
              <DescriptionsItem label="命中次数">
                {{ cacheStats.l1_cache?.hits || 0 }}
              </DescriptionsItem>
              <DescriptionsItem label="未命中次数">
                {{ cacheStats.l1_cache?.misses || 0 }}
              </DescriptionsItem>
            </Descriptions>
          </Card>
        </Col>
        <Col :span="12">
          <Card size="small" title="L2 缓存 (Redis)">
            <Descriptions :column="1" size="small">
              <DescriptionsItem label="命中率">
                {{ cacheStats.l2_cache?.hit_rate || 0 }}%
              </DescriptionsItem>
              <DescriptionsItem label="TTL">
                {{ cacheStats.l2_cache?.ttl || 0 }}s
              </DescriptionsItem>
              <DescriptionsItem label="命中次数">
                {{ cacheStats.l2_cache?.hits || 0 }}
              </DescriptionsItem>
              <DescriptionsItem label="未命中次数">
                {{ cacheStats.l2_cache?.misses || 0 }}
              </DescriptionsItem>
            </Descriptions>
          </Card>
        </Col>
      </Row>
    </Card>

    <!-- 缓存管理 -->
    <Card title="缓存管理" class="mb-4">
      <Space>
        <Button
          type="primary"
          @click="clearAllCacheHandler"
          :loading="loading"
          :icon="h(Trash2)"
        >
          清除所有缓存
        </Button>
        <Button @click="refreshStats" :loading="refreshing" :icon="h(RotateCw)">
          刷新统计
        </Button>
      </Space>
    </Card>

    <!-- 用户权限查询 -->
    <Card title="用户权限查询">
      <Form layout="inline" :model="queryForm" @submit.prevent>
        <FormItem label="用户ID">
          <InputNumber
            v-model:value="queryForm.userId"
            :min="1"
            style="width: 150px"
          />
        </FormItem>
        <FormItem label="权限码">
          <Input
            v-model:value="queryForm.permission"
            placeholder="例如: user:read"
            style="width: 200px"
          />
        </FormItem>
        <FormItem>
          <Space>
            <Button type="primary" @click="searchUserPermissions">
              查询权限
            </Button>
            <Button @click="checkPermissionHandler">检查权限</Button>
          </Space>
        </FormItem>
      </Form>

      <Divider />

      <Alert v-if="userPermissions" type="success" show-icon class="mb-3">
        <template #message>
          用户 {{ queryForm.userId }} 的权限 ({{ userPermissions.count }} 个)
        </template>
        <template #description>
          <div style="margin-top: 8px">
            <Tag
              v-for="perm in userPermissions.permissions"
              :key="perm"
              color="blue"
              style="margin: 0 8px 8px 0"
            >
              {{ perm }}
            </Tag>
          </div>
        </template>
      </Alert>

      <Alert v-if="permissionCheckResult" type="info" show-icon>
        <template #message>权限检查结果</template>
        <template #description>
          用户 {{ queryForm.userId }}
          {{ permissionCheckResult.has_permission ? '有' : '没有' }}
          权限: {{ queryForm.permission }}
        </template>
      </Alert>
    </Card>
  </Page>
</template>

<style scoped>
.mb-4 {
  margin-bottom: 16px;
}

.mb-3 {
  margin-bottom: 12px;
}
</style>
