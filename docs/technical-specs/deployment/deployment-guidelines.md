# Deployment & Ops Guidelines

## 1. Scope and Goals

This document defines deployment and operations practices for AlkaidSYS, focusing on configuration, observability, and runtime safety. It complements the security and API specifications and is the **authoritative reference** for production operations.

## 2. Configuration and Environments

- All environment-specific settings **MUST** be provided via environment variables or configuration files checked into version control (without secrets).
- Secrets (passwords, tokens, keys) **MUST NOT** be committed to the repository; a secret management solution **SHOULD** be used.
- Stage and production configurations **SHOULD** be as similar as possible, differing only in endpoints, secrets and capacity.
- The repository ships two **placeholder-only** templates: `.env.example` is the single source of truth
  for the key set, and `.env.production.example` is the production template (ops copy it to
  `.env.production` and replace every placeholder). The production template **MUST** contain every key
  present in `.env.example`; the reverse is **not** required — production may carry keys the example does
  not have (e.g. `APP_ENV`, `CACHE_DRIVER`, `REDIS_*`, `CORS_ALLOWED_ORIGINS`).
  This one-way constraint is enforced by check E of `php scripts/check-env-example.php --strict`
  (CI gate `Env Example Consistency`); run it locally after touching either template.
  A missing key never fails at boot — it surfaces at runtime as "connecting as root with an empty
  password", "every request without `X-Tenant-ID` returns 500", or "all clients share one rate-limit
  bucket", so it has to be guarded by a gate rather than by code review.

## 3. Logging and Traceability

- All services **MUST** emit structured logs (JSON or key-value) including at least timestamp, level, message, `trace_id`, and tenant/site context when available.
- Application log levels **MUST** be configurable per environment.
- Trace IDs propagated via `X-Trace-Id` **MUST** be logged and correlated across services.

## 4. Metrics and Health Checks

- Services **SHOULD** expose metrics endpoints compatible with the chosen monitoring stack.
- Liveness and readiness probes **MUST** be implemented and configured for orchestrated environments.
- The backend exposes two probes with **different** semantics (`app\controller\HealthController`):

  | Endpoint | Semantics | Dependencies | What to do on failure |
  | --- | --- | --- | --- |
  | `GET /healthz` | **liveness**: can the worker still finish a request | none (no DB, no cache, no auth) | **restart** the container |
  | `GET /readyz` | **readiness**: are the runtime dependencies usable | database + Redis + config | **take it out of rotation**, do not restart |

  - Both return the unified response envelope plus `Cache-Control: no-store`; both are
    unauthenticated, idempotent and side-effect free.
  - Both are short-circuited by the global `app\middleware\HealthProbeBypass` middleware
    **before** `SessionInit`, so a **Redis blip cannot turn liveness into a 500** (otherwise
    Kubernetes would restart every instance and turn a local failure into a global one).
    Check the `X-Health-Probe-Bypass` response header (`healthz` / `readyz`) to confirm the
    bypass actually applied; without it the request took the routing fallback and the immunity
    is **not** in effect.
  - `/readyz` answers **503** on failure and lists every check under `checks.{database,redis,config}`
    (`ok` / `latency_ms` / `error`). Each check has its **own 2s timeout** and never blocks the
    others, so all failures are reported at once.
  - The `config` check enforces two fail-closed preconditions: `DEFAULT_TENANT_ID` is configured
    and positive (ER-04), and `APP_DEBUG=false` in production-like environments.
  - The `Dockerfile` `HEALTHCHECK` and the backend/nginx healthchecks in `docker-compose.yml`
    use `/healthz` (compose has no readiness concept). For Kubernetes / Swarm probe
    configuration see §6.1 of
    [Image & Orchestration Production Requirements](./image-and-orchestration-production-requirements.md).
- Critical business KPIs and error rates **SHOULD** be monitored with alerts.

## 5. Caching, Sessions and Queues

- Redis (or equivalent) **MUST** be used as the canonical backend for cache, session and queue where applicable.
- Cache keys **SHOULD** include tenant/site scope when the data is tenant-specific.
- Queue workers **MUST** be configured with safe retry and backoff policies; poison message handling SHOULD be defined.

## 6. Deployment Process

- Deployments to production **MUST** be automated (CI/CD) and reproducible.
- Each deployment **SHOULD** be tied to a specific git commit and build artifact.
- Rollback procedures **MUST** be documented and periodically exercised in non-production environments.
- Container-level requirements (reproducible image builds, non-root execution, health probes,
  port exposure surface, credential handling) are specified in
  [Image & Orchestration Production Requirements](./image-and-orchestration-production-requirements.md).

## 7. Phase Model (Ops)

- **Phase 1 (baseline)**:
  - Basic logging and environment configuration exist; manual rollback is possible.
- **Phase 2 (target)**:
  - Full observability (logs, metrics, traces) with alerting; automated rollback for failed deployments is available.

