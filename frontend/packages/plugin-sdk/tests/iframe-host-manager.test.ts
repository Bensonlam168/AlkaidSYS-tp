import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

import { IframeHostBridge } from '../src/host/iframe-host-manager';
import type { PluginMessage } from '../src/types';

class MockContentWindow {
  sent: Array<{ message: any; targetOrigin?: string }> = [];

  postMessage(message: any, targetOrigin?: string) {
    this.sent.push({ message, targetOrigin });
  }
}

const pluginOrigin = 'https://plugin.example.com';
const pluginUrl = `${pluginOrigin}/app`;

const createIframe = () => {
  const mockWindow = new MockContentWindow();
  const iframe = {
    contentWindow: mockWindow as unknown as Window,
  } as HTMLIFrameElement;
  return { iframe, mockWindow };
};

describe('IframeHostBridge', () => {
  beforeEach(() => {
    // 为单测注入 Pinia 实例，避免 useStore 无激活实例报错
    setActivePinia(createPinia());
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('握手应携带上下文并接受 ack', async () => {
    const { iframe, mockWindow } = createIframe();
    const statuses: string[] = [];
    const bridge = new IframeHostBridge({
      pluginKey: 'demo',
      pluginUrl,
      iframeGetter: () => iframe,
      contextProvider: async () => ({
        token: 'jwt-token',
        tenantId: 1,
        siteId: 2,
        permissions: ['a'],
        user: { id: 'u1', username: 'alice', roles: ['admin'] },
        issuedAt: 1,
      }),
      onStatusChange: ({ status }) => statuses.push(status),
    });

    await bridge.start();
    const handshake = mockWindow.sent.find(
      (item) => (item.message as PluginMessage).type === 'handshake',
    );
    expect(handshake).toBeTruthy();
    expect(handshake?.message.payload.token).toBe('jwt-token');
    expect(handshake?.targetOrigin).toBe(pluginOrigin);

    const ack = new MessageEvent('message', {
      origin: pluginOrigin,
      data: {
        source: 'alkaid-plugin',
        type: 'handshake:ack',
        pluginKey: 'demo',
        payload: { supportedVersions: ['1.0.0'] },
      },
    });
    window.dispatchEvent(ack);

    expect(statuses).toContain('ready');
  });

  it('应拒绝非允许 origin 的消息', async () => {
    const { iframe, mockWindow } = createIframe();
    const bridge = new IframeHostBridge({
      pluginKey: 'demo',
      pluginUrl,
      iframeGetter: () => iframe,
      allowedOrigins: [pluginOrigin],
    });
    await bridge.start();

    const bad = new MessageEvent('message', {
      origin: 'https://evil.example.com',
      data: {
        source: 'alkaid-plugin',
        type: 'handshake:ack',
        pluginKey: 'demo',
      },
    });
    window.dispatchEvent(bad);
    expect(mockWindow.sent.length).toBeGreaterThan(0);
    const before = mockWindow.sent.length;

    const request = new MessageEvent('message', {
      origin: 'https://evil.example.com',
      data: {
        source: 'alkaid-plugin',
        type: 'request',
        pluginKey: 'demo',
        requestId: 'x1',
        payload: { path: '/api' },
      },
    });
    window.dispatchEvent(request);

    // 无新响应（origin 被拦截）
    expect(mockWindow.sent.length).toBe(before);
  });

  it('应处理相对路径请求并拒绝绝对路径', async () => {
    const { iframe, mockWindow } = createIframe();
    const bridge = new IframeHostBridge({
      pluginKey: 'demo',
      pluginUrl,
      iframeGetter: () => iframe,
      onRequest: async () => ({ ok: true, status: 200, data: { ok: 1 } }),
    });
    await bridge.start();

    const ack = new MessageEvent('message', {
      origin: pluginOrigin,
      data: {
        source: 'alkaid-plugin',
        type: 'handshake:ack',
        pluginKey: 'demo',
        payload: { supportedVersions: ['1.0.0'] },
      },
    });
    window.dispatchEvent(ack);

    const request = new MessageEvent('message', {
      origin: pluginOrigin,
      data: {
        source: 'alkaid-plugin',
        type: 'request',
        pluginKey: 'demo',
        requestId: 'r1',
        payload: { path: '/ok', method: 'GET' },
      },
    });
    window.dispatchEvent(request);

    await vi.waitUntil(() =>
      mockWindow.sent.some(
        (item) => (item.message as PluginMessage).requestId === 'r1',
      ),
    );
    const response = mockWindow.sent.find(
      (item) => (item.message as PluginMessage).requestId === 'r1',
    )?.message as PluginMessage | undefined;
    expect((response?.payload as any)?.ok).toBe(true);

    const badRequest = new MessageEvent('message', {
      origin: pluginOrigin,
      data: {
        source: 'alkaid-plugin',
        type: 'request',
        pluginKey: 'demo',
        requestId: 'r2',
        payload: { path: 'https://evil', method: 'GET' },
      },
    });
    window.dispatchEvent(badRequest);
    const badResp = mockWindow.sent.find(
      (item) => (item.message as PluginMessage).requestId === 'r2',
    )?.message as PluginMessage;
    expect(badResp.payload.ok).toBe(false);
    expect(badResp.payload.status).toBe(400);
  });

  it('心跳超时应标记 offline', async () => {
    vi.useFakeTimers();
    const { iframe } = createIframe();
    const statuses: string[] = [];
    const bridge = new IframeHostBridge({
      pluginKey: 'demo',
      pluginUrl,
      iframeGetter: () => iframe,
      heartbeatInterval: 20,
      onStatusChange: ({ status }) => statuses.push(status),
    });

    await bridge.start();
    const ack = new MessageEvent('message', {
      origin: pluginOrigin,
      data: {
        source: 'alkaid-plugin',
        type: 'handshake:ack',
        pluginKey: 'demo',
        payload: { supportedVersions: ['1.0.0'] },
      },
    });
    window.dispatchEvent(ack);

    // 推进时间，超过两倍心跳周期触发 offline
    await vi.advanceTimersByTimeAsync(120);
    await vi.waitUntil(() => statuses.includes('offline'));
  });
});
