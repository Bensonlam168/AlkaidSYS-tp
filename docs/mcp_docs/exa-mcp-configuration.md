# Exa MCP Server 配置指南（Augment IDE）

> **文档版本**：1.0  
> **最后更新**：2025-01-24  
> **适用于**：Augment IDE MCP 配置

---

## 📋 目录

1. [问题诊断](#一问题诊断)
2. [正确配置](#二正确配置)
3. [配置说明](#三配置说明)
4. [验证步骤](#四验证步骤)
5. [故障排查](#五故障排查)
6. [可用工具](#六可用工具)
7. [官方资源](#七官方资源)

---

## 一、问题诊断

### 🔍 用户配置的错误分析

#### **配置 1 的问题**（mcp-remote 方式）

```json
{
  "mcpServers": {
    "mcp-server-exa-search": {
      "command": "npx",
      "args": [],  // ❌ 错误：应该包含实际参数
      "env": {
        "args": "[\"-y\", \"mcp-remote\", \"https://mcp.exa.ai/mcp\"]",  // ❌ 错误：args 不应该在 env 中
        "env": "{ EXA_API_KEY = \"45afbd27-04b3-49d6-8861-68bd3741042d\" }"  // ❌ 错误：env 嵌套错误
      }
    }
  }
}
```

**错误点**：
1. ✗ `args` 字段为空数组，实际参数被错误地放在 `env` 对象中
2. ✗ `env` 对象中有嵌套的 `env` 字符串，这是无效的 JSON 结构
3. ✗ 环境变量格式错误（使用了 `=` 而不是 `:`）

#### **配置 2 的问题**（exa-mcp-server 方式）

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": [],  // ❌ 错误：应该包含实际参数
      "env": {
        "args": "[\"-y\", \"exa-mcp-server\"]",  // ❌ 错误：args 不应该在 env 中
        "env": "\"exa_api_key\": \"45afbd27-04b3-49d6-8861-68bd3741042d\""  // ❌ 错误：env 嵌套错误
      }
    }
  }
}
```

**错误点**：
1. ✗ `args` 字段为空数组
2. ✗ 命令参数被放在 `env` 对象中作为字符串
3. ✗ `env` 对象中有嵌套结构，格式错误
4. ✗ 环境变量键名错误（应该是 `EXA_API_KEY` 而不是 `exa_api_key`）

### 📊 正确的 JSON 结构

MCP 配置文件的正确结构应该是：

```json
{
  "mcpServers": {
    "服务器名称": {
      "command": "可执行命令",
      "args": ["参数1", "参数2", "..."],  // 数组格式
      "env": {
        "环境变量名": "环境变量值"  // 键值对格式
      }
    }
  }
}
```

**关键点**：
- `args` 必须是**数组**，包含命令行参数
- `env` 必须是**对象**，包含环境变量的键值对
- 不能在 `env` 中嵌套 `args` 或 `env`

---

## 二、正确配置

### ✅ 方式 1：本地安装（推荐）

这是最简单、最可靠的配置方式。

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": ["-y", "exa-mcp-server"],
      "env": {
        "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"
      }
    }
  }
}
```

**特点**：
- ✓ 使用 `npx` 自动下载和运行最新版本
- ✓ `-y` 参数自动确认安装
- ✓ 默认启用 `web_search_exa` 和 `get_code_context_exa` 工具
- ✓ 配置简单，易于维护

### ✅ 方式 2：远程 MCP

使用 Exa 托管的远程 MCP 服务器。

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://mcp.exa.ai/mcp"],
      "env": {
        "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"
      }
    }
  }
}
```

**特点**：
- ✓ 无需本地安装 exa-mcp-server
- ✓ 使用 Exa 官方托管的服务器
- ✓ 自动获取最新功能和更新
- ✓ 需要稳定的网络连接

### ✅ 方式 3：启用特定工具

如果只需要代码搜索功能（推荐开发者使用）：

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": [
        "-y",
        "exa-mcp-server",
        "tools=get_code_context_exa"
      ],
      "env": {
        "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"
      }
    }
  }
}
```

### ✅ 方式 4：启用所有工具

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": [
        "-y",
        "exa-mcp-server",
        "tools=web_search_exa,deep_search_exa,get_code_context_exa,crawling_exa,company_research_exa,linkedin_search_exa,deep_researcher_start,deep_researcher_check"
      ],
      "env": {
        "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"
      }
    }
  }
}
```

---

## 三、配置说明

### 📝 字段详解

#### 1. `command` 字段

```json
"command": "npx"
```

- **作用**：指定要执行的命令
- **值**：`npx` - Node.js 包执行器，用于运行 npm 包
- **说明**：`npx` 会自动下载并运行指定的 npm 包，无需全局安装

#### 2. `args` 字段

```json
"args": ["-y", "exa-mcp-server"]
```

- **作用**：命令行参数数组
- **格式**：必须是 JSON 数组 `[]`
- **参数说明**：
  - `"-y"` - 自动确认 npx 的安装提示
  - `"exa-mcp-server"` - 要运行的 npm 包名称
  - `"tools=..."` - （可选）指定要启用的工具

**常见错误**：
- ❌ `"args": []` - 空数组，缺少必要参数
- ❌ `"args": "[\"-y\", \"exa-mcp-server\"]"` - 字符串格式，应该是数组
- ✓ `"args": ["-y", "exa-mcp-server"]` - 正确格式

#### 3. `env` 字段

```json
"env": {
  "EXA_API_KEY": "your-api-key-here"
}
```

- **作用**：环境变量配置
- **格式**：必须是 JSON 对象 `{}`，包含键值对
- **必需变量**：
  - `EXA_API_KEY` - Exa API 密钥（从 [dashboard.exa.ai/api-keys](https://dashboard.exa.ai/api-keys) 获取）

**常见错误**：
- ❌ `"env": "{ EXA_API_KEY = \"...\" }"` - 字符串格式，应该是对象
- ❌ `"env": { "exa_api_key": "..." }` - 键名错误，应该是大写
- ✓ `"env": { "EXA_API_KEY": "..." }` - 正确格式

### 🔧 工具参数说明

通过 `tools=` 参数可以指定要启用的工具：

```json
"args": ["-y", "exa-mcp-server", "tools=tool1,tool2,tool3"]
```

- **格式**：`tools=工具1,工具2,工具3`（逗号分隔，无空格）
- **默认工具**：如果不指定 `tools` 参数，默认启用：
  - `web_search_exa` - 网页搜索
  - `get_code_context_exa` - 代码搜索

---

## 四、验证步骤

### ✅ 步骤 1：保存配置文件

1. 打开配置文件：
   ```bash
   code ~/Library/Application\ Support/Augment/mcp-settings.json
   ```

2. 粘贴正确的配置（选择上面的方式 1、2、3 或 4）

3. 保存文件（`Cmd+S` 或 `Ctrl+S`）

### ✅ 步骤 2：重启 Augment IDE

完全退出并重新启动 Augment IDE，以加载新配置。

### ✅ 步骤 3：检查 MCP 服务器状态

1. 在 Augment IDE 中查看 MCP 服务器列表
2. 确认 `exa` 服务器显示为**绿色**（已连接）
3. 检查是否显示可用工具

### ✅ 步骤 4：测试工具功能

在 Augment IDE 中测试 Exa 工具：

**测试代码搜索**：
```
请使用 Exa 搜索如何在 Python 中使用 requests 库发送 POST 请求
```

**测试网页搜索**：
```
请使用 Exa 搜索最新的 React 18 新特性
```

### ✅ 步骤 5：验证成功标志

如果配置成功，你应该看到：
- ✓ MCP 服务器状态为绿色
- ✓ 显示可用工具列表（至少 2 个工具）
- ✓ 工具调用返回实际结果（不是错误信息）

---

## 五、故障排查

### ❌ 问题 1：MCP 服务器显示绿色但没有工具

**症状**：
- MCP 服务器状态为绿色（已连接）
- 但工具列表为空或不显示

**可能原因**：
1. `args` 字段配置错误（空数组或格式错误）
2. `env` 字段配置错误（API Key 无效或格式错误）
3. 网络问题（无法下载 exa-mcp-server）

**解决方案**：

1. **检查 `args` 字段**：
   ```json
   "args": ["-y", "exa-mcp-server"]  // ✓ 正确
   ```
   不应该是：
   ```json
   "args": []  // ❌ 错误
   ```

2. **检查 `env` 字段**：
   ```json
   "env": {
     "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"  // ✓ 正确
   }
   ```
   不应该是：
   ```json
   "env": {
     "args": "...",  // ❌ 错误：args 不应该在 env 中
     "env": "..."    // ❌ 错误：env 不应该嵌套
   }
   ```

3. **验证 API Key**：
   - 访问 [dashboard.exa.ai/api-keys](https://dashboard.exa.ai/api-keys)
   - 确认 API Key 有效且未过期
   - 复制完整的 API Key（包括所有字符）

4. **检查网络连接**：
   ```bash
   # 测试是否能访问 npm registry
   npm ping

   # 测试是否能下载 exa-mcp-server
   npx -y exa-mcp-server --version
   ```

### ❌ 问题 2：MCP 服务器连接失败（红色或灰色）

**症状**：
- MCP 服务器状态为红色（连接失败）或灰色（未启动）

**可能原因**：
1. `npx` 命令不可用（Node.js 未安装）
2. JSON 格式错误（语法错误）
3. 配置文件路径错误

**解决方案**：

1. **检查 Node.js 安装**：
   ```bash
   node --version  # 应该显示版本号，如 v18.x.x
   npx --version   # 应该显示版本号
   ```

   如果未安装，请安装 Node.js：
   - 访问 [nodejs.org](https://nodejs.org/)
   - 下载并安装 LTS 版本

2. **验证 JSON 格式**：
   - 使用 JSON 验证工具检查配置文件
   - 确保所有引号、逗号、括号匹配
   - 可以使用在线工具：[jsonlint.com](https://jsonlint.com/)

3. **检查配置文件路径**：
   ```bash
   # macOS
   ls -la ~/Library/Application\ Support/Augment/mcp-settings.json

   # 如果文件不存在，创建它
   mkdir -p ~/Library/Application\ Support/Augment
   touch ~/Library/Application\ Support/Augment/mcp-settings.json
   ```

### ❌ 问题 3：工具调用返回错误

**症状**：
- MCP 服务器已连接
- 工具列表显示正常
- 但调用工具时返回错误

**可能原因**：
1. API Key 无效或配额用尽
2. 网络连接问题
3. 工具参数错误

**解决方案**：

1. **检查 API 配额**：
   - 登录 [dashboard.exa.ai](https://dashboard.exa.ai/)
   - 查看 API 使用情况和配额
   - 确认未超出限制

2. **测试 API Key**：
   ```bash
   # 使用 curl 测试 API Key
   curl -X POST https://api.exa.ai/search \
     -H "Content-Type: application/json" \
     -H "x-api-key: 45afbd27-04b3-49d6-8861-68bd3741042d" \
     -d '{"query": "test"}'
   ```

3. **查看错误日志**：
   - 在 Augment IDE 中查看 MCP 服务器日志
   - 查找具体的错误信息
   - 根据错误信息调整配置

### ❌ 问题 4：特定工具不可用

**症状**：
- 部分工具可用，但某些工具不显示

**可能原因**：
- `tools` 参数配置错误
- 工具名称拼写错误

**解决方案**：

1. **检查工具名称**：
   确保使用正确的工具名称（区分大小写）：
   - ✓ `get_code_context_exa`
   - ✓ `web_search_exa`
   - ✓ `deep_search_exa`
   - ❌ `get_code_context` （缺少 `_exa` 后缀）
   - ❌ `web_search` （缺少 `_exa` 后缀）

2. **检查工具参数格式**：
   ```json
   "args": [
     "-y",
     "exa-mcp-server",
     "tools=tool1,tool2,tool3"  // ✓ 正确：逗号分隔，无空格
   ]
   ```
   不应该是：
   ```json
   "args": [
     "-y",
     "exa-mcp-server",
     "tools=tool1, tool2, tool3"  // ❌ 错误：有空格
   ]
   ```

3. **使用默认工具**：
   如果不确定，可以不指定 `tools` 参数，使用默认工具：
   ```json
   "args": ["-y", "exa-mcp-server"]
   ```

---

## 六、可用工具

### 🛠️ 工具列表

Exa MCP Server 提供以下工具：

#### 1. **get_code_context_exa** 🔍

**功能**：搜索代码片段、示例和文档

**适用场景**：
- 查找开源库的使用示例
- 获取 API 文档和最佳实践
- 搜索 GitHub 仓库中的代码实现
- 查找编程框架的使用模式

**示例查询**：
- "如何在 Python 中使用 requests 库发送 POST 请求"
- "React hooks useEffect 的正确用法"
- "TypeScript 泛型约束的示例"

#### 2. **web_search_exa** 🌐

**功能**：实时网页搜索，优化结果和内容提取

**适用场景**：
- 搜索最新技术文章
- 查找教程和指南
- 获取技术新闻和更新

**示例查询**：
- "2024 年最佳 React 状态管理库"
- "Python 3.12 新特性"
- "Docker 容器化最佳实践"

#### 3. **deep_search_exa** 🔬

**功能**：深度网页搜索，智能查询扩展和高质量摘要

**适用场景**：
- 复杂技术问题研究
- 需要详细摘要的搜索
- 多角度信息收集

#### 4. **crawling_exa** 📄

**功能**：从特定 URL 提取内容

**适用场景**：
- 读取文章内容
- 提取 PDF 文档
- 获取网页详细信息

#### 5. **company_research_exa** 🏢

**功能**：全面的公司研究工具

**适用场景**：
- 了解公司背景
- 收集商业信息
- 市场调研

#### 6. **linkedin_search_exa** 👔

**功能**：搜索 LinkedIn 公司和人员信息

**适用场景**：
- 查找公司信息
- 搜索专业人士
- 职业研究

#### 7. **deep_researcher_start** 🚀

**功能**：启动智能 AI 研究员处理复杂问题

**适用场景**：
- 深度技术研究
- 综合信息收集
- 生成详细研究报告

#### 8. **deep_researcher_check** ✅

**功能**：检查研究任务状态并获取结果

**适用场景**：
- 查看研究进度
- 获取完整报告
- 跟踪研究任务

### 📊 工具选择建议

**开发者推荐配置**（仅代码搜索）：
```json
"args": ["-y", "exa-mcp-server", "tools=get_code_context_exa"]
```

**通用配置**（代码 + 网页搜索）：
```json
"args": ["-y", "exa-mcp-server"]
// 默认启用 web_search_exa 和 get_code_context_exa
```

**完整配置**（所有工具）：
```json
"args": [
  "-y",
  "exa-mcp-server",
  "tools=web_search_exa,deep_search_exa,get_code_context_exa,crawling_exa,company_research_exa,linkedin_search_exa,deep_researcher_start,deep_researcher_check"
]
```

---

## 七、官方资源

### 📚 文档和链接

- **官方文档**：[docs.exa.ai/reference/exa-mcp](https://docs.exa.ai/reference/exa-mcp)
- **GitHub 仓库**：[github.com/exa-labs/exa-mcp-server](https://github.com/exa-labs/exa-mcp-server)
- **API 密钥管理**：[dashboard.exa.ai/api-keys](https://dashboard.exa.ai/api-keys)
- **NPM 包**：[npmjs.com/package/exa-mcp-server](https://www.npmjs.com/package/exa-mcp-server)
- **远程 MCP URL**：`https://mcp.exa.ai/mcp`

