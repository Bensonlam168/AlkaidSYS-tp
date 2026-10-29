# iframe 插件加载器与 SDK 设计（T-053）

## 背景与范围
- 依据 `design/02-app-plugin-ecosystem/06-2-plugin-system-design.md` 与 `docs/todo/plugin-system-hybrid-implementation-plan.md` 的 iframe 方案。
- `design/02-app-plugin-ecosystem/IFRAME-VS-MICROAPP-ANALYSIS-2025-01-20.md` 在仓库中缺失，以下实现基于已存在规范与安全基线文档（`design/04-security-performance/11-security-design.md`、`docs/technical-specs/security/security-guidelines*.md`）。
- 本设计覆盖：主应用 iframe 加载器、postMessage 协议、上下文注入与安全策略、插件端 SDK、测试要求。

## 协议与消息总览
- 协议版本：`1.0.0`（`protocolVersion`）。
- 消息包格式：
  - `source`: `alkaid-host` | `alkaid-plugin`
  - `type`: `handshake` | `handshake:ack` | `heartbeat` | `heartbeat:ack` | `request` | `response` | `event` | `context:update`
  - `requestId`：请求/响应关联 ID
  - `pluginKey`：插件唯一标识
  - `payload`：上下文/请求/事件具体数据
- 握手流程（host→plugin→host）：
  1. iframe 加载完成后，Host 发送 `handshake`：`{token, tenantId, siteId, permissions, userInfo, traceId, issuedAt}`。
  2. Plugin 校验 `origin` 与 `source`，返回 `handshake:ack`，附带 `sdkVersion`、`supportedVersions`、`capabilities`。
  3. Host 记录 `ready` 状态并开始心跳；握手超时视为加载失败。
- 心跳：Host 每 `heartbeatInterval` 发送 `heartbeat`，Plugin 回 `heartbeat:ack`，超时触发 offline。
- API 请求：Plugin 发送 `request`（仅允许相对路径），Host 调用注入的 `onRequest` 回调返回 `response`。
- 事件：Plugin 发送 `event`（`eventName` + `data`），Host 转发给宿主回调；Host 可用 `context:update` 将最新 token/租户上下文推送给 Plugin。

## 安全与隔离策略
- iframe 属性：
  - `sandbox` 默认 `allow-scripts allow-same-origin allow-forms`，禁止 `allow-top-navigation`，可通过配置追加。
  - `referrerpolicy="no-referrer"`，`loading="lazy"`，可选 `csp` 属性（若浏览器支持）。
- 域名/来源校验：
  - 允许列表：插件 URL origin + 显式 `allowedOrigins`；非 HTTPS 仅在开发模式允许（需显式 `allowHttpInDev`）。
  - 所有 `message` 必须满足：`origin` ∈ allowlist 且 `data.source === 'alkaid-plugin'`（或 host）且 `pluginKey` 匹配。
- 请求约束：
  - `request.path` 仅允许以 `/` 开头的相对路径，禁止直接外部 URL（避免 SSRF）。
  - 可在 `onRequest` 中执行权限校验、签名补全、trace_id 透传。
- 上下文注入：
  - token：当前 accessToken（JWT），可由宿主回调自定义。
  - 多租户：`tenantId`、`siteId`，权限列表 `permissions`，用户标识 `userId/roles/username`。
  - trace：握手时生成 `traceId`，Host 与 Plugin 侧共享。
- 防护：
  - 点击劫持：iframe 强制 `sandbox`，宿主仍需全局 `frame-ancestors`/`X-Frame-Options`。
  - 恶意消息：严格校验 `origin`、`source`、`pluginKey`、`type`，未注册 `requestId` 的消息丢弃。
  - 过期上下文：Host 在 token 变更时下发 `context:update`，Plugin 覆盖本地上下文。

## SDK API（插件侧）
- `createIframePluginClient(options)`：
  - `onReady(cb)`：ready 后回调（携带上下文）。
  - `onEvent(cb)`：监听来自 Host 的事件或 `context:update`。
  - `request(path, options)`：向 Host 请求代理 API，Promise 形式返回。
  - `emit(eventName, data)`：向 Host 发送事件。
- SDK 自动行为：
  - 侦听 `handshake` 并回复 `handshake:ack`。
  - 自动响应 `heartbeat`。
  - 校验 `hostOrigin`（以首个握手消息的 origin 为准）。

## Host 组件/管理器接口
- `IframePluginLoader`（Vue 组件）：
  - `pluginKey`、`src`、`sandbox?`、`csp?`、`allowedOrigins?`、`heartbeatInterval?`、`timeout?`。
  - `tokenProvider?`、`contextProvider?`：可自定义 JWT/上下文提供者。
  - `onRequest?`：处理插件请求，需返回 `{status,data}|{status,error}`。
  - `onEvent?`：接收插件事件。
- `IframeHostBridge`（纯 TS 管理器，组件内部使用）：
  - `start()`：绑定监听并触发握手。
  - `updateContext(ctx)`：下发最新 token/租户/权限。
  - `destroy()`：解绑监听与心跳。

## 测试要求
- 单测（Vitest + happy-dom）覆盖：
  - 握手成功/超时处理。
  - allowlist 校验：非法 origin 消息被忽略。
  - request/response 流程与相对路径约束。
  - 心跳 ack 与 offline 标记。
  - 上下文更新推送。
- 集成/E2E（后续）：
  - iframe 加载/卸载。
  - 租户/站点/权限注入正确。
  - 恶意消息、防点击劫持校验。

## 交付物
- 新包 `@alkaidsys/plugin-sdk`：Host 加载器 + 插件侧 SDK。
- 文档（本文件）与示例用法说明。
- 单元测试覆盖核心协议路径，后续补充 E2E 与后端 API 代理集成。 
