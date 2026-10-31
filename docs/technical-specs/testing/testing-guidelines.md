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

### 4.1 Test database bootstrap: `migrate:run` is NOT enough (R50)

Cold start has **two** steps. `migrate:run` only creates schema plus the 19 system permissions
(`tenant.*`, `role.*`, `user.*`, `dept.*`, `menu.*`, `casbin.manage`); it deliberately creates
**no roles, no users and no business permissions**. Tests, however, hard-code
`userId: 1, tenantId: 1, siteId: 0` (`tests/Helpers/AuthHelper::generateTestToken`), so a
schema-only database makes every "admin accesses a protected endpoint" test fail as
*missing seed data* — not as a real regression.

```bash
# 1) schema (creates alkaid_sys_test)
docker exec -it alkaid-backend sh -lc 'PHP_ENV_NAME=test php think migrate:run'

# 2) seed: roles 1/2, 23 business permissions (explicit ids 1001..1023),
#    role-permission bindings, default site, and the id=1 system user.
docker exec -it alkaid-backend sh -lc 'PHP_ENV_NAME=test php think seed:run -s TestPlatformSeed'
```

Division of responsibility (do not move data into migrations): **schema belongs to migrations,
seed data belongs to seeders**. `TestPlatformSeed` is the test-only seeder — it is a subclass of
`CorePlatformSeed` whose single difference is that the `id = 1` system user gets a *random,
unusable* password hash, because tests authenticate with a JWT and never need a password.
It therefore requires **no** `ADMIN_INIT_PASSWORD` (unlike `CorePlatformSeed`, whose fail-closed
policy for real environments stays intact).

Both steps are idempotent: running them twice inserts no new rows and does not overwrite data.

**Aged local test databases**: the explicit permission ids matter. When `permissions` already
contains business rows created by an older seeder (ids in the 99xxxx range, inherited from
AUTO_INCREMENT), several unit tests legitimately clean up their own fixtures with
`DELETE FROM permissions WHERE id >= 7000` / `>= 8000` / `>= 9000` and will sweep those rows away
mid-run. Rebuild the test database once so the seed lands on the reserved ids:

```bash
docker exec alkaid-mysql mysql -uroot -proot -e 'DROP DATABASE IF EXISTS alkaid_sys_test; CREATE DATABASE alkaid_sys_test CHARACTER SET utf8mb4;'
# then repeat steps 1) and 2) above
```

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

## 7. Unified Test Entry Point (T-056)

The project provides a unified test entry point via ThinkPHP CLI command:

### Running Tests

```bash
# Run all tests (in Docker container)
docker exec -it alkaid-backend php think test

# Run Unit tests only
docker exec -it alkaid-backend php think test --testsuite=Unit

# Run Feature tests only
docker exec -it alkaid-backend php think test --testsuite=Feature

# Run Performance tests (requires phpunit.performance.xml)
docker exec -it alkaid-backend php think test -c phpunit.performance.xml

# Filter by test method name
docker exec -it alkaid-backend php think test --filter=testCanCreateUser

# Generate HTML coverage report
docker exec -it alkaid-backend php think test --coverage-html=coverage

# Stop on first failure
docker exec -it alkaid-backend php think test --stop-on-failure

# Verbose output
docker exec -it alkaid-backend php think test --phpunit-verbose

# Pass additional PHPUnit arguments
docker exec -it alkaid-backend php think test --passthru="--group=auth"
```

### Available Options

| Option | Short | Description |
|--------|-------|-------------|
| `--testsuite` | `-s` | Test suite name (Unit/Feature/Performance) |
| `--filter` | `-f` | Filter tests by method/class name |
| `--coverage-html` | | Generate HTML coverage report |
| `--coverage-text` | | Output text coverage report |
| `--configuration` | `-c` | PHPUnit configuration file (default: phpunit.xml) |
| `--stop-on-failure` | | Stop on first failure |
| `--stop-on-error` | | Stop on first error |
| `--phpunit-verbose` | | PHPUnit verbose output |
| `--phpunit-debug` | | PHPUnit debug mode |
| `--list-suites` | | List available test suites |
| `--passthru` | `-p` | Additional PHPUnit arguments |

### Important Notes

- All test commands **MUST** be executed inside the `alkaid-backend` Docker container.
- The default configuration file is `phpunit.xml` in the project root.
- The `phpunit.xml` file **MUST** reference the PHPUnit 11.5 schema (`https://schema.phpunit.de/11.5/phpunit.xsd`) and use `<source>` for coverage configuration.
- PHPUnit metadata **MUST** use attributes (for example, `#[CoversClass(...)]`) instead of DocBlock `@covers`.
- For performance tests, use `phpunit.performance.xml` configuration.
- Exit code 0 indicates all tests passed; non-zero indicates failures.

## 8. CI Integration and Coverage (T-059)

- The CI pipeline **MUST** run the full automated test suite on every merge into protected branches.
- Projects **SHOULD** define a minimum coverage threshold for critical modules (domain, security, API layer).
- Failing tests **MUST** be treated as release blockers unless explicitly waived through a documented process.

### CI Workflow

The project uses GitHub Actions for CI/CD with two main jobs:

1. **PHP Code Style Check (PSR-12)**: Runs PHP-CS-Fixer in dry-run mode to check code formatting.
2. **PHPUnit Tests**: Runs the test suite against MySQL and Redis services.

CI is triggered on:
- Pull requests to any branch
- Push to `main`, `develop`, and `releases/*` branches

### CI Configuration

See `.github/workflows/backend-php-cs-fixer.yml` for the complete CI configuration.

## 9. Phase Model (Testing)

- **Phase 1 (current baseline)**:
  - Existing tests MAY remain in mixed styles, but new tests **MUST** follow this guideline.
  - Critical areas (authentication, authorization, billing, rate limiting) **MUST** have automated tests before production changes.
- **Phase 2 (target capability)**:
  - Test suites SHOULD cover all P0/P1 Backlog items and main domain workflows end-to-end.
  - Regression test packs SHOULD be defined for release candidates.
