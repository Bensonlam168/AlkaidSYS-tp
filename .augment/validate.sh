#!/bin/bash
# Augment 配置验证脚本
# 用于验证 .augment 目录下的配置文件是否完整和正确

set -e

echo "🔍 开始验证 Augment 配置..."
echo ""

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 计数器
TOTAL=0
PASSED=0
FAILED=0

# 检查函数
check_file() {
    local file=$1
    local description=$2
    TOTAL=$((TOTAL + 1))

    if [ -f "$file" ] || [ -d "$file" ]; then
        echo -e "${GREEN}✓${NC} $description: $file"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "${RED}✗${NC} $description: $file ${RED}(缺失)${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

# 检查 YAML 语法
check_yaml() {
    local file=$1
    TOTAL=$((TOTAL + 1))

    if command -v python3 &> /dev/null; then
        # 检查是否安装了 PyYAML
        if python3 -c "import yaml" 2>/dev/null; then
            if python3 -c "import yaml; yaml.safe_load(open('$file'))" 2>/dev/null; then
                echo -e "${GREEN}✓${NC} YAML 语法检查: $file"
                PASSED=$((PASSED + 1))
                return 0
            else
                echo -e "${RED}✗${NC} YAML 语法错误: $file"
                FAILED=$((FAILED + 1))
                return 1
            fi
        else
            echo -e "${YELLOW}⚠${NC} 跳过 YAML 语法检查（需要 PyYAML）: $file"
            PASSED=$((PASSED + 1))
            return 0
        fi
    else
        echo -e "${YELLOW}⚠${NC} 跳过 YAML 语法检查（需要 Python3）"
        PASSED=$((PASSED + 1))
        return 0
    fi
}

echo "📁 检查目录结构..."
echo ""

# 检查主目录
check_file ".augment" "主目录"
check_file ".augment/subagents" "Subagents 目录"
check_file ".augment/skills" "Skills 目录"
check_file ".augment/commands" "Commands 目录"
check_file ".augment/examples" "Examples 目录"

echo ""
echo "📄 检查配置文件..."
echo ""

# 检查主配置文件
check_file ".augment/config.yaml" "主配置文件"
check_file ".augment/.augmentignore" "忽略文件配置"

echo ""
echo "📚 检查文档文件..."
echo ""

# 检查文档
check_file ".augment/README.md" "完整文档"
check_file ".augment/QUICKSTART.md" "快速入门"
check_file ".augment/INDEX.md" "配置索引"
check_file ".augment/examples/usage-examples.md" "使用示例"

echo ""
echo "🤖 检查 Subagents..."
echo ""

# 检查 Subagents
check_file ".augment/subagents/lowcode-developer.yaml" "低代码开发专家"
check_file ".augment/subagents/api-developer.yaml" "API 开发专家"

echo ""
echo "🛠️ 检查 Skills..."
echo ""

# 检查 Skills
check_file ".augment/skills/create-collection.yaml" "创建 Collection"
check_file ".augment/skills/create-api-endpoint.yaml" "创建 API 端点"

echo ""
echo "📋 检查 Commands..."
echo ""

# 检查 Commands
check_file ".augment/commands/lowcode-init.yaml" "初始化低代码环境"
check_file ".augment/commands/generate-crud.yaml" "生成 CRUD 代码"

echo ""
echo "🔍 检查 YAML 语法..."
echo ""

# 检查 YAML 语法
if command -v python3 &> /dev/null; then
    check_yaml ".augment/config.yaml"
    check_yaml ".augment/subagents/lowcode-developer.yaml"
    check_yaml ".augment/subagents/api-developer.yaml"
    check_yaml ".augment/skills/create-collection.yaml"
    check_yaml ".augment/skills/create-api-endpoint.yaml"
    check_yaml ".augment/commands/lowcode-init.yaml"
    check_yaml ".augment/commands/generate-crud.yaml"
else
    echo -e "${YELLOW}⚠${NC} Python3 未安装，跳过 YAML 语法检查"
    echo -e "${YELLOW}💡${NC} 提示：安装 Python3 和 PyYAML 以启用语法检查"
    echo "   brew install python3"
    echo "   pip3 install pyyaml"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# 输出结果
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}✅ 验证通过！${NC}"
    echo ""
    echo "📊 统计："
    echo "   总计: $TOTAL 项"
    echo "   通过: $PASSED 项"
    echo "   失败: $FAILED 项"
    echo ""
    echo "🚀 您可以开始使用 Augment 了！"
    echo ""
    echo "💡 下一步："
    echo "   1. 查看快速入门：cat .augment/QUICKSTART.md"
    echo "   2. 初始化环境：auggie --print \"运行 lowcode-init 命令\""
    echo "   3. 查看示例：cat .augment/examples/usage-examples.md"
    exit 0
else
    echo -e "${RED}❌ 验证失败！${NC}"
    echo ""
    echo "📊 统计："
    echo "   总计: $TOTAL 项"
    echo "   通过: $PASSED 项"
    echo "   失败: $FAILED 项"
    echo ""
    echo "💡 请检查缺失的文件并重新运行验证"
    exit 1
fi

