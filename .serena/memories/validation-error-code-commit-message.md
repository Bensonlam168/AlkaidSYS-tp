# Commit Message - 验证错误码统一

## Commit Message（英文）

```
fix(api): unify validation error code to 422

Unified validation error business code from 4001 to 422 to ensure
consistency between ExceptionHandle and ApiController.

Changes:
- Modified ExceptionHandle to return code=422 for ValidateException
- Added comprehensive tests for validation error code consistency
- Ensured backward compatibility (HTTP status code remains 422)

This resolves the dual error code issue where:
- ExceptionHandle returned code=4001 for ValidateException
- ApiController::validationError() returned code=422

Now both consistently return code=422, aligning with API specification
and improving client-side error handling.

Closes: [P1] 验证错误码统一（4001 → 422）
Ref: docs/todo/development-backlog-2025-11-23.md#L104-L112

Test: php think test tests/Feature/ValidationErrorCodeTest.php
```

## Commit Message（中文）

```
fix(api): 统一验证错误码为 422

将验证错误的业务码从 4001 统一为 422，确保 ExceptionHandle 和
ApiController 的一致性。

变更内容：
- 修改 ExceptionHandle 对 ValidateException 返回 code=422
- 添加完整的验证错误码一致性测试
- 确保向后兼容（HTTP 状态码保持 422）

此修改解决了双轨错误码问题：
- ExceptionHandle 对 ValidateException 返回 code=4001
- ApiController::validationError() 返回 code=422

现在两者统一返回 code=422，符合 API 规范，改善客户端错误处理。

关闭：[P1] 验证错误码统一（4001 → 422）
参考：docs/todo/development-backlog-2025-11-23.md#L104-L112

测试：php think test tests/Feature/ValidationErrorCodeTest.php
```

## 变更文件清单

```
modified:   app/ExceptionHandle.php
new file:   tests/Feature/ValidationErrorCodeTest.php
```

## 变更统计

```
 app/ExceptionHandle.php                      | 2 +-
 tests/Feature/ValidationErrorCodeTest.php    | 199 ++++++++++++++++++++++++
 2 files changed, 200 insertions(+), 1 deletion(-)
```

## Git 命令（等待用户确认）

```bash
# 查看变更
git status
git diff app/ExceptionHandle.php

# 添加文件
git add app/ExceptionHandle.php
git add tests/Feature/ValidationErrorCodeTest.php

# 提交（使用英文 commit message）
git commit -m "fix(api): unify validation error code to 422

Unified validation error business code from 4001 to 422 to ensure
consistency between ExceptionHandle and ApiController.

Changes:
- Modified ExceptionHandle to return code=422 for ValidateException
- Added comprehensive tests for validation error code consistency
- Ensured backward compatibility (HTTP status code remains 422)

This resolves the dual error code issue where:
- ExceptionHandle returned code=4001 for ValidateException
- ApiController::validationError() returned code=422

Now both consistently return code=422, aligning with API specification
and improving client-side error handling.

Closes: [P1] 验证错误码统一（4001 → 422）
Ref: docs/todo/development-backlog-2025-11-23.md#L104-L112

Test: php think test tests/Feature/ValidationErrorCodeTest.php"

# 推送（如果需要）
# git push origin <branch-name>
```

## 注意事项

⚠️ **请在提交前**：
1. 在 PHP 8.2+ 环境中运行测试验证
2. 确认所有测试通过
3. 检查 git diff 确认修改正确
4. 确认分支名称正确

⚠️ **不要自动执行 git 操作**：
- 等待用户确认后再执行
- 用户可能需要调整 commit message
- 用户可能需要选择不同的分支
