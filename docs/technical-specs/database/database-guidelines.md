# Database Design Guidelines

## 1. Scope and Goals

This document defines mandatory database design and data access rules for the AlkaidSYS project. It is the **only authoritative specification** for relational schema design, multi-tenant keys, indexing, and migrations.

- All new database work **MUST** comply with this document.
- In case of conflict, this document and `design/03-data-layer/10-api-design.md` together override ad-hoc implementation choices.

## 2. References

- `design/03-data-layer/10-api-design.md` (data layer and API contracts)
- `design/04-security-performance/11-security-design.md` (tenant isolation, security)
- `docs/todo/development-backlog-2025-11-23.md` (P1: migrations and test coverage, BaseModel scope optimisation)
- `docs/report/t1-domain-cleanup-s3-s4-execution-report.md` (Legacy domain cleanup)

## 3. Schema and Naming Rules

- Database names, schemas and tables **MUST** use `snake_case`.
- Table names **MUST** be plural nouns, e.g. `users`, `workflow_nodes`, `lowcode_collections`.
- Column names **MUST** use `snake_case` with stable semantics, e.g. `created_at`, `updated_at`, `tenant_id`, `site_id`.
- Primary keys **SHOULD** be `BIGINT UNSIGNED` auto-increment columns named `id`, unless a strong reason for natural keys is documented.
- Foreign keys **MUST** reference the target table's `id` (or the declared primary key) and follow the pattern `<entity>_id`.
- Soft-deletion (when used) **MUST** use a nullable `deleted_at` column.

## 4. Multi-tenant and Multi-site Isolation

- All business tables that store tenant-specific data **MUST** include a `tenant_id` column; per-site data **MUST** additionally include `site_id` where applicable.
- Multi-tenant constraints **MUST** ensure that:
  - Primary lookup indices include `tenant_id` (and `site_id` when relevant), not only `id`.
  - Unique constraints that are logically per-tenant **MUST** include `tenant_id`.
- Backend models **MUST** honour tenant and site scopes via the `BaseModel` global scopes; raw queries and manual joins **MUST** add the same filters explicitly.
- Cross-tenant access is forbidden at the data layer; cross-tenant reports **MUST** be implemented through dedicated reporting views/services with explicit security review.

## 5. Indexing and Query Optimisation

- Every frequently queried column (filter, join, sort) **SHOULD** be covered by a B-tree index.
- Composite indices **MUST** follow the actual query patterns, typically placing `tenant_id` first, followed by high-selectivity business keys.
- Developers **MUST** use the query planner and slow query logs in stage/production to validate index effectiveness for new features.
- N+1 query patterns **MUST** be eliminated using joins, eager loading, or batched queries.
- Large text/blob columns **MUST NOT** be used in equality or range filters; dedicated search infrastructure SHOULD be used when required.

## 6. Migrations and Data Changes

- All schema changes **MUST** be introduced via database migration files under `database/migrations/`; direct manual DDL on shared environments is forbidden.
- Each migration **MUST** be idempotent and reversible when technically feasible.
- Migrations that perform data backfills or destructive changes **MUST** be accompanied by:
  - A documented rollback or remediation plan.
  - Test coverage that exercises the expected data shapes.
- Application code, migrations, and design documents **MUST** stay in sync; any intentional divergence **MUST** be recorded in `docs/report/*` and, if work is pending, in the Backlog.

## 7. Legacy vs Low-code Data Model

- `Domain\Lowcode` and `Infrastructure\Lowcode` are the **single source of truth** for low-code collection/field definitions.
- New features **MUST NOT** introduce new dependencies on legacy collection APIs such as:
  - `Domain\Model\Collection`
  - `Infrastructure\Collection\CollectionManager`
  - `Infrastructure\Field\FieldTypeRegistry`
- Legacy tables and columns marked as deprecated **MUST** only be used for backward compatibility glue code and MUST NOT receive new behaviour.
- When a low-code collection replaces a legacy table, migrations **MUST** document the mapping rules (field-by-field) and, if necessary, data migration strategies.

## 8. Phase Model (Data Layer)

- **Phase 1 (current baseline capability)**:
  - Existing schemas and partial migrations **MUST** still conform to naming and multi-tenant rules when modified.
  - New tables and columns **MUST** be created through migrations and follow this guideline from day one.
  - Low-code collections and relational tables **MUST** be kept consistent; ad-hoc tables outside the low-code model are strongly discouraged.
- **Phase 2 (target capability)**:
  - All business-critical tables SHOULD have complete forward/backward migrations and seed data where appropriate.
  - Legacy tables that are fully shadowed by low-code implementations SHOULD be retired according to the T1 domain cleanup execution report and the project's major-version deprecation policy.
  - Schema evolution and data migrations SHOULD be exercised in automated tests as part of the standard CI pipeline.

