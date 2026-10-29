import type { Recordable } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace SystemTenantApi {
  /**
   * Tenant status type
   */
  export type TenantStatus = 'active' | 'inactive' | 'suspended';

  /**
   * Tenant interface
   */
  export interface SystemTenant {
    [key: string]: any;
    id: number;
    name: string;
    slug: string;
    domain?: string;
    status: TenantStatus;
    config: Record<string, any>;
    max_sites: number;
    max_users: number;
    expires_at?: string;
    created_at: string;
    updated_at: string;
  }

  /**
   * Create tenant request
   */
  export interface CreateTenantRequest {
    name: string;
    slug: string;
    domain?: string;
    status?: TenantStatus;
    config?: Record<string, any>;
    max_sites?: number;
    max_users?: number;
    expires_at?: string;
  }

  /**
   * Update tenant request
   */
  export interface UpdateTenantRequest {
    name?: string;
    slug?: string;
    domain?: string;
    status?: TenantStatus;
    config?: Record<string, any>;
    max_sites?: number;
    max_users?: number;
    expires_at?: string;
  }
}

/**
 * Get tenant list | 获取租户列表
 */
async function getTenantList(params: Recordable<any>) {
  return requestClient.get<Array<SystemTenantApi.SystemTenant>>(
    '/v1/admin/tenants',
    { params },
  );
}

/**
 * Get single tenant | 获取单个租户
 * @param id Tenant ID
 */
async function getTenant(id: number) {
  return requestClient.get<SystemTenantApi.SystemTenant>(
    `/v1/admin/tenants/${id}`,
  );
}

/**
 * Create tenant | 创建租户
 * @param data Tenant data
 */
async function createTenant(data: SystemTenantApi.CreateTenantRequest) {
  return requestClient.post<SystemTenantApi.SystemTenant>(
    '/v1/admin/tenants',
    data,
  );
}

/**
 * Update tenant | 更新租户
 * @param id Tenant ID
 * @param data Tenant data
 */
async function updateTenant(
  id: number,
  data: SystemTenantApi.UpdateTenantRequest,
) {
  return requestClient.put<SystemTenantApi.SystemTenant>(
    `/v1/admin/tenants/${id}`,
    data,
  );
}

/**
 * Delete tenant | 删除租户
 * @param id Tenant ID
 */
async function deleteTenant(id: number) {
  return requestClient.delete(`/v1/admin/tenants/${id}`);
}

/**
 * Toggle tenant status | 切换租户状态
 * @param id Tenant ID
 * @param status New status
 */
async function toggleTenantStatus(
  id: number,
  status: SystemTenantApi.TenantStatus,
) {
  return requestClient.patch<SystemTenantApi.SystemTenant>(
    `/v1/admin/tenants/${id}/status`,
    { status },
  );
}

export {
  createTenant,
  deleteTenant,
  getTenant,
  getTenantList,
  toggleTenantStatus,
  updateTenant,
};
