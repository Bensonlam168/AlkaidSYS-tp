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

# 2) seed: roles 1/2, 33 business permissions (explicit ids 1001..1033,
#    including the 10 workflow_* permissions route/workflow.php depends on),
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

### Test suites: the 5 declared in `phpunit.xml`

`php think test --testsuite=<name>` only accepts suite names **registered** in `phpunit.xml`. A misspelled
name does not report "suite not found" — it silently runs 0 tests (see "zero tests must be red" below).

| Suite | Directory | What it runs |
| --- | --- | --- |
| `Unit` | `tests/Unit` | Unit tests: no DB / Redis / HTTP. Behaviour of single classes and functions (Domain, Infrastructure, Schema, low-code form validators, ...). |
| `Feature` | `tests/Feature` | Feature / API tests: the full HTTP request path — authentication, authorization, error codes, rate limiting, tenant isolation. |
| `Integration` | `tests/Integration` | Integration tests against real MySQL / Redis: `BaseModel` global scopes and tenant filtering, Casbin policy cache and permission cleanup, event monitoring, plugin hooks, workflow tables/foreign keys, the low-code CLI. |
| `E2E` | `tests/E2E` | End-to-end tests: critical user journeys across modules (currently `MultiTenantIsolationTest` — a collection created by one tenant must be invisible to another). |
| `Legacy` | `tests/Legacy` | **Guard suite**: asserts no business behaviour; it statically scans the whole repository (the removed legacy low-code stack must not be referenced again, unreviewed `withoutTenantScope()` back doors must not appear). |

`tests/Benchmark/` and the suites in `phpunit.performance.xml` are **not** in the table above — benchmarks and
performance budgets only run under the performance configuration, see §7.1.

**`Legacy` is a guard suite, not "leftover/historical tests"**: each guard recursively reads every PHP file
under `app/`, `domain/`, `infrastructure/`, `tests/` and `plugins/` (several thousand files, ~8 s per guard),
so the whole suite takes ~16–20 s. That cost is deliberate — the value of a guard comes from scanning
*everything*; narrowing the scan range would defeat its purpose.

**"Zero tests executed" must be red**: `phpunit.xml` sets `failOnEmptyTestSuite="true"`. PHPUnit's default is
to treat 0 tests as a pass (it prints `No tests executed!` and exits 0), which produces the worst kind of false
green — the report says "in CI" while not a single byte was checked. With the flag enabled, all of the following
become exit 1: a misspelled suite name, a `--filter` that matches nothing, a moved directory, an emptied test file.

> **Real incident**: `tests/Legacy` used to be in no `testsuite` at all while CI invoked it by class name
> (`--filter=LegacyCollectionUsageGuardTest`) — the step matched nothing yet stayed green, so both guards were
> idle. CI now runs `--testsuite=Legacy`; any new guard dropped into `tests/Legacy` is picked up automatically.

### Running Tests

```bash
# Run all tests (in Docker container)
docker exec -it alkaid-backend php think test

# Run Unit tests only
docker exec -it alkaid-backend php think test --testsuite=Unit

# Run Feature tests only
docker exec -it alkaid-backend php think test --testsuite=Feature

# Run the guard suite only (static-scan guards in tests/Legacy)
docker exec -it alkaid-backend php think test --testsuite=Legacy

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
| `--testsuite` | `-s` | Test suite name (Unit / Feature / Integration / E2E / Legacy, see "Test suites" above) |
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

### 7.1 Performance-budget assertions do not belong to the functional suite (`performance` group)

**Rule**: a test that asserts *how fast this machine happens to be* **MUST NOT** live in the
functional regression suite.

- **Wall-clock assertions** (latency / throughput / QPS / hit rate / timeouts) **MUST** carry
  `#[Group('performance')]`, and their thresholds **MUST** be overridable via environment variables
  with a generous default.
