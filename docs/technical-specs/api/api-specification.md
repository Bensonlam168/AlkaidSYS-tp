# API Specification

## 1. Overview
This document outlines the API design specifications for the AlkaidSYS project. It covers RESTful standards, response formats, versioning, and rate limiting.

## 2. RESTful API Standards

### 2.1 HTTP Methods
| Method | Purpose | Example |
|--------|---------|---------|
| **GET** | Retrieve resources | `GET /api/users` |
| **POST** | Create resources | `POST /api/users` |
| **PUT** | Full update | `PUT /api/users/1` |
| **PATCH** | Partial update | `PATCH /api/users/1` |
| **DELETE** | Delete resources | `DELETE /api/users/1` |

### 2.2 URL Design
- Use nouns, not verbs (e.g., `/api/users`, not `/api/getUsers`).
- Use plural nouns for collections.
- Use hierarchy for relationships (e.g., `/api/users/123/orders`).

### 2.3 Query Parameters
- **Pagination**: `?page=1&page_size=20`
- **Filtering**: `?status=1&role=admin`
- **Sorting**: `?sort=-created_at,+name` (- for desc, + for asc)
- **Field Selection**: `?fields=id,name`
- **Includes**: `?include=roles,permissions`

## 3. Unified Response Format

All API responses must follow this structure:

```json
{
  "code": 0,          // Business status code (0 = success)
  "message": "Success", // Human-readable message
  "data": { ... },    // Business data or null
  "timestamp": 1705651200, // Server timestamp (seconds)
  "trace_id": "abc..." // Optional trace ID
}
```

### 3.1 Response Structure Rules

- **HTTP Status Code**: Indicates transport/semantic layer (200/4xx/5xx)
- **Business Code** (`code`): Business logic status
  - `0` = Success
  - Non-zero = Failure (see error code matrix below)
- **Message**: Human-readable text (English primary, localize on frontend if needed)
- **Data**: Business payload or `null`
- **Timestamp**: Server unix timestamp (seconds)
- **Trace ID**: Optional request trace ID for debugging (injected by Trace middleware)

### 3.2 Success Response
```json
{
  "code": 0,
  "message": "Operation successful",
  "data": { "id": 1, "name": "Test" },
  "timestamp": 1705651200
}
```

### 3.3 Error Response
```json
{
  "code": 422,
  "message": "Validation failed",
  "data": {
    "errors": {
      "email": ["Invalid format"]
    }
  },
  "timestamp": 1705651200
}
```

### 3.4 Pagination Response
```json
{
  "code": 0,
  "message": "Success",
  "data": {
    "list": [...],
    "total": 100,
    "page": 1,
    "page_size": 20,
    "total_pages": 5
  },
  "timestamp": 1705651200
}
```

## 4. ApiController Standard Methods

All API controllers **MUST** extend `app\controller\ApiController`. The following standard methods are available:

- **`success(mixed $data = null, string $message = 'Success', int $code = 0, int $httpCode = 200)`**
  - Purpose: Business success response
  - Default: `code = 0`, HTTP 200

- **`paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success')`**
  - Purpose: Unified pagination response
  - **Standard response payload**: `{ list, total, page, page_size, total_pages }`
  - **Implementation status**: ✅ Fully aligned with target specification.
    - `ApiController::paginate` returns `{ list, total, page, page_size, total_pages }` with snake_case field names.
    - All new code MUST use this helper to ensure consistent pagination payloads across all APIs.

- **`error(string $message, int $code = 400, array $errors = [], int $httpCode = 400)`**
  - Purpose: Business failure response
  - If `$errors` provided, `data.errors` will contain field-level errors

- **`validationError(array $errors, string $message = 'Validation failed')`**
  - Purpose: Form/parameter validation failure
  - Default: `code = 422`, HTTP 422

- **`notFound(string $message = 'Resource not found')`**
  - Purpose: Resource not found
  - Default: `code = 404`, HTTP 404

- **`unauthorized(string $message = 'Unauthorized')`**
  - Purpose: Not authenticated
  - Default: `code = 401`, HTTP 401

- **`forbidden(string $message = 'Forbidden')`**
  - Purpose: Authenticated but insufficient permissions
  - Default: `code = 403`, HTTP 403

**Mandatory Requirements:**
- All API controllers MUST extend `ApiController`
- Controllers MUST NOT use `return json([...])` directly
- Use standard methods for consistent responses

## 5. Error Code Matrix

### 5.1 HTTP Status to Business Code Mapping

| Scenario | HTTP Status | Business Code | Description |
|----------|-------------|---------------|-------------|
| Success | 200/201 | 0 | Operation successful |
| Validation Error | 422 | 422 | Input validation failed |
| Unauthorized | 401 | 2001 | Token missing/invalid/expired |
| Forbidden | 403 | 2002 | Insufficient permissions |
| Refresh Token Invalid | 401 | 2003 | Invalid refresh token format |
| Refresh Token Expired | 401 | 2004 | Refresh token expired |
| Token Issuer Mismatch | 401 | 2005 | Token issuer mismatch |
| Wrong Token Type | 401 | 2006 | Access token used for refresh endpoint |
| Token Revoked/Replayed | 401 | 2007 | Refresh token revoked or replayed |
| Not Found | 404 | 404 | Resource not found |
| Server Error | 500 | 5000 | Internal server error |

