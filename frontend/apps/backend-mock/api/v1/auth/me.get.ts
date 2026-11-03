import { eventHandler } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import { MOCK_CODES } from '~/utils/mock-data';
import { unAuthorizedResponse, useResponseSuccess } from '~/utils/response';

/**
 * Mock API for /v1/auth/me
 * Returns user info in the format expected by the real backend
 */
export default eventHandler((event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  // Get permissions for this user
  const userCodes = MOCK_CODES.find(
    (item) => item.username === userinfo.username,
  );
  const permissions = userCodes?.codes || [];

  // Return in the format expected by /v1/auth/me
  return useResponseSuccess({
    user: {
      id: userinfo.id,
      tenant_id: 1,
      username: userinfo.username,
      email: `${userinfo.username}@example.com`,
      name: userinfo.realName,
      avatar: '',
      status: 'active',
    },
    roles: userinfo.roles.map((_role: string, index: number) => index + 1),
    permissions,
  });
});
