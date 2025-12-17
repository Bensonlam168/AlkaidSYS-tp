【状态更新（2025-12-14）】本计划已完成，实现细节与测试结果见 `pagination-structure-commit-message` 与 `PaginationStructureTest` 相关记忆。

# 分页结构统一任务规划

**任务**：[P1-P2] 分页结构统一（page/page_size/total/total_pages）

**日期**：2025-01-24

## 任务背景

**来源**：`docs/todo/development-backlog-2025-11-23.md` 第 124-132 行

**当前状态**：
- 设计规范要求：`{ list, total, page, page_size, total_pages }`
- 当前实现：`{ list, total, page, pageSize }` 
- 问题：
  1. 使用 `pageSize` 而不是 `page_size`（驼峰命名 vs 下划线命名）
  2. 缺少 `total_pages` 字段

**预估工时**：1-2 天

## 影响范围分析

### 后端使用 paginate() 的地方

1. **app/controller/lowcode/FormDataController.php** 第 65 行
   ```php
   return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
   ```

2. **app/controller/lowcode/CollectionController.php** 第 80 行
   ```php
   return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
   ```

3. **app/controller/lowcode/FormSchemaController.php** 第 60 行
   ```php
   return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
   ```

### 前端影响

- 前端可能依赖 `pageSize` 字段
- 需要检查前端代码是否硬编码了字段名

## 实施计划

### 步骤 1：修改 ApiController::paginate()

修改 `app/controller/ApiController.php` 第 57-67 行：

```php
// 修改前
protected function paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success'): Response
{
    $data = [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,  // ❌ 驼峰命名
    ];

    return $this->success($data, $message);
}

// 修改后
protected function paginate(array $list, int $total, int $page, int $pageSize, string $message = 'Success'): Response
{
    $data = [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,  // ✅ 下划线命名
        'total_pages' => (int)ceil($total / max($pageSize, 1)),  // ✅ 新增字段
    ];

    return $this->success($data, $message);
}
```

**注意**：
- 参数名保持 `$pageSize`（驼峰），因为这是 PHP 内部变量
- 响应字段改为 `page_size`（下划线），符合 API 规范
- 添加 `total_pages` 字段，使用 `max($pageSize, 1)` 避免除零错误

### 步骤 2：更新 PHPDoc 注释

更新方法注释，说明新的响应结构。

### 步骤 3：检查前端影响

搜索前端代码中是否使用了 `pageSize` 字段，如果有，需要更新为 `page_size`。

### 步骤 4：添加测试

创建测试文件验证：
1. 分页响应包含所有必需字段
2. `page_size` 字段存在且值正确
3. `total_pages` 计算正确
4. 边界情况（total=0, pageSize=1 等）

### 步骤 5：更新文档

更新 API 文档中的分页示例。

## 验收标准

- [ ] `ApiController::paginate()` 返回 `page_size` 而不是 `pageSize`
- [ ] 响应包含 `total_pages` 字段
- [ ] `total_pages` 计算正确（`ceil(total / page_size)`）
- [ ] 所有使用 `paginate()` 的控制器正常工作
- [ ] 测试通过
- [ ] 代码审查通过

## 风险评估

- ⚠️ **中等风险**：前端可能依赖 `pageSize` 字段
- ✅ **缓解措施**：
  1. 先检查前端代码
  2. 如果前端依赖，需要同步更新前端
  3. 或者提供兼容层（同时返回 `pageSize` 和 `page_size`）

## 参考文档

- `docs/todo/development-backlog-2025-11-23.md`
- `design/03-data-layer/10-api-design.md` §3
- `docs/technical-specs/api/api-specification*.md` §3.4
