import { acceptHMRUpdate, defineStore } from 'pinia';

interface TenantState {
  /** 当前站点编码（预留字段） */
  siteCode: null | string;
  /** 当前站点 ID（预留字段） */
  siteId: null | number;
  /** 当前租户编码（预留字段） */
  tenantCode: null | string;
  /** 当前租户 ID */
  tenantId: null | number;
}

/**
 * @zh_CN 多租户上下文 Store
 * 用于在前端集中管理 tenant/site 相关信息，便于请求头自动注入和组件访问。
 */
export const useTenantStore = defineStore('core-tenant', {
  state: (): TenantState => ({
    tenantId: null,
    tenantCode: null,
    siteId: null,
    siteCode: null,
  }),
  actions: {
    /**
     * 设置当前租户上下文
     */
    setTenant(payload: null | Partial<TenantState>) {
      if (!payload) {
        this.clearTenant();
        return;
      }

      if (payload.tenantId !== undefined) {
        this.tenantId = payload.tenantId ?? null;
      }
      if (payload.tenantCode !== undefined) {
        this.tenantCode = payload.tenantCode ?? null;
      }
      if (payload.siteId !== undefined) {
        this.siteId = payload.siteId ?? null;
      }
      if (payload.siteCode !== undefined) {
        this.siteCode = payload.siteCode ?? null;
      }
    },
    /**
     * 清空租户上下文
     */
    clearTenant() {
      this.tenantId = null;
      this.tenantCode = null;
      this.siteId = null;
      this.siteCode = null;
    },
  },
  persist: {
    // 基于 pinia-plugin-persistedstate 持久化租户上下文。
    //
    // 【为什么不持久化 siteId / siteCode】
    // 1. 前端从未写入这两个字段（全仓无任何赋值方，见 tenant.test.ts 的用例说明），
    //    持久化它们只会让「历史遗留值」无限期存活在 localStorage 里，并在每次启动时
    //    重新注水进 state。
    // 2. siteId 一旦被注水回 state，apps/*/src/api/request.ts 的
    //    `if (tenantStore.siteId != null)` 就会自动带上 X-Site-ID 请求头；后端
    //    SiteIdentify 现已要求该站点「存在 + 属于当前租户 + status=active」，
    //    否则直接 403（code=2002）—— 即老用户升级后「一开页面就 403」。
    // 3. siteCode 是同一类「幽灵站点上下文」：当前无任何消费者，但保留持久化等于给
    //    将来新增 X-Site-Code 请求头的人埋下同一颗雷，故一并移出白名单。
    //
    // 【为什么移除 pick 就能连历史值一起挡住】
    // pinia-plugin-persistedstate v4 的 pick 是**白名单**，注水（hydrateStore →
    // deepPick）与写入（persistState → deepPick）都会经过它，因此移出 pick 既阻止
    // 新的写入，也阻止老 localStorage 里的值被注水回 state（依据见 v4.7.1
    // dist/index.js 的 hydrateStore 实现，tenant.test.ts 有对应回归用例）。
    //
    // 注意：state 字段本身（siteId / siteCode）与 setTenant 契约保持不变，
    // plugin-sdk 仍可读取 siteId（运行时恒为 null），不做破坏性删字段。
    pick: ['tenantId', 'tenantCode'],
  },
});

/**
 * @zh_CN 组件层使用的简洁 composable
 */
export function useTenant() {
  return useTenantStore();
}

// 解决热更新问题
const hot = import.meta.hot;
if (hot) {
  hot.accept(acceptHMRUpdate(useTenantStore, hot));
}
