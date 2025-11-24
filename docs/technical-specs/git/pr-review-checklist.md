# PR Review Checklist

> This checklist is the reference for reviewers when validating Pull Requests against the technical specifications.

## 1. General

- [ ] PR title and description clearly explain the intent and main changes.
- [ ] Related backlog items / issues are linked.
- [ ] Changes are reasonably small and focused (no unnecessary scope creep).
- [ ] For Git operations, remote configuration has been checked (see "Remote safety checks").

## 2. API Guidelines

- [ ] New / changed endpoints follow the API spec (`api-specification*.md`).
- [ ] HTTP methods, paths, status codes and response formats are consistent.
- [ ] Error responses use the unified error format and codes.
- [ ] Pagination, filtering, and sorting follow project conventions.

## 3. Security Guidelines

- [ ] Auth / RBAC / Casbin changes follow the security guidelines.
- [ ] Permissions use `resource:action` codes and `permissions.slug=resource.action` mapping.
- [ ] Rate limiting behaviour (Phase 1 / Phase 2) is respected where applicable.
- [ ] Sensitive data is not logged and is handled securely.

## 4. Database Guidelines

- [ ] Schema and migrations follow naming and multi-tenant key rules.
- [ ] Indexes include `tenant_id` (and `site_id` where required).
- [ ] No obvious N+1 queries; queries use appropriate joins / eager loading.
- [ ] Long-running or complex queries are justified and documented.

## 5. Testing Guidelines

- [ ] New behaviour is covered by unit / integration / feature tests.
- [ ] Existing tests updated when behaviour changes.
- [ ] Tests pass locally (or via CI) for backend and frontend (where relevant).

## 6. Performance & Scalability

- [ ] Critical paths are checked for performance regressions.
- [ ] Database queries follow performance guidelines and use proper indexes.
- [ ] Caching and invalidation strategies are reasonable and documented.
- [ ] Rate limiting and backpressure considerations are addressed.

## 7. Low-code Guidelines

- [ ] Changes to low-code engine follow `lowcode-guidelines*.md`.
- [ ] Domain/Lowcode remains the single source of truth.
- [ ] No new hard dependencies on legacy low-code modules are introduced.

## 8. Deployment & Ops

- [ ] New env vars / configs are documented with safe defaults.
- [ ] Logging, metrics, and tracing are updated where needed.
- [ ] Deployment / migration steps are documented (if required).

## 9. Git Workflow & Commits

- [ ] Branch name follows the Git workflow conventions.
- [ ] Commits follow Conventional Commits and project-specific rules.
- [ ] Large changes are split into reviewable commits where reasonable.

