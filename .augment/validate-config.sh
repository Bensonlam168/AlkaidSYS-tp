#!/bin/bash
# Augment 配置验证脚本
# 用于验证 .augment 目录下配置文件的完整性和正确性

# 不使用 set -e，因为我们要收集所有错误
# set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 计数器
total_checks=0
passed_checks=0
failed_checks=0
warnings=0

echo -e "${BLUE}🔍 验证 Augment 配置完整性...${NC}\n"

# 函数：打印成功消息
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
    ((passed_checks++))
    ((total_checks++))
}

# 函数：打印失败消息
print_error() {
    echo -e "${RED}❌ $1${NC}"
    ((failed_checks++))
    ((total_checks++))
}

# 函数：打印警告消息
print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
    ((warnings++))
}

# 函数：打印信息消息
print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# 检查目录是否存在
check_directory() {
    local dir=$1
    local name=$2
    
    if [ -d "$dir" ]; then
        print_success "$name 目录存在"
        return 0
    else
        print_error "$name 目录不存在: $dir"
        return 1
    fi
}

# 检查文件是否存在
check_file() {
    local file=$1
    local name=$2
    
    if [ -f "$file" ]; then
        print_success "$name 文件存在"
        return 0
    else
        print_error "$name 文件不存在: $file"
        return 1
    fi
}

# 检查 YAML 语法
check_yaml_syntax() {
    local file=$1

    # 检查是否安装了 Python 和 PyYAML
    if ! command -v python3 &> /dev/null; then
        # Python3 未安装，跳过检查
        return 0
    fi

    # 检查是否有 yaml 模块
    if ! python3 -c "import yaml" 2>/dev/null; then
        # PyYAML 未安装，跳过检查
        return 0
    fi

    # 使用 Python 验证 YAML 语法
    if python3 -c "import yaml; yaml.safe_load(open('$file'))" 2>/dev/null; then
        return 0
    else
        print_error "YAML 语法错误: $file"
        return 1
    fi
}

# 检查 YAML 文件的必需字段
check_yaml_fields() {
    local file=$1
    shift
    local required_fields=("$@")

    local missing_fields=()

    for field in "${required_fields[@]}"; do
        if ! grep -q "^$field:" "$file"; then
            missing_fields+=("$field")
        fi
    done

    if [ ${#missing_fields[@]} -eq 0 ]; then
        return 0
    else
        print_error "$file 缺少必需字段: ${missing_fields[*]}"
        return 1
    fi
}

echo -e "${BLUE}=== 1. 检查目录结构 ===${NC}\n"

check_directory ".augment" "Augment 配置根"
check_directory ".augment/rules" "Rules"
check_directory ".augment/subagents" "Subagents"
check_directory ".augment/skills" "Skills"
check_directory ".augment/commands" "Commands"

echo -e "\n${BLUE}=== 2. 检查官方 Rules 配置 ===${NC}\n"

check_file ".augment/rules/always-alkaidsys-project-rules.md" "Always Rules"
check_file ".augment/rules/auto-alkaidsys-guidelines.md" "Auto Rules"

echo -e "\n${BLUE}=== 3. 检查自定义配置文件 ===${NC}\n"

check_file ".augment/config.yaml" "配置索引文件"
check_file ".augment/README.md" "README 文档"

echo -e "\n${BLUE}=== 4. 检查 config.yaml 中声明的文件 ===${NC}\n"

# 检查 Skills
print_info "检查 Skills 配置..."
skills=(
    "create-collection"
    "create-field"
    "create-api-endpoint"
    "create-form-schema"
    "run-migration"
    "run-tests"
    "review-code"
    "auth-permission-best-practices"
    "rate-limit-and-gateway-best-practices"
    "workflow-and-plugin-architecture"
)

for skill in "${skills[@]}"; do
    file=".augment/skills/${skill}.yaml"
    if [ -f "$file" ]; then
        print_success "Skill: $skill"
        check_yaml_syntax "$file"
        check_yaml_fields "$file" "name" "version" "description"
    else
        print_error "Skill 文件不存在: $skill"
    fi
done

echo ""
print_info "检查 Commands 配置..."
commands=(
    "lowcode-init"
    "generate-crud"
    "setup-project"
    "deploy"
    "tests-and-migrations-hardening"
    "auth-permission-integration"
    "casbin-phase2-rollout"
    "api-error-trace-pagination-unify"
)

for command in "${commands[@]}"; do
    file=".augment/commands/${command}.yaml"
    if [ -f "$file" ]; then
        print_success "Command: $command"
        check_yaml_syntax "$file"
        check_yaml_fields "$file" "name" "version" "description"
    else
        print_error "Command 文件不存在: $command"
    fi
done

echo ""
print_info "检查 Subagents 配置..."
subagents=(
    "lowcode-developer"
    "api-developer"
    "auth-security-engineer"
    "frontend-integrator"
    "test-migration-engineer"
    "code-reviewer"
    "documentation-reviewer"
    "architecture-reviewer"
)

for subagent in "${subagents[@]}"; do
    file=".augment/subagents/${subagent}.yaml"
    if [ -f "$file" ]; then
        print_success "Subagent: $subagent"
        check_yaml_syntax "$file"
        check_yaml_fields "$file" "name" "version" "description"
    else
        print_error "Subagent 文件不存在: $subagent"
    fi
done

echo -e "\n${BLUE}=== 5. 检查依赖文件 ===${NC}\n"

# 检查 subagents 中引用的 context_files
print_info "检查 Subagents 的 context_files..."
for file in .augment/subagents/*.yaml; do
    if [ -f "$file" ]; then
        # 提取 context_files 列表（简单的 grep，可能不完美）
        context_files=$(grep -A 10 "context_files:" "$file" | grep "^  - " | sed 's/^  - //' || true)
        
        if [ -n "$context_files" ]; then
            while IFS= read -r context_file; do
                if [ -f "$context_file" ]; then
                    # 文件存在，不输出（避免过多输出）
                    :
                else
                    print_warning "$(basename $file) 引用的文件不存在: $context_file"
                fi
            done <<< "$context_files"
        fi
    fi
done

echo -e "\n${BLUE}=== 验证结果汇总 ===${NC}\n"

echo -e "总检查项: ${total_checks}"
echo -e "${GREEN}通过: ${passed_checks}${NC}"
echo -e "${RED}失败: ${failed_checks}${NC}"
echo -e "${YELLOW}警告: ${warnings}${NC}"

echo ""

if [ $failed_checks -eq 0 ]; then
    echo -e "${GREEN}✅ 所有配置文件验证通过！${NC}"
    exit 0
else
    echo -e "${RED}❌ 发现 ${failed_checks} 个问题，请修复后重试${NC}"
    echo ""
    echo "建议："
    echo "  1. 检查缺失的配置文件"
    echo "  2. 验证 YAML 语法是否正确"
    echo "  3. 确保必需字段完整"
    echo "  4. 更新 config.yaml 中的声明"
    exit 1
fi