### 🆘 获取帮助

如果遇到问题：

1. **查看官方文档**：[docs.exa.ai](https://docs.exa.ai/)
2. **GitHub Issues**：[github.com/exa-labs/exa-mcp-server/issues](https://github.com/exa-labs/exa-mcp-server/issues)
3. **社区支持**：访问 Exa 官方社区

### 📝 配置文件位置

**Augment IDE**：
```
~/Library/Application Support/Augment/mcp-settings.json
```

**Claude Desktop**：
```
# macOS
~/Library/Application Support/Claude/claude_desktop_config.json

# Windows
%APPDATA%\Claude\claude_desktop_config.json
```

---

## 📌 快速参考

### ✅ 推荐配置（复制即用）

```json
{
  "mcpServers": {
    "exa": {
      "command": "npx",
      "args": ["-y", "exa-mcp-server"],
      "env": {
        "EXA_API_KEY": "45afbd27-04b3-49d6-8861-68bd3741042d"
      }
    }
  }
}
```

### 🔑 关键要点

1. ✓ `args` 必须是数组格式：`["-y", "exa-mcp-server"]`
2. ✓ `env` 必须是对象格式：`{ "EXA_API_KEY": "..." }`
3. ✓ 不要在 `env` 中嵌套 `args` 或 `env`
4. ✓ API Key 名称必须是 `EXA_API_KEY`（大写）
5. ✓ 保存配置后需要重启 Augment IDE

### 🎯 下一步

1. 复制上面的推荐配置
2. 替换 API Key（如果需要）
3. 保存到 `~/Library/Application Support/Augment/mcp-settings.json`
4. 重启 Augment IDE
5. 测试 Exa 工具功能

---

**文档结束** | 如有问题，请参考[官方文档](https://docs.exa.ai/reference/exa-mcp)或查看[故障排查](#五故障排查)章节。

