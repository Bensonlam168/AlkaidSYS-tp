# Serena MCP Server 使用指南

> **版本**: 0.1.4
> **项目**: AlkaidSYS-tp
> **更新时间**: 2024-11-24
> **官方文档**: https://oraios.github.io/serena/
> **GitHub 仓库**: https://github.com/oraios/serena

---

## 📋 目录

1. [简介](#简介)
2. [配置说明](#配置说明)
3. [路径映射](#路径映射)
4. [项目工作流程](#项目工作流程)
5. [可用工具](#可用工具)
6. [使用示例](#使用示例)
7. [常见问题](#常见问题)
8. [最佳实践](#最佳实践)
9. [限制和注意事项](#限制和注意事项)
10. [外部资源](#外部资源)

---

## 简介

Serena 是一个强大的 MCP (Model Context Protocol) Server，提供了一套完整的代码分析、编辑和项目管理工具。它运行在 Docker 容器中，通过 MCP 协议与 Augment IDE 集成。

### 核心特性

- 🔍 **智能代码分析**：符号级别的代码理解和搜索
- ✏️ **精确代码编辑**：基于符号的代码修改，避免全文件读写
- 🧠 **项目记忆**：持久化项目知识和上下文
- 🎯 **多语言支持**：支持 PHP、TypeScript 等多种编程语言
- 🚀 **高效工作流**：减少不必要的文件读取，提高效率

---

## 配置说明

### Docker Compose 配置

#### 基础配置 (`compose.yaml`)

```yaml
services:
  serena:
    image: serena:latest
    build:
      context: ./
      dockerfile: Dockerfile
      target: production
    ports:
      - "${SERENA_PORT:-9121}:9121"
      - "${SERENA_DASHBOARD_PORT:-24282}:24282"
    environment:
      - SERENA_DOCKER=1
```

#### 覆盖配置 (`compose.override.yml`)

```yaml
services:
  serena:
    volumes:
      # 挂载整个 Code 目录到容器内
      - /Users/Benson/Code:/workspace/projects
    command:
      # 使用 streamable-http 模式和 ide-assistant 上下文
      - "uv run --directory . serena-mcp-server --transport streamable-http --port 9121 --host 0.0.0.0 --context ide-assistant"
```

### Augment IDE MCP 配置

**配置文件位置**：
- macOS: `~/Library/Application Support/Augment/mcp-settings.json`
- Windows: `%APPDATA%\Augment\mcp-settings.json`
- Linux: `~/.config/Augment/mcp-settings.json`

**正确的配置**：

```json
{
  "mcpServers": {
    "serena": {
      "command": "docker",
      "args": [
        "exec",
        "-i",
        "serena-serena-1",
        "uv",
        "run",
        "--directory",
        ".",
        "serena-mcp-server",
        "--transport",
        "stdio",
        "--context",
        "ide-assistant"
      ]
    }
  }
}
```

**配置要点**：
- ✅ `args` 必须是数组，包含所有 docker 命令参数
- ✅ `--transport stdio` 用于 MCP 通信
- ✅ `--context ide-assistant` 避免工具重复
- ❌ 不要将参数放在 `env` 对象中
- ❌ 不要使用转义引号的字符串格式

---

## 路径映射

### 关键概念

Serena 运行在 Docker 容器内，只能访问容器内的路径。必须理解宿主机路径和容器内路径的映射关系。

### 路径映射表

| 宿主机路径 | 容器内路径 | 说明 |
|-----------|-----------|------|
| `/Users/Benson/Code` | `/workspace/projects` | 根目录挂载 |
| `/Users/Benson/Code/AlkaidSYS-tp` | `/workspace/projects/AlkaidSYS-tp` | 本项目路径 |
| `/Users/Benson/Code/serena` | `/workspace/projects/serena` | Serena 源码路径 |

### 使用规则

1. **激活项目时**：使用容器内路径或项目名称
2. **文件操作时**：使用相对于项目根目录的相对路径
3. **绝对路径**：始终使用容器内的绝对路径

---

## 项目工作流程

Serena 使用基于项目的工作流程。理解这个工作流程对于高效使用 Serena 至关重要。

### 工作流程概览

1. **项目创建和索引** - 配置项目设置并建立代码索引
2. **项目激活** - 让 Serena 知道你要处理哪个项目
3. **Onboarding（入职）** - 让 Serena 熟悉项目并创建记忆
4. **实际编码任务** - 使用 Serena 工具进行代码分析和编辑

### 1. 项目创建和索引

#### 隐式创建（推荐用于快速开始）

直接激活一个目录作为项目，Serena 会使用默认设置自动创建项目：

```python
activate_project_serena(project="/workspace/projects/my-project")
```

#### 显式创建（推荐用于生产环境）

使用命令行工具显式创建项目，可以自定义配置：

```bash
# 在项目目录中运行
uvx --from git+https://github.com/oraios/serena serena project create [options]

# 选项示例：
# --language python          # 指定编程语言
# --language python --language typescript  # 多语言项目
# --name "My Project"        # 自定义项目名称
# --index                    # 创建后立即索引
```

**项目配置文件**：创建后会生成 `.serena/project.yml`，可以手动编辑调整设置。

#### 项目索引

对于大型项目，建议在创建后立即索引，以避免首次使用时的延迟：

```bash
# 在项目目录中运行
uvx --from git+https://github.com/oraios/serena serena project index
```

**注意**：索引只需执行一次，Serena 会在文件变化时自动更新索引。

### 2. 项目激活

项目激活让 Serena 知道你要处理哪个项目。有两种激活方式：

#### 方法 1：在对话中激活（推荐）

```python
# 使用项目名称（项目已注册后）
activate_project_serena(project="AlkaidSYS-tp")

# 使用完整路径（首次激活）
activate_project_serena(project="/workspace/projects/AlkaidSYS-tp")
```

**优点**：
- ✅ 灵活，可以在对话中切换项目
- ✅ 适合处理多个项目

#### 方法 2：启动时激活（推荐用于 IDE 集成）

在 MCP 服务器启动时指定项目：

```bash
serena-mcp-server --project /workspace/projects/AlkaidSYS-tp
```

**优点**：
- ✅ 自动激活，无需手动操作
- ✅ 适合固定项目的 IDE 集成

#### 验证激活状态

```python
# 获取当前配置
get_current_config_serena()

# 检查入职状态
check_onboarding_performed_serena()
```

### 3. Onboarding（入职）和记忆系统

#### Onboarding 流程

Serena 在首次激活项目时会自动执行 onboarding 流程：

1. **分析项目结构** - 扫描目录和文件组织
2. **识别技术栈** - 检测使用的编程语言和框架
3. **理解代码模式** - 分析常见的代码模式和约定
4. **创建记忆文件** - 将项目知识保存到 `.serena/memories/` 目录

**重要提示**：
- Onboarding 会读取大量代码，可能消耗较多 token
- 建议在 onboarding 完成后切换到新对话
- 如果 LLM 未能完成 onboarding，需要手动要求其写入记忆

#### 记忆系统

**记忆文件位置**：`.serena/memories/`

**记忆文件格式**：Markdown（`.md`）

**记忆的作用**：
- 存储项目的长期知识和上下文
- 在后续对话中可以快速加载
- 减少重复分析的需要

**管理记忆**：

```python
# 列出所有记忆
list_memories_serena()

# 读取特定记忆
read_memory_serena(memory_file_name="project_overview.md")

# 创建新记忆
write_memory_serena(
    memory_file_name="custom_patterns.md",
    content="# 项目特定模式\n\n..."
)

# 编辑记忆
edit_memory_serena(
    memory_file_name="project_overview.md",
    needle="旧内容",
    repl="新内容",
    mode="literal"
)

# 删除记忆
delete_memory_serena(memory_file_name="outdated.md")
```

**最佳实践**：
- ✅ Onboarding 完成后检查记忆文件
- ✅ 根据需要手动添加项目特定的知识
- ✅ 定期更新记忆以反映项目变化
- ✅ 删除过时或不相关的记忆

### 4. 项目激活状态的持久性

⚠️ **重要：项目激活状态不持久**

由于 Serena 通过 `docker exec` 启动独立实例，每次新会话都需要重新激活项目。这是 MCP 协议的正常行为，不是 bug。

**解决方案**：
- 在每次新会话开始时，首先调用 `activate_project_serena()`
- 或者在 MCP 服务器启动时使用 `--project` 参数

---

## 可用工具

Serena 提供了 25 个活动工具，分为以下几类：

### 📁 文件和目录操作

#### `list_dir_serena`
列出目录中的文件和子目录。

**参数**：
- `relative_path`: 相对路径（相对于项目根目录）
- `recursive`: 是否递归列出子目录
- `skip_ignored_files`: 是否跳过被忽略的文件

**示例**：
```python
# 列出项目根目录
list_dir_serena(relative_path=".", recursive=False)

# 递归列出 app 目录
list_dir_serena(relative_path="app", recursive=True)
```

#### `find_file_serena`
查找匹配特定文件掩码的文件。

**参数**：
- `file_mask`: 文件掩码（支持通配符 `*` 和 `?`）
- `relative_path`: 搜索的起始路径

**示例**：
```python
# 查找所有 PHP 文件
find_file_serena(file_mask="*.php", relative_path="app")

# 查找所有控制器
find_file_serena(file_mask="*Controller.php", relative_path="app/Http/Controllers")
```

---

### 🔍 代码搜索和分析

#### `search_for_pattern_serena`
使用正则表达式在代码库中搜索模式。

**参数**：
- `substring_pattern`: 正则表达式模式
- `relative_path`: 搜索路径（可选）
- `restrict_search_to_code_files`: 是否仅搜索代码文件
- `context_lines_before/after`: 上下文行数

**示例**：
```python
# 搜索所有类定义
search_for_pattern_serena(
    substring_pattern="class.*Controller",
    relative_path="app/Http/Controllers",
    restrict_search_to_code_files=True
)
```

#### `get_symbols_overview_serena`
获取文件中顶层符号的概览（类、函数、方法等）。

**参数**：
- `relative_path`: 文件路径

**示例**：
```python
# 获取控制器文件的符号概览
get_symbols_overview_serena(
    relative_path="app/Http/Controllers/AuthController.php"
)
```

#### `find_symbol_serena`
查找符号/代码实体（类、方法、函数等）。

**参数**：
- `name_path_pattern`: 符号名称路径模式（如 `MyClass/myMethod`）
- `relative_path`: 限制搜索的文件或目录
- `depth`: 检索子符号的深度
- `include_body`: 是否包含符号体代码
- `substring_matching`: 是否使用子串匹配

**示例**：
```python
# 查找 AuthController 类
find_symbol_serena(
    name_path_pattern="AuthController",
    relative_path="app/Http/Controllers/AuthController.php",
    depth=1,  # 包含方法列表
    include_body=False
)

# 查找特定方法并包含代码
find_symbol_serena(
    name_path_pattern="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    include_body=True
)
```

#### `find_referencing_symbols_serena`
查找引用特定符号的位置。

**参数**：
- `name_path`: 符号名称路径
- `relative_path`: 包含符号的文件路径

**示例**：
```python
# 查找所有调用 login 方法的地方
find_referencing_symbols_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php"
)
```

---

### ✏️ 代码编辑

#### `replace_symbol_body_serena`
替换符号的主体内容。

**参数**：
- `name_path`: 符号名称路径
- `relative_path`: 文件路径
- `body`: 新的符号体代码

**示例**：
```python
replace_symbol_body_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    body="""public function login(Request $request)
{
    // 新的实现
    return response()->json(['success' => true]);
}"""
)
```

#### `insert_after_symbol_serena`
在符号定义后插入内容。

**参数**：
- `name_path`: 符号名称路径
- `relative_path`: 文件路径
- `body`: 要插入的内容

**示例**：
```python
# 在类的最后一个方法后添加新方法
insert_after_symbol_serena(
    name_path="AuthController/logout",
    relative_path="app/Http/Controllers/AuthController.php",
    body="""
    public function refresh(Request $request)
    {
        // 刷新令牌
    }
"""
)
```

#### `insert_before_symbol_serena`
在符号定义前插入内容。

**参数**：
- `name_path`: 符号名称路径
- `relative_path`: 文件路径
- `body`: 要插入的内容

**示例**：
```python
# 在类的第一个方法前添加导入语句
insert_before_symbol_serena(
    name_path="AuthController",
    relative_path="app/Http/Controllers/AuthController.php",
    body="use App\\Services\\AuthService;\n"
)
```

#### `rename_symbol_serena`
在整个代码库中重命名符号。

**参数**：
- `name_path`: 符号名称路径
- `relative_path`: 文件路径
- `new_name`: 新名称

**示例**：
```python
# 重命名方法
rename_symbol_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    new_name="authenticate"
)
```

---

### 🧠 记忆管理

#### `write_memory_serena`
写入项目记忆（Markdown 格式）。

**参数**：
- `memory_file_name`: 记忆文件名
- `content`: 记忆内容（Markdown 格式）

**示例**：
```python
write_memory_serena(
    memory_file_name="authentication-flow.md",
    content="""# 认证流程

## 登录流程
1. 用户提交用户名和密码
2. 验证凭据
3. 生成 JWT 令牌
4. 返回令牌给客户端
"""
)
```

#### `read_memory_serena`
读取记忆文件。

**参数**：
- `memory_file_name`: 记忆文件名

**示例**：
```python
read_memory_serena(memory_file_name="authentication-flow.md")
```

#### `list_memories_serena`
列出所有可用的记忆。

**示例**：
```python
list_memories_serena()
```

#### `delete_memory_serena`
删除记忆文件。

**参数**：
- `memory_file_name`: 记忆文件名

**示例**：
```python
delete_memory_serena(memory_file_name="old-notes.md")
```

#### `edit_memory_serena`
编辑记忆内容。

**参数**：
- `memory_file_name`: 记忆文件名
- `needle`: 要搜索的字符串或正则表达式
- `repl`: 替换字符串
- `mode`: "literal" 或 "regex"

**示例**：
```python
edit_memory_serena(
    memory_file_name="authentication-flow.md",
    needle="JWT 令牌",
    repl="JWT Token",
    mode="literal"
)
```

---

### ⚙️ 项目管理

#### `activate_project_serena`
激活项目。

**参数**：
- `project`: 项目名称或路径

**示例**：
```python
activate_project_serena(project="AlkaidSYS-tp")
```

#### `get_current_config_serena`
获取当前配置。

**示例**：
```python
get_current_config_serena()
```

#### `check_onboarding_performed_serena`
检查是否已执行项目入职。

**示例**：
```python
check_onboarding_performed_serena()
```

#### `onboarding_serena`
执行项目入职流程。

**示例**：
```python
onboarding_serena()
```

---

### 🤔 思考辅助

这些工具帮助 AI 助手进行自我反思和质量控制。

#### `think_about_collected_information_serena`
思考收集的信息是否充分和相关。

**使用场景**：在完成一系列搜索操作后调用。

#### `think_about_task_adherence_serena`
思考是否仍在正确轨道上。

**使用场景**：在长时间对话或复杂任务中调用。

#### `think_about_whether_you_are_done_serena`
思考是否已完成任务。

**使用场景**：在认为任务完成时调用。

---

### 📖 使用指南

#### `initial_instructions_serena`
获取 Serena 使用手册。

**示例**：
```python
initial_instructions_serena()
```

---

## 使用示例

### 示例 1：分析控制器结构

```python
# 1. 激活项目
activate_project_serena(project="AlkaidSYS-tp")

# 2. 获取控制器文件的符号概览
get_symbols_overview_serena(
    relative_path="app/Http/Controllers/AuthController.php"
)

# 3. 查找特定方法的实现
find_symbol_serena(
    name_path_pattern="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    include_body=True
)

# 4. 查找该方法的所有引用
find_referencing_symbols_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php"
)
```

### 示例 2：搜索和修改代码

```python
# 1. 搜索所有使用特定模式的代码
search_for_pattern_serena(
    substring_pattern="DB::table\\('users'\\)",
    relative_path="app",
    restrict_search_to_code_files=True,
    context_lines_before=2,
    context_lines_after=2
)

# 2. 查找需要修改的符号
find_symbol_serena(
    name_path_pattern="UserRepository/findByEmail",
    relative_path="app/Repositories/UserRepository.php",
    include_body=True
)

# 3. 替换符号体
replace_symbol_body_serena(
    name_path="UserRepository/findByEmail",
    relative_path="app/Repositories/UserRepository.php",
    body="""public function findByEmail(string $email): ?User
{
    return User::where('email', $email)->first();
}"""
)
```

### 示例 3：添加新功能

```python
# 1. 查看现有方法
find_symbol_serena(
    name_path_pattern="AuthController",
    relative_path="app/Http/Controllers/AuthController.php",
    depth=1,
    include_body=False
)

# 2. 在最后一个方法后添加新方法
insert_after_symbol_serena(
    name_path="AuthController/logout",
    relative_path="app/Http/Controllers/AuthController.php",
    body="""
    /**
     * 刷新访问令牌
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
"""
)
```

### 示例 4：项目知识管理

```python
# 1. 创建项目记忆
write_memory_serena(
    memory_file_name="permission-system-design.md",
    content="""# 权限系统设计

## 核心概念
- Resource: 资源（如 user, role, permission）
- Action: 操作（如 create, read, update, delete）
- Permission: 权限码格式为 `resource:action`

## 数据库表
- `permissions`: 权限表，slug 字段格式为 `resource.action`
- `roles`: 角色表
- `role_permissions`: 角色权限关联表

## API 设计
- `/v1/auth/me`: 返回用户信息和权限列表
- 权限码格式：`resource:action`（如 `user:create`）
"""
)

# 2. 列出所有记忆
list_memories_serena()

# 3. 读取记忆
read_memory_serena(memory_file_name="permission-system-design.md")

# 4. 更新记忆
edit_memory_serena(
    memory_file_name="permission-system-design.md",
    needle="## API 设计",
    repl="## API 设计\n\n### 前端集成\n- Vben Admin 使用 `access` 指令进行权限控制\n- 权限码直接从 `/v1/auth/me` 获取\n\n### 原 API 设计",
    mode="literal"
)
```

---

## 常见问题

### Q1: 为什么每次都需要重新激活项目？

**A**: Serena 通过 `docker exec` 启动独立实例，每次调用都是新的进程，不共享状态。这是 MCP 协议的正常行为。

**解决方案**：在每次新会话开始时，首先调用 `activate_project_serena(project="AlkaidSYS-tp")`。

---

### Q2: 项目激活失败，提示 "Project not found"

**A**: 可能使用了错误的路径。

**常见错误**：
```python
# ❌ 错误：使用宿主机路径
activate_project_serena(project="/Users/Benson/Code/AlkaidSYS-tp")
```

**正确方法**：
```python
# ✅ 方法 1：使用项目名称（推荐）
activate_project_serena(project="AlkaidSYS-tp")

# ✅ 方法 2：使用容器内路径
activate_project_serena(project="/workspace/projects/AlkaidSYS-tp")
```

---

### Q3: MCP 连接断开或超时

**A**: 可能的原因：
1. Docker 容器未运行
2. 容器重启
3. 网络问题

**排查步骤**：

```bash
# 1. 检查容器状态
docker ps | grep serena

# 2. 如果容器未运行，启动容器
docker start serena-serena-1

# 3. 查看容器日志
docker logs serena-serena-1

# 4. 测试 Serena 命令
docker exec serena-serena-1 uv run --directory . serena-mcp-server --help
```

**解决方案**：
- 重启 Docker 容器
- 重启 Augment IDE
- 检查 Docker Compose 配置

---

### Q4: 找不到符号或文件

**A**: 可能的原因：
1. 路径错误（使用了绝对路径而非相对路径）
2. 符号名称拼写错误
3. 文件不在项目目录中

**解决方案**：

```python
# 1. 先列出目录内容
list_dir_serena(relative_path="app/Http/Controllers", recursive=False)

# 2. 使用文件查找
find_file_serena(file_mask="*Controller.php", relative_path="app")

# 3. 获取文件的符号概览
get_symbols_overview_serena(relative_path="app/Http/Controllers/AuthController.php")

# 4. 使用子串匹配查找符号
find_symbol_serena(
    name_path_pattern="login",
    relative_path="app/Http/Controllers",
    substring_matching=True
)
```

---

### Q5: 代码编辑后没有生效

**A**: 可能的原因：
1. 符号路径错误
2. 代码格式问题
3. 文件被其他进程锁定

**验证步骤**：

```python
# 1. 编辑前先查看符号
find_symbol_serena(
    name_path_pattern="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    include_body=True
)

# 2. 执行编辑
replace_symbol_body_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    body="..."
)

# 3. 编辑后再次查看验证
find_symbol_serena(
    name_path_pattern="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    include_body=True
)
```

---

### Q6: 如何处理方法重载（如 Java）？

**A**: 对于支持方法重载的语言，使用索引来区分：

```python
# 查找第一个重载
find_symbol_serena(
    name_path_pattern="MyClass/myMethod[0]",
    relative_path="src/MyClass.java",
    include_body=True
)

# 查找第二个重载
find_symbol_serena(
    name_path_pattern="MyClass/myMethod[1]",
    relative_path="src/MyClass.java",
    include_body=True
)
```

---

## 最佳实践

### 1. 高效的代码阅读策略

**❌ 不推荐**：直接读取整个文件
```python
# 低效：读取整个文件
read_file("app/Http/Controllers/AuthController.php")
```

**✅ 推荐**：使用符号级别的工具
```python
# 1. 先获取概览
get_symbols_overview_serena(
    relative_path="app/Http/Controllers/AuthController.php"
)

# 2. 只读取需要的符号
find_symbol_serena(
    name_path_pattern="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    include_body=True
)
```

**优势**：
- 减少 token 消耗
- 提高响应速度
- 更精确的上下文

---

### 2. 渐进式信息收集

**步骤**：
1. 从概览开始
2. 逐步深入细节
3. 只读取必要的代码

**示例**：
```python
# 步骤 1：列出目录结构
list_dir_serena(relative_path="app/Http/Controllers", recursive=False)

# 步骤 2：查找相关文件
find_file_serena(file_mask="Auth*.php", relative_path="app/Http/Controllers")

# 步骤 3：获取文件概览
get_symbols_overview_serena(relative_path="app/Http/Controllers/AuthController.php")

# 步骤 4：读取特定符号
find_symbol_serena(
    name_path_pattern="AuthController/login",
    include_body=True
)
```

---

### 3. 使用项目记忆

**场景**：
- 记录架构决策
- 保存重要的代码模式
- 记录常见问题和解决方案

**示例**：
```python
# 记录权限系统设计
write_memory_serena(
    memory_file_name="permission-system.md",
    content="""# 权限系统设计

## 决策记录
- 2024-11-24: 决定使用 resource:action 格式的权限码
- 后端内部使用 resource.action 作为 slug
- API 返回 resource:action 格式给前端

## 关键文件
- `app/Http/Controllers/AuthController.php`: 认证控制器
- `app/Models/Permission.php`: 权限模型
- `database/migrations/*_create_permissions_table.php`: 权限表迁移
"""
)
```

---

### 4. 符号级别的编辑

**优势**：
- 精确修改，不影响其他代码
- 自动处理缩进和格式
- 减少冲突风险

**示例**：
```python
# 替换整个方法
replace_symbol_body_serena(
    name_path="AuthController/login",
    relative_path="app/Http/Controllers/AuthController.php",
    body="""public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'access_token' => $token,
        'token_type' => 'Bearer',
    ]);
}"""
)
```

---

### 5. 使用思考工具

在关键节点使用思考工具，确保任务质量：

```python
# 收集信息后
think_about_collected_information_serena()

# 长时间任务中
think_about_task_adherence_serena()

# 认为完成时
think_about_whether_you_are_done_serena()
```

---

### 6. 路径使用规范

**规则**：
- ✅ 使用相对路径（相对于项目根目录）
- ✅ 使用正斜杠 `/`
- ❌ 不要使用绝对路径（除非激活项目时）
- ❌ 不要使用反斜杠 `\`

**示例**：
```python
# ✅ 正确
list_dir_serena(relative_path="app/Http/Controllers")
find_file_serena(file_mask="*.php", relative_path="app")

# ❌ 错误
list_dir_serena(relative_path="/workspace/projects/AlkaidSYS-tp/app")
find_file_serena(file_mask="*.php", relative_path="app\\Http")
```

---

### 7. 错误处理

**策略**：
1. 验证项目激活状态
2. 检查路径是否存在
3. 验证符号名称
4. 使用搜索工具辅助定位

**示例**：
```python
# 1. 确保项目已激活
try:
    get_current_config_serena()
except:
    activate_project_serena(project="AlkaidSYS-tp")

# 2. 验证文件存在
files = find_file_serena(file_mask="AuthController.php", relative_path="app")
if files:
    # 3. 获取符号概览
    overview = get_symbols_overview_serena(
        relative_path="app/Http/Controllers/AuthController.php"
    )
```

---

## 附录

### A. 支持的编程语言

- PHP
- TypeScript
- JavaScript
- Python
- Java
- C#
- Go
- Rust
- 等等...

### B. 符号类型（LSP Symbol Kinds）

| 类型 | 编号 | 说明 |
|------|------|------|
| File | 1 | 文件 |
| Module | 2 | 模块 |
| Namespace | 3 | 命名空间 |
| Package | 4 | 包 |
| Class | 5 | 类 |
| Method | 6 | 方法 |
| Property | 7 | 属性 |
| Field | 8 | 字段 |
| Constructor | 9 | 构造函数 |
| Enum | 10 | 枚举 |
| Interface | 11 | 接口 |
| Function | 12 | 函数 |
| Variable | 13 | 变量 |
| Constant | 14 | 常量 |

### C. 正则表达式语法

Serena 支持 Python `re` 模块的正则表达式语法（DOTALL 和 MULTILINE 模式）。

**常用模式**：
- `.` - 匹配任意字符（包括换行符）
- `*` - 0 次或多次
- `+` - 1 次或多次
- `?` - 0 次或 1 次
- `[abc]` - 字符类
- `[a-z]` - 范围
- `^` - 行首
- `$` - 行尾
- `\d` - 数字
- `\w` - 单词字符
- `\s` - 空白字符

### D. 相关资源

- **Serena 源码**: `/workspace/projects/serena`
- **项目文档**: `/workspace/projects/AlkaidSYS-tp/docs`
- **设计文档**: `/workspace/projects/AlkaidSYS-tp/design`
- **Serena 记忆**: `/workspace/projects/AlkaidSYS-tp/.serena/memories`

---

## 限制和注意事项

### 语言支持限制

虽然 Serena 支持超过 30 种编程语言，但某些语言需要额外的依赖：

- **完全支持**：Python, TypeScript, JavaScript, PHP, Java, C#, Go, Rust 等
- **需要额外依赖**：某些语言需要安装特定的语言服务器
- **详细信息**：查看[官方语言支持页面](https://oraios.github.io/serena/01-about/020_programming-languages.html)

### 项目规模考虑

**适合的场景**：
- ✅ 中大型项目（复杂的代码结构）
- ✅ 结构化良好的代码库
- ✅ 需要精确代码编辑的场景
- ✅ 需要符号级别分析的任务

**不太适合的场景**：
- ⚠️ 非常小的项目（几个文件）
- ⚠️ 从零开始编写代码（初期结构简单）
- ⚠️ 完全非结构化的代码（如单个巨大的文件）

### 性能注意事项

1. **首次索引**：大型项目的首次索引可能需要一些时间
2. **Onboarding**：会读取大量代码，消耗较多 token
3. **内存使用**：语言服务器可能占用一定内存

**优化建议**：
- 使用 `serena project index` 预先索引项目
- Onboarding 完成后切换到新对话
- 对于超大型项目，考虑分模块处理

### Git 集成要求

**Windows 用户必读**：

```bash
# 全局设置（推荐）
git config --global core.autocrlf true

# 或针对单个项目
git config core.autocrlf true
```

**原因**：Serena 使用系统原生行结束符写入文件。如果不设置 `core.autocrlf`，在 Windows 上可能导致巨大的 git diff（仅因行结束符不同）。

### 调试限制

⚠️ **Serena 不能使用调试器**

Serena 必须依赖以下方式评估代码正确性：
- 程序执行结果
- 日志输出
- Linting 结果
- 测试结果

**建议**：
- 编写有意义的日志消息
- 保持良好的测试覆盖率
- 从所有检查和测试通过的状态开始任务

### Docker 容器限制

**路径访问**：
- ✅ 只能访问挂载到容器的路径
- ❌ 无法访问宿主机的其他路径
- ⚠️ 必须使用容器内路径或项目名称

**容器重启**：
- 容器重启后需要重新激活项目
- 项目配置和记忆文件会保留（存储在项目目录中）

---

## 外部资源

### 官方资源

- **官方文档**: https://oraios.github.io/serena/
- **GitHub 仓库**: https://github.com/oraios/serena
- **更新日志**: https://github.com/oraios/serena/blob/main/CHANGELOG.md
- **路线图**: https://github.com/oraios/serena/blob/main/roadmap.md
- **经验教训**: https://github.com/oraios/serena/blob/main/lessons_learned.md

### 用户指南

- **快速开始**: https://oraios.github.io/serena/02-usage/000_intro.html
- **客户端配置**: https://oraios.github.io/serena/02-usage/030_clients.html
- **项目工作流程**: https://oraios.github.io/serena/02-usage/040_workflow.html
- **配置选项**: https://oraios.github.io/serena/02-usage/050_configuration.html
- **安全考虑**: https://oraios.github.io/serena/02-usage/060_security.html

### 特殊指南

- **自定义 Agent**: https://github.com/oraios/serena/blob/main/docs/03-special-guides/custom_agent.md
- **ChatGPT 集成**: https://github.com/oraios/serena/blob/main/docs/03-special-guides/serena_on_chatgpt.md
- **添加新语言支持**: https://github.com/oraios/serena/blob/main/.serena/memories/adding_new_language_support_guide.md

### 社区资源

#### 视频教程

- [AI Labs - Serena 介绍](https://www.youtube.com/watch?v=wYWyJNs1HVk&t=1s)
- [Yo Van Eyck - Serena 使用指南](https://www.youtube.com/watch?v=UqfxuQKuMo8&t=45s)
- [JeredBlu - Serena 实战](https://www.youtube.com/watch?v=fzPnM3ySmjE&t=32s)

#### 博客文章

- [Serena 的设计原则](https://medium.com/@souradip1000/deconstructing-serenas-mcp-powered-semantic-code-understanding-architecture-75802515d116)
- [将 Claude Code 打造成开发利器](https://robertmarshall.dev/blog/turning-claude-code-into-a-development-powerhouse/)
- [Serena 与 Claude Code（日语）](https://blog.lai.so/serena/)

#### Reddit 讨论

- [Try out Serena MCP. Thank me later.](https://www.reddit.com/r/ClaudeAI/comments/1lfsdll/try_out_serena_mcp_thank_me_later/)
- [Absolutely insane improvement of Claude Code](https://www.reddit.com/r/ClaudeCode/comments/1mguoia/absolutely_insane_improvement_of_claude_code)
- [Must-Have MCP Servers for Coding](https://www.reddit.com/r/ClaudeAI/comments/1k0f3vs/musthave_mcp_servers_for_coding_and_beyond/)

### MCP 协议资源

- **MCP 规范**: https://modelcontextprotocol.io/specification/2025-06-18
- **MCP 介绍**: https://www.anthropic.com/news/model-context-protocol
- **MCP GitHub**: https://github.com/modelcontextprotocol
- **MCP 注册表**: https://github.com/mcp

### 技术基础

- **Language Server Protocol (LSP)**: https://microsoft.github.io/language-server-protocol/
- **multilspy**: https://github.com/microsoft/multilspy
- **Python MCP SDK**: https://github.com/modelcontextprotocol/python-sdk

---

## 更新日志

- **2024-11-24 v2**:
  - 添加官方资源链接和外部资源章节
  - 补充项目工作流程的详细说明
  - 添加 Onboarding 和记忆系统的详细说明
  - 新增限制和注意事项章节
  - 添加 Git 配置最佳实践
  - 改进文档结构和导航

- **2024-11-24 v1**: 初始版本，包含完整的配置说明、工具列表、使用示例和最佳实践

---

**文档维护者**: AI Assistant
**项目**: AlkaidSYS-tp
**Serena 版本**: 0.1.4
**官方文档**: https://oraios.github.io/serena/
**GitHub**: https://github.com/oraios/serena


