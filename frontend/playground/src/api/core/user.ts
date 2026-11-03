import type { UserInfo } from '@vben/types';

import { requestClient } from '#/api/request';

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

export async function getUserInfoApi(): Promise<UserInfo> {
  const response = await requestClient.get<AuthMeResponse>('/v1/auth/me');

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
