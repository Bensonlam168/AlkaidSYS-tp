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

### 2.5 Session Invalidation Closure (ER-04)

> Status: **Implemented**. See the Chinese version of this document (§2.5) for the full rationale,
> measurements and trade-offs; this section is the normative summary.

A JWT only proves that the token was genuine **when it was issued**; it does not prove that the subject is still valid.

- **Where**: `app\middleware\Auth` (every protected request), `AuthController::refresh()` (renewal) and
  `AuthController::login()` (session start), all delegating to `Infrastructure\Auth\PrincipalStateChecker`.
  Tenant usability criteria live in exactly one place: `Infrastructure\Tenant\Service\TenantUsability`
  (existence + `status = active` + `expires_at` not in the past).
- **Checks**: user still exists inside the tenant declared by the token, `users.status = active`,
  and the tenant is usable. Refresh additionally revokes the presented refresh token when the subject is unusable.
- **Rejection**: HTTP **401** + business code **2001** with a single external message (`error.session_invalid`).
  The machine-readable reason (`user_not_found` / `user_inactive` / `user_locked` / `tenant_not_found` /
  `tenant_unusable` / `tenant_expired`) is **logged only** — distinguishing it in the response would leak
  account existence and tenant operational state, while the client action is identical for all cases.
  A failure of the check itself (e.g. database unavailable) returns **500**, never a fabricated 401.
- **Performance**: +2 primary-key lookups per authenticated request (`EXPLAIN`: `type=const`, `rows=1`),
  roughly +1.6–2.0 ms per request in the local Docker environment; end-to-end difference is below measurement noise.
- **Caching**: deliberately **none** (not even `static`), because `alkaid-backend` is a long-lived Swoole process
  and caching `active` re-introduces "disabled but still usable". Any future cache MUST have a short TTL
  documented here, explicit invalidation on every user/tenant status write path, and a stated worst-case delay.
- **Not covered**: routes that do not mount the `Auth` middleware, and the dev-only `AUTH_SKIP_MIDDLEWARE`
  switch (must never be enabled in production-like environments).

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
  - A slug MUST contain exactly one `.` — the `resource` segment and the `action` segment each form a single token.
    Multi-word resources are joined with underscores inside the resource segment, never with a second dot:
    - Correct: `workflow_definition.read` (`resource = workflow_definition`, `action = read`)
    - Incorrect: `workflow.definition.read` (three segments; rejected by `scripts/check-permission-codes.php`)
    - Same convention as the existing `form_data.view` / `form_data.write` resources.
  - New code MUST NOT introduce additional permission code formats (such as `AC_xxx`) unless explicitly required by a future design decision.

#### Permission middleware parameter format

The permission middleware `app\\middleware\\Permission` supports two input formats for route-level permission declarations, in order to provide backward compatibility with legacy slugs while keeping a single external contract:

1. **External permission code format (recommended):** `resource:action`
   - This matches the permission codes returned by authentication APIs and consumed by frontend clients.
   - Examples: `forms:view`, `lowcode:read`.
   - Route example: `->middleware(\\app\\middleware\\Permission::class . ':forms:view')`.

2. **Internal slug format (for legacy routes only):** `resource.action`
   - This mirrors the `permissions.slug` value stored in the database.
   - Examples: `forms.view`, `lowcode.read`.
   - Route example: `->middleware(\\app\\middleware\\Permission::class . ':forms.view')`.

Internally, the middleware normalizes both formats to the external `resource:action` form before delegating to `PermissionService`:

- When it receives `resource.action`, it converts it to `resource:action` before calling `PermissionService::hasPermission()`.
- `PermissionService` and all authentication APIs always work with the external `resource:action` format.
- New routes **MUST** use the external `resource:action` format in middleware declarations; the slug format exists only for backward compatibility with older code and MUST NOT be used in new implementations.

### 3.2 Tenant Isolation
- All data access must be scoped by `tenant_id`.
- Permission codes for multi-tenant scenarios are prefixed with tenant scope, for example: `tenant:{tenantId}:{resource}:{action}` (e.g., `tenant:1001:forms:view`).
- Cross-tenant access is prohibited at authorization layer.

### 3.3 Permission Monitor Endpoint Authorization (`/v1/admin/permission-monitor`)

This route group exposes internal state of the permission subsystem (L1/L2 cache hit rates, TTL,
P95 latency, and any user's permission set) and contains two **write** operations (clear a user's
cache / clear all permission caches). The whole group therefore **MUST** carry `Auth` + `Permission`;
anonymous access is not allowed.

| Method | Path | Required permission | Notes |
| --- | --- | --- | --- |
| GET | `/v1/admin/permission-monitor/cache-stats` | `permission.read` | Cache statistics (hit rate / TTL) |
| GET | `/v1/admin/permission-monitor/performance` | `permission.read` | Performance metrics (P95 / hit-rate targets) |
| GET | `/v1/admin/permission-monitor/health` | `permission.read` | Internal health detail, not a liveness probe |
| GET | `/v1/admin/permission-monitor/user/<userId>/permissions` | `permission.read` | Target user must belong to the current tenant, otherwise 404 |
| GET | `/v1/admin/permission-monitor/user/<userId>/check/<permission>` | `permission.read` | Same rule; `:permission` accepts `resource:action` |
| DELETE | `/v1/admin/permission-monitor/cache/user/<userId>` | `permission.write` | Same rule; clearing cache is a write on the permission subsystem |
| DELETE | `/v1/admin/permission-monitor/cache` | `permission.write` | Permission caches only (whitelisted prefixes); **no longer** `Cache::clear()`, see 3.4 |

