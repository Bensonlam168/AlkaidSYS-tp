# Serena MCP 快速参考

## 🚀 一分钟快速开始

### 1. 测试 Server（可选）
```bash
./.serena/start-server.sh
```
按 `Ctrl+C` 停止。

### 2. 配置 Claude Code

**复制配置到 Claude Code**：
```bash
# 查看配置
cat .serena/claude-code-config-example.json

# 配置文件位置（macOS）
~/Library/Application Support/Claude/claude_desktop_config.json
```

### 3. 重启 Claude Code

### 4. 激活项目

在 Claude Code 中输入：
```
serena onboard
```

### 5. 测试功能

```
使用 Serena 查找 Collection 类
```

---

## 📋 常用命令

### 查找代码
```
使用 Serena 查找 CollectionController 类
使用 Serena 查找 Collection 类的所有方法
使用 Serena 查找所有使用 CollectionManager 的地方
```

### 编辑代码
```
使用 Serena 在 CollectionController 的 index 方法后添加 export 方法
使用 Serena 修改 Collection 类的 __construct 方法
```

### 理解代码
```
使用 Serena 查找所有实现 CollectionInterface 的类
使用 Serena 查找 Collection 类的所有子类
```

---

## 🔧 故障排除

### Server 启动失败
```bash
# 检查 uv
uv --version

# 重新安装
curl -LsSf https://astral.sh/uv/install.sh | sh
```

### Claude Code 无法连接
1. 检查配置文件路径
2. 确保 JSON 格式正确
3. 重启 Claude Code

### 项目未激活
```
serena onboard
```

---

## 📚 完整文档

- **激活指南**: `cat ../docs/serena-mcp-activation-guide.md`
- **配置说明**: `cat README.md`
- **总结文档**: `cat ../SERENA_MCP_ACTIVATION_SUMMARY.md`

---

**需要帮助？** 在 Claude Code 中询问！

