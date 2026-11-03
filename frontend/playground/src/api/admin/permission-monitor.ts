import { request } from '#/api/request';

/**
 * 权限服务监控 API
 * Permission Service Monitor API
 */

// 获取缓存统计
export async function getCacheStats() {
  return await request.get('/v1/admin/permission-monitor/cache-stats');
}

// 获取性能指标
export async function getPerformanceMetrics() {
  return await request.get('/v1/admin/permission-monitor/performance');
}

// 清除用户缓存
export async function clearUserCache(userId: number) {
  return await request.delete(
    `/v1/admin/permission-monitor/cache/user/${userId}`,
  );
}

// 清除所有缓存
export async function clearAllCache() {
  return await request.delete('/v1/admin/permission-monitor/cache');
}

// 获取用户权限
export async function getUserPermissions(userId: number) {
  return await request.get(
    `/v1/admin/permission-monitor/user/${userId}/permissions`,
  );
}

// 检查用户权限
export async function checkPermission(userId: number, permission: string) {
  return await request.get(
    `/v1/admin/permission-monitor/user/${userId}/check/${permission}`,
  );
}

// 健康检查
export async function healthCheck() {
  return await request.get('/v1/admin/permission-monitor/health');
}
