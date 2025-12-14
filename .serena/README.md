# Serena MCP Server - 快速入门指南

> **项目**: AlkaidSYS-tp
> **Serena 版本**: 0.1.4
> **更新时间**: 2024-11-24
> **官方文档**: https://oraios.github.io/serena/
> **GitHub**: https://github.com/oraios/serena

---

## 🚀 什么是 Serena？

Serena 是一个强大的 MCP (Model Context Protocol) Server，为 AI 编码助手提供 IDE 级别的代码分析和编辑能力。

**核心优势**：
- 🔍 **符号级别的代码理解** - 不再需要读取整个文件
- ✏️ **精确的代码编辑** - 基于符号的修改，避免全文件读写
- 🧠 **项目记忆系统** - 持久化项目知识和上下文
- 🎯 **多语言支持** - 支持 30+ 种编程语言（包括 PHP、TypeScript）
- 🆓 **完全免费开源** - 增强现有 LLM 的能力，无需额外费用

## ✅ 激活状态

**Serena 项目已成功激活!** 🎉

- **激活时间**: 2024-11-24
- **项目名称**: AlkaidSYS-tp
- **索引文件**: 809 个 (PHP: 150, TypeScript: 659)
- **健康检查**: ✅ 通过

## 📁 文件说明

- `project.yml` - Serena 项目配置文件 (官方格式)
- `cache/` - 符号索引缓存目录
  - `php/` - PHP 语言服务器缓存
  - `typescript/` - TypeScript 语言服务器缓存
- `memories/` - 项目记忆存储
- `logs/` - 健康检查和运行日志
- `start-server.sh` - 启动 Serena MCP Server 的脚本
- `test-server.sh` - 测试 Serena MCP Server 的脚本
- `auggie-mcp-config.json` - Auggie (Augment) MCP 配置
- `claude-code-config-example.json` - Claude Code 配置示例
- `README.md` - 本文件

---

## ⚡ 快速开始（3 步）

### 1. 激活项目

在 Augment IDE 中，每次新会话开始时需要激活项目：

```python
activate_project_serena(project="AlkaidSYS-tp")
```

**重要提示**：
- ⚠️ 项目激活状态不持久，每次新会话都需要重新激活
- ✅ 使用项目名称 `AlkaidSYS-tp` 或容器内路径 `/workspace/projects/AlkaidSYS-tp`
- ❌ 不要使用宿主机路径 `/Users/Benson/Code/AlkaidSYS-tp`

### 2. 验证状态

```python
# 获取当前配置
get_current_config_serena()

# 检查入职状态
check_onboarding_performed_serena()
```

### 3. 开始使用

```python
# 列出项目目录
list_dir_serena(relative_path=".", recursive=False)

# 查找文件
find_file_serena(file_mask="*.php", relative_path="app")

# 查找符号
find_symbol_serena(name_path_pattern="MyClass", relative_path="app")
```

---

## 🚀 使用方法

### 在 Augment IDE 中使用（推荐）

Serena MCP 已在 Augment IDE 中配置并运行，可以直接使用 Serena 工具。

**配置文件位置**：
```
~/Library/Application Support/Augment/mcp-settings.json
```

**使用示例**：
```python
# 查找类定义
find_symbol_serena(name_path_pattern="Collection", include_body=True)

# 查找所有引用
find_referencing_symbols_serena(
    name_path="CollectionManager",
    relative_path="app/service/CollectionManager.php"
)

# 在方法后插入代码
insert_after_symbol_serena(
    name_path="CollectionController/index",
    relative_path="app/controller/CollectionController.php",
    body="public function export() { /* 实现 */ }"
)
```

### 在 Zed IDE 中使用

Serena MCP 也可以在 Zed IDE 中使用：

```
使用 Serena 查找 Collection 类
使用 Serena 查找所有使用 CollectionManager 的地方
使用 Serena 在 CollectionController 的 index 方法后添加 export 方法
```

### 命令行测试

```bash
# 运行健康检查
uvx --from git+https://github.com/oraios/serena serena project health-check /Users/Benson/Code/AlkaidSYS-tp

# 重新索引项目
uvx --from git+https://github.com/oraios/serena serena project index /Users/Benson/Code/AlkaidSYS-tp
```

---

## 📚 核心功能

### 1. 文件和目录操作

- `list_dir_serena` - 列出目录内容
- `find_file_serena` - 查找匹配的文件

### 2. 代码分析

- `get_symbols_overview_serena` - 获取文件的符号概览
- `find_symbol_serena` - 查找类、方法、函数等符号
- `find_referencing_symbols_serena` - 查找符号的引用位置
- `search_for_pattern_serena` - 使用正则表达式搜索代码

### 3. 代码编辑

- `replace_symbol_body_serena` - 替换符号的实现
- `insert_after_symbol_serena` - 在符号后插入代码
- `insert_before_symbol_serena` - 在符号前插入代码
- `rename_symbol_serena` - 重命名符号（全局）

### 4. 项目记忆

- `write_memory_serena` - 创建项目记忆
- `read_memory_serena` - 读取记忆
- `list_memories_serena` - 列出所有记忆
- `edit_memory_serena` - 编辑记忆
- `delete_memory_serena` - 删除记忆

### 5. 项目管理

- `activate_project_serena` - 激活项目
- `get_current_config_serena` - 获取当前配置
- `check_onboarding_performed_serena` - 检查入职状态
- `onboarding_serena` - 执行项目入职

