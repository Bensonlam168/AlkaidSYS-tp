# Serena MCP 激活和实施指南

## 📋 目录

1. [什么是 Serena MCP](#什么是-serena-mcp)
2. [为什么需要 Serena](#为什么需要-serena)
3. [环境准备](#环境准备)
4. [安装步骤](#安装步骤)
5. [项目激活](#项目激活)
6. [配置客户端](#配置客户端)
7. [验证安装](#验证安装)
8. [使用示例](#使用示例)
9. [故障排除](#故障排除)

---

## 什么是 Serena MCP

**Serena** 是一个强大的编码代理工具包，通过 **Model Context Protocol (MCP)** 提供语义代码检索和编辑能力。

### 核心特性

- 🔍 **语义代码检索** - 基于符号级别的代码理解，而非简单的文本搜索
- ✏️ **智能代码编辑** - 提供类似 IDE 的代码编辑能力
- 🌐 **多语言支持** - 支持 30+ 种编程语言（Python、PHP、JavaScript、TypeScript 等）
- 🆓 **完全免费** - 开源项目，无需付费
- 🔌 **广泛集成** - 支持 Claude Code、Claude Desktop、Cursor、VSCode 等

### 与传统方法的区别

| 传统方法 | Serena MCP |
|---------|-----------|
| 读取整个文件 | 只读取相关符号 |
| grep 文本搜索 | 语义符号搜索 |
| 字符串替换 | 符号级别编辑 |
| 高 Token 消耗 | 低 Token 消耗 |

---

## 为什么需要 Serena

### 对 AlkaidSYS 项目的价值

1. **提高效率** - 在大型代码库中快速定位和修改代码
2. **降低成本** - 减少 Token 消耗，节省 API 费用
3. **提升质量** - 基于语义理解，生成更准确的代码
4. **增强能力** - 为 AI 提供类似 IDE 的工具

### 适用场景

✅ **适合使用 Serena 的场景**：
- 大型代码库（如 AlkaidSYS）
- 复杂的架构（DDD、多层架构）
- 需要精确定位和修改代码
- 需要理解代码关系和依赖

❌ **不太需要 Serena 的场景**：
- 从零开始写代码
- 只涉及少量文件
- 简单的脚本项目

---

## 环境准备

### 1. 系统要求

- **操作系统**: macOS、Linux 或 Windows (WSL2)
- **Python**: 不需要（Serena 使用 uv 管理）
- **uv**: 必须安装

### 2. 安装 uv

```bash
# macOS/Linux
curl -LsSf https://astral.sh/uv/install.sh | sh

# 或使用 Homebrew (macOS)
brew install uv

# 验证安装
uv --version
```

### 3. 检查环境

```bash
# 检查 uv 是否安装
which uv

# 检查 Git
git --version

# 检查项目目录
pwd
# 应该在 /Users/Benson/Code/AlkaidSYS-tp
```

---

## 安装步骤

### 方式 1: 快速测试（推荐首次使用）

```bash
# 直接运行 Serena MCP Server（无需安装）
uvx --from git+https://github.com/oraios/serena serena start-mcp-server --help
```

这会显示所有可用选项：
```
Options:
  --context [ide|cli]  运行上下文（IDE 或 CLI）
  --project-path PATH  项目路径
  --help              显示帮助信息
```

### 方式 2: 本地安装（推荐长期使用）

```bash
# 克隆 Serena 仓库
cd ~/Code
git clone https://github.com/oraios/serena.git
cd serena

# 使用 uv 安装
uv sync

# 验证安装
uv run serena --version
```

---

## 项目激活

### ⚠️ 重要：必须先激活项目

根据 Serena 文档，**在首次使用前必须激活项目**。这是一个关键步骤！

### 激活步骤

#### 方式 1: 使用 Claude Code 激活（推荐）

1. **启动 Serena MCP Server**

```bash
cd /Users/Benson/Code/AlkaidSYS-tp

# 启动 MCP Server
uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context ide \
  --project-path /Users/Benson/Code/AlkaidSYS-tp
```

2. **在 Claude Code 中激活**

打开 Claude Code，在聊天中输入：

```
请运行 Serena Onboarding 来激活这个项目
```

或者：

```
serena onboard
```

3. **Serena 会自动**：
   - 分析项目结构
   - 检测编程语言
   - 创建配置文件（`.serena/config.yaml`）
   - 初始化语言服务器

#### 方式 2: 手动激活

```bash
cd /Users/Benson/Code/AlkaidSYS-tp

# 创建 Serena 配置目录
mkdir -p .serena

# 创建配置文件
cat > .serena/config.yaml << 'EOF'
# Serena 项目配置
project:
  name: AlkaidSYS-tp
  root: /Users/Benson/Code/AlkaidSYS-tp
  
languages:
  - php
  - javascript
  - typescript
  - yaml
  - markdown

# 忽略的目录
ignore:
  - node_modules
  - vendor
  - runtime
  - .git
EOF
```

---

## 配置客户端

### 配置 Claude Code

创建或编辑 Claude Code 的 MCP 配置文件：

```bash
# macOS 配置文件位置
mkdir -p ~/Library/Application\ Support/Claude/
nano ~/Library/Application\ Support/Claude/claude_desktop_config.json
```

添加以下配置：

```json
{
  "mcpServers": {
    "serena": {
      "command": "uvx",
      "args": [
        "--from",
        "git+https://github.com/oraios/serena",
        "serena",
        "start-mcp-server",
        "--context",
        "ide",
        "--project-path",
        "/Users/Benson/Code/AlkaidSYS-tp"
      ]
    }
  }
}
```

### 配置 Auggie (如果使用)

创建 Auggie 的 MCP 配置：

```bash
# 在项目根目录创建 MCP 配置
cat > .augment/mcp-config.json << 'EOF'
{
  "mcpServers": {
    "serena": {
      "command": "uvx",
      "args": [
        "--from",
        "git+https://github.com/oraios/serena",
        "serena",
        "start-mcp-server",
        "--context",
        "cli",
        "--project-path",
        "/Users/Benson/Code/AlkaidSYS-tp"
      ]
    }
  }
}
EOF
```

---

## 验证安装

### 1. 测试 MCP Server

```bash
# 启动 Serena MCP Server
uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path /Users/Benson/Code/AlkaidSYS-tp
```

应该看到类似输出：
```
Starting Serena MCP Server...
Project: AlkaidSYS-tp
Path: /Users/Benson/Code/AlkaidSYS-tp
Languages detected: PHP, JavaScript, TypeScript
Server ready on stdio
```

### 2. 检查配置文件

```bash
# 检查 Serena 配置是否创建
ls -la .serena/
cat .serena/config.yaml
```

### 3. 在 Claude Code 中测试

重启 Claude Code，然后在聊天中输入：

```
使用 Serena 查找 CollectionController 类
```

如果 Serena 正常工作，它会快速定位到文件和符号。

---

## 使用示例

### 示例 1: 查找符号

```
使用 Serena 查找 Collection 类的定义
```

Serena 会使用 `find_symbol` 工具快速定位。

### 示例 2: 查找引用

```
使用 Serena 查找所有使用 CollectionManager 的地方
```

Serena 会使用 `find_referencing_symbols` 工具。

### 示例 3: 智能编辑

```
使用 Serena 在 CollectionController 的 index 方法后添加一个新方法 export
```

Serena 会使用 `insert_after_symbol` 工具精确插入代码。

---

## 故障排除

### 问题 1: uv 命令未找到

**解决方案**：
```bash
# 重新安装 uv
curl -LsSf https://astral.sh/uv/install.sh | sh

# 添加到 PATH
echo 'export PATH="$HOME/.cargo/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

### 问题 2: 项目未激活

**错误信息**: "Project not activated"

**解决方案**：
```bash
# 在 Claude Code 中运行
serena onboard

# 或手动创建配置文件（见上文）
```

### 问题 3: 语言服务器启动失败

**解决方案**：
```bash
# 检查 PHP 语言服务器
composer global require felixfbecker/language-server

# 检查 TypeScript 语言服务器
npm install -g typescript-language-server typescript
```

### 问题 4: MCP Server 无法连接

**解决方案**：
```bash
# 检查配置文件路径
cat ~/Library/Application\ Support/Claude/claude_desktop_config.json

# 确保路径正确
# 重启 Claude Code
```

---

## 下一步

1. ✅ 完成 Serena 激活
2. ✅ 在 Claude Code 中测试基本功能
3. ✅ 尝试使用 Serena 进行代码导航
4. ✅ 使用 Serena 辅助开发 AlkaidSYS 功能

---

**参考资源**：
- [Serena GitHub](https://github.com/oraios/serena)
- [Serena 文档](https://oraios.github.io/serena)
- [MCP 协议](https://modelcontextprotocol.io)

