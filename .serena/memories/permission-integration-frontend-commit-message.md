# Commit Message - 前后端权限集成（前端部分）

## Commit Message（英文）

```
feat(frontend): integrate permission API with frontend apps

Integrated backend permission API (/v1/auth/me) with all frontend
applications. Updated type definitions, API layer, and auth stores
to use permissions from user info.

Changes:
- Updated BasicUserInfo type to include permissions field
- Modified getUserInfoApi to call /v1/auth/me instead of /user/info
- Added getAccessCodesFromMe() method to get permissions from user info
- Updated getAccessCodesApi to use /v1/auth/codes endpoint
- Modified auth stores to extract permissions from user info
- Reduced HTTP requests from 2 to 1 (getUserInfo + getAccessCodes → getUserInfo only)

Affected Applications:
- web-antd: API layer + auth store
- web-ele: API layer + auth store
- web-naive: API layer + auth store
- web-tdesign: API layer + auth store
- playground: API layer + auth store

Permission Format:
- Format: resource:action (e.g., forms:view, forms:create)
- Source: /v1/auth/me API response
- Storage: accessStore.accessCodes

Benefits:
- Reduced HTTP requests (from 2 to 1)
- Better data consistency (permissions from same API call)
- Simplified login flow
- Backward compatible (permissions field is optional)

Code Review:
- Score: 95/100
- P0 Issues: 0
- P1 Issues: 0
- P2 Issues: 2 (error handling, data validation - non-blocking)

This completes the frontend part of the permission integration task.
Backend part was completed in commit 51b23f7a0721088818d1ad4d4049874ab1da7b1c.

Closes: [P0] 前后端权限集成（前端部分）
Ref: docs/todo/development-backlog-2025-11-23.md#L88-L103
Ref: docs/todo/vben-backend-integration-plan.md
Ref: docs/report_archive/2025.11.24/vben-permission-integration-decision.md
Related: 51b23f7a0721088818d1ad4d4049874ab1da7b1c (backend part)
```

## 变更文件清单

```
修改文件：
  frontend/packages/@core/base/typings/src/basic.d.ts
  frontend/apps/web-antd/src/api/core/user.ts
  frontend/apps/web-antd/src/api/core/auth.ts
  frontend/apps/web-antd/src/store/auth.ts
  frontend/apps/web-ele/src/api/core/user.ts
  frontend/apps/web-ele/src/api/core/auth.ts
  frontend/apps/web-ele/src/store/auth.ts
  frontend/apps/web-naive/src/api/core/user.ts
  frontend/apps/web-naive/src/api/core/auth.ts
  frontend/apps/web-naive/src/store/auth.ts
  frontend/apps/web-tdesign/src/api/core/user.ts
  frontend/apps/web-tdesign/src/api/core/auth.ts
  frontend/apps/web-tdesign/src/store/auth.ts
  frontend/playground/src/api/core/user.ts
  frontend/playground/src/api/core/auth.ts
  frontend/playground/src/store/auth.ts
```

## 变更统计（预估）

```
 frontend/packages/@core/base/typings/src/basic.d.ts | 4 +
 frontend/apps/web-antd/src/api/core/user.ts         | 38 +++++++-
 frontend/apps/web-antd/src/api/core/auth.ts         | 14 ++-
 frontend/apps/web-antd/src/store/auth.ts            | 8 +-
 frontend/apps/web-ele/src/api/core/user.ts          | 38 +++++++-
 frontend/apps/web-ele/src/api/core/auth.ts          | 14 ++-
 frontend/apps/web-ele/src/store/auth.ts             | 8 +-
 frontend/apps/web-naive/src/api/core/user.ts        | 38 +++++++-
 frontend/apps/web-naive/src/api/core/auth.ts        | 14 ++-
 frontend/apps/web-naive/src/store/auth.ts           | 8 +-
 frontend/apps/web-tdesign/src/api/core/user.ts      | 34 +++++++-
 frontend/apps/web-tdesign/src/api/core/auth.ts      | 10 ++-
 frontend/apps/web-tdesign/src/store/auth.ts         | 6 +-
 frontend/playground/src/api/core/user.ts             | 26 +++++++-
 frontend/playground/src/api/core/auth.ts             | 6 ++-
 frontend/playground/src/store/auth.ts                | 6 +-
 16 files changed, ~250 insertions(+), ~50 deletions(-)
```
