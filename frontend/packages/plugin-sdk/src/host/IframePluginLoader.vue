<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch, computed } from 'vue';
import {
  useAccessStore,
  useTenantStore,
  useUserStore,
} from '@vben/stores';

import { IframeHostBridge } from './iframe-host-manager';
import type {
  HostContextProvider,
  PluginEventHandler,
  PluginRequestHandler,
  PluginContext,
} from '../types';

const props = defineProps<{
  pluginKey: string;
  src: string;
  sandbox?: string[];
  csp?: string;
  allowedOrigins?: string[];
  heartbeatInterval?: number;
  handshakeTimeout?: number;
  allowHttpInDev?: boolean;
  tokenProvider?: () => Promise<string | null | undefined> | string | null | undefined;
  contextProvider?: HostContextProvider;
  onRequest?: PluginRequestHandler;
  onEvent?: PluginEventHandler;
}>();

const iframeRef = ref<HTMLIFrameElement | null>(null);
const status = ref<'idle' | 'loading' | 'ready' | 'offline' | 'error'>('idle');
const errorMessage = ref<string | null>(null);
const accessStore = useAccessStore();
const tenantStore = useTenantStore();
const userStore = useUserStore();

let bridge: IframeHostBridge | null = null;

const sandboxAttr = computed(() =>
  (props.sandbox?.length
    ? props.sandbox
    : ['allow-scripts', 'allow-same-origin', 'allow-forms']
  ).join(' '),
);

const iframeAttrs = computed(() => ({
  referrerpolicy: 'no-referrer',
  loading: 'lazy',
  sandbox: sandboxAttr.value,
  csp: props.csp,
}));

/**
 * 默认上下文提供者，基于 Pinia store 注入租户/权限/用户信息
 */
const defaultContextProvider: HostContextProvider = async (): Promise<PluginContext> => {
  const token =
    (await props.tokenProvider?.()) ?? accessStore.accessToken ?? null;
  return {
    token,
    tenantId: tenantStore.tenantId ?? null,
    siteId: tenantStore.siteId ?? null,
    permissions: accessStore.accessCodes ?? [],
    user: {
      id: userStore.userInfo?.userId,
      username: userStore.userInfo?.username,
      realName: userStore.userInfo?.realName,
      roles: userStore.userInfo?.roles ?? [],
    },
    traceId:
      typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `trace-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    issuedAt: Date.now(),
  };
};

const startBridge = async () => {
  bridge?.destroy();
  bridge = new IframeHostBridge({
    pluginKey: props.pluginKey,
    pluginUrl: props.src,
    iframeGetter: () => iframeRef.value,
    allowedOrigins: props.allowedOrigins,
    heartbeatInterval: props.heartbeatInterval,
    handshakeTimeout: props.handshakeTimeout,
    allowHttpInDev: props.allowHttpInDev,
    tokenProvider: props.tokenProvider,
    contextProvider: props.contextProvider ?? defaultContextProvider,
    onRequest: props.onRequest,
    onEvent: props.onEvent,
    onStatusChange: ({ status: next, reason }) => {
      status.value = next === 'ready' ? 'ready' : (next as typeof status.value);
      errorMessage.value = next === 'error' ? reason ?? null : null;
    },
  });
  status.value = 'loading';
  await bridge.start();
};

const handleLoad = async () => {
  await startBridge();
};

onMounted(() => {
  // iframe 刷新后重新握手
  if (iframeRef.value?.complete) {
    void startBridge();
  }
});

onBeforeUnmount(() => {
  bridge?.destroy();
});

// 监听 token / 租户变更，推送上下文
watch(
  () => [
    accessStore.accessToken,
    tenantStore.tenantId,
    tenantStore.siteId,
    accessStore.accessCodes.join(','),
    userStore.userInfo?.userId,
  ],
  () => {
    void bridge?.updateContext();
  },
);
</script>

<template>
  <iframe
    ref="iframeRef"
    :src="props.src"
    :sandbox="iframeAttrs.sandbox"
    :referrerpolicy="iframeAttrs.referrerpolicy"
    :loading="iframeAttrs.loading"
    v-bind="iframeAttrs.csp ? { csp: iframeAttrs.csp } : {}"
    class="w-full h-full border-0"
    @load="handleLoad"
  />
  <p v-if="status === 'error'" class="text-red-500 text-sm mt-2">
    {{ errorMessage || '插件加载失败' }}
  </p>
</template>