- **Functional assertions** (response fields present, `code`/`message`, allowed vs. denied, type
  contract) **MUST** stay in the regular test. Field *presence* is a functional contract and stays;
  the field's *value as elapsed time* does not.
- **Never** delete a functional assertion, tighten an unrelated threshold or skip a test just to make
  a flaky run green.

**Why**: a functional run must answer "is the behaviour correct?", not "was the machine fast at that
moment?". In practice, `CasbinControllerTest::testReloadPolicySuccess` used to contain
`assertLessThan(1000, execution_time_ms)`; under the load of ~3000 assertions it measured
1029ms / 1114ms, turning a perfectly correct behaviour red and invalidating the whole run.
The token bucket had the same defect: with `rate = 1.0`, "deny once exhausted" actually asserted
"five Redis round-trips finish within one second".

**How the default suite excludes it**: `phpunit.xml` declares the group exclusion, so `php think test`
and CI never execute these tests:

```xml
<groups>
    <exclude>
        <group>performance</group>
    </exclude>
</groups>
```

`tests/Benchmark/` (benchmarks such as `PermissionServiceBenchmarkTest`) is **not** part of any default
suite — do not add it to `phpunit.xml`'s `testsuites`; benchmarks only run under the performance config.

**Running the group** (inside the `alkaid-backend` container; its working directory is `/var/www/html`):

```bash
# performance group only
docker exec alkaid-backend ./vendor/bin/phpunit --group performance

# full performance suite = tests/Benchmark + tests/Performance + performance-group tests in feature files
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.performance.xml

# benchmark directory only
docker exec alkaid-backend ./vendor/bin/phpunit -c phpunit.performance.xml --testsuite=Benchmark
```

`phpunit.performance.xml` selects tests with `<groups><include><group>performance</group></include></groups>`:
tests annotated `#[Group('performance')]` are picked up automatically, and directories that belong to
the performance suite as a whole are tagged with `<directory groups="performance">`. Its `bootstrap`
**MUST** be `vendor/autoload.php` (it used to point at `vendor/topthink/framework/src/helper.php`,
which registers no autoloader and makes every test fail with "Class not found").

**Tuning a threshold**: thresholds are read from the environment, with a default in code; an empty or
invalid value falls back to the default (it does **not** disable the budget). Current thresholds:

| Test | Environment variable | Default | Meaning |
| --- | --- | --- | --- |
| `CasbinControllerTest::testReloadPolicyExecutionTimeWithinBudget` | `CASBIN_RELOAD_BUDGET_MS` | 3000 | Reload-policy latency budget (ms) |

```bash
# loosen on a slow machine / tighten for a release check
docker exec alkaid-backend env CASBIN_RELOAD_BUDGET_MS=1500 ./vendor/bin/phpunit --group performance
```

**When to tag a new test with `performance`** (decide in this order; make it deterministic if you can):

| Situation | Action |
| --- | --- |
| Criterion is wall-clock/throughput and cannot be rewritten deterministically (P95 latency, QPS, hit rate, total time of a large loop) | Tag `#[Group('performance')]`; threshold from env + generous default |
| You want the default to "just barely fit today's measurement" (measured 900ms, assert 1000ms) | **Forbidden**: leave a multiple-factor margin, otherwise load jitter is treated as a regression |
| Criterion is behaviour (allowed/denied, field present, `code == 0`) and only the implementation involves time | **Do not** tag: make it deterministic — shrink `rate` so refilling one token takes far longer than the test, or replace waiting with **bounded polling** |
| Undecided | Write it deterministically first; tag only if that is impossible, and document in the test why it can only be probabilistic |

Reference implementations: `CasbinControllerTest` splits the budget into its own test (threshold from
env), and `RateLimitServiceTest` turns its four time-sensitive tests into "deterministic deny at
`DENY_RATE = 0.01`" plus "deterministic refill via bounded polling" — so they stay in the **Unit**
suite and need no group at all.

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
