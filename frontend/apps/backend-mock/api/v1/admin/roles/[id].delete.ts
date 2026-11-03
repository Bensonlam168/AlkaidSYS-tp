import { defineEventHandler, getRouterParam } from 'h3';

export default defineEventHandler((event) => {
  const id = Number(getRouterParam(event, 'id'));

  // Mock: Check if role exists | 模拟：检查角色是否存在
  if (id > 100) {
    return {
      code: 404,
      message: 'Role not found',
      data: null,
      timestamp: Date.now(),
    };
  }

  // Mock: Check if system role | 模拟：检查是否系统角色
  if (id <= 3) {
    return {
      code: 403,
      message: 'System roles cannot be deleted',
      data: null,
      timestamp: Date.now(),
    };
  }

  return {
    code: 0,
    message: 'Role deleted successfully',
    data: null,
    timestamp: Date.now(),
  };
});
