# Testing Guidelines

## 1. Scope and Goals

This document defines mandatory testing practices for the AlkaidSYS project. It is the **authoritative specification** for unit, integration, feature and end-to-end tests used to protect the system architecture and critical behaviours.

- All new code **MUST** be covered by appropriate automated tests.
- Refactors **MUST NOT** reduce effective coverage for critical paths without explicit approval and compensating tests.

## 2. Test Types

- **Unit tests** **MUST** validate individual classes and functions without external dependencies (DB, Redis, HTTP, etc.).
- **Integration tests** **SHOULD** cover database, queue, cache and HTTP boundaries.
- **Feature / API tests** **MUST** validate REST endpoints, including authentication, authorization, error handling and rate limiting.
- **End-to-end tests** MAY be used for cross-service scenarios and critical user journeys.

## 3. Test Structure and Naming

- Test files **MUST** live under the framework's standard test directories (e.g. `tests/`), following project conventions.
- Test class and method names **SHOULD** describe the behaviour, not implementation details, e.g. `test_can_create_tenant_scoped_user`.
- Tests **MUST** be deterministic and independent; they MUST NOT rely on execution order.

## 4. Database and Migrations in Tests

- Integration and feature tests that touch the database **MUST** use migrations to create schemas; manual DDL is forbidden.
- Test cases **SHOULD** reset database state between tests using transactions or truncation helpers.
- When a migration introduces destructive changes, tests **MUST** cover both pre- and post-migration data shapes where relevant.

## 5. Security and Permissions Testing

- All secured endpoints **MUST** have tests that cover:
  - Requests without authentication (expect `401` and proper error code).
  - Requests with invalid/expired tokens (expect `401` or `403` with security error codes from the matrix).
  - Requests with insufficient permissions (expect `403` with missing `resource:action` context when applicable).
- When Casbin is introduced (Phase 2), tests **MUST** validate policy behaviour for typical roles and edge cases.

## 6. Rate Limiting, Error Codes and Trace IDs

- Tests for throttled endpoints **MUST** confirm:
  - HTTP `429` status and business `code = 429`.
  - Presence of diagnostic fields and rate-limit headers as defined in API and security specifications.
- Error-handling tests **MUST** assert that all 4xx/5xx JSON responses include `trace_id`.

## 7. CI Integration and Coverage

- The CI pipeline **MUST** run the full automated test suite on every merge into protected branches.
- Projects **SHOULD** define a minimum coverage threshold for critical modules (domain, security, API layer).
- Failing tests **MUST** be treated as release blockers unless explicitly waived through a documented process.

## 8. Phase Model (Testing)

- **Phase 1 (current baseline)**:
  - Existing tests MAY remain in mixed styles, but new tests **MUST** follow this guideline.
  - Critical areas (authentication, authorization, billing, rate limiting) **MUST** have automated tests before production changes.
- **Phase 2 (target capability)**:
  - Test suites SHOULD cover all P0/P1 Backlog items and main domain workflows end-to-end.
  - Regression test packs SHOULD be defined for release candidates.

