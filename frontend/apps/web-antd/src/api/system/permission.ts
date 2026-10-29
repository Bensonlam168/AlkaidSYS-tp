import type { Recordable } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace SystemPermissionApi {
  /**
   * Permission interface | 权限接口
   */
  export interface SystemPermission {
    [key: string]: any;
    id: number;
    name: string;
    slug: string;
    resource?: string;
    action?: string;
    description?: string;
    roles?: number[];
    created_at: string;
    updated_at: string;
  }

  /**
   * Create permission request | 创建权限请求
   */
  export interface CreatePermissionRequest {
    name: string;
    slug: string;
    resource?: string;
    action?: string;
    description?: string;
  }

  /**
   * Update permission request | 更新权限请求
   */
  export interface UpdatePermissionRequest {
    name?: string;
    slug?: string;
    resource?: string;
    action?: string;
    description?: string;
  }

  /**
   * Grouped permissions response | 分组权限响应
   */
  export interface GroupedPermissionsResponse {
    grouped: Record<string, SystemPermission[]>;
  }

  /**
   * Resources response | 资源列表响应
   */
  export interface ResourcesResponse {
    list: string[];
  }

  /**
   * Actions response | 操作列表响应
   */
  export interface ActionsResponse {
    list: string[];
  }
}

/**
 * Get permission list | 获取权限列表
 */
async function getPermissionList(params: Recordable<any>) {
  return requestClient.get<Array<SystemPermissionApi.SystemPermission>>(
    '/v1/admin/permissions',
    { params },
  );
}

/**
 * Get single permission | 获取单个权限
 * @param id Permission ID
 */
async function getPermission(id: number) {
  return requestClient.get<SystemPermissionApi.SystemPermission>(
    `/v1/admin/permissions/${id}`,
  );
}

/**
 * Create permission | 创建权限
 * @param data Permission data
 */
async function createPermission(
  data: SystemPermissionApi.CreatePermissionRequest,
) {
  return requestClient.post<SystemPermissionApi.SystemPermission>(
    '/v1/admin/permissions',
    data,
  );
}

/**
 * Update permission | 更新权限
 * @param id Permission ID
 * @param data Permission data
 */
async function updatePermission(
  id: number,
  data: SystemPermissionApi.UpdatePermissionRequest,
) {
  return requestClient.put<SystemPermissionApi.SystemPermission>(
    `/v1/admin/permissions/${id}`,
    data,
  );
}

/**
 * Delete permission | 删除权限
 * @param id Permission ID
 */
async function deletePermission(id: number) {
  return requestClient.delete(`/v1/admin/permissions/${id}`);
}

/**
 * Get permissions grouped by resource | 获取按资源分组的权限
 */
async function getGroupedPermissions() {
  return requestClient.get<SystemPermissionApi.GroupedPermissionsResponse>(
    '/v1/admin/permissions/grouped',
  );
}

/**
 * Get all resource types | 获取所有资源类型
 */
async function getResources() {
  return requestClient.get<SystemPermissionApi.ResourcesResponse>(
    '/v1/admin/permissions/resources',
  );
}

/**
 * Get all action types | 获取所有操作类型
 */
async function getActions() {
  return requestClient.get<SystemPermissionApi.ActionsResponse>(
    '/v1/admin/permissions/actions',
  );
}

export {
  createPermission,
  deletePermission,
  getActions,
  getGroupedPermissions,
  getPermission,
  getPermissionList,
  getResources,
  updatePermission,
};
