import type { BasicUserInfo } from '@vben-core/typings';

/** 用户信息 */
interface UserInfo extends BasicUserInfo {
  /**
   * 用户描述
   */
  desc: string;
  /**
   * 首页地址
   */
  homePath: string;

  /**
   * 角色 slug 列表（如 ['admin']），用于路由 meta.authority 比对
   * 后端 /v1/auth/me 的 role_slugs 字段透传，旧版本后端可能不返回
   */
  role_slugs?: string[];

  /**
   * accessToken
   */
  token: string;
}

export type { UserInfo };
