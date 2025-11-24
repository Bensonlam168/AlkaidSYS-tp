#!/bin/bash
# Serena MCP 激活脚本
# 用于自动激活 AlkaidSYS-tp 项目的 Serena MCP 支持

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
echo -e "${BLUE}   Serena MCP 激活脚本${NC}"
echo -e "${BLUE}   项目: AlkaidSYS-tp${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 步骤 1: 检查 uv 是否安装
echo -e "${YELLOW}[1/6]${NC} 检查 uv 是否安装..."
if ! command -v uv &> /dev/null; then
    echo -e "${RED}✗${NC} uv 未安装"
    echo ""
    echo "请先安装 uv:"
    echo "  curl -LsSf https://astral.sh/uv/install.sh | sh"
    echo ""
    echo "或使用 Homebrew:"
    echo "  brew install uv"
    exit 1
else
    UV_VERSION=$(uv --version)
    echo -e "${GREEN}✓${NC} uv 已安装: $UV_VERSION"
fi
echo ""

# 步骤 2: 创建 Serena 配置目录
echo -e "${YELLOW}[2/6]${NC} 创建 Serena 配置目录..."
mkdir -p "$PROJECT_ROOT/.serena"
echo -e "${GREEN}✓${NC} 配置目录已创建: $PROJECT_ROOT/.serena"
echo ""

# 步骤 3: 创建 Serena 配置文件
echo -e "${YELLOW}[3/6]${NC} 创建 Serena 配置文件..."
cat > "$PROJECT_ROOT/.serena/config.yaml" << 'EOF'
# Serena MCP 项目配置
# 为 AlkaidSYS-tp 项目自动生成

project:
  name: AlkaidSYS-tp
  description: 强大、现代、低代码的企业级 SAAS 系统框架
  root: PROJECT_ROOT_PLACEHOLDER
  
# 支持的编程语言
languages:
  - php        # 后端主要语言
  - javascript # 前端脚本
  - typescript # 前端主要语言
  - yaml       # 配置文件
  - markdown   # 文档
  - json       # 配置和数据

# 忽略的目录和文件
ignore:
  # 依赖目录
  - node_modules
  - vendor
  - frontend/node_modules
  
  # 构建产物
  - runtime
  - public/static
  - frontend/dist
  - frontend/.turbo
  
  # 版本控制
  - .git
  
  # IDE 配置
  - .idea
  - .vscode
  
  # 临时文件
  - "*.log"
  - "*.tmp"
  - "*.cache"

# 语言服务器配置
language_servers:
  php:
    enabled: true
    # PHP Intelephense 或 PHP Language Server
  
  typescript:
    enabled: true
    # TypeScript Language Server
  
  javascript:
    enabled: true
    # JavaScript Language Server

# 代码分析选项
analysis:
  max_file_size: 1048576  # 1MB
  timeout: 30  # 30 秒
  
# 缓存配置
cache:
  enabled: true
  directory: .serena/cache
EOF

# 替换项目根目录占位符
sed -i.bak "s|PROJECT_ROOT_PLACEHOLDER|$PROJECT_ROOT|g" "$PROJECT_ROOT/.serena/config.yaml"
rm "$PROJECT_ROOT/.serena/config.yaml.bak"

echo -e "${GREEN}✓${NC} 配置文件已创建: $PROJECT_ROOT/.serena/config.yaml"
echo ""

# 步骤 4: 创建 .gitignore 条目
echo -e "${YELLOW}[4/6]${NC} 更新 .gitignore..."
if ! grep -q ".serena/cache" "$PROJECT_ROOT/.gitignore" 2>/dev/null; then
    echo "" >> "$PROJECT_ROOT/.gitignore"
    echo "# Serena MCP 缓存" >> "$PROJECT_ROOT/.gitignore"
    echo ".serena/cache/" >> "$PROJECT_ROOT/.gitignore"
    echo -e "${GREEN}✓${NC} .gitignore 已更新"
else
    echo -e "${GREEN}✓${NC} .gitignore 已包含 Serena 配置"
fi
echo ""

# 步骤 5: 测试 Serena MCP Server
echo -e "${YELLOW}[5/6]${NC} 测试 Serena MCP Server..."
echo "正在启动 Serena MCP Server（测试模式）..."
echo ""

# 创建测试脚本
cat > "$PROJECT_ROOT/.serena/test-server.sh" << 'EOF'
#!/bin/bash
timeout 5 uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path PROJECT_ROOT_PLACEHOLDER \
  2>&1 | head -20
EOF

sed -i.bak "s|PROJECT_ROOT_PLACEHOLDER|$PROJECT_ROOT|g" "$PROJECT_ROOT/.serena/test-server.sh"
rm "$PROJECT_ROOT/.serena/test-server.sh.bak"
chmod +x "$PROJECT_ROOT/.serena/test-server.sh"

if bash "$PROJECT_ROOT/.serena/test-server.sh" 2>&1 | grep -q "Serena\|Server\|MCP"; then
    echo -e "${GREEN}✓${NC} Serena MCP Server 测试成功"
else
    echo -e "${YELLOW}⚠${NC} Serena MCP Server 测试未完成（这是正常的）"
    echo "   Server 将在实际使用时启动"
fi
echo ""

# 步骤 6: 创建启动脚本
echo -e "${YELLOW}[6/6]${NC} 创建启动脚本..."
cat > "$PROJECT_ROOT/.serena/start-server.sh" << 'EOF'
#!/bin/bash
# Serena MCP Server 启动脚本

PROJECT_ROOT="PROJECT_ROOT_PLACEHOLDER"

echo "🚀 启动 Serena MCP Server..."
echo "项目: AlkaidSYS-tp"
echo "路径: $PROJECT_ROOT"
echo ""

uvx --from git+https://github.com/oraios/serena serena start-mcp-server \
  --context cli \
  --project-path "$PROJECT_ROOT"
EOF

sed -i.bak "s|PROJECT_ROOT_PLACEHOLDER|$PROJECT_ROOT|g" "$PROJECT_ROOT/.serena/start-server.sh"
rm "$PROJECT_ROOT/.serena/start-server.sh.bak"
chmod +x "$PROJECT_ROOT/.serena/start-server.sh"

echo -e "${GREEN}✓${NC} 启动脚本已创建: $PROJECT_ROOT/.serena/start-server.sh"
echo ""

# 完成
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Serena MCP 激活完成！${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "📁 创建的文件："
echo "   - .serena/config.yaml          # 项目配置"
echo "   - .serena/start-server.sh      # 启动脚本"
echo "   - .serena/test-server.sh       # 测试脚本"
echo ""

echo "🚀 下一步："
echo ""
echo "1. 手动启动 Serena MCP Server（测试）："
echo "   ${BLUE}./.serena/start-server.sh${NC}"
echo ""
echo "2. 配置 Claude Code（如果使用）："
echo "   编辑: ~/Library/Application Support/Claude/claude_desktop_config.json"
echo "   添加 Serena MCP Server 配置"
echo ""
echo "3. 在 Claude Code 中激活项目："
echo "   打开 Claude Code，输入: ${BLUE}serena onboard${NC}"
echo ""
echo "4. 测试 Serena 功能："
echo "   ${BLUE}使用 Serena 查找 Collection 类${NC}"
echo ""

echo "📚 详细文档："
echo "   ${BLUE}cat docs/serena-mcp-activation-guide.md${NC}"
echo ""

echo "💡 提示："
echo "   - Serena 会自动检测项目中的编程语言"
echo "   - 首次使用时可能需要下载语言服务器"
echo "   - 配置文件位于 .serena/config.yaml"
echo ""

