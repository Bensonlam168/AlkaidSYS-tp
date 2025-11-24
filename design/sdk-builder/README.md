# AlkaidSYS SDK Builder 模式对外包规范（草案 v0.1）

本规范给出面向前端/Node 使用的 SDK（建议包名：`@alkaidsys/sdk`）的设计约定、类型生成、最小用例与发布指引，确保与 AI 原生框架对齐（链式 Builder + TypeScript 强类型 + OpenAPI 同步）。

## 1. 安装与类型生成

- 安装（发布后）
```bash
npm i @alkaidsys/sdk
```

- 从 OpenAPI 生成类型（与 03-data-layer/10-api-design.md 流水线一致）
```bash
npx openapi-typescript public/api-docs.json -o src/types/api.d.ts
```

> 建议：以 CI 产物方式下发到使用方仓库；或将类型内置到 SDK 包内（保持与对应 API 版本号一致）。

## 2. SDK 设计约定

- 包结构（示例）
```
@alkaidsys/sdk
├─ dist/
├─ src/
│  ├─ client.ts         # AlkaidClient 实现
│  ├─ builder.ts        # QueryBuilder 定义
│  ├─ http.ts           # fetch 封装/重试/超时
│  ├─ auth.ts           # 令牌获取与刷新
│  ├─ errors.ts         # 标准错误包装
│  └─ types/
│     └─ api.d.ts       # 由 openapi-typescript 生成
└─ package.json
```

- ClientConfig
```ts
export interface ClientConfig {
  baseUrl: string;
  apiKey?: string;                 // 可选：用于私有/内部 API Key
  getToken?: () => Promise<string>; // 建议：动态获取 Bearer Token
  fetch?: typeof globalThis.fetch;  // 可注入自定义 fetch（如拦截器/日志）
  timeoutMs?: number;               // 默认 20_000
  retry?: { attempts: number; backoffMs: number }; // 默认 {3, 300}
}
```

- AlkaidClient（核心能力）
```ts
export class AlkaidClient {
  constructor(cfg: ClientConfig)
  db<T>(table: string): QueryBuilder<T>
  api: {
    get<T>(path: string, params?: Record<string, any>): Promise<T>
    post<T>(path: string, body?: any): Promise<T>
    put<T>(path: string, body?: any): Promise<T>
    delete<T>(path: string): Promise<T>
  }
}
```

- QueryBuilder（链式 API，示例）
```ts
export interface QueryBuilder<T> {
  select(fields: (keyof T)[] | string[]): this
  eq<K extends keyof T>(field: K, value: T[K]): this
  gt<K extends keyof T>(field: K, value: T[K]): this
  gte<K extends keyof T>(field: K, value: T[K]): this
  orderBy(field: keyof T, direction?: 'asc' | 'desc'): this
  limit(n: number): this
  page(n: number): this
  get(): Promise<T[]>
  one(): Promise<T | null>
  insert(data: Partial<T>): Promise<{ id: string | number }>
  update(data: Partial<T>): Promise<number>         // 返回影响行数
  delete(): Promise<number>
  count(): Promise<number>
}
```

- 错误模型（与后端统一）
```ts
export interface ApiErrorShape {
  code: number;
  message: string;
  data?: unknown;
}

export class ApiError extends Error {
  code: number; data?: unknown;
  constructor(e: ApiErrorShape) { super(e.message); this.code = e.code; this.data = e.data; }
}
```

## 3. 最小使用示例（前端/Node 通用）

```ts
import { createClient } from '@alkaidsys/sdk';
import type { components } from '@alkaidsys/sdk/types/api';

type Product = components['schemas']['Product'];

const client = createClient({
  baseUrl: 'https://api.example.com',
  getToken: async () => localStorage.getItem('token') || ''
});

// 1) 列表查询（强类型字段约束 + 分页）
const list = await client.db<Product>('products')
  .select(['id', 'name', 'price', 'status'])
  .eq('status', 1)
  .orderBy('created_at', 'desc')
  .limit(20)
  .page(1)
  .get();

// 2) 单条查询
const one = await client.db<Product>('products')
  .eq('id', 1001)
  .one();

// 3) 创建
const created = await client.db<Product>('products')
  .insert({ name: 'Keyboard', price: 199.0, status: 1 });

// 4) 更新
const affected = await client.db<Product>('products')
  .eq('id', created.id)
  .update({ price: 189.0 });

// 5) 直接调用 REST 接口（自动注入鉴权与超时/重试）
const stats = await client.api.get<{ total: number }>('/api/v1/products/stats');
```

> 更多示例见 `examples/usage.ts`。

## 4. 安全与性能约定
- 鉴权：优先通过 `getToken()` 动态注入 Bearer；不在代码中硬编码密钥
- 超时/重试：默认 20s/3 次，指数或线性退避；失败抛出 ApiError（保留 code/message）
- 分页：默认 page/limit 约定；集合接口务必限制上限并支持筛选/排序
- 日志与隐私：严禁输出 Token/密钥/PII；必要时以 **脱敏** 方式记录

## 5. 版本与发布（npm）
- 语义化版本：`MAJOR.MINOR.PATCH`（与后端 API 版本协同）
- package.json（要点）
```json
{
  "name": "@alkaidsys/sdk",
  "version": "1.0.0",
  "type": "module",
  "main": "dist/index.js",
  "types": "dist/index.d.ts",
  "exports": { ".": { "import": "./dist/index.js", "types": "./dist/index.d.ts" } },
  "sideEffects": false
}
```
- 构建：使用 tsup/rollup（产出 ESM + d.ts）
- 发布：
```bash
# 1) 登录（CI 使用 NPM_TOKEN）
echo "//registry.npmjs.org/:_authToken=${NPM_TOKEN}" > ~/.npmrc
# 2) 构建
npm run build
# 3) 发布
npm publish --access public
```

## 6. CI 建议
- 在 05-deployment-testing/16-development-workflow.md 的 AI 流水线后追加：
```yaml
- name: Build SDK
  run: npm run build --workspace @alkaidsys/sdk
- name: Publish SDK (manual)
  if: ${{ github.event_name == 'workflow_dispatch' && inputs.publish == 'true' }}
  run: npm publish --workspace @alkaidsys/sdk --access public
```

## 7. 与 OpenAPI 的同步策略
- 每次后端 API 变更 → 生成新的 `api-docs.json` → 产出 `api.d.ts` → bump SDK 次版本号（MINOR）
- 破坏性变更：主版本（MAJOR）+ 升级指南（Breaking Changes）

## 8. 目录与示例
- 示例：`docs/sdk-builder/examples/usage.ts`
- 对应 OpenAPI 类型：`src/types/api.d.ts`（可由 CI 自动生成）

---

维护者：AlkaidSYS 架构团队
