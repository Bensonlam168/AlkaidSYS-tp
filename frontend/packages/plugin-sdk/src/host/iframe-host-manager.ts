import {
  useAccessStore,
  useTenantStore,
  useUserStore,
} from '@vben/stores';

import type {
  IframeHostOptions,
  PluginContext,
  PluginEventPayload,
  PluginHandshakeAck,
  PluginMessage,
  PluginRequestPayload,
  PluginResponsePayload,
} from '../types';

const DEFAULT_PROTOCOL_VERSION = '1.0.0';
const DEFAULT_HEARTBEAT_INTERVAL = 15000;
const DEFAULT_HANDSHAKE_TIMEOUT = 8000;

/**
 * Host 侧 iframe 通信管理器，负责握手、心跳、安全校验与请求转发
 */
export class IframeHostBridge {
  private readonly pluginOrigin: string;
  private readonly allowedOrigins: Set<string>;
  private readonly protocolVersion: string;
  private readonly heartbeatInterval: number;
  private readonly handshakeTimeout: number;
  private handshakeTimer: number | null = null;
  private heartbeatTimer: number | null = null;
  private lastHeartbeatAck = 0;
  private ready = false;
  private destroyed = false;
  private pendingContext: PluginContext | null = null;

  constructor(private readonly options: IframeHostOptions) {
    const url = new URL(options.pluginUrl);
    if (url.protocol !== 'https:' && !options.allowHttpInDev && !import.meta.env.DEV) {
      throw new Error('iframe 插件仅允许 HTTPS，开发模式外禁止非安全协议');
    }

    this.pluginOrigin = url.origin;
    this.allowedOrigins = new Set([
      this.pluginOrigin,
      ...(options.allowedOrigins ?? []),
    ]);
    this.protocolVersion = options.protocolVersion ?? DEFAULT_PROTOCOL_VERSION;
    this.heartbeatInterval =
      options.heartbeatInterval ?? DEFAULT_HEARTBEAT_INTERVAL;
    this.handshakeTimeout = options.handshakeTimeout ?? DEFAULT_HANDSHAKE_TIMEOUT;
  }

  /**
   * 启动握手流程
   */
  async start() {
    if (this.destroyed) return;
    window.addEventListener('message', this.handleMessage);
    await this.sendHandshake();
  }

  /**
   * 更新上下文（token/租户/权限），ready 前会缓存，ready 后立即下发
   */
  async updateContext(ctx?: PluginContext) {
    const context = ctx ?? (await this.resolveContext());
    this.pendingContext = context;
    if (!this.ready) return;
    this.postMessage({
      source: 'alkaid-host',
      type: 'context:update',
      pluginKey: this.options.pluginKey,
      version: this.protocolVersion,
      payload: context,
      timestamp: Date.now(),
    });
  }

  /**
   * 清理资源
   */
  destroy() {
    this.destroyed = true;
    window.removeEventListener('message', this.handleMessage);
    if (this.handshakeTimer) {
      window.clearTimeout(this.handshakeTimer);
    }
    if (this.heartbeatTimer) {
      window.clearInterval(this.heartbeatTimer);
    }
  }

  /**
   * 发送握手消息
   */
  private async sendHandshake() {
    const iframe = this.options.iframeGetter();
    if (!iframe?.contentWindow) {
      this.emitStatus('error', 'iframe 尚未就绪，无法握手');
      return;
    }

    const context = await this.resolveContext();
    this.pendingContext = context;
    this.ready = false;

    const message: PluginMessage<PluginContext> = {
      source: 'alkaid-host',
      type: 'handshake',
      pluginKey: this.options.pluginKey,
      version: this.protocolVersion,
      payload: context,
      timestamp: Date.now(),
    };

    iframe.contentWindow.postMessage(message, this.pluginOrigin);

    if (this.handshakeTimer) window.clearTimeout(this.handshakeTimer);
    this.handshakeTimer = window.setTimeout(() => {
      if (!this.ready) {
        this.emitStatus('error', '握手超时');
        this.destroy();
      }
    }, this.handshakeTimeout);
  }

  /**
   * 处理来自插件的消息
   */
  private handleMessage = (event: MessageEvent) => {
    if (this.destroyed) return;
    if (!this.allowedOrigins.has(event.origin)) return;

    const data = event.data as PluginMessage;
    if (!data || typeof data !== 'object') return;
    if (data.pluginKey !== this.options.pluginKey) return;
    if (data.source !== 'alkaid-plugin') return;

    switch (data.type) {
      case 'handshake:ack':
        this.onHandshakeAck(data.payload as PluginHandshakeAck | undefined);
        break;
      case 'heartbeat:ack':
        this.lastHeartbeatAck = Date.now();
        break;
      case 'request':
        this.handleRequest(data);
        break;
      case 'event':
        this.handleEvent(data.payload as PluginEventPayload);
        break;
      default:
        break;
    }
  };

