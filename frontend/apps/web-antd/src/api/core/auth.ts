import { baseRequestClient, requestClient } from '#/api/request';

export namespace AuthApi {
  /** 登录接口参数 */
  export interface LoginParams {
    /**
     * 登录标识：优先使用 email，其次使用 username
     * Login identifier: prefer email, fallback to username
     */
    email?: string;
    password?: string;
    username?: string;
  }

  /** 登录接口返回值 */
  export interface LoginResult {
    accessToken: string;
  }

  export interface RefreshTokenResult {
    data: string;
    status: number;
  }
}

/**
 * 登录
 */
export async function loginApi(data: AuthApi.LoginParams) {
  // 后端登录接口使用 /v1/auth/login，且返回字段为 snake_case(access_token)
  // 这里做一层协议适配，将表单参数转换为后端所需格式，并将响应映射为前端统一的 LoginResult
  const payload = {
    email: data.email ?? data.username ?? '',
    password: data.password ?? '',
  };

  const response = await requestClient.post<{
    access_token: string;
    refresh_token?: string;
  }>('/v1/auth/login', payload);

  return {
    accessToken: response.access_token,
  } as AuthApi.LoginResult;
}

/**
 * 刷新accessToken
 */
export async function refreshTokenApi() {
  return baseRequestClient.post<AuthApi.RefreshTokenResult>('/auth/refresh', {
    withCredentials: true,
  });
}

/**
 * 退出登录
 */
export async function logoutApi() {
  return baseRequestClient.post('/auth/logout', {
    withCredentials: true,
  });
}

/**
 * 获取用户权限码
 * Get user permission codes from /v1/auth/codes
 *
 * Note: It's recommended to use getAccessCodesFromMe() to reduce HTTP requests
 * 注意：推荐使用 getAccessCodesFromMe() 以减少 HTTP 请求
 */
export async function getAccessCodesApi() {
  return requestClient.get<string[]>('/v1/auth/codes');
}

/**
 * 从用户信息中获取权限码（推荐）
 * Get permission codes from user info (recommended)
 *
 * This method gets permissions from /v1/auth/me, avoiding an extra HTTP request
 * 此方法从 /v1/auth/me 获取权限，避免额外的 HTTP 请求
 */
export async function getAccessCodesFromMe(): Promise<string[]> {
  const { getUserInfoApi } = await import('./user');
  const userInfo = await getUserInfoApi();
  return userInfo.permissions || [];
}
