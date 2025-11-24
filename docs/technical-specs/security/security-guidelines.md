# Security Guidelines

## 1. Overview
This document outlines the security architecture and guidelines for AlkaidSYS. It covers authentication, authorization, data protection, and common vulnerability prevention.

## 2. Authentication (AuthN)

### 2.1 Mechanism
- **Dual Token System**:
  - **Access Token**: JWT, short-lived (default: 2 hours). Used for API access.
  - **Refresh Token**: JWT, long-lived (default: 7 days). Used to obtain new Access Tokens.
- **Storage**:
  - Clients should store tokens securely (e.g., HTTPOnly cookies or secure local storage).

### 2.2 JWT Configuration
- **Issuer (`iss`)**: Must be configured via `JWT_ISSUER` environment variable
  - Format: Fully qualified domain (e.g., `https://api.alkaidsys.local`)
  - Multi-environment examples:
    - Dev: `https://api.alkaidsys.local`
    - Stage: `https://api.test.alkaidsys.com`
    - Production: `https://api.alkaidsys.com`
- **Validation**: All JWTs MUST have matching `iss` claim, otherwise rejected

### 2.3 Authentication Flow
1. **Login**: `POST /v1/auth/login` → Returns Access & Refresh Tokens + user info
2. **API Access**: Send `Authorization: Bearer <Access Token>` in headers
3. **Token Refresh**: When Access Token expires (401), call `POST /v1/auth/refresh` with `Authorization: Bearer <Refresh Token>` in headers to get a new Access/Refresh token pair

### 2.4 Refresh Token Rotation & Security
- **Mandatory Rotation**: Every refresh operation returns a NEW Refresh Token
- **Old Token Invalidation**: 
  - Old Refresh Token removed from whitelist
  - Old Refresh Token's `jti` added to blacklist
  - Old token immediately invalid (prevents replay attacks)
- **Replay Detection**: Using revoked/blacklisted token triggers `code = 2007` error and audit log
- **Blacklist TTL**: Matches Refresh Token lifetime (7 days default)

## 3. Authorization (AuthZ)

### 3.1 RBAC Model

The authorization model is defined in two phases. Both phases share the same external permission code contract.

- **Phase 1 (required baseline – DB-based RBAC)**:
  - Backend MUST enforce RBAC using the `roles`, `permissions`, and `role_permissions` tables.
  - In Phase 1, the `app/middleware/Permission.php` middleware MAY implement the enforcement logic directly, but it SHOULD call a dedicated `PermissionService` instead of embedding SQL logic in controllers.
  - The internal canonical key is the `permissions.slug` field in the form `resource.action` (e.g., `forms.view`). The decomposed columns `permissions.resource` and `permissions.action` store the same information in structured form (see `database/seeds/CorePlatformSeed.php`).
  - Route middleware declarations MUST use this slug value, e.g. `->middleware(Permission::class . ':forms.view')`.

- **Phase 2 (target architecture – Casbin-based RBAC, mandatory)**:
  - The long-term authorization engine MUST be **PHP-Casbin** running on top of the same `roles` / `permissions` tables (see `design/04-security-performance/11-security-design.md`).
  - A `PermissionService` abstraction MUST be introduced/kept as the single entrypoint for authorization checks so that the underlying engine can be switched from direct DB queries to Casbin without changing controllers or middleware signatures.
  - Casbin policies MUST use the conceptual permission code format `resource:action` (e.g., `forms:view`, `user:create`).
  - Multi-tenant scope MUST be modeled by prefixing the resource with a tenant domain, for example `tenant:{tenantId}:{resource}:{action}` (e.g., `tenant:1001:forms:view`), consistent with section 3.2.

- **External API & frontend contract (all phases)**:
  - For APIs and clients, the canonical permission code format is `resource:action` (e.g., `forms:view`, `user:create`), assembled from the `resource` and `action` fields.
  - Authentication APIs (for example `GET /v1/auth/me`, and an optional `GET /v1/auth/codes`) MUST expose `permissions: string[]` in `resource:action` format so that frontend clients (such as the Vben access store) can consume them directly.
  - This contract, and the mapping between internal slugs and external codes, is defined in `docs/report/vben-permission-integration-decision.md` and MUST be treated as the source of truth for backend–frontend permission integration.

- **Mapping between internal and external permission codes**:
  - Internal slug and external code differ only by separator, and are always derived from the same `resource` / `action` pair:
    - Internal: `slug = resource . '.' . action` (e.g., `forms.view`)
    - External: `code = resource . ':' . action` (e.g., `forms:view`)
  - New code MUST NOT introduce additional permission code formats (such as `AC_xxx`) unless explicitly required by a future design decision.

### 3.2 Tenant Isolation
- All data access must be scoped by `tenant_id`.
- Permission codes for multi-tenant scenarios are prefixed with tenant scope, for example: `tenant:{tenantId}:{resource}:{action}` (e.g., `tenant:1001:forms:view`).
- Cross-tenant access is prohibited at authorization layer.

## 4. Data Security

### 4.1 Encryption
- **Transmission**: Enforce HTTPS (TLS 1.2+ minimum, TLS 1.3 recommended)
- **Storage**:
  - Passwords: `bcrypt` or `Argon2`
  - Sensitive Data (PII): AES-256 encryption at rest

