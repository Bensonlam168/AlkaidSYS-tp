import { defineEventHandler, readBody, setResponseStatus } from 'h3';
import {
  clearRefreshTokenCookie,
  setRefreshTokenCookie,
} from '~/utils/cookie-utils';
import { generateAccessToken, generateRefreshToken } from '~/utils/jwt-utils';
import { MOCK_USERS } from '~/utils/mock-data';
import {
  forbiddenResponse,
  useResponseError,
  useResponseSuccess,
} from '~/utils/response';

/**
 * Mock API for /v1/auth/login
 * Compatible with the real backend login format
 */
export default defineEventHandler(async (event) => {
  const body = await readBody(event);
  // Support both email and username for login
  const email = body.email || body.username;
  const password = body.password;

  if (!password || !email) {
    setResponseStatus(event, 400);
    return useResponseError(
      'BadRequestException',
      'Email/Username and password are required',
    );
  }

  // Find user by username (mock treats email as username)
  const findUser = MOCK_USERS.find(
    (item) => item.username === email && item.password === password,
  );

  if (!findUser) {
    clearRefreshTokenCookie(event);
    return forbiddenResponse(event, 'Email or password is incorrect.');
  }

  const accessToken = generateAccessToken(findUser);
  const refreshToken = generateRefreshToken(findUser);

  setRefreshTokenCookie(event, refreshToken);

  // Return in the format expected by /v1/auth/login
  return useResponseSuccess({
    access_token: accessToken,
    refresh_token: refreshToken,
  });
});
