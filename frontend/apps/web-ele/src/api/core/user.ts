import type { UserInfo } from '@vben/types';

import { useTenantStore } from '@vben/stores';

import { requestClient } from '#/api/request';

/**
 * Backend response type for /v1/auth/me
 * 后端 /v1/auth/me 响应类型
 */
interface AuthMeResponse {
  user: {
    avatar?: string;
    email: string;
    id: number;
    name?: string;
    status: string;
    tenant_id: number;
    username: string;
  };
  roles: number[];
  permissions: string[];
}

/**
 * 获取用户信息
 * Get user information from /v1/auth/me
 * 从 /v1/auth/me 获取用户信息和权限
 */
export async function getUserInfoApi(): Promise<UserInfo> {
  // Call backend /v1/auth/me API
  // 调用后端 /v1/auth/me API
  const response = await requestClient.get<AuthMeResponse>('/v1/auth/me');

  // 同步更新前端多租户上下文
  const tenantStore = useTenantStore();
  tenantStore.setTenant({
    tenantId: response.user.tenant_id,
  });

  // Transform backend response to frontend UserInfo format
  // 转换后端响应为前端 UserInfo 格式
  const userInfo: UserInfo = {
    userId: String(response.user.id),
    username: response.user.username,
    realName: response.user.name || response.user.username,
    avatar: response.user.avatar || '',
    roles: response.roles.map(String), // Convert to string array | 转换为字符串数组
    permissions: response.permissions, // Add permissions field | 添加权限字段
    desc: '',
    homePath: '/dashboard',
    token: '', // Token is managed by accessStore | Token 由 accessStore 管理
  };

  return userInfo;
}
