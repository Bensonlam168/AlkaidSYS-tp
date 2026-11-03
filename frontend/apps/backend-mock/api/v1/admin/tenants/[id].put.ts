import {
  defineEventHandler,
  getRouterParam,
  readBody,
  setResponseStatus,
} from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import {
  unAuthorizedResponse,
  useResponseError,
  useResponseSuccess,
} from '~/utils/response';

/**
 * Mock API for PUT /v1/admin/tenants/:id
 * Updates an existing tenant
 */
export default defineEventHandler(async (event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  const id = getRouterParam(event, 'id');
  if (!id) {
    setResponseStatus(event, 400);
    return useResponseError('BadRequest', 'Tenant ID is required');
  }

  const body = await readBody(event);
  const {
    name,
    slug,
    domain,
    status,
    config,
    max_sites,
    max_users,
    expires_at,
  } = body;

  // Validate slug format if provided
  if (slug && !/^[a-z0-9_-]+$/.test(slug)) {
    setResponseStatus(event, 422);
    return useResponseError(
      'ValidationError',
      'Slug must contain only lowercase letters, numbers, hyphens and underscores',
    );
  }

  // Mock updated tenant
  const tenant = {
    id: Number(id),
    name: name || 'Updated Tenant',
    slug: slug || 'updated-tenant',
    domain: domain || null,
    status: status || 'active',
    config: config || {},
    max_sites: max_sites || 1,
    max_users: max_users || 10,
    expires_at: expires_at || null,
    created_at: '2024-01-01T00:00:00.000Z',
    updated_at: new Date().toISOString(),
  };

  return useResponseSuccess(tenant);
});
