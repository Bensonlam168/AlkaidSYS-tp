# Deployment & Ops Guidelines

## 1. Scope and Goals

This document defines deployment and operations practices for AlkaidSYS, focusing on configuration, observability, and runtime safety. It complements the security and API specifications and is the **authoritative reference** for production operations.

## 2. Configuration and Environments

- All environment-specific settings **MUST** be provided via environment variables or configuration files checked into version control (without secrets).
- Secrets (passwords, tokens, keys) **MUST NOT** be committed to the repository; a secret management solution **SHOULD** be used.
- Stage and production configurations **SHOULD** be as similar as possible, differing only in endpoints, secrets and capacity.

## 3. Logging and Traceability

- All services **MUST** emit structured logs (JSON or key-value) including at least timestamp, level, message, `trace_id`, and tenant/site context when available.
- Application log levels **MUST** be configurable per environment.
- Trace IDs propagated via `X-Trace-Id` **MUST** be logged and correlated across services.

## 4. Metrics and Health Checks

- Services **SHOULD** expose metrics endpoints compatible with the chosen monitoring stack.
- Liveness and readiness probes **MUST** be implemented and configured for orchestrated environments.
- Critical business KPIs and error rates **SHOULD** be monitored with alerts.

## 5. Caching, Sessions and Queues

- Redis (or equivalent) **MUST** be used as the canonical backend for cache, session and queue where applicable.
- Cache keys **SHOULD** include tenant/site scope when the data is tenant-specific.
- Queue workers **MUST** be configured with safe retry and backoff policies; poison message handling SHOULD be defined.

## 6. Deployment Process

- Deployments to production **MUST** be automated (CI/CD) and reproducible.
- Each deployment **SHOULD** be tied to a specific git commit and build artifact.
- Rollback procedures **MUST** be documented and periodically exercised in non-production environments.

## 7. Phase Model (Ops)

- **Phase 1 (baseline)**:
  - Basic logging and environment configuration exist; manual rollback is possible.
- **Phase 2 (target)**:
  - Full observability (logs, metrics, traces) with alerting; automated rollback for failed deployments is available.

