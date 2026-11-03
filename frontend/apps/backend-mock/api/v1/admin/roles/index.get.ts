import { defineEventHandler, getQuery } from 'h3';

// Mock role data | 模拟角色数据
const MOCK_ROLES = [
  {
    id: 1,
    tenant_id: 1,
    name: 'Super Admin',
    slug: 'super-admin',
    description: 'Full system access',
    is_system: true,
    permissions: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    created_at: '2024-01-01 00:00:00',
    updated_at: '2024-01-01 00:00:00',
  },
  {
    id: 2,
    tenant_id: 1,
    name: 'Administrator',
    slug: 'admin',
    description: 'Administrative access',
    is_system: true,
    permissions: [1, 2, 3, 4, 5, 6],
    created_at: '2024-01-01 00:00:00',
    updated_at: '2024-01-01 00:00:00',
  },
  {
    id: 3,
    tenant_id: 1,
    name: 'User',
    slug: 'user',
    description: 'Regular user access',
    is_system: true,
    permissions: [1, 2],
    created_at: '2024-01-01 00:00:00',
    updated_at: '2024-01-01 00:00:00',
  },
  {
    id: 4,
    tenant_id: 1,
    name: 'Content Editor',
    slug: 'content-editor',
    description: 'Can edit content',
    is_system: false,
    permissions: [1, 2, 3, 4],
    created_at: '2024-06-15 10:30:00',
    updated_at: '2024-06-15 10:30:00',
  },
  {
    id: 5,
    tenant_id: 1,
    name: 'Viewer',
    slug: 'viewer',
    description: 'Read-only access',
    is_system: false,
    permissions: [1],
    created_at: '2024-08-20 14:00:00',
    updated_at: '2024-08-20 14:00:00',
  },
];

export default defineEventHandler((event) => {
  const query = getQuery(event);
  const page = Number(query.page) || 1;
  const pageSize = Number(query.pageSize) || 20;
  const name = (query.name as string) || '';
  const slug = (query.slug as string) || '';

  // Filter roles | 筛选角色
  let filteredRoles = [...MOCK_ROLES];

  if (name) {
    filteredRoles = filteredRoles.filter((role) =>
      role.name.toLowerCase().includes(name.toLowerCase()),
    );
  }

  if (slug) {
    filteredRoles = filteredRoles.filter((role) =>
      role.slug.toLowerCase().includes(slug.toLowerCase()),
    );
  }

  // Pagination | 分页
  const total = filteredRoles.length;
  const start = (page - 1) * pageSize;
  const end = start + pageSize;
  const list = filteredRoles.slice(start, end);

  return {
    code: 0,
    message: 'Success',
    data: {
      list,
      total,
      page,
      page_size: pageSize,
      total_pages: Math.ceil(total / pageSize),
    },
    timestamp: Date.now(),
  };
});
