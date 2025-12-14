# T-061 任务完成报告：语言包 key 一致性检查

> **完成日期**: 2025-12-14  
> **任务 ID**: T-061  
> **任务描述**: 语言包 key 一致性检查  
> **预估工作量**: 2-3 天  
> **实际工作量**: 0.5 天

---

## 一、任务背景

### 1.1 问题描述

根据 `docs/todo/development-backlog-2025-11-25.md` 的任务定义：

> "检查所有语言包文件的 key 一致性，确保不同语言版本的 key 完全一致"

### 1.2 任务目标

1. 创建 `php think lang:check` 命令检查语言包一致性
2. 比较不同语言版本的文件和 key
3. 输出差异报告

---

## 二、解决方案

### 2.1 技术方案

1. **LangCheckCommand** (`app/command/LangCheckCommand.php`)
   - 扫描 `app/lang/` 目录下所有语言目录
   - 加载每个语言的所有 PHP 语言文件
   - 支持嵌套数组 key 扁平化
   - 以指定语言为基准进行对比
   - 输出缺失/多余的文件和 key

### 2.2 命令用法

```bash
# 检查语言包一致性（默认以 zh-cn 为基准）
php think lang:check

# 指定基准语言
php think lang:check --base=en-us

# 显示修复建议
php think lang:check --fix
```

### 2.3 检查项目

| 检查项 | 说明 |
|--------|------|
| 缺失文件 | 其他语言缺少基准语言中存在的文件 |
| 多余文件 | 其他语言存在基准语言中没有的文件 |
| 缺失 key | 其他语言缺少基准语言中存在的 key |
| 多余 key | 其他语言存在基准语言中没有的 key |

---

## 三、变更文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `app/command/LangCheckCommand.php` | 新增 | 语言包一致性检查命令 |
| `config/console.php` | 修改 | 注册 lang:check 命令 |

---

## 四、测试结果

### 4.1 命令测试

```bash
$ docker exec alkaid-backend php think lang:check
Checking language pack consistency...
检查语言包一致性...

Found 2 locales: en-us, zh-cn
Base locale: zh-cn

No issues found | 未发现问题

✓ All language packs are consistent | 所有语言包一致
```

### 4.2 当前语言包状态

| 语言 | 文件数 | 状态 |
|------|--------|------|
| zh-cn | 3 (auth, common, error) | ✅ 基准 |
| en-us | 3 (auth, common, error) | ✅ 一致 |

### 4.3 单元测试

```
PHPUnit 11.5.44
Tests: 191, Assertions: 677, Incomplete: 3
OK, but there were issues!
```

### 4.4 代码格式检查

```
PHP-CS-Fixer: Found 0 of 232 files that can be fixed
```

---

## 五、CI 验证

| 项目 | 值 |
|------|-----|
| Workflow Run ID | 20209743709 |
| 状态 | ✅ success |
| Commit SHA | 8d62eb644d894e6bab151a6c9766bef472694e7e |
| 分支 | feature/lowcode-t002-p1-tenantization |

---

## 六、功能特性

1. **多语言支持**: 自动扫描所有语言目录
2. **嵌套 key 支持**: 支持嵌套数组结构的 key 比较
3. **灵活基准**: 可通过 `--base` 选项指定基准语言
4. **修复建议**: 使用 `--fix` 选项显示缺失 key 的建议值
5. **退出码**: 发现不一致时返回非零退出码，可用于 CI 集成

---

## 七、后续建议

1. **CI 集成**: 可选择在 CI 中添加 `php think lang:check` 步骤
2. **新增语言**: 添加新语言时运行检查确保 key 完整
3. **自动修复**: 未来可扩展自动生成缺失 key 的功能

---

## 八、Git 提交记录

```
commit 8d62eb6
feat(testing): add language pack key consistency check (T-061)

- Create LangCheckCommand (php think lang:check)
- Compare keys across all locale directories
- Report missing/extra files and keys
- Support --base option for base locale selection
- Support --fix option for fix suggestions
```

