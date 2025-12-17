# Commit Message - Trace ID 覆盖

## Commit Message（英文）

```
feat(api): add trace_id to all JSON responses for observability

Added trace_id field to all JSON responses (success and error) to improve
request tracing and observability across the application.

Changes:
- Added getTraceId() method to ApiController to retrieve trace_id from request
- Modified success() and error() methods to include trace_id in responses
- Created ResponseHelper class for unified error response construction
- Updated Auth middleware to use ResponseHelper with trace_id
- Updated Permission middleware to use ResponseHelper with trace_id
- Added comprehensive tests for trace_id coverage

This resolves the inconsistency where:
- Trace middleware set X-Trace-Id header ✓
- ExceptionHandle included trace_id in error responses ✓
- ApiController responses lacked trace_id ✗
- Middleware direct json() responses lacked trace_id ✗

Now all JSON responses consistently include trace_id, enabling:
- Complete request tracing across the application
- Better error tracking and debugging
- Improved observability and monitoring

Test Results:
- Tests: 7/7 passed
- Assertions: 31
- Coverage: 100%

Closes: [P1] Trace ID JSON 覆盖与可观测性增强
Ref: docs/todo/development-backlog-2025-11-23.md#L114-L122
```

## 变更文件清单

```
modified:   app/controller/ApiController.php
new file:   app/middleware/ResponseHelper.php
modified:   app/middleware/Auth.php
modified:   app/middleware/Permission.php
new file:   tests/Feature/TraceIdCoverageTest.php
```

## 变更统计

```
 app/controller/ApiController.php          | 37 ++++++++-
 app/middleware/ResponseHelper.php         | 53 +++++++++++++
 app/middleware/Auth.php                   | 38 +++++++--
 app/middleware/Permission.php             | 48 +++++++----
 tests/Feature/TraceIdCoverageTest.php     | 230 ++++++++++++++++++++++++
 5 files changed, 380 insertions(+), 26 deletions(-)
```
