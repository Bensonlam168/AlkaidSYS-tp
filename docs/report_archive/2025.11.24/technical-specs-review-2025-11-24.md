# AlkaidSYS Technical Specs Review (2025-11-24)

## 1. Scope

This report summarises the review and consolidation of the technical specification documents under `docs/technical-specs/` and their alignment with:

- `design/03-data-layer/10-api-design.md`
- `design/04-security-performance/11-security-design.md`
- `docs/todo/development-backlog-2025-11-23.md` (P0/P1 items)

The goal is to make `docs/technical-specs/` the **single authoritative source** for day-to-day development standards.

## 2. New / Updated Specification Documents

The following specification documents were created or updated in this review:

- **API**
  - `docs/technical-specs/api/api-specification.md` (updated: clarified rate limiting Phase 1/2, aligned with security guidelines)
  - `docs/technical-specs/api/api-specification.zh-CN.md` (updated to match the English version, including Phase 1/2 and headers)

- **Security**
  - `docs/technical-specs/security/security-guidelines.md` (previously updated: JWT, RBAC/Casbin Phase 2, rate limiting, security error codes)
  - `docs/technical-specs/security/security-guidelines.zh-CN.md` (previously updated to mirror the English version)

- **Code Style**
  - `docs/technical-specs/code-style/general-guidelines.md` (unchanged in this review, acts as PSR-12 based style guide)
  - `docs/technical-specs/code-style/general-guidelines.zh-CN.md` (unchanged; mirrors the English version)

- **Database** (new)
  - `docs/technical-specs/database/database-guidelines.md`
  - `docs/technical-specs/database/database-guidelines.zh-CN.md`

- **Testing** (new)
  - `docs/technical-specs/testing/testing-guidelines.md`
  - `docs/technical-specs/testing/testing-guidelines.zh-CN.md`

- **Deployment & Ops** (new)
  - `docs/technical-specs/deployment/deployment-guidelines.md`
  - `docs/technical-specs/deployment/deployment-guidelines.zh-CN.md`

- **Low-code** (new)
  - `docs/technical-specs/lowcode/lowcode-guidelines.md`
  - `docs/technical-specs/lowcode/lowcode-guidelines.zh-CN.md`

- **Index / README**
  - `docs/technical-specs/README.md` (updated Chinese section to include Database, Testing, Deployment & Ops, Low-code guidelines and to align topic descriptions with the English section.)

## 3. Key Alignment Decisions

### 3.1 API & Error Handling

- Unified response structure and error code semantics are taken from `10-api-design.md` and applied consistently.
- Rate limiting behaviour is expressed as a two-phase model:
  - **Phase 1**: Fixed-window limits with `HTTP 429` + `code = 429`, including diagnostic fields and minimal headers.
  - **Phase 2**: Redis-backed token bucket as a **mandatory target algorithm**, with standard `X-RateLimit-*` headers; details defined jointly by security and API specs.
- All 4xx/5xx JSON responses **MUST** include `trace_id` and correspond to the security error code matrix.

### 3.2 Security & RBAC/Casbin

- The security guidelines are the single source for:
  - JWT and refresh token behaviour.
  - Security-related error codes.
  - Permissions model and Casbin migration.
- Permissions are consistently defined as:
  - External API and frontend use `resource:action` string permission codes.
  - Internal DB `permissions.slug = resource.action` is the primary key.
- Casbin is documented as **mandatory Phase 2 target architecture**, with Phase 1 remaining DB-based RBAC through a `PermissionService` abstraction.

### 3.3 Database & Low-code

- The new database guidelines define:
  - Naming rules, primary/foreign key conventions, soft delete semantics.
  - Tenant and site isolation requirements via `tenant_id` / `site_id`.
  - Indexing and query-optimisation rules and expectations around migrations.
- Low-code guidelines define `Domain\\Lowcode` and `Infrastructure\\Lowcode` as the single source of truth for dynamic collections and fields, and restrict new dependencies on legacy collection APIs.

### 3.4 Testing & Deployment

- Testing guidelines formalise minimum expectations for unit, integration, feature/API and optional end-to-end tests, and tie them directly to:
  - Security scenarios (authentication, authorization, Casbin policies).
  - Rate limiting behaviour and error code/trace ID requirements.
  - CI integration requirements.
- Deployment guidelines capture the baseline and target expectations for:
  - Configuration management and secrets.
  - Logging, metrics and Trace ID propagation.
  - Health checks, cache/session/queue backends, and rollback processes.

## 4. Language and Style Normalisation

- All new and updated documents use RFC-style modal verbs (MUST/SHOULD/MAY) and their Chinese counterparts （必须/应当/可以）.
- "Current vs design" comparative language has been removed from the specifications; the documents now express:
  - **Phase 1**: current baseline capabilities and mandatory minimum behaviour.
  - **Phase 2**: target architecture and mandatory/expected end state.
- Progress, implementation status and technical debt remain tracked in:
  - `docs/todo/development-backlog-2025-11-23.md`
  - `docs/report/design-implementation-gap-analysis-2025-11-23.md`

## 5. Known Gaps and Future Work

- Some existing code and migrations may still deviate from these specs; such gaps are already captured in the Backlog and gap-analysis report.
- Future work **SHOULD** include:
  - Enhancing examples within each spec with concrete ThinkPHP 8, PHP-Casbin, Redis and Nginx configuration snippets.
  - Tightening CI checks to validate the presence of tests and basic config for key areas defined in these specs.

## 6. Conclusion

With the above documents created and updated, the `docs/technical-specs/` directory can now serve as the **single authoritative technical standard** for API behaviour, security, database design, testing, deployment/ops and low-code development.

