import type { UserInfo } from '@vben/types';

import { useTenantStore } from '@vben/stores';
import { requestClient } from '#/api/request';

/**
 * Backend response type for /v1/auth/me
 * 后端 /v1/auth/me 响应类型
 */
interface AuthMeResponse {
  user: {
    id: number;
    tenant_id: number;
    username: string;
    email: string;
    name?: string;
    avatar?: string;
    status: string;
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
  const response = await requestClient.get<AuthMeResponse>('/v1/auth/me');

  // 同步更新前端多租户上下文
  const tenantStore = useTenantStore();
  tenantStore.setTenant({
    tenantId: response.user.tenant_id,
  });

  const userInfo: UserInfo = {
    userId: String(response.user.id),
    username: response.user.username,
    realName: response.user.name || response.user.username,
    avatar: response.user.avatar || '',
    roles: response.roles.map(String),
    permissions: response.permissions,
    desc: '',
    homePath: '/dashboard',
    token: '',
  };

  return userInfo;
}