> **Reference**: Complete error code matrix in `design/04-security-performance/11-security-design.md`

### 5.2 Error code mapping rules

- **Security / authentication / authorization errors (2001–2007, 5000)**
  - Implementations MUST follow the matrix defined in `design/04-security-performance/11-security-design.md`.
- **Validation errors**
  - Request validation failures MUST use business code `422` with HTTP 422.
  - Controllers and middleware MUST use the `ApiController::validationError` / `error` helpers (or equivalent) to produce validation error responses; ad-hoc JSON responses MUST NOT invent alternative validation error codes.
- **HTTP exceptions**
  - When a `HttpException` is rendered as JSON, it MUST be mapped to `code = statusCode * 10` while preserving the original HTTP status (e.g. 404 → 4040, 405 → 4050).
- **Model/data not found**
  - `ModelNotFoundException` / `DataNotFoundException` MUST be mapped to HTTP 404 with business code `4004`.
- **Generic server errors**
  - Unhandled exceptions MUST be mapped to HTTP 500 with business code `5000`.
- **Client handling recommendation**
  - Clients MUST treat `code = 0` as success and any non-zero `code` as failure, regardless of HTTP status.
  - HTTP status codes SHOULD be used as transport hints (retry, throttling, auth), while business branching SHOULD be based primarily on the `code` field.

## 6. Trace ID Mechanism

The `trace_id` field enables end-to-end request tracing:

- **Generation**:
  - Prefers `X-Trace-Id` header from client
  - Falls back to `X-Request-Id` header
  - Generates new ID via `bin2hex(random_bytes(16))` if neither present
- **Injection**: Trace middleware injects into request object.
- **Response & propagation**:
  - The Trace middleware MUST always write the trace ID into the `X-Trace-Id` response header.
  - All JSON error responses (HTTP 4xx/5xx), regardless of where they are generated (controllers, middleware, global exception handler), MUST include a `trace_id` field whose value matches the `X-Trace-Id` header.
  - Successful JSON responses MAY omit the `trace_id` field in the body, but SHOULD still include the `X-Trace-Id` header to support correlation.
- **Client usage**:
  - Clients SHOULD log the `trace_id` from either the header or JSON body and attach it to bug reports and support tickets to simplify troubleshooting.
- **Use Cases**: Audit logs, debugging, distributed tracing

## 7. Versioning

- **URL Versioning**: `/api/v1/...`, `/api/v2/...`
- **Header Versioning**: `Api-Version: v1` (Supported via middleware)

## 8. Rate Limiting

Rate limiting behaviour is defined in two phases and MUST follow the rules in `docs/technical-specs/security/security-guidelines.md` §5.1.

- **Phase 1 (baseline – fixed window)**:
  - The backend MUST enforce per-scope fixed-window limits on top of the configured cache store (typically Redis).
  - The middleware MUST support at least the following scopes: `user`, `tenant`, `ip`, and `route`, with route-level overrides via configuration.
  - On cache failures, the middleware MUST fail open (pass-through) while emitting a warning log.
  - When a limit is exceeded, the API MUST return HTTP `429` with business `code = 429`.
  - The JSON response body for throttled requests MUST include diagnostic fields (scope, limit, period, current, identifier) as defined in the Security Guidelines.
  - The following headers MUST be present when a request is throttled:
    - `Retry-After`: Remaining penalty window in seconds.
    - `X-Rate-Limited`: Always set to `"1"` when the request has been throttled.
    - `X-RateLimit-Scope`: The scope that triggered the limit (e.g. `user`, `tenant`, `ip`, `route`).

- **Phase 2 (target algorithm – Redis token bucket, mandatory)**:
  - The long-term target algorithm for API-level throttling MUST be a Redis-backed token bucket that provides smoother burst handling and finer-grained limits.
  - Once Phase 2 is active in production, the middleware SHOULD expose standard rate limit headers, including at least:
    - `X-RateLimit-Limit`: Maximum requests allowed in the current window.
    - `X-RateLimit-Remaining`: Remaining requests in the current window.
    - `X-RateLimit-Reset`: Time when the current window resets.
  - Concrete token bucket parameters (bucket size, refill rate, per-route policies) MAY vary by environment and MUST be documented in deployment playbooks.
  - When Phase 2 is rolled out, this specification MUST be updated to state which headers are REQUIRED for all throttled responses.

## 9. API Documentation
- Powered by Swagger/OpenAPI
- Auto-generated via annotations in Controllers
- Generation command: `php think api:doc`
- Validation: `npx redocly lint public/api-docs.json`
- TypeScript generation: `npx openapi-typescript public/api-docs.json -o admin/src/api/types.d.ts`

## 10. Security Headers (Optional)

For high-security interfaces (optional enhancement):
- `X-App-Key`: Application Key (platform-assigned)
- `X-Timestamp`: Request timestamp (±300s tolerance)
- `X-Nonce`: Unique random string (16+ bytes)
- `X-Signature`: HMAC-SHA256 signature
- `X-Signature-Algorithm`: Signature algorithm (hmac-sha256/rsa-sha256/ed25519)

**Signature String**:
```
plain = method + '|' + path_with_query + '|' + timestamp + '|' + nonce + '|' + body
signature = HMAC_SHA256(plain, app_secret)
```
