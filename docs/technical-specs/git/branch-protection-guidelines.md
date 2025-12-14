# Branch Protection Guidelines

## 1. Scope and Goals

This document describes how to configure protected branches for the AlkaidSYS project (e.g. in GitHub or GitLab) in a way that matches the Git Workflow & Commit Guidelines.

- `main` MUST represent production-ready code.
- `develop` SHOULD be the integration branch for the next release.
- Both MUST be configured as protected branches.

## 2. Protected Branch Rules (main & develop)

The following rules SHOULD be applied to both `main` and `develop`:

1. **No direct pushes**
   - Force all changes to go through Pull Requests / Merge Requests.

2. **Require status checks (CI)**
   - At least one successful CI pipeline MUST be required before merging.
   - Recommended checks (non-exhaustive):
     - Backend test suite (PHPUnit)
     - Frontend checks (lint + tests), if applicable
     - Static analysis / security checks (Phase 2)

3. **Require code review approvals**
   - At least one approval MUST be required before merging.
   - High-risk changes (security, Casbin integration, rate limiting, DB migrations) SHOULD require 2+ approvals or domain experts.

4. **Enforce linear history where supported**
   - Prefer "squash and merge" for feature/fix branches.
   - Disallow merge commits into `main` and `develop` where the platform supports it.

5. **Restrict who can push and merge**
   - Only maintainers / core developers SHOULD be allowed to merge into protected branches.

## 3. Branch Rules for Feature / Fix / Hotfix / Release Branches

- Feature (`feature/*`) and fix (`fix/*`) branches are generally not protected, but CI SHOULD still be configured to run on PRs.
- Hotfix (`hotfix/*`) branches SHOULD be treated as high-risk:
  - CI MUST pass before merging into `main`.
  - At least one reviewer with production experience SHOULD approve.
- Release (`release/*`) branches MAY be protected with lighter rules (e.g. CI + single approval).

## 4. Mapping to Platforms

### 4.1 GitHub (Example)

For each protected branch (`main` and `develop`):

- Enable **"Require a pull request before merging"**.
- Enable **"Require status checks to pass before merging"** and select required CI workflows.
- Enable **"Require approvals"** with minimum 1 (or 2 for high-risk repos).
- Optionally enable **"Require linear history"** to enforce squash/rebase.

### 4.2 GitLab (Example)

For each protected branch (`main` and `develop`):

- Mark branch as **protected**.
- Restrict who can push and merge.
- Use project settings to require **pipeline success** and **approvals** before merge.

## 5. Relation to Git Workflow & Commit Guidelines

- Protected branch rules MUST be consistent with:
  - `docs/technical-specs/git/git-workflow-guidelines.md`
  - Commit message rules enforced via commitlint and Git hooks.
- Future CI (Phase 2) MAY add automated checks to reject branches or PRs that violate naming / commit rules before merge.

## 6. Current Status and GitHub Free Plan Limitation

- For private repositories on the GitHub Free plan, "Branch protection rules" cannot be enabled.
- The branch protection rules described in this document are **fully designed and agreed at the specification level**, but **are not yet enforced by GitHub** for this repository.
- Once the repository is upgraded to a paid plan or made public, these branch protection rules SHOULD be enabled immediately according to this guideline.