  /**
   * 握手确认处理
   */
  private onHandshakeAck(ack?: PluginHandshakeAck) {
    if (
      ack?.supportedVersions &&
      !ack.supportedVersions.includes(this.protocolVersion)
    ) {
      this.emitStatus('error', '协议版本不兼容');
      this.destroy();
      return;
    }

    this.ready = true;
    this.lastHeartbeatAck = Date.now();
    if (this.handshakeTimer) {
      window.clearTimeout(this.handshakeTimer);
      this.handshakeTimer = null;
    }
    if (this.pendingContext) {
      // ready 后补发上下文
      this.updateContext(this.pendingContext);
    }
    this.startHeartbeat();
    this.emitStatus('ready');
  }

  /**
   * 心跳调度
   */
  private startHeartbeat() {
    if (this.heartbeatTimer) window.clearInterval(this.heartbeatTimer);
    this.heartbeatTimer = window.setInterval(() => {
      if (this.destroyed) return;
      const iframe = this.options.iframeGetter();
      if (!iframe?.contentWindow) return;

      const now = Date.now();
      if (this.lastHeartbeatAck && now - this.lastHeartbeatAck > this.heartbeatInterval * 2) {
        this.emitStatus('offline', '心跳超时');
      }

      const heartbeat: PluginMessage = {
        source: 'alkaid-host',
        type: 'heartbeat',
        pluginKey: this.options.pluginKey,
        version: this.protocolVersion,
        timestamp: now,
      };
      iframe.contentWindow.postMessage(heartbeat, this.pluginOrigin);
    }, this.heartbeatInterval);
  }

  /**
   * 处理插件请求，限制仅允许相对路径
   */
  private async handleRequest(message: PluginMessage<PluginRequestPayload>) {
    const iframe = this.options.iframeGetter();
    if (!iframe?.contentWindow) return;

    const payload = message.payload;
    const requestId = message.requestId;
    if (!payload || !requestId) return;

    if (!payload.path?.startsWith('/')) {
      const resp: PluginMessage<PluginResponsePayload> = {
        source: 'alkaid-host',
        type: 'response',
        pluginKey: this.options.pluginKey,
        requestId,
        payload: { ok: false, status: 400, error: '仅允许相对路径请求' },
        timestamp: Date.now(),
      };
      iframe.contentWindow.postMessage(resp, this.pluginOrigin);
      return;
    }

    if (!this.options.onRequest) {
      const resp: PluginMessage<PluginResponsePayload> = {
        source: 'alkaid-host',
        type: 'response',
        pluginKey: this.options.pluginKey,
        requestId,
        payload: { ok: false, status: 501, error: 'Host 未注册 onRequest 处理器' },
        timestamp: Date.now(),
      };
      iframe.contentWindow.postMessage(resp, this.pluginOrigin);
      return;
    }

    try {
      const ctx = await this.resolveContext();
      const result = await this.options.onRequest(payload, ctx);
      const resp: PluginMessage<PluginResponsePayload> = {
        source: 'alkaid-host',
        type: 'response',
        pluginKey: this.options.pluginKey,
        requestId,
        payload: result,
        timestamp: Date.now(),
      };
      iframe.contentWindow.postMessage(resp, this.pluginOrigin);
    } catch (error: any) {
      const resp: PluginMessage<PluginResponsePayload> = {
        source: 'alkaid-host',
        type: 'response',
        pluginKey: this.options.pluginKey,
        requestId,
        payload: {
          ok: false,
          status: 500,
          error: error?.message ?? 'Host 处理请求失败',
        },
        timestamp: Date.now(),
      };
      iframe.contentWindow.postMessage(resp, this.pluginOrigin);
    }
  }

  /**
   * 处理插件事件
   */
  private handleEvent(payload?: PluginEventPayload) {
    if (!payload) return;
    this.options.onEvent?.(payload);
  }

  /**
   * 统一发送消息给插件
   */
  private postMessage(message: PluginMessage) {
    const iframe = this.options.iframeGetter();
    if (!iframe?.contentWindow) return;
    iframe.contentWindow.postMessage(message, this.pluginOrigin);
  }

  /**
   * 生成上下文（默认从 Pinia store 获取）
   */
  private async resolveContext(): Promise<PluginContext> {
    if (this.options.contextProvider) {
      return await this.options.contextProvider();
    }

    const accessStore = useAccessStore();
    const tenantStore = useTenantStore();
    const userStore = useUserStore();

    const token =
      (await this.options.tokenProvider?.()) ?? accessStore.accessToken ?? null;

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
  }

  /**
   * 状态变更回调
   */
  private emitStatus(status: 'ready' | 'offline' | 'error', reason?: string) {
    this.options.onStatusChange?.({ status, reason });
  }
}
