export type PluginMessageType =
  | 'handshake'
  | 'handshake:ack'
  | 'heartbeat'
  | 'heartbeat:ack'
  | 'request'
  | 'response'
  | 'event'
  | 'context:update';

export interface PluginMessage<T = unknown> {
  source: 'alkaid-host' | 'alkaid-plugin';
  type: PluginMessageType;
  pluginKey: string;
  version?: string;
  requestId?: string;
  payload?: T;
  timestamp?: number;
}

export interface PluginUserInfo {
  id?: string;
  username?: string;
  realName?: string;
  roles?: string[];
}

export interface PluginContext {
  token?: string | null;
  tenantId?: number | string | null;
  siteId?: number | string | null;
  permissions?: string[];
  user?: PluginUserInfo;
  traceId?: string;
  issuedAt: number;
}

export interface PluginRequestPayload {
  path: string;
  method?: string;
  headers?: Record<string, string>;
  body?: unknown;
}

export interface PluginResponsePayload {
  ok: boolean;
  status: number;
  data?: unknown;
  error?: string;
}

export interface PluginEventPayload {
  eventName: string;
  data?: unknown;
}

export interface PluginHandshakeAck {
  sdkVersion: string;
  supportedVersions: string[];
  capabilities?: string[];
}

export interface HostContextProvider {
  (): Promise<PluginContext> | PluginContext;
}

export interface TokenProvider {
  (): Promise<string | null | undefined> | string | null | undefined;
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
  (detail: { status: 'ready' | 'offline' | 'error'; reason?: string }): void;
}

export interface IframeHostOptions {
  pluginKey: string;
  pluginUrl: string;
  iframeGetter: () => HTMLIFrameElement | null;
  allowedOrigins?: string[];
  protocolVersion?: string;
  heartbeatInterval?: number;
  handshakeTimeout?: number;
  allowHttpInDev?: boolean;
  tokenProvider?: TokenProvider;
  contextProvider?: HostContextProvider;
  onRequest?: PluginRequestHandler;
  onEvent?: PluginEventHandler;
  onStatusChange?: HostLifecycleHandler;
}

export interface IframePluginClientOptions {
  pluginKey: string;
  protocolVersion?: string;
  allowedHostOrigins?: string[];
}

export interface PluginReadyDetail {
  context: PluginContext;
  hostVersion: string;
  capabilities?: string[];
}
