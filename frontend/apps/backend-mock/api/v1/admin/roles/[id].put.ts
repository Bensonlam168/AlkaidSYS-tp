import { defineEventHandler, getRouterParam, readBody } from 'h3';

export default defineEventHandler(async (event) => {
  const id = Number(getRouterParam(event, 'id'));
  const body = await readBody(event);

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
      message: 'System roles cannot be modified',
      data: null,
      timestamp: Date.now(),
    };
  }

  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const updatedRole = {
    id,
    tenant_id: 1,
    name: body.name || 'Updated Role',
    slug: body.slug || 'updated-role',
    description: body.description || null,
    is_system: false,
    permissions: body.permissions || [],
    created_at: '2024-01-01 00:00:00',
    updated_at: now,
  };

  return {
    code: 0,
    message: 'Role updated successfully',
    data: updatedRole,
    timestamp: Date.now(),
  };
});
