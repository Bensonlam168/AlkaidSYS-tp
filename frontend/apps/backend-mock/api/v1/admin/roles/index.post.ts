import { defineEventHandler, readBody } from 'h3';

let nextId = 6;

export default defineEventHandler(async (event) => {
  const body = await readBody(event);

  // Validate required fields | 验证必填字段
  if (!body.name || !body.slug) {
    return {
      code: 422,
      message: 'Validation failed',
      data: {
        errors: {
          name: body.name ? [] : ['name is required'],
          slug: body.slug ? [] : ['slug is required'],
        },
      },
      timestamp: Date.now(),
    };
  }

  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const newRole = {
    id: nextId++,
    tenant_id: 1,
    name: body.name,
    slug: body.slug,
    description: body.description || null,
    is_system: false,
    permissions: body.permissions || [],
    created_at: now,
    updated_at: now,
  };

  return {
    code: 0,
    message: 'Role created successfully',
    data: newRole,
    timestamp: Date.now(),
  };
});
