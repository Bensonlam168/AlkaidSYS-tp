import { defineEventHandler, readBody, setResponseStatus } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import {
  unAuthorizedResponse,
  useResponseError,
  useResponseSuccess,
} from '~/utils/response';

/**
 * Mock API for POST /v1/admin/tenants
 * Creates a new tenant
 */
export default defineEventHandler(async (event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  const body = await readBody(event);
  const { name, slug, domain, status, config, max_sites, max_users, expires_at } = body;

  // Validate required fields
  if (!name || !slug) {
    setResponseStatus(event, 422);
    return useResponseError(
      'ValidationError',
      'Name and slug are required',
    );
  }

  // Validate slug format
  if (!/^[a-z0-9_-]+$/.test(slug)) {
    setResponseStatus(event, 422);
    return useResponseError(
      'ValidationError',
      'Slug must contain only lowercase letters, numbers, hyphens and underscores',
    );
  }

  // Mock created tenant
  const tenant = {
    id: Date.now(),
    name,
    slug,
    domain: domain || null,
    status: status || 'active',
    config: config || {},
    max_sites: max_sites || 1,
    max_users: max_users || 10,
    expires_at: expires_at || null,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };

  setResponseStatus(event, 201);
  return useResponseSuccess(tenant);
});
