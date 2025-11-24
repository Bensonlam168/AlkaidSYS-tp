# Performance & Scalability Guidelines

## 1. Scope and Goals

This document defines performance and scalability requirements for AlkaidSYS. It complements API, security, database and deployment specifications and is the **authoritative reference** for performance-sensitive design and optimisation.

## 2. General Principles

- Performance work **MUST** focus on end-to-end user-perceived latency and throughput, not only microbenchmarks.
- Changes **MUST NOT** trade correctness, security or maintainability for premature optimisation.

## 3. Database Performance

- Queries on tenant-specific data **MUST** use indices that include `tenant_id` (and `site_id` when applicable).
- N+1 query patterns **MUST** be eliminated using eager loading, joins or batch queries.
- Long-running queries **SHOULD** be detected via slow query logs and optimised with proper indexing or query rewrites.

## 4. Caching and Rate Limiting

- Read-heavy endpoints **SHOULD** use caching where appropriate, with cache keys that include tenant/site scope.
- Rate limiting **MUST** follow the Phase 1/Phase 2 model defined in the API and security specs, with Redis-backed token bucket as the Phase 2 target.

## 5. PHP Runtime and HTTP Layer

- PHP-FPM or equivalent workers **SHOULD** be sized according to CPU cores and external dependency latency.
- Timeouts for upstream calls (DB, Redis, HTTP) **MUST** be configured explicitly.

## 6. Phase Model (Performance)

- **Phase 1 (baseline)**: identify and fix obvious bottlenecks, enforce basic indexing and N+1 elimination.
- **Phase 2 (target)**: define and monitor SLOs, use metrics and tracing to guide continuous optimisation.

