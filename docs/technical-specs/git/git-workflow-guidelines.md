# Git Workflow & Commit Guidelines

## 1. Scope and Goals

This document defines the Git branching model, commit message conventions and merge policies for the AlkaidSYS project. It is the **authoritative specification** for version control practices and a prerequisite for CI rules and PR review checklists.

- All contributors **MUST** follow this guideline for branches and commits.
- CI and code review rules in later phases **WILL** rely on the conventions defined here.
- The project **MUST** use `pnpm` as the JavaScript/Node.js package manager (no `npm`/`yarn`).

## 2. Branching Model

### 2.1 Main Branches

- `main` **MUST** represent the production-ready code.
- `develop` **SHOULD** be used as the integration branch for upcoming releases.
- Direct commits to `main` and `develop` **MUST NOT** be allowed; changes **MUST** go through feature/fix branches and PRs.

### 2.2 Feature Branches

- Feature branches **MUST** be created from `develop`.
- Naming convention:
  - `feature/<priority>-<area>-<short-description>`
  - Examples:
    - `feature/p0-auth-permission-service`
    - `feature/p1-security-casbin-phase2`
    - `feature/p1-rate-limit-redis-token-bucket`
- Feature branches SHOULD focus on a coherent task or Backlog item (e.g. a P0/P1 task from `development-backlog-2025-11-23.md`).

### 2.3 Fix Branches

- Fix branches **MUST** be used for bug fixes.
- Naming convention:
  - `fix/<priority>-<area>-<short-description>`
  - Examples:
    - `fix/p0-auth-refresh-token-rotation`
    - `fix/p1-db-tenant-scope-bug`

### 2.4 Hotfix Branches

- Hotfix branches **MUST** be created from `main` when urgent production issues require immediate fixes.
- Naming convention:
  - `hotfix/<version>-<short-description>`
  - Example: `hotfix/1.2.3-rate-limit-429-misconfig`
- After merging into `main`, hotfix branches **MUST** be merged back into `develop` (or the active release branch) to keep branches in sync.

### 2.5 Release Branches

- Release branches **MAY** be used when preparing a release that requires stabilization.
- Naming convention:
  - `release/<version>`
  - Example: `release/1.3.0`
- Only bug fixes, documentation and release-related changes **SHOULD** be merged into release branches.

## 3. Commit Message Convention

### 3.1 Format

- The project **SHOULD** follow the [Conventional Commits](https://www.conventionalcommits.org/) style:
  - `<type>(<scope>): <subject>`
  - Optional body and footer for details.
- `scope` **SHOULD** indicate the affected area (e.g. `auth`, `api`, `db`, `lowcode`, `security`, `performance`).

### 3.2 Allowed Types

The following commit types are recommended:

- `feat` – new feature.
- `fix` – bug fix.
- `docs` – documentation only.
- `refactor` – code refactoring without behaviour change.
- `test` – adding or adjusting tests only.
- `chore` – build or tooling changes (no production code change).
- `perf` – performance-related changes.
- `style` – formatting or style-only changes (no logic change).

### 3.3 Examples

**Good examples:**

- `feat(auth): add PermissionService for resource:action checks`
- `fix(db): enforce tenant_id scope on invoice queries`
- `perf(rate-limit): switch to Redis token bucket algorithm`
- `docs(specs): update security guidelines for Casbin Phase 2`

**Bad examples (NOT allowed):**

- `update`
- `fix bug`
- `change stuff`

### 3.4 Linking Backlog Items and Issues

- When relevant, commits **SHOULD** reference Backlog items or tickets in the footer, e.g.:
  - `Refs: P0-auth-permission-service`
  - `Closes: P1-security-casbin-phase2`
- Such references **MAY** be used later by tooling to generate change logs and progress reports.

### 3.5 Breaking Changes

- Breaking changes **MUST** be explicitly marked using one of the following:
  - `feat(scope)!: ...` in the header, or
  - A `BREAKING CHANGE:` footer in the commit body.
- The description **MUST** clearly explain the impact (e.g. removed endpoints, changed response formats) and reference the relevant design/spec/backlog sections.

Example:

- `feat(auth)!: remove legacy v1 auth endpoints`

  Body:

  - `BREAKING CHANGE: /v1/auth/login-legacy has been removed; clients must migrate to /v2/auth/login as per api-specification.md.`

## 4. Merge Strategy

- All changes **MUST** go through Pull Requests (PRs) or Merge Requests (MRs).
- For `main` and `develop`, history **SHOULD** be kept linear:
  - Contributors **SHOULD** rebase their branches on the target branch before merging.
  - Squash-and-merge **SHOULD** be preferred for feature and fix branches to map one branch to one logical commit.
- Merge commits **MAY** be used for release branches if they improve traceability.

## 5. Protected Branches and CI Requirements

- `main` and `develop` **MUST** be protected branches:
  - Direct pushes **MUST NOT** be allowed.
  - At least one successful CI pipeline **MUST** be required before merging.
  - At least one code review approval **MUST** be required (more for high-risk changes is recommended).
- High-risk changes (e.g. security, Casbin integration, rate limiting, database migrations) **SHOULD** require additional reviewers.

## 6. Remote Repository Safety Checks

- Before running `git push`, contributors **MUST** verify the configured remotes:
  - Run `git remote -v` and confirm that the URLs point to the intended AlkaidSYS repository.
  - Never push to any upstream / third-party official repositories (e.g. `vbenjs/vue-vben-admin`).
- For monorepo subprojects (e.g. `frontend/`), contributors **MUST** ensure that their `.git/config` remotes are correct before pushing.
- If in doubt, treat the repository as **local-only** until the remote has been explicitly reviewed and confirmed.

## 7. Alignment with Other Technical Specs

- Commits that touch application code **MUST** comply with:
  - Code style guidelines (`docs/technical-specs/code-style/general-guidelines*.md`).
  - Security guidelines (especially for auth, RBAC/Casbin and rate limiting).
  - Database guidelines (for any schema or migration changes).
  - Testing guidelines (functional changes **SHOULD** include or update tests).
- Large or cross-cutting changes **SHOULD** be split into smaller, reviewable commits that each respect these constraints.

## 7. Relation to Backlog and Phases

- Branch names for P0/P1 items **SHOULD** include the priority and area (e.g. `p0-auth`, `p1-security`, `p1-db`).
- Casbin, Redis token bucket rate limiting and other P0/P1 tasks **SHOULD** be implemented via dedicated feature branches to keep histories clear.
- Future CI rules (Phase 2) **MAY** enforce commit message format and branch naming automatically (e.g. via commitlint and branch name validators).

