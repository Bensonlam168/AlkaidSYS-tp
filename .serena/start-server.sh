#!/bin/bash
# Serena MCP Server 启动脚本

PROJECT_ROOT="/Users/Benson/Code/AlkaidSYS-tp"

echo "🚀 启动 Serena MCP Server..."
echo "项目: AlkaidSYS-tp"
echo "路径: $PROJECT_ROOT"
echo ""

uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path "$PROJECT_ROOT"
