import { defineEventHandler, getRouterParam, setResponseStatus } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import {
  unAuthorizedResponse,
  useResponseError,
  useResponseSuccess,
} from '~/utils/response';

/**
 * Mock API for GET /v1/admin/tenants/:id
 * Gets a single tenant by ID
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

  // Mock tenant data
  const tenant = {
    id: Number(id),
    name: 'Demo Tenant',
    slug: 'demo-tenant',
    domain: 'demo.example.com',
    status: 'active',
    config: {},
    max_sites: 5,
    max_users: 50,
    expires_at: '2025-12-31T23:59:59.000Z',
    created_at: '2024-01-01T00:00:00.000Z',
    updated_at: '2024-06-15T12:30:00.000Z',
  };

  return useResponseSuccess(tenant);
});
