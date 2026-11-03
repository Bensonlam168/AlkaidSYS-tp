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
  /**
   * 角色 slug 数组（如 ['admin']），缺陷 A7 新增字段；旧版本后端可能不返回
   * Role slugs (e.g. ['admin']). Added for defect A7, may be absent on old backends.
   */
  role_slugs?: string[];
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

  // 缺陷 A7：路由 `meta.authority` 比对的是「角色 slug」（如 `admin`），
  // 而后端 `roles` 是角色 ID（如 `[1, 3]`），直接 `map(String)` 只会得到
  // `['1', '3']`，与 `admin` 永不匹配，导致系统管理菜单被整体过滤（含 admin 账号）。
  // 因此这里把 `role_slugs` 透传出来：优先取 slug，旧版本后端缺失该字段时
  // 回退到角色 ID 字符串（失败关闭：菜单不可见，而不是越权放行）。
  const roleSlugs: string[] = (response.role_slugs ?? []).map(String);

  // Transform backend response to frontend UserInfo format
  // 转换后端响应为前端 UserInfo 格式
  const userInfo: UserInfo = {
    userId: String(response.user.id),
    username: response.user.username,
    realName: response.user.name || response.user.username,
    avatar: response.user.avatar || '',
    // roles 语义约定：已归一为「角色 slug」数组，与路由 meta.authority 同一词表
    roles:
      roleSlugs.length > 0 ? roleSlugs : (response.roles ?? []).map(String),
    // 显式透传 role_slugs，避免字段在「逐字段重建」时被丢弃（缺陷 A7）
    role_slugs: roleSlugs,
    permissions: response.permissions, // Add permissions field | 添加权限字段
    desc: '',
    homePath: '/dashboard',
    token: '', // Token is managed by accessStore | Token 由 accessStore 管理
  };

  return userInfo;
}
