# T-063 任务完成报告：语言包变更流程

> **完成日期**: 2025-12-14  
> **任务 ID**: T-063  
> **任务描述**: 设计语言包变更工作流，创建同步工具，编写贡献指南  
> **预估工作量**: 1-2 天  
> **实际工作量**: 0.3 天

---

## 一、任务目标

1. 创建语言包同步工具 (`lang:sync`)
2. 编写语言包贡献指南
3. 支持 Git Hook 集成（可选）

---

## 二、实现内容

### 2.1 新增命令

**`php think lang:sync`** - 语言包同步命令

功能特性：
- 将基准语言包中缺失的 key 同步到其他语言包
- 支持 dry-run 模式（预览不写入）
- 支持写入模式 (`--write`)
- 自动生成 `TODO:` 占位标记
- 支持创建缺失的语言文件

### 2.2 使用示例

```bash
# 预览同步（dry-run）
php think lang:sync

# 写入同步
php think lang:sync --write

# 使用 en-us 作为基准
php think lang:sync --base=en-us --write
```

### 2.3 输出示例

```
Language Pack Sync | 语言包同步
Base locale: zh-cn
Mode: DRY-RUN

Processing locale: en-us

✓ All locales are in sync | 所有语言包已同步
```

---

## 三、变更文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `app/command/LangSyncCommand.php` | 新增 | 语言包同步命令（226 行） |
| `config/console.php` | 修改 | 注册 lang:sync 命令 |
| `docs/developer-guides/language-pack-contribution.md` | 新增 | 语言包贡献指南 |

---

## 四、工作流程设计

```
1. 开发者在 zh-cn 中添加新 key
         ↓
2. 运行 lang:check 检查一致性
         ↓
3. 运行 lang:sync --write 同步到其他语言
         ↓
4. 手动翻译 TODO: 标记的内容
         ↓
5. 再次运行 lang:check 确认一致
         ↓
6. 提交变更
```

---

## 五、Git Hook 集成（可选）

在 `.husky/pre-commit` 中添加：

```bash
#!/bin/sh
docker exec alkaid-backend php think lang:check
```

这将在每次提交前自动检查语言包一致性。

---

## 六、测试结果

### 6.1 命令执行

```bash
$ docker exec alkaid-backend php think lang:sync
✓ All locales are in sync | 所有语言包已同步
```

### 6.2 与 lang:check 配合

```bash
$ docker exec alkaid-backend php think lang:check
Found 2 locales: en-us, zh-cn
✓ All language packs are consistent | 所有语言包一致
```

---

## 七、后续优化建议

1. 添加自动翻译集成（如 Google Translate API）
2. 支持嵌套 key 的同步
3. 添加 CI 流水线检查步骤
4. 创建语言包变更 PR 模板

