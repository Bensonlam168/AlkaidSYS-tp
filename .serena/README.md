# Serena MCP 配置目录

这个目录包含 AlkaidSYS-tp 项目的 Serena MCP 配置和脚本。

## 📁 文件说明

- `config.yaml` - Serena 项目配置文件
- `start-server.sh` - 启动 Serena MCP Server 的脚本
- `test-server.sh` - 测试 Serena MCP Server 的脚本
- `claude-code-config-example.json` - Claude Code 配置示例
- `README.md` - 本文件

## 🚀 快速开始

### 1. 测试 Serena MCP Server

```bash
# 在项目根目录运行
./.serena/start-server.sh
```

### 2. 配置 Claude Code

将 `claude-code-config-example.json` 的内容添加到 Claude Code 配置文件：

**macOS**:
```bash
~/Library/Application Support/Claude/claude_desktop_config.json
```

**Linux**:
```bash
~/.config/Claude/claude_desktop_config.json
```

**Windows**:
```
%APPDATA%\Claude\claude_desktop_config.json
```

### 3. 在 Claude Code 中激活项目

1. 重启 Claude Code
2. 在聊天中输入：
   ```
   serena onboard
   ```
3. Serena 会自动分析项目并完成激活

### 4. 测试 Serena 功能

在 Claude Code 中尝试：

```
使用 Serena 查找 Collection 类的定义
```

```
使用 Serena 查找所有使用 CollectionManager 的地方
```

```
使用 Serena 在 CollectionController 中添加一个新方法
```

## 🔧 配置说明

### config.yaml

主要配置项：

- `project.name` - 项目名称
- `project.root` - 项目根目录
- `languages` - 支持的编程语言列表
- `ignore` - 忽略的目录和文件模式
- `language_servers` - 语言服务器配置
- `analysis` - 代码分析选项
- `cache` - 缓存配置

### 支持的语言

当前配置支持以下语言：

- ✅ PHP - 后端主要语言
- ✅ JavaScript - 前端脚本
- ✅ TypeScript - 前端主要语言
- ✅ YAML - 配置文件
- ✅ Markdown - 文档
- ✅ JSON - 配置和数据

### 忽略的目录

以下目录会被 Serena 忽略：

- `node_modules/` - Node.js 依赖
- `vendor/` - PHP 依赖
- `runtime/` - 运行时文件
- `frontend/dist/` - 前端构建产物
- `.git/` - Git 版本控制
- `.idea/`, `.vscode/` - IDE 配置

## 📚 Serena 工具

Serena 提供以下主要工具：

### 代码检索工具

- `find_symbol` - 查找符号定义
- `find_referencing_symbols` - 查找符号引用
- `find_symbols_in_file` - 查找文件中的所有符号
- `search_code` - 搜索代码

### 代码编辑工具

- `insert_after_symbol` - 在符号后插入代码
- `insert_before_symbol` - 在符号前插入代码
- `replace_symbol` - 替换符号
- `delete_symbol` - 删除符号

### 文件操作工具

- `read_file` - 读取文件
- `write_file` - 写入文件
- `list_files` - 列出文件

## 💡 使用技巧

### 1. 高效查找代码

❌ 不好的方式：
```
读取 app/controller/lowcode/CollectionController.php 文件
```

✅ 好的方式：
```
使用 Serena 查找 CollectionController 类的 index 方法
```

### 2. 精确编辑代码

❌ 不好的方式：
```
在 CollectionController.php 的第 50 行后添加代码
```

✅ 好的方式：
```
使用 Serena 在 CollectionController 的 index 方法后添加 export 方法
```

### 3. 理解代码关系

```
使用 Serena 查找所有实现 CollectionInterface 的类
```

```
使用 Serena 查找 Collection 类的所有子类
```

## 🔍 故障排除

### 问题 1: Server 启动失败

**检查**：
```bash
# 测试 uv 是否正常
uv --version

# 测试 Serena 是否可访问
uvx --from git+https://github.com/oraios/serena serena --version
```

### 问题 2: 语言服务器未启动

**解决方案**：

PHP:
```bash
composer global require felixfbecker/language-server
```

TypeScript:
```bash
npm install -g typescript-language-server typescript
```

### 问题 3: 项目未激活

**解决方案**：

在 Claude Code 中运行：
```
serena onboard
```

或检查配置文件：
```bash
cat .serena/config.yaml
```

### 问题 4: 性能问题

**优化建议**：

1. 增加忽略的目录（编辑 `config.yaml`）
2. 减少 `max_file_size` 限制
3. 启用缓存（默认已启用）

## 📖 相关文档

- [Serena MCP 激活指南](../docs/serena-mcp-activation-guide.md)
- [Serena GitHub](https://github.com/oraios/serena)
- [Serena 官方文档](https://oraios.github.io/serena)
- [MCP 协议](https://modelcontextprotocol.io)

## 🆘 获取帮助

如果遇到问题：

1. 查看详细文档：`cat ../docs/serena-mcp-activation-guide.md`
2. 查看 Serena 日志（如果有）
3. 在 GitHub 提 Issue：https://github.com/oraios/serena/issues
4. 询问 Claude Code 或 Auggie

---

**最后更新**: 2024-11-24
**维护者**: AlkaidSYS Team

