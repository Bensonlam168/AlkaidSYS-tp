import { createApp, nextTick } from 'vue';

import { createPinia, defineStore, setActivePinia } from 'pinia';
import { createPersistedState } from 'pinia-plugin-persistedstate';
import { beforeEach, describe, expect, it } from 'vitest';

import { useTenantStore } from './tenant';

/**
 * 持久化 key 规则与线上保持一致（见 packages/stores/src/setup.ts 与 apps/*\/src/main.ts）：
 *   namespace = `${VITE_APP_NAMESPACE}-${appVersion}-${env}`
 *   key       = `${namespace}-${store.$id}`
 * 这里固定一个 namespace 常量，让测试不依赖具体构建期变量。
 */
const TEST_NAMESPACE = 'vben-web-antd-5.5.9-dev';
/** Tenant Store 实际落盘的 localStorage key */
const STORAGE_KEY = `${TEST_NAMESPACE}-core-tenant`;

/**
 * 用与 setup.ts 完全一致的插件参数（自定义 key 前缀 + localStorage）创建 pinia。
 * setup.ts 在 DEV 分支用的就是裸 localStorage，因此这里的存储语义与开发环境等价。
 *
 * 注意：pinia 插件只有在 `app.use(pinia)` 之后才会真正生效（pinia 的内部实现会先把
 * 提前 use 的插件暂存到 toBeInstalled，安装到 app 时才并入 _p），因此这里必须建一个
 * 空的 Vue 应用并安装 pinia —— 这与 setup.ts 的 `app.use(pinia)` 流程保持一致。
 */
function createPersistedPinia() {
  const pinia = createPinia();
  pinia.use(
    createPersistedState({
      key: (storeId: string) => `${TEST_NAMESPACE}-${storeId}`,
      storage: localStorage,
    }),
  );
  createApp({ render: () => null }).use(pinia);
  return pinia;
}

/** 读取 Tenant Store 在 localStorage 中的持久化快照 */
function readPersisted(): null | Record<string, unknown> {
  const raw = localStorage.getItem(STORAGE_KEY);
  return raw ? (JSON.parse(raw) as Record<string, unknown>) : null;
}

/**
 * 对照组用的「旧版」store 定义：pick 里仍然包含 siteId / siteCode。
 * 仅用于证明上面的夹具（storage key + 数据结构）确实会触发插件注水，
 * 避免「因为插件根本没装好，所以 siteId 恒为 null」这种假绿。
 */
const useLegacyTenantStore = defineStore('core-tenant', {
  state: () => ({
    tenantId: null as null | number,
    tenantCode: null as null | string,
    siteId: null as null | number,
    siteCode: null as null | string,
  }),
  persist: {
    pick: ['tenantId', 'tenantCode', 'siteId', 'siteCode'],
  },
});

