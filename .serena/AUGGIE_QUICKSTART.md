# Auggie + Serena 快速参考

## 🚀 3 步配置

### 1. 打开 VS Code Augment Settings
- 打开 Augment 扩展
- 点击设置图标（⚙️）
- 找到 "MCP Servers"

### 2. 导入配置
点击 "Import from JSON"，粘贴：
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

### 3. 测试
```bash
auggie --print "使用 Serena 查找 Collection 类"
```

---

## 📋 常用命令

### 查找代码
```bash
# 查找类定义
auggie --print "使用 Serena 查找 Collection 类的定义"

# 查找方法引用
auggie --print "使用 Serena 查找所有使用 CollectionManager 的地方"

# 查找接口实现
auggie --print "使用 Serena 查找所有实现 CollectionInterface 的类"
```

### 编辑代码
```bash
# 添加方法
auggie --print "使用 Serena 在 CollectionController 的 index 方法后添加 export 方法"

# 修改方法
auggie --print "使用 Serena 修改 Collection 类的 __construct 方法"
```

### 结合 Subagents
```bash
# 创建 Collection
auggie --print "使用 lowcode-developer 和 Serena 创建 Product Collection"

# 生成 CRUD
auggie --print "使用 api-developer 和 Serena 为 Product 生成 CRUD API"
```

---

## 🔧 故障排除

### Auggie 无法识别 Serena
1. 检查 MCP Servers 配置
2. 重启 VS Code
3. 确认 "serena" 已连接

### Server 未连接
```bash
# 测试 uv
uv --version

# 测试 Serena
./.serena/start-server.sh
```

---

## 📚 完整文档

- **详细指南**: `cat docs/auggie-serena-mcp-guide.md`
- **配置说明**: `cat .serena/README.md`
- **总结文档**: `cat AUGGIE_SERENA_SETUP_SUMMARY.md`

---

**需要帮助？** 查看完整文档或询问 Auggie！