Rules and decisions:

- **`health` gets no exemption**: it returns internal cache/latency state, not "is the process alive".
  Process liveness must use a separate endpoint that returns no business data; making this endpoint
  anonymous would re-open the unauthenticated read that was just closed.
- **Cross-tenant access is always 404 (never 403)**: the per-user queries inside `PermissionService`
  derive the tenant from the target user row and carry no request-tenant dimension, so the controller
  must verify "target user belongs to the current request tenant" before delegating. Returning 404
  instead of 403 avoids leaking "this userId exists but belongs to someone else".
- **Route middleware parameter format**: declarations under `route/**` use the internal slug form
  (`permission.read`) because `scripts/check-permission-codes.php` forbids `resource:action` (which
  contains `:`) at the route layer. Both forms are normalized inside the middleware and are equivalent.

### 3.4 Permission Cache Invalidation Policy (R1 / ER-06)

> Implementation tasks: R1 (`clearAllCache` used to flush the whole Redis DB), ER-06 (no cache
> invalidation after an authority change).

#### 3.4.1 Key space and TTL

| Key (logical; the driver prepends `alkaid:` when stored in Redis) | TTL | Producer |
| --- | --- | --- |
| `l1:permission:tenant:{t}:user:{u}:permissions` | 60s | `PermissionCacheService` |
| `l2:permission:tenant:{t}:user:{u}:permissions` | 300s | `PermissionCacheService` |
| `permission:tenant:{t}:user:{u}:check:{md5(code)}` | 30s | `PermissionCacheService::hasPermission()` |
| `casbin:check:{u}:{t}:{resource}:{action}` | 300s (`CASBIN_CACHE_TTL`) | `CasbinService::check()` |
| `casbin:policy:full` | 300s (`policy_cache_enabled`, off by default) | `DatabaseAdapter` |

Real stored form (sampled with phpredis `SCAN`): `alkaid:l1:permission:tenant:9:user:9:permissions`,
`alkaid:session:verify-r1-abc123`, `alkaid:rl:dev:user:verify-r1:token_bucket`.

#### 3.4.2 Prefix whitelist (the safety boundary)

Purges may **only** hit these prefix families; any other prefix (including the empty string) is
rejected with an exception:

`l1:permission:` / `l2:permission:` / `permission:` / `casbin:check:` / `casbin:policy:`

The boundary is structural: every permission-related key contains a `permission:` or `casbin:`
segment, while `session:` (sessions), `rl:` (rate limiting), `lowcode:` and `casbin_last_reload`
do not. Purging permission caches therefore **cannot** delete sessions or rate-limit counters.

**Never** use `Cache::clear()` for permission caches: ThinkPHP's Redis driver implements it as
`flushDB()` (the whole DB, and sessions live in the same DB); the File driver implements it as
`rmdir(cache directory)`. Likewise `KEYS pattern` is forbidden (O(N) and blocking on a
single-threaded Redis); only `SCAN` + batched `DEL` is allowed.

#### 3.4.3 Driver adaptation

| Driver | Strategy | Notes |
| --- | --- | --- |
| Redis (phpredis) | `SCAN` cursor iteration + batched `DEL` (500 per call) | Production path. Prefixes are grouped by their first segment so one `SCAN` pass serves a group |
| File | Exact deletion through a key index | The File driver stores `md5(key)` on disk (`runtime/cache/ab/cdef….php`), which cannot be reversed; keys written by the permission services are therefore recorded and deleted one by one |
| Others (Predis / Memory / custom) | Same as File (index) | **Never** degrades to `Cache::clear()`; an empty index only logs a warning and returns 0 |

#### 3.4.4 When invalidation happens and what it covers

Invalidation always runs **after** the write commits (clearing earlier lets concurrent requests
re-populate the cache from uncommitted data):

| Change | Invalidated | Hook |
| --- | --- | --- |
| Role permissions (`syncPermissions`) | **All users** holding the role | `RoleRepository::syncPermissions()` |
| Role deletion | Members of the role | `RoleRepository::delete()` (members collected first) |
| User roles (`setRoles`) | That user | `UserController::setRoles()` |
| User status (`updateStatus` / `update`) | That user | `UserController::updateStatus()/update()` |
| User deletion | That user | `UserController::delete()` |
| Permission definition change/deletion | All permission caches | `PermissionRepository::save()/delete()` |
| Admin "clear all permission caches" | All permission caches | `PermissionCacheService::clearAllCache()` |

Above 100 affected users the invalidation escalates to a whole-family purge: one `SCAN` beats
assembling thousands of per-user keys.

#### 3.4.5 Failure handling

Cache invalidation is **best effort**: every `PermissionCacheInvalidator` method wraps its work in
`catch (Throwable)`, logs a `warning` and returns `success=false`. It never re-throws and never
rolls back the business write — failing a successful revocation because a cache delete failed is
backwards; the worst case is that stale permissions live for one more TTL.

#### 3.4.6 Known degradation (File driver, development/testing only)

The key index is capped (50k entries) with a 1-hour TTL. When the cap is exceeded the oldest
entries are dropped with a warning; those keys can only expire via TTL. Production forces Redis
(`config/cache.php` forces `redis` for `production/prod/stage/staging`), so this does not apply there.

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
