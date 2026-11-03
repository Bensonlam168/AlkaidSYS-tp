<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import { IframePluginLoader } from '@alkaidsys/plugin-sdk';
import { Alert, Card, Space, Tag, Typography } from 'ant-design-vue';

import { requestClient } from '#/api/request';

const logs = ref<string[]>([]);
const loading = ref(false);

// 读取插件 URL 与安全白名单，允许通过环境变量配置
const pluginUrl = computed(() => import.meta.env.VITE_PLUGIN_IFRAME_URL || '');

// 解析插件 URL：computed 保持纯计算，不在这里写日志
const pluginUrlParse = computed(() => {
  if (!pluginUrl.value) {
    return { error: '', origin: '' };
  }
  try {
    return { error: '', origin: new URL(pluginUrl.value).origin };
  } catch (error) {
    return { error: `插件 URL 无效：${error}`, origin: '' };
  }
});

const pluginOrigin = computed(() => pluginUrlParse.value.origin);

// 解析失败时补一条日志（副作用从 computed 移到 watch，避免在计算属性里改状态）
watch(
  () => pluginUrlParse.value.error,
  (error) => {
    if (error) {
      logs.value.unshift(error);
    }
  },
  { immediate: true },
);

const allowedOrigins = computed(() => {
  const extra = (import.meta.env.VITE_PLUGIN_IFRAME_ALLOWED_ORIGINS || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
  const origins = pluginOrigin.value ? [pluginOrigin.value] : [];
  return [...new Set([...extra, ...origins])];
});

const csp = computed(() => import.meta.env.VITE_PLUGIN_IFRAME_CSP || undefined);

/**
 * Host -> 后端代理：仅允许相对路径，自动附加 JWT/租户/站点/trace_id
 */
async function handleRequest(
  payload: {
    body?: unknown;
    headers?: Record<string, string>;
    method?: string;
    path: string;
  },
  context: {
    siteId?: null | number | string;
    tenantId?: null | number | string;
    token?: null | string;
    traceId?: null | string;
  },
) {
  try {
    loading.value = true;
    const response = await requestClient.request({
      url: payload.path,
      method: payload.method ?? 'GET',
      data: payload.body,
      headers: {
        ...payload.headers,
        ...(context.token ? { Authorization: `Bearer ${context.token}` } : {}),
        ...(context.tenantId === null || context.tenantId === undefined
          ? {}
          : { 'X-Tenant-ID': String(context.tenantId) }),
        ...(context.siteId === null || context.siteId === undefined
          ? {}
          : { 'X-Site-ID': String(context.siteId) }),
        ...(context.traceId ? { 'X-Trace-Id': context.traceId } : {}),
      },
    });
    return { ok: true, status: 200, data: response };
  } catch (error: any) {
    const message = error?.message ?? '代理请求失败';
    logs.value.unshift(message);
    return { ok: false, status: 500, error: message };
  } finally {
    loading.value = false;
  }
}

function handleEvent(payload: { data?: unknown; eventName: string }) {
  logs.value.unshift(`收到事件: ${payload.eventName}`);
}

function handleStatusChange(detail: { reason?: string; status: string }) {
  if (detail.status === 'error' && detail.reason) {
    logs.value.unshift(`握手失败: ${detail.reason}`);
  }
}
</script>

<template>
  <Space direction="vertical" size="middle" class="w-full">
    <Card title="iframe 插件加载器示例" bordered>
      <Typography.Paragraph>
        该示例演示如何使用 <code>@alkaidsys/plugin-sdk</code> 实现 postMessage
        握手、上下文注入与安全白名单校验。
      </Typography.Paragraph>
      <Typography.Paragraph>
        使用前请在环境变量中设置 <code>VITE_PLUGIN_IFRAME_URL</code>（插件
        URL），可选
        <code>VITE_PLUGIN_IFRAME_ALLOWED_ORIGINS</code>（逗号分隔）与
        <code>VITE_PLUGIN_IFRAME_CSP</code>。
      </Typography.Paragraph>
      <Space wrap>
        <Tag color="blue">URL: {{ pluginUrl || '未配置' }}</Tag>
        <Tag color="geekblue">
          Origin 白名单: {{ allowedOrigins.join(', ') || '无' }}
        </Tag>
        <Tag color="purple">CSP: {{ csp || '未配置' }}</Tag>
      </Space>
    </Card>

    <Alert
      v-if="!pluginUrl"
      message="请配置 VITE_PLUGIN_IFRAME_URL 后再加载插件。当前页面仅作配置指引。"
      type="warning"
      show-icon
    />

    <Card v-else title="插件沙箱">
      <IframePluginLoader
        :src="pluginUrl"
        plugin-key="demo-plugin"
        :allowed-origins="allowedOrigins"
        :csp="csp"
        :sandbox="['allow-scripts', 'allow-same-origin', 'allow-forms']"
        :on-request="handleRequest"
        :on-event="handleEvent"
        :handshake-timeout="8000"
        :heartbeat-interval="15000"
        :on-status-change="handleStatusChange"
      />
    </Card>

    <Card title="事件与日志" size="small">
      <Typography.Paragraph v-if="logs.length === 0">
        暂无日志，等待插件事件或请求。
      </Typography.Paragraph>
      <ul v-else class="list-disc space-y-1 pl-4">
        <li v-for="(item, index) in logs" :key="index">{{ item }}</li>
      </ul>
    </Card>
  </Space>
</template>