### 6. 思维辅助

- `think_about_collected_information_serena` - 思考收集的信息
- `think_about_task_adherence_serena` - 思考任务执行情况
- `think_about_whether_you_are_done_serena` - 思考是否完成

**完整工具列表**：查看 [serena-usage-guide.md](./serena-usage-guide.md#可用工具)

---

## 🎯 使用场景

### 场景 1：分析代码结构

```python
# 1. 获取文件概览
get_symbols_overview_serena(relative_path="app/controller/User.php")

# 2. 查看特定方法
find_symbol_serena(
    name_path_pattern="User/login",
    relative_path="app/controller/User.php",
    include_body=True
)
```

### 场景 2：修改代码

```python
# 1. 查找要修改的方法
find_symbol_serena(
    name_path_pattern="User/login",
    include_body=True
)

# 2. 替换方法实现
replace_symbol_body_serena(
    name_path="User/login",
    relative_path="app/controller/User.php",
    body="public function login() { /* 新实现 */ }"
)
```

### 场景 3：管理项目知识

```python
# 创建项目记忆
write_memory_serena(
    memory_file_name="authentication_flow.md",
    content="""# 认证流程

## 登录流程
1. 用户提交凭据
2. 验证凭据
3. 生成 JWT 令牌
4. 返回令牌
"""
)

# 后续读取
read_memory_serena(memory_file_name="authentication_flow.md")
```

## 📚 配置文件

### project.yml

主配置文件,包含:
- 支持的编程语言 (PHP, TypeScript)
- 忽略路径规则
- 可用工具列表
- 项目设置

### 缓存目录

Serena 自动缓存符号索引以提高性能:
- `cache/php/` - PHP 符号缓存
- `cache/typescript/` - TypeScript 符号缓存

如果代码有大量外部修改,可以重新索引:
```bash
uvx --from git+https://github.com/oraios/serena serena project index /Users/Benson/Code/AlkaidSYS-tp
```

## 🔧 配置其他客户端

### Claude Code

将 `claude-code-config-example.json` 的内容添加到:

**macOS**:
```bash
~/Library/Application Support/Claude/claude_desktop_config.json
```

### Auggie (Augment)

配置文件已准备: `auggie-mcp-config.json`

在 VS Code 的 Augment 扩展中导入此配置。

## ⚠️ 注意事项

1. **缓存管理**: Serena 会自动缓存,但大量外部修改后需要重新索引
2. **语言服务器**: 已自动安装在 `~/.serena/language_servers/`
3. **忽略文件**: 使用项目的 `.gitignore` + 额外规则
4. **性能**: 大文件 (>1MB) 会被跳过

---

## ⚠️ 重要提示

### 路径使用

- ✅ **正确**：使用项目名称 `AlkaidSYS-tp` 或容器内路径 `/workspace/projects/AlkaidSYS-tp`
- ❌ **错误**：使用宿主机路径 `/Users/Benson/Code/AlkaidSYS-tp`

### 项目激活

- ⚠️ 每次新会话都需要重新激活项目（这是正常行为）
- 💡 建议在每次对话开始时首先激活项目

### 最佳实践

1. **从干净的 git 状态开始** - 便于查看和管理变更
2. **使用符号级别的工具** - 比读取整个文件更高效
3. **利用项目记忆** - 存储重要的项目知识
4. **渐进式信息收集** - 从概览到细节，逐步深入

---

## 📖 文档导航

- **完整使用指南**: [serena-usage-guide.md](./serena-usage-guide.md)
  - 详细的工具说明和参数
  - 完整的使用示例
  - 常见问题解答
  - 最佳实践建议

- **官方文档**: https://oraios.github.io/serena/
- **GitHub 仓库**: https://github.com/oraios/serena

- **项目文档**:
  - `../docs/SERENA_MCP_ACTIVATION_SUMMARY.md` - 激活总结
  - `../docs/serena-mcp-activation-guide.md` - 激活指南
  - `QUICKSTART.md` - 快速参考

---

## 🆘 常见问题

### Q: 为什么每次都要重新激活项目？

**A**: Serena 通过 `docker exec` 启动独立实例，每次调用都是新进程，不共享状态。这是 MCP 协议的正常行为。

### Q: 找不到文件或符号怎么办？

**A**:
1. 确认使用的是相对路径（相对于项目根目录）
2. 使用 `list_dir_serena` 查看目录结构
3. 使用 `find_file_serena` 查找文件
4. 使用 `get_symbols_overview_serena` 查看文件中的符号

### Q: 如何提高效率？

**A**:
1. 使用符号级别的工具而不是读取整个文件
2. 利用项目记忆存储常用知识
3. 从概览开始，逐步深入细节
4. 使用 `search_for_pattern_serena` 进行精确搜索

---

## 🔗 相关资源

- **项目记忆**: `.serena/memories/` 目录
- **项目配置**: `.serena/project.yml`
- **Serena 日志**: 查看 Docker 容器日志

---

## 🎉 开始使用

Serena 已经完全激活并可以使用了！

在 Augment IDE 或其他配置了 Serena MCP 的客户端中，直接使用 Serena 工具即可。

**需要帮助？** 查看[完整使用指南](./serena-usage-guide.md)或访问[官方文档](https://oraios.github.io/serena/)

**祝你使用愉快! 🚀**

---

**最后更新**: 2024-11-24
**Serena 版本**: 0.1.4
**维护者**: AlkaidSYS Team
