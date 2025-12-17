【状态更新（2025-12-14）】本计划已完成，实现与测试结果见 `validation-error-code-commit-message`、`validation-error-code-review-report` 与 `ValidationErrorCodeTest` 相关记忆。

# 验证错误码统一任务计划

**任务**：[P1] 验证错误码统一（4001 → 422）

**日期**：2025-01-24

## 任务背景

**来源**：`docs/todo/development-backlog-2025-11-23.md` 第 104-112 行

**当前状态**：
- 设计规范要求验证错误使用 `code=422`
- `ExceptionHandle` 将 `ValidateException` 映射为 `code=4001`
- `ApiController::validationError()` 使用 `code=422`
- 导致双轨错误码并存

**预估工时**：1-2 天

## 影响分析

### 代码搜索结果

1. **后端使用 4001 的地方**：
   - `app/ExceptionHandle.php` 第 67 行 ✅ 需要修改

2. **前端依赖**：
   - ✅ 前端没有硬编码依赖 4001
   - ✅ 前端使用通用错误处理，基于 HTTP 状态码和 message
   - ✅ 修改安全

3. **文档**：
   - `docs/technical-specs/api/api-specification.zh-CN.md` - 错误码矩阵
   - `docs/report_archive/.../design-implementation-gap-analysis-2025-11-23.md` - 分析文档

## 实施计划

### 步骤 1：修改 ExceptionHandle.php

修改 `app/ExceptionHandle.php` 第 67 行：

```php
// 修改前
return $this->buildJsonResponse(
    422,
    'Validation failed | 验证失败',
    4001,  // ❌ 旧的错误码
    ['errors' => $e->getError()],
    $traceId,
    $isDebug ? $e : null
);

// 修改后
return $this->buildJsonResponse(
    422,
    'Validation failed | 验证失败',
    422,  // ✅ 统一为 422
    ['errors' => $e->getError()],
    $traceId,
    $isDebug ? $e : null
);
```

### 步骤 2：更新文档

更新 `docs/technical-specs/api/api-specification.zh-CN.md`：
- 确认错误码矩阵中 422 的定义
- 移除任何对 4001 的引用

### 步骤 3：添加测试

创建或更新测试文件：
- 测试 ValidateException 返回 code=422
- 测试 ApiController::validationError() 返回 code=422
- 确保两者一致

### 步骤 4：代码审查

使用 `code-reviewer` 审查修改：
- 检查是否有遗漏的地方
- 验证修改的正确性

## 验收标准

- [ ] `ExceptionHandle` 将 ValidateException 映射为 code=422
- [ ] 所有验证错误统一使用 code=422
- [ ] 测试通过
- [ ] 文档更新
- [ ] 代码审查通过

## 风险评估

- ✅ **低风险**：前端没有硬编码依赖 4001
- ✅ **向后兼容**：HTTP 状态码保持 422 不变
- ✅ **影响范围小**：只修改一个文件的一行代码

## 参考文档

- `docs/todo/development-backlog-2025-11-23.md`
- `docs/technical-specs/api/api-specification.zh-CN.md`
- `docs/report_archive/.../design-implementation-gap-analysis-2025-11-23.md`
