import { eventHandler } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import { MOCK_CODES } from '~/utils/mock-data';
import { unAuthorizedResponse, useResponseSuccess } from '~/utils/response';

/**
 * Mock API for /v1/auth/codes
 * Returns permission codes for the current user
 */
export default eventHandler((event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  const findCodes = MOCK_CODES.find(
    (item) => item.username === userinfo.username,
  );

  return useResponseSuccess(findCodes?.codes ?? []);
});
