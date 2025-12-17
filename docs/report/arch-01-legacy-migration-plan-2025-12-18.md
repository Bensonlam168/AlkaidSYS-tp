# ARCH-01/02 低代码 Legacy 迁移路线（2025-12-18）

## 现状梳理

- Legacy 模块：`infrastructure/Collection/CollectionManager.php` + `domain/Model/Collection.php`
- 引用情况：未检出生产引用（`Infrastructure\\Collection\\CollectionManager` 无使用方），属于遗留文件。
- 新栈：`domain/Lowcode/Collection/**` + `infrastructure/Lowcode/**` 已作为主实现。

## 目标

1. 明确 Legacy -> 新栈 迁移路线与批次计划（A4）。
2. 为 Legacy 类追加 `@deprecated` 标记并在 CI 阻断新增依赖（A5）。
3. 首批迁移示例：CLI/Test 工具侧用新栈替换（A6）。

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

- 更新 Backlog：A4（迁移路线）完成；A5/A6 待执行。
- 如需 CI 规则，可在 php-cs-fixer 或自定义脚本中增加扫描禁用名单。*** End Patch*** End Patchু**END Patch ఏమ**"}, "metadata": {"error": "InvalidPatchFormat", "message": "Failed to parse patch input."}"}​