describe('useTenantStore', () => {
  beforeEach(() => {
    // 清空 localStorage，避免用例之间相互污染
    localStorage.clear();
    setActivePinia(createPinia());
  });

  it('initializes with null tenant context', () => {
    const store = useTenantStore();
    expect(store.tenantId).toBeNull();
    expect(store.tenantCode).toBeNull();
    expect(store.siteId).toBeNull();
    expect(store.siteCode).toBeNull();
  });

  it('sets and clears tenant context correctly', () => {
    const store = useTenantStore();

    store.setTenant({
      tenantId: 1,
      tenantCode: 'demo',
      siteId: 2,
      siteCode: 'main',
    });

    expect(store.tenantId).toBe(1);
    expect(store.tenantCode).toBe('demo');
    expect(store.siteId).toBe(2);
    expect(store.siteCode).toBe('main');

    store.clearTenant();

    expect(store.tenantId).toBeNull();
    expect(store.tenantCode).toBeNull();
    expect(store.siteId).toBeNull();
    expect(store.siteCode).toBeNull();
  });

  /**
   * 这一组用例保护「老用户升级后不会带着残留 siteId 发 X-Site-ID 而被后端 403」。
   * 覆盖范围：真实安装 pinia-plugin-persistedstate（v4.7.1）+ 真实 localStorage
   * + 与线上一致的 key 规则，即「插件注水 / 写入是否受 pick 约束」这条真实链路。
   */
  describe('persist whitelist（siteId 必须被彻底隔离）', () => {
    it('对照用例：把 siteId 放回 pick 时历史值确实会被注水（证明夹具真的走了注水链路）', () => {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ tenantId: 1, siteId: 999, tenantCode: 'demo' }),
      );

      setActivePinia(createPersistedPinia());
      const legacyStore = useLegacyTenantStore();

      // 旧定义下 siteId=999 会被注水回 state —— 这正是需要修掉的行为；
      // 该断言同时反证：上面的 storage key / 插件安装方式都是真实生效的。
      expect(legacyStore.tenantId).toBe(1);
      expect(legacyStore.siteId).toBe(999);
    });

    it('不会把历史 localStorage 里的 siteId / siteCode 注水回 state', () => {
      // 模拟老用户：升级前的 localStorage 里存着 siteId=999（该站点已不存在/跨租户/停用）
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          tenantId: 1,
          tenantCode: 'demo',
          siteId: 999,
          siteCode: 'legacy-site',
        }),
      );

      setActivePinia(createPersistedPinia());
      const store = useTenantStore();

      // 站点上下文必须被彻底丢弃，否则 request.ts 会带上 X-Site-ID 触发 403
      expect(store.siteId).toBeNull();
      expect(store.siteCode).toBeNull();
      // 白名单内的字段行为不变
      expect(store.tenantId).toBe(1);
      expect(store.tenantCode).toBe('demo');
    });

    it('新写入的 storage 不含 siteId / siteCode', async () => {
      setActivePinia(createPersistedPinia());
      const store = useTenantStore();

      store.setTenant({ tenantId: 7 });
      // $subscribe 的 watcher 默认 pre flush，需等一个 tick 才会落盘
      await nextTick();

      const persisted = readPersisted();
      // 深比较同时验证「没有多余的 siteId / siteCode 键」
      expect(persisted).toEqual({ tenantCode: null, tenantId: 7 });
    });

    it('一次变更后会把遗留的 siteId 从 storage 中清除（自愈）', async () => {
      // 老 storage 带 siteId
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ tenantId: 1, siteId: 999, tenantCode: 'demo' }),
      );

      setActivePinia(createPersistedPinia());
      const store = useTenantStore();
      expect(store.siteId).toBeNull();

      // 任何一次 state 变更都会用「白名单过滤后的 state」重写 storage，
      // 历史 siteId 因此被自然垃圾回收，不会长期滞留在浏览器里。
      store.setTenant({ tenantId: 2 });
      await nextTick();

      // {tenantId: 2, tenantCode: 'demo'}，且不含 siteId
      expect(readPersisted()).toEqual({ tenantCode: 'demo', tenantId: 2 });
    });

    it('setTenant({ tenantId }) 之后 siteId 仍为 null（不会被意外设置）', async () => {
      // 即使带历史值启动，登录后 /v1/auth/me 只回填 tenantId，也不得复活 siteId
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ tenantId: 1, siteId: 999 }),
      );

      setActivePinia(createPersistedPinia());
      const store = useTenantStore();

      store.setTenant({ tenantId: 1 });
      expect(store.siteId).toBeNull();
      expect(store.siteCode).toBeNull();

      // tenantId 真正变化时，storage 会被白名单过滤后的 state 重写，
      // 历史 siteId 随之被清除（注意：值不变时 pinia 的 deep watch 不触发，
      // 因此不会重写 storage —— 这一点对「不 403」没有影响，因为注水阶段
      // siteId 已被 pick 挡在 state 之外）。
      store.setTenant({ tenantId: 3 });
      await nextTick();

      expect(store.siteId).toBeNull();
      expect(readPersisted()).toEqual({ tenantCode: null, tenantId: 3 });
    });

    it('回归：tenantId / tenantCode 的持久化行为不变', async () => {
      // 注水：白名单字段仍能正常恢复
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ tenantId: 42, tenantCode: 'tenant-42' }),
      );

      setActivePinia(createPersistedPinia());
      const store = useTenantStore();
      expect(store.tenantId).toBe(42);
      expect(store.tenantCode).toBe('tenant-42');

      // 写入：变更后仍会落盘
      store.setTenant({ tenantId: 43, tenantCode: 'tenant-43' });
      await nextTick();

      const persisted = readPersisted();
      expect(persisted).toEqual({ tenantId: 43, tenantCode: 'tenant-43' });
    });

    it('回归：clearTenant 后 storage 仅保留空白的租户字段', async () => {
      setActivePinia(createPersistedPinia());
      const store = useTenantStore();

      store.setTenant({ tenantId: 5, tenantCode: 'tenant-5' });
      await nextTick();
      store.clearTenant();
      await nextTick();

      expect(readPersisted()).toEqual({ tenantId: null, tenantCode: null });
    });
  });
});
