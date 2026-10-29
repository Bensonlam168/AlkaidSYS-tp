import { acceptHMRUpdate, defineStore } from 'pinia';

interface TenantState {
  /** 当前租户 ID */
  tenantId: number | null;
  /** 当前租户编码（预留字段） */
  tenantCode: string | null;
  /** 当前站点 ID（预留字段） */
  siteId: number | null;
  /** 当前站点编码（预留字段） */
  siteCode: string | null;
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
    setTenant(payload: Partial<TenantState> | null) {
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
    // 基于 pinia-plugin-persistedstate 持久化租户上下文
    pick: ['tenantId', 'tenantCode', 'siteId', 'siteCode'],
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
