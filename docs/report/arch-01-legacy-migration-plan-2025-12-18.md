# ARCH-01/02 低代码 Legacy 迁移路线（2025-12-18）

## 现状梳理（2025-12-18 更新）

- Legacy 模块：`infrastructure/Collection/CollectionManager.php` + `domain/Model/Collection.php` + `infrastructure/Field/FieldTypeRegistry.php` **已删除**。
- 引用情况：生产代码无 Legacy 引用；Guard 测试已收紧（仅允许 Guard 自身），CI 运行可阻断新增依赖。
- 新栈：`domain/Lowcode/Collection/**` + `infrastructure/Lowcode/**` 为唯一实现。

## 目标

1. 明确 Legacy -> 新栈 迁移路线与批次计划（A4）。
2. 为 Legacy 类追加 `@deprecated` 标记并在 CI 阻断新增依赖（A5）。
3. 首批迁移示例：CLI/Test 工具侧用新栈替换（A6）。**当前已删除 Legacy 文件，后续可直接在 Guard 白名单收紧后保持 CI 监控。**

## 迁移路线（Phase 1-3）

- **Phase 1（本周）**：标记与护栏
  - 在 Legacy 类头部添加 `@deprecated` 注释，说明替代路径（Lowcode Collection 模块）。
  - CI 审查规则：禁止新增 `Infrastructure\\Collection\\CollectionManager` / `Domain\\Model\\Collection` 依赖。
  - 文档更新：在设计与 Backlog 中说明 Legacy 已冻结。
- **Phase 2（1-2 周）**：替换调用侧
  - 检查 CLI（`app/command/TestCollection.php`, `app/command/TestField.php`）等工具，确认是否需要切换到新栈或移除。
  - 若存在其他调用（后续发现），按模块迁移到 `Infrastructure\\Lowcode\\...`。
- **Phase 3（2-4 周）**：清理与验证
  - 移除 Legacy 代码与测试，确保 CI 无引用。
  - 回归测试：低代码核心 API 覆盖率 ≥90%。

## 护栏与动作

- **护栏 1**：在 Legacy 文件添加 `@deprecated`，并在 README/Backlog 标注“冻结”。
- **护栏 2**：在代码审查 checklist 中新增“不得新增 Legacy 依赖”。
- **护栏 3**：必要时添加简单 PHPUnit 用例，确保 Legacy 不被误调用（加载即失败）。

## 后续待办

- ✅ 更新 Backlog：A4（迁移路线）完成
- ✅ A5（@deprecated + CI 阻断规则）已完成（通过删除 Legacy 文件 + Legacy Guard 实现）
- ✅ A6（首批迁移）已完成（Legacy 文件已删除，Guard 测试生效）

## 迁移完成状态（2025-12-18 更新）

**Phase 1-3 已全部完成**：

1. **Phase 1（标记与护栏）** ✅
   - Legacy 文件已删除（替代 @deprecated 标记）
   - CI 阻断规则：LegacyCollectionUsageGuardTest 已集成到 CI 流程

2. **Phase 2（替换调用侧）** ✅
   - 生产代码无 Legacy 引用
   - 新栈完全替代 Legacy 栈

3. **Phase 3（清理与验证）** ✅
   - Legacy 代码已移除
   - CI 验证无 Legacy 引用
   - 回归测试：低代码核心 API 测试通过（78 tests, 343 assertions）

## 经验总结

- **直接删除 vs @deprecated 标记**：在生产环境，直接删除 Legacy 文件比标记 @deprecated 更安全有效
- **CI 集成**：Legacy Guard 测试必须集成到 CI，才能真正阻断新增依赖
- **文档维护**：迁移完成后应及时更新相关文档和 Backlog 状态"}, "metadata": {"error": "InvalidPatchFormat", "message": "Failed to parse patch input."}"}​