### 4.2 Input Validation
- Validate all inputs using Validation classes
- Sanitize user input to prevent XSS
- Use Parameterized Queries (PDO/ORM) to prevent SQL Injection

## 5. API Security

### 5.1 Rate Limiting

Rate limiting is defined in two phases. Both phases share the same error response contract (HTTP `429` with business `code = 429`).

- **Phase 1 (baseline – fixed window, current requirement)**:
  - The `app/middleware/RateLimit.php` middleware MUST implement a **fixed time window** counter per scope on top of the configured cache store (typically Redis).
  - It MUST support multi-scope limiting: `user`, `tenant`, `ip`, and `route`, with route-specific overrides via `config/ratelimit.php`.
  - It MUST support IP/user/tenant whitelists; on cache failures, the middleware MUST degrade to pass-through (fail-open) while logging a warning.
  - When the limit is exceeded, it MUST return HTTP `429` with business `code = 429`.
  - The response body MUST include `data.scope`, `data.limit`, `data.period`, `data.current`, and `data.identifier` to help diagnostics.
  - Response headers MUST include at least:
    - `Retry-After`: Remaining penalty window in seconds.
    - `X-Rate-Limited`: Always set to `"1"` when the request has been throttled.
    - `X-RateLimit-Scope`: The scope that triggered the limit (e.g. `user`, `tenant`, `ip`, `route`).

- **Phase 2 (target algorithm – Redis token bucket, mandatory)**:
  - The long-term target algorithm for API-level throttling MUST be a Redis-backed token bucket that supports fine-grained limits and smoother burst handling.
  - When Phase 2 is implemented, the middleware SHOULD expose standard rate limit headers such as:
    - `X-RateLimit-Limit`: Maximum requests allowed in the current window.
    - `X-RateLimit-Remaining`: Remaining requests in the current window.
    - `X-RateLimit-Reset`: Time when the current window resets.
  - Details of the concrete token bucket configuration and migration plan SHOULD be kept in design documents and deployment playbooks, and this section MUST be updated once the Phase 2 algorithm is active in production.

### 5.2 API Signatures (High Security - Optional)
- For critical/third-party APIs requiring enhanced security
- Required Headers:
  - `X-App-Key`: Application Key (platform-assigned)
  - `X-Timestamp`: Request timestamp (±300s tolerance)
  - `X-Nonce`: Unique random string (16+ bytes, CSPRNG)
  - `X-Signature`: Signature value (default: HMAC-SHA256)
  - `X-Signature-Algorithm` (optional): Algorithm (hmac-sha256/rsa-sha256/ed25519)
- **Signature String**:
  ```
  plain = method + '|' + path_with_query + '|' + timestamp + '|' + nonce + '|' + body
  signature = HMAC_SHA256(plain, app_secret)
  ```
- **Replay Prevention**: Nonce cached during time window, duplicate nonce rejected

## 6. Error Handling

- Do not expose stack traces in production (`APP_DEBUG=false`)
- Use standard error codes for consistent client handling

### 6.1 Complete Security Error Code Matrix

| Business Code | HTTP Status | Scenario | Triggered By | Example Message |
|---------------|-------------|----------|--------------|-----------------|
| 2001 | 401 | Unauthorized: Token missing/invalid/expired | Auth middleware; Permission middleware (unauthenticated) | "Unauthorized: Token is missing, invalid, or expired" |
| 2002 | 403 | Forbidden: Insufficient permissions | Permission middleware (auth but no access) | "Forbidden: Insufficient permissions" |
| 2003 | 401 | Invalid Refresh Token format/signature | `/v1/auth/refresh` (JwtService validation) | "Invalid refresh token format or signature" |
| 2004 | 401 | Refresh Token expired | `/v1/auth/refresh` (expiry check) | "Refresh token has expired" |
| 2005 | 401 | Token issuer mismatch | `/v1/auth/refresh` (issuer validation) | "Token issuer mismatch" |
| 2006 | 401 | Wrong token type (Access used for Refresh) | `/v1/auth/refresh` (type check) | "Expected refresh token, got access token" |
| 2007 | 401 | Refresh Token revoked/replayed | `/v1/auth/refresh` (whitelist/blacklist check) | "Refresh token has been revoked or invalidated" |
| 5000 | 500 | Internal server error | Global exception handler | "Internal Server Error" |

> **Reference**: `design/04-security-performance/11-security-design.md` for complete error handling design

## 7. Security Best Practices

### 7.1 Password Policy
- Minimum length: 8 characters
- Required: uppercase, lowercase, numbers, special characters
- Periodic password update reminders

### 7.2 Session Management
- Session data stored in Redis (never file-based in production)
- Session ID generated with cryptographically secure random
- Reasonable session timeout configured

### 7.3 Audit Logging
- Log all sensitive operations (login, permission changes, data modifications)
- Log fields: user_id, tenant_id, operation, timestamp, IP, trace_id
- Regular review of anomalous behavior

## 8. Security Checklist (Pre-Deployment)

- [ ] All sensitive configs removed from code, use environment variables
- [ ] Database uses strong passwords
- [ ] Redis password authentication enabled
- [ ] HTTPS certificates configured and valid
- [ ] Firewall rules configured (only necessary ports open)
- [ ] Production `APP_DEBUG=false`
- [ ] Regular backup strategy established
- [ ] Security vulnerability scan completed
- [ ] Redis enforced in production (no file cache)
