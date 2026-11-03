import type { Recordable } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace SystemUserApi {
  /**
   * User status type | 用户状态类型
   */
  export type UserStatus = 'active' | 'inactive' | 'locked';

  /**
   * User interface | 用户接口
   */
  export interface SystemUser {
    [key: string]: any;
    id: number;
    tenant_id: number;
    username: string;
    email: string;
    name: string;
    phone?: string;
    avatar?: string;
    status: UserStatus;
    role_ids?: number[];
    created_at: string;
    updated_at: string;
  }

  /**
   * User with roles | 带角色的用户
   */
  export interface UserWithRoles {
    user: SystemUser;
    roles: Array<{
      description?: string;
      id: number;
      name: string;
      slug: string;
    }>;
  }

  /**
   * Create user request | 创建用户请求
   */
  export interface CreateUserRequest {
    username: string;
    email: string;
    password: string;
    name?: string;
    phone?: string;
    status?: UserStatus;
    role_ids?: number[];
  }

  /**
   * Update user request | 更新用户请求
   */
  export interface UpdateUserRequest {
    username?: string;
    email?: string;
    name?: string;
    phone?: string;
    status?: UserStatus;
  }

  /**
   * User list response | 用户列表响应
   */
  export interface UserListResponse {
    items: SystemUser[];
    total: number;
    page: number;
    page_size: number;
  }

  /**
   * Set roles request | 设置角色请求
   */
  export interface SetRolesRequest {
    role_ids: number[];
  }

  /**
   * Reset password request | 重置密码请求
   */
  export interface ResetPasswordRequest {
    new_password?: string;
  }

  /**
   * Reset password response | 重置密码响应
   */
  export interface ResetPasswordResponse {
    new_password: string;
  }

  /**
   * Update status request | 更新状态请求
   */
  export interface UpdateStatusRequest {
    status: UserStatus;
  }
}

/**
 * Get user list | 获取用户列表
 */
async function getUserList(params: Recordable<any>) {
  return requestClient.get<SystemUserApi.UserListResponse>('/v1/admin/users', {
    params,
  });
}

/**
 * Get single user with roles | 获取单个用户（含角色）
 * @param id User ID
 */
async function getUser(id: number) {
  return requestClient.get<SystemUserApi.UserWithRoles>(
    `/v1/admin/users/${id}`,
  );
}

/**
 * Create user | 创建用户
 * @param data User data
 */
async function createUser(data: SystemUserApi.CreateUserRequest) {
  return requestClient.post<{ id: number }>('/v1/admin/users', data);
}

/**
 * Update user | 更新用户
 * @param id User ID
 * @param data User data
 */
async function updateUser(id: number, data: SystemUserApi.UpdateUserRequest) {
  return requestClient.put(`/v1/admin/users/${id}`, data);
}

/**
 * Delete user | 删除用户
 * @param id User ID
 */
async function deleteUser(id: number) {
  return requestClient.delete(`/v1/admin/users/${id}`);
}

/**
 * Update user status | 更新用户状态
 * @param id User ID
 * @param status New status
 */
async function updateUserStatus(id: number, status: SystemUserApi.UserStatus) {
  return requestClient.patch(`/v1/admin/users/${id}/status`, { status });
}

/**
 * Set user roles | 设置用户角色
 * @param id User ID
 * @param roleIds Role IDs
 */
async function setUserRoles(id: number, roleIds: number[]) {
  return requestClient.put(`/v1/admin/users/${id}/roles`, {
    role_ids: roleIds,
  });
}

/**
 * Reset user password | 重置用户密码
 * @param id User ID
 * @param newPassword New password (optional, will generate random if not provided)
 */
async function resetUserPassword(id: number, newPassword?: string) {
  return requestClient.post<SystemUserApi.ResetPasswordResponse>(
    `/v1/admin/users/${id}/reset-password`,
    { new_password: newPassword },
  );
}

export {
  createUser,
  deleteUser,
  getUser,
  getUserList,
  resetUserPassword,
  setUserRoles,
  updateUser,
  updateUserStatus,
};
