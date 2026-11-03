export type PluginMessageType =
  | 'context:update'
  | 'event'
  | 'handshake'
  | 'handshake:ack'
  | 'heartbeat'
  | 'heartbeat:ack'
  | 'request'
  | 'response';

export interface PluginMessage<T = unknown> {
  payload?: T;
  pluginKey: string;
  requestId?: string;
  source: 'alkaid-host' | 'alkaid-plugin';
  timestamp?: number;
  type: PluginMessageType;
  version?: string;
}

export interface PluginUserInfo {
  id?: string;
  realName?: string;
  roles?: string[];
  username?: string;
}

export interface PluginContext {
  issuedAt: number;
  permissions?: string[];
  siteId?: null | number | string;
  tenantId?: null | number | string;
  token?: null | string;
  traceId?: string;
  user?: PluginUserInfo;
}

export interface PluginRequestPayload {
  body?: unknown;
  headers?: Record<string, string>;
  method?: string;
  path: string;
}

export interface PluginResponsePayload {
  data?: unknown;
  error?: string;
  ok: boolean;
  status: number;
}

export interface PluginEventPayload {
  data?: unknown;
  eventName: string;
}

export interface PluginHandshakeAck {
  capabilities?: string[];
  sdkVersion: string;
  supportedVersions: string[];
}

export interface HostContextProvider {
  (): PluginContext | Promise<PluginContext>;
}

export interface TokenProvider {
  (): null | Promise<null | string | undefined> | string | undefined;
}

export interface PluginRequestHandler {
  (
    payload: PluginRequestPayload,
    context: PluginContext,
  ): Promise<PluginResponsePayload>;
}

export interface PluginEventHandler {
  (payload: PluginEventPayload): void;
}

export interface HostLifecycleHandler {
  (detail: { reason?: string; status: 'error' | 'offline' | 'ready' }): void;
}

export interface IframeHostOptions {
  allowedOrigins?: string[];
  allowHttpInDev?: boolean;
  contextProvider?: HostContextProvider;
  handshakeTimeout?: number;
  heartbeatInterval?: number;
  iframeGetter: () => HTMLIFrameElement | null;
  onEvent?: PluginEventHandler;
  onRequest?: PluginRequestHandler;
  onStatusChange?: HostLifecycleHandler;
  pluginKey: string;
  pluginUrl: string;
  protocolVersion?: string;
  tokenProvider?: TokenProvider;
}

export interface IframePluginClientOptions {
  allowedHostOrigins?: string[];
  pluginKey: string;
  protocolVersion?: string;
}

export interface PluginReadyDetail {
  capabilities?: string[];
  context: PluginContext;
  hostVersion: string;
}
