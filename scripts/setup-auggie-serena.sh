#!/bin/bash
# Auggie + Serena MCP 配置脚本
# 帮助您快速在 Auggie 中配置 Serena MCP

set -e

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}   Auggie + Serena MCP 配置向导${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 步骤 1: 检查前置条件
echo -e "${YELLOW}[1/4]${NC} 检查前置条件..."
echo ""

# 检查 uv
if ! command -v uv &> /dev/null; then
    echo -e "${RED}✗${NC} uv 未安装"
    echo ""
    echo "请先安装 uv:"
    echo "  curl -LsSf https://astral.sh/uv/install.sh | sh"
    exit 1
else
    echo -e "${GREEN}✓${NC} uv 已安装"
fi

# 检查 Serena 配置
if [ ! -f "$PROJECT_ROOT/.serena/config.yaml" ]; then
    echo -e "${YELLOW}⚠${NC} Serena 项目配置不存在"
    echo ""
    echo "正在运行 Serena 激活脚本..."
    bash "$PROJECT_ROOT/scripts/activate-serena-mcp.sh"
else
    echo -e "${GREEN}✓${NC} Serena 项目配置已存在"
fi

echo ""

# 步骤 2: 显示配置信息
echo -e "${YELLOW}[2/4]${NC} 准备 MCP 配置..."
echo ""

echo "配置文件位置: ${BLUE}.serena/auggie-mcp-config.json${NC}"
echo ""
echo "配置内容:"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
cat "$PROJECT_ROOT/.serena/auggie-mcp-config.json"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 步骤 3: 配置说明
echo -e "${YELLOW}[3/4]${NC} 配置 Augment MCP..."
echo ""

echo "请按照以下步骤在 VS Code 中配置 Serena MCP:"
echo ""
echo "1. 打开 VS Code"
echo "2. 打开 Augment 扩展"
echo "3. 点击右上角的设置图标（⚙️）"
echo "4. 找到 'MCP Servers' 部分"
echo "5. 点击 'Import from JSON' 按钮"
echo "6. 粘贴以下配置:"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
cat "$PROJECT_ROOT/.serena/auggie-mcp-config.json"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "7. 点击 'Save'"
echo "8. 确认 'serena' 出现在 MCP Servers 列表中"
echo ""

# 询问是否已完成配置
read -p "$(echo -e ${YELLOW}是否已完成 VS Code 中的配置？ [y/N]: ${NC})" -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo -e "${YELLOW}💡 提示：${NC}"
    echo "   完成配置后，重新运行此脚本进行验证"
    echo ""
    echo "   或查看详细指南："
    echo "   ${BLUE}cat docs/auggie-serena-mcp-guide.md${NC}"
    echo ""
    exit 0
fi

echo ""

# 步骤 4: 测试配置
echo -e "${YELLOW}[4/4]${NC} 测试 Auggie + Serena..."
echo ""

echo "正在测试 Auggie 命令..."
echo ""

# 创建测试脚本
TEST_COMMAND="auggie --print '使用 Serena 列出项目中的主要 PHP 类'"

echo "运行测试命令:"
echo -e "${BLUE}$TEST_COMMAND${NC}"
echo ""

echo -e "${YELLOW}注意：${NC}如果 Auggie 提示无法访问 Serena，请："
echo "  1. 重启 VS Code"
echo "  2. 确认 MCP Server 配置正确"
echo "  3. 查看故障排除指南"
echo ""

# 完成
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Auggie + Serena MCP 配置完成！${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "📁 相关文件："
echo "   - .serena/auggie-mcp-config.json    # MCP 配置"
echo "   - docs/auggie-serena-mcp-guide.md   # 详细指南"
echo "   - .serena/config.yaml               # Serena 项目配置"
echo ""

echo "🚀 使用示例："
echo ""
echo "1. 查找类定义："
echo "   ${BLUE}auggie --print \"使用 Serena 查找 Collection 类的定义\"${NC}"
echo ""
echo "2. 查找方法引用："
echo "   ${BLUE}auggie --print \"使用 Serena 查找所有使用 CollectionManager 的地方\"${NC}"
echo ""
echo "3. 智能代码编辑："
echo "   ${BLUE}auggie --print \"使用 Serena 在 CollectionController 中添加 export 方法\"${NC}"
echo ""
echo "4. 结合 Subagents："
echo "   ${BLUE}auggie --print \"使用 lowcode-developer 和 Serena 创建 Order Collection\"${NC}"
echo ""

echo "📚 详细文档："
echo "   ${BLUE}cat docs/auggie-serena-mcp-guide.md${NC}"
echo ""

echo "💡 提示："
echo "   - 在命令中明确说明 '使用 Serena' 以确保使用 MCP"
echo "   - Serena 提供符号级别的代码理解，比文本搜索更精确"
echo "   - 可以结合 Augment Subagents 使用，效果更好"
echo ""

echo "🔍 故障排除："
echo "   如果遇到问题，查看："
echo "   ${BLUE}cat docs/auggie-serena-mcp-guide.md${NC} (故障排除部分)"
echo ""

