import { defineEventHandler } from 'h3';

// Mock permission data | 模拟权限数据
const MOCK_PERMISSIONS = [
  // Tenant permissions | 租户权限
  {
    id: 1,
    name: 'View Tenants',
    slug: 'tenant.read',
    resource: 'tenant',
    action: 'read',
    description: 'View tenant list and details',
  },
  {
    id: 2,
    name: 'Manage Tenants',
    slug: 'tenant.write',
    resource: 'tenant',
    action: 'write',
    description: 'Create and update tenants',
  },
  {
    id: 3,
    name: 'Delete Tenants',
    slug: 'tenant.delete',
    resource: 'tenant',
    action: 'delete',
    description: 'Delete tenants',
  },

  // Role permissions | 角色权限
  {
    id: 4,
    name: 'View Roles',
    slug: 'role.read',
    resource: 'role',
    action: 'read',
    description: 'View role list and details',
  },
  {
    id: 5,
    name: 'Manage Roles',
    slug: 'role.write',
    resource: 'role',
    action: 'write',
    description: 'Create and update roles',
  },
  {
    id: 6,
    name: 'Delete Roles',
    slug: 'role.delete',
    resource: 'role',
    action: 'delete',
    description: 'Delete roles',
  },

  // User permissions | 用户权限
  {
    id: 7,
    name: 'View Users',
    slug: 'user.read',
    resource: 'user',
    action: 'read',
    description: 'View user list and details',
  },
  {
    id: 8,
    name: 'Manage Users',
    slug: 'user.write',
    resource: 'user',
    action: 'write',
    description: 'Create and update users',
  },
  {
    id: 9,
    name: 'Delete Users',
    slug: 'user.delete',
    resource: 'user',
    action: 'delete',
    description: 'Delete users',
  },

  // Casbin permissions | Casbin 权限
  {
    id: 10,
    name: 'Manage Casbin',
    slug: 'casbin.manage',
    resource: 'casbin',
    action: 'manage',
    description: 'Manage Casbin policies',
  },

  // Form permissions | 表单权限
  {
    id: 11,
    name: 'View Forms',
    slug: 'forms.read',
    resource: 'forms',
    action: 'read',
    description: 'View form list and details',
  },
  {
    id: 12,
    name: 'Create Forms',
    slug: 'forms.create',
    resource: 'forms',
    action: 'create',
    description: 'Create forms',
  },
  {
    id: 13,
    name: 'Update Forms',
    slug: 'forms.update',
    resource: 'forms',
    action: 'update',
    description: 'Update forms',
  },
  {
    id: 14,
    name: 'Delete Forms',
    slug: 'forms.delete',
    resource: 'forms',
    action: 'delete',
    description: 'Delete forms',
  },

  // Collection permissions | 集合权限
  {
    id: 15,
    name: 'View Collections',
    slug: 'collections.read',
    resource: 'collections',
    action: 'read',
    description: 'View collection list and details',
  },
  {
    id: 16,
    name: 'Manage Collections',
    slug: 'collections.write',
    resource: 'collections',
    action: 'write',
    description: 'Create and update collections',
  },
];

export default defineEventHandler(() => {
  // Group by resource | 按资源分组
  const grouped: Record<string, typeof MOCK_PERMISSIONS> = {};
  for (const permission of MOCK_PERMISSIONS) {
    const resource = permission.resource || 'other';
    if (!grouped[resource]) {
      grouped[resource] = [];
    }
    grouped[resource].push(permission);
  }

  return {
    code: 0,
    message: 'Success',
    data: {
      list: MOCK_PERMISSIONS,
      grouped,
    },
    timestamp: Date.now(),
  };
});
