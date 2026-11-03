import type { Recordable } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace SystemRoleApi {
  /**
   * Permission interface
   */
  export interface Permission {
    id: number;
    name: string;
    slug: string;
    resource?: string;
    action?: string;
    description?: string;
  }

  /**
   * Role interface
   */
  export interface SystemRole {
    [key: string]: any;
    id: number;
    tenant_id: number;
    name: string;
    slug: string;
    description?: string;
    is_system: boolean;
    permissions: number[];
    created_at: string;
    updated_at: string;
  }

  /**
   * Create role request
   */
  export interface CreateRoleRequest {
    name: string;
    slug: string;
    description?: string;
    permissions?: number[];
  }

  /**
   * Update role request
   */
  export interface UpdateRoleRequest {
    name?: string;
    slug?: string;
    description?: string;
    permissions?: number[];
  }

  /**
   * Permissions response
   */
  export interface PermissionsResponse {
    list: Permission[];
    grouped: Record<string, Permission[]>;
  }
}

/**
 * Get role list | 获取角色列表
 */
async function getRoleList(params: Recordable<any>) {
  return requestClient.get<Array<SystemRoleApi.SystemRole>>('/v1/admin/roles', {
    params,
  });
}

/**
 * Get single role | 获取单个角色
 * @param id Role ID
 */
async function getRole(id: number) {
  return requestClient.get<SystemRoleApi.SystemRole>(`/v1/admin/roles/${id}`);
}

/**
 * Create role | 创建角色
 * @param data Role data
 */
async function createRole(data: SystemRoleApi.CreateRoleRequest) {
  return requestClient.post<SystemRoleApi.SystemRole>('/v1/admin/roles', data);
}

/**
 * Update role | 更新角色
 * @param id Role ID
 * @param data Role data
 */
async function updateRole(id: number, data: SystemRoleApi.UpdateRoleRequest) {
  return requestClient.put<SystemRoleApi.SystemRole>(
    `/v1/admin/roles/${id}`,
    data,
  );
}

/**
 * Delete role | 删除角色
 * @param id Role ID
 */
async function deleteRole(id: number) {
  return requestClient.delete(`/v1/admin/roles/${id}`);
}

/**
 * Set role permissions | 设置角色权限
 * @param id Role ID
 * @param permissions Permission IDs
 */
async function setRolePermissions(id: number, permissions: number[]) {
  return requestClient.put<SystemRoleApi.SystemRole>(
    `/v1/admin/roles/${id}/permissions`,
    { permissions },
  );
}

/**
 * Get all permissions | 获取所有权限
 * Uses the grouped endpoint to get both list and grouped permissions.
 * 使用分组端点获取列表和分组权限。
 */
async function getAllPermissions() {
  return requestClient.get<SystemRoleApi.PermissionsResponse>(
    '/v1/admin/permissions/grouped',
  );
}

export {
  createRole,
  deleteRole,
  getAllPermissions,
  getRole,
  getRoleList,
  setRolePermissions,
  updateRole,
};
