# Auggie + Serena MCP 集成指南

本指南专门针对在 **Auggie CLI** 中使用 Serena MCP。

## 📋 目录

1. [为什么在 Auggie 中使用 Serena](#为什么在-auggie-中使用-serena)
2. [配置方法](#配置方法)
3. [验证配置](#验证配置)
4. [使用示例](#使用示例)
5. [故障排除](#故障排除)

---

## 为什么在 Auggie 中使用 Serena

### Auggie + Serena 的强大组合

| 功能 | 只用 Auggie | Auggie + Serena | 提升 |
|------|------------|----------------|------|
| 代码导航 | 基于文件 | 基于符号 | ⚡ 10x 精确 |
| 代码编辑 | 文本替换 | 符号级编辑 | ✅ 更安全 |
| Token 消耗 | 高 | 低 | 💰 节省 70% |
| 理解代码关系 | 有限 | 完整 | 🎯 更准确 |

### 实际效果

**传统方式**（只用 Auggie）：
```bash
auggie --print "查找 Collection 类的定义"
# Auggie 需要读取多个文件，消耗大量 Token
```

**使用 Serena**（Auggie + Serena MCP）：
```bash
auggie --print "使用 Serena 查找 Collection 类的定义"
# Serena 直接定位符号，快速且准确
```

---

## 配置方法

### 方法 1: 使用 Augment Settings Panel（推荐）

这是最简单的方法，通过 VS Code 的 Augment 扩展配置。

#### 步骤 1: 打开 Augment Settings

1. 在 VS Code 中打开 Augment 扩展
2. 点击右上角的设置图标（⚙️）
3. 找到 "MCP Servers" 部分

#### 步骤 2: 导入 JSON 配置

1. 点击 "Import from JSON" 按钮
2. 粘贴以下配置：

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
        "cli",
        "--project-path",
        "/Users/Benson/Code/AlkaidSYS-tp"
      ]
    }
  }
}
```

3. 点击 "Save"

#### 步骤 3: 验证配置

在 MCP Servers 列表中应该能看到 "serena" 服务器。

---

### 方法 2: 使用配置文件（快速）

直接使用项目中已准备好的配置文件：

```bash
# 查看配置文件
cat .serena/auggie-mcp-config.json

# 在 Augment Settings Panel 中导入这个文件的内容
```

---

### 方法 3: 手动配置（高级）

如果您需要自定义配置，可以手动添加：

1. 打开 Augment Settings Panel
2. 在 MCP 部分点击 "+" 按钮
3. 填写以下信息：
   - **Name**: `serena`
   - **Command**: `uvx`
   - **Args**: 
     ```
     --from
     git+https://github.com/oraios/serena
     serena
     start-mcp-server
     --context
     cli
     --project-path
     /Users/Benson/Code/AlkaidSYS-tp
     ```
   - **Environment Variables** (可选):
     - `SERENA_LOG_LEVEL`: `info`

---

## 验证配置

### 1. 检查 MCP Server 状态

在 VS Code 的 Augment 扩展中：
1. 打开 Settings Panel
2. 查看 MCP Servers 列表
3. 确认 "serena" 显示为已连接

### 2. 测试 Auggie 命令

```bash
# 测试 1: 简单查询
auggie --print "使用 Serena 列出项目中的所有 PHP 类"

# 测试 2: 查找符号
auggie --print "使用 Serena 查找 Collection 类的定义"

# 测试 3: 查找引用
auggie --print "使用 Serena 查找所有使用 CollectionManager 的地方"
```

### 3. 预期输出

如果配置成功，Auggie 会：
- ✅ 识别 Serena MCP Server
- ✅ 使用 Serena 的工具（find_symbol、find_referencing_symbols 等）
- ✅ 返回精确的符号级别信息

---

## 使用示例

### 示例 1: 查找类定义

```bash
auggie --print "使用 Serena 查找 Domain\Lowcode\Collection\Model\Collection 类的定义"
```

**Serena 会**：
- 直接定位到文件和行号
- 显示类的完整定义
- 包含所有方法和属性

### 示例 2: 查找方法引用

```bash
auggie --print "使用 Serena 查找所有调用 CollectionManager::create 方法的地方"
```

**Serena 会**：
- 列出所有引用位置
- 显示调用上下文
- 包含文件路径和行号

### 示例 3: 智能代码编辑

```bash
auggie --print "使用 Serena 在 CollectionController 的 index 方法后添加一个新方法 export，
用于导出 Collection 数据为 JSON 格式"
```

**Serena 会**：
- 精确定位 index 方法
- 在正确位置插入新方法
- 保持代码格式和缩进

### 示例 4: 理解代码架构

```bash
auggie --print "使用 Serena 查找所有实现 CollectionInterface 的类"
```

**Serena 会**：
- 列出所有实现类
- 显示继承关系
- 帮助理解架构设计

### 示例 5: 结合 Augment Subagents

```bash
auggie --print "使用 lowcode-developer 和 Serena 创建一个新的 Order Collection，
包含字段：order_no, user_id, total_amount, status"
```

**效果**：
- Augment Subagent 提供高层次指导
- Serena 提供精确的代码操作
- 完美配合，事半功倍

---

## 故障排除

### 问题 1: Auggie 无法识别 Serena

**症状**：
```bash
auggie --print "使用 Serena 查找 Collection 类"
# 输出：I don't have access to Serena
```

**解决方案**：
1. 检查 MCP Server 配置是否正确
2. 在 Augment Settings Panel 中确认 Serena 已启用
3. 重启 VS Code
4. 重新运行 auggie 命令

### 问题 2: Serena Server 启动失败

**症状**：
MCP Servers 列表中 Serena 显示为"未连接"或"错误"

**解决方案**：
```bash
# 1. 检查 uv 是否安装
uv --version

# 2. 手动测试 Serena
uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path /Users/Benson/Code/AlkaidSYS-tp

# 3. 检查项目路径是否正确
pwd
# 应该输出: /Users/Benson/Code/AlkaidSYS-tp
```

### 问题 3: 项目未激活

**症状**：
Serena 提示 "Project not activated"

**解决方案**：
```bash
# 检查 Serena 配置
cat .serena/config.yaml

# 如果不存在，运行激活脚本
./scripts/activate-serena-mcp.sh
```

### 问题 4: 语言服务器未启动

**症状**：
Serena 无法分析 PHP 代码

**解决方案**：
```bash
# 安装 PHP 语言服务器
composer global require felixfbecker/language-server

# 安装 TypeScript 语言服务器
npm install -g typescript-language-server typescript
```

---

## 最佳实践

### 1. 明确指定使用 Serena

在 auggie 命令中明确说明使用 Serena：

✅ **好的方式**：
```bash
auggie --print "使用 Serena 查找 Collection 类"
```

❌ **不好的方式**：
```bash
auggie --print "查找 Collection 类"
# Auggie 可能不会使用 Serena
```

### 2. 利用 Serena 的符号理解能力

✅ **好的方式**：
```bash
auggie --print "使用 Serena 查找所有实现 CollectionInterface 的类"
```

❌ **不好的方式**：
```bash
auggie --print "grep 查找 implements CollectionInterface"
```

### 3. 结合 Augment Subagents

```bash
# 使用 Subagent 提供上下文，Serena 提供精确操作
auggie --print "使用 lowcode-developer 和 Serena 为 Product Collection 添加 images 字段"
```

---

## 下一步

1. ✅ 完成 MCP 配置
2. ✅ 测试基本功能
3. ✅ 尝试复杂场景
4. ✅ 结合 Augment Subagents 使用

---

**参考资源**：
- [Serena GitHub](https://github.com/oraios/serena)
- [Augment MCP 文档](https://docs.augmentcode.com/setup-augment/mcp)
- [Serena 配置说明](.serena/README.md)
- [Augment Subagents 配置](.augment/README.md)

