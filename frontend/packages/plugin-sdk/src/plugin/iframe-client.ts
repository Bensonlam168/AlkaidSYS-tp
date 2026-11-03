import type {
  IframePluginClientOptions,
  PluginContext,
  PluginMessage,
  PluginRequestPayload,
  PluginResponsePayload,
} from '../types';

const SDK_VERSION = '0.1.0';
const DEFAULT_PROTOCOL_VERSION = '1.0.0';

type ReadyCallback = (detail: { context: PluginContext }) => void;
type EventCallback = (eventName: string, data?: unknown) => void;

/**
 * 插件侧 postMessage SDK，封装握手、心跳与请求/事件通信
 */
export class IframePluginClient {
  private readonly pluginKey: string;
  private readonly protocolVersion: string;
  private readonly allowedHostOrigins: Set<string>;
  private hostOrigin: string | null = null;
  private hostWindow: Window | null = null;
  private readyContext: PluginContext | null = null;
  private readyCallbacks: ReadyCallback[] = [];
  private eventCallbacks: EventCallback[] = [];
  private pendingRequests = new Map<
    string,
    {
      resolve: (value: PluginResponsePayload) => void;
      reject: (reason?: unknown) => void;
    }
  >();
  private readyPromise: Promise<void>;
  private resolveReady!: () => void;

  constructor(options: IframePluginClientOptions) {
    this.pluginKey = options.pluginKey;
    this.protocolVersion = options.protocolVersion ?? DEFAULT_PROTOCOL_VERSION;
    this.allowedHostOrigins = new Set(options.allowedHostOrigins ?? []);
    this.readyPromise = new Promise((resolve) => {
      this.resolveReady = resolve;
    });
  }

  /**
   * 初始化监听
   */
  start() {
    window.addEventListener('message', this.handleMessage);
  }

  /**
   * ready 回调
   */
  onReady(callback: ReadyCallback) {
    if (this.readyContext) {
      callback({ context: this.readyContext });
      return;
    }
    this.readyCallbacks.push(callback);
  }

  /**
   * 监听 Host 事件（含 context:update）
   */
  onEvent(callback: EventCallback) {
    this.eventCallbacks.push(callback);
  }

  /**
   * 发起 API 请求，Host 代理执行
   */
  async request(
    path: string,
    options: Omit<PluginRequestPayload, 'path'> = {},
  ): Promise<PluginResponsePayload> {
    if (!path.startsWith('/')) {
      throw new Error('仅允许相对路径请求');
    }

    await this.waitReady();
    const requestId = this.generateRequestId();
    const message: PluginMessage<PluginRequestPayload> = {
      source: 'alkaid-plugin',
      type: 'request',
      pluginKey: this.pluginKey,
      requestId,
      version: this.protocolVersion,
      payload: { ...options, path },
      timestamp: Date.now(),
    };

    return await new Promise<PluginResponsePayload>((resolve, reject) => {
      this.pendingRequests.set(requestId, { resolve, reject });
      this.postToHost(message);
    });
  }

  /**
   * 插件向 Host 发送事件
   */
  async emit(eventName: string, data?: unknown) {
    await this.waitReady();
    const message: PluginMessage = {
      source: 'alkaid-plugin',
      type: 'event',
      pluginKey: this.pluginKey,
      version: this.protocolVersion,
      payload: { eventName, data },
      timestamp: Date.now(),
    };
    this.postToHost(message);
  }

  /**
   * 等待 ready
   */
  private async waitReady() {
    await this.readyPromise;
  }

  /**
   * 处理 Host 消息
   */
  private handleMessage = (event: MessageEvent) => {
    const data = event.data as PluginMessage;
    if (!data || typeof data !== 'object') return;
    if (data.pluginKey !== this.pluginKey) return;
    if (data.source !== 'alkaid-host') return;

    // 首次握手记录 host 来源
    if (!this.hostOrigin) {
      if (this.allowedHostOrigins.size > 0 && !this.allowedHostOrigins.has(event.origin)) {
        return;
      }
      this.hostOrigin = event.origin;
      this.hostWindow = event.source as Window;
    } else if (event.origin !== this.hostOrigin) {
      return;
    }

    switch (data.type) {
      case 'handshake':
        this.onHandshake(data);
        break;
      case 'heartbeat':
        this.replyHeartbeat();
        break;
      case 'response':
        this.handleResponse(data);
        break;
      case 'context:update':
        this.handleContextUpdate(data.payload as PluginContext);
        break;
      case 'event':
        this.dispatchEventFromHost(data.payload as { eventName: string; data?: unknown });
        break;
      default:
        break;
    }
  };

  /**
   * 握手处理，记录上下文并返回 ack
   */
  private onHandshake(message: PluginMessage<PluginContext>) {
    this.readyContext =
      message.payload ??
      ({
        issuedAt: Date.now(),
      } satisfies PluginContext);
    const ack: PluginMessage = {
      source: 'alkaid-plugin',
      type: 'handshake:ack',
      pluginKey: this.pluginKey,
      version: this.protocolVersion,
      payload: {
        sdkVersion: SDK_VERSION,
        supportedVersions: [this.protocolVersion],
      },
      timestamp: Date.now(),
    };
    this.postToHost(ack);

    this.resolveReady();
    this.readyCallbacks
      .splice(0)
      .forEach((cb) => cb({ context: this.readyContext! }));
  }

  /**
   * 心跳回应
   */
  private replyHeartbeat() {
    const ack: PluginMessage = {
      source: 'alkaid-plugin',
      type: 'heartbeat:ack',
      pluginKey: this.pluginKey,
      version: this.protocolVersion,
      timestamp: Date.now(),
    };
    this.postToHost(ack);
  }

  /**
   * 处理 Host 响应
   */
  private handleResponse(message: PluginMessage<PluginResponsePayload>) {
    const requestId = message.requestId;
    if (!requestId) return;
    const resolver = this.pendingRequests.get(requestId);
    if (!resolver) return;
    this.pendingRequests.delete(requestId);
    resolver.resolve(message.payload as PluginResponsePayload);
  }

  /**
   * 上下文更新
   */
  private handleContextUpdate(context?: PluginContext) {
    if (!context) return;
    this.readyContext = context;
    this.dispatchEventFromHost({ eventName: 'context:update', data: context });
  }

  /**
   * Host 事件转发给回调
   */
  private dispatchEventFromHost(event?: { eventName: string; data?: unknown }) {
    if (!event) return;
    this.eventCallbacks.forEach((cb) => cb(event.eventName, event.data));
  }

  /**
   * 发送消息到 Host
   */
  private postToHost(message: PluginMessage) {
    if (!this.hostOrigin || !this.hostWindow) {
      // 尚未握手成功，不发送
      return;
    }
    this.hostWindow.postMessage(message, this.hostOrigin);
  }

  /**
   * 生成请求 ID
   */
  private generateRequestId() {
    return `req-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  }
}
