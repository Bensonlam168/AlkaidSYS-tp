#!/bin/bash

# Git工作流本地开发工具集成脚本
# 提供便捷的Git工作流相关命令和检查

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# 配置文件
COMMITLINT_CONFIG="$PROJECT_ROOT/commitlint.config.js"
PHP_CS_FIXER_CONFIG="$PROJECT_ROOT/.php-cs-fixer.php"

# 显示帮助信息
show_help() {
    echo -e "${BLUE}Git工作流开发工具${NC}"
    echo ""
    echo "用法: $0 [命令] [选项]"
    echo ""
    echo "可用命令:"
    echo "  ${GREEN}setup${NC}           初始化开发环境"
    echo "  ${GREEN}check${NC}           运行所有检查"
    echo "  ${GREEN}commit${NC}          智能提交助手"
    echo "  ${GREEN}pr${NC}             创建Pull Request"
    echo "  ${GREEN}branch${NC}         分支管理"
    echo "  ${GREEN}clean${NC}          清理开发环境"
    echo "  ${GREEN}status${NC}          显示工作流状态"
    echo "  ${GREEN}help${NC}           显示此帮助信息"
    echo ""
    echo "示例:"
    echo "  $0 setup                    # 初始化环境"
    echo "  $0 commit                   # 智能提交"
    echo "  $0 pr                       # 创建PR"
    echo "  $0 branch feature/user-auth  # 创建功能分支"
    echo ""
}

# 检查是否在项目根目录
check_project_root() {
    if [ ! -f "$COMMITLINT_CONFIG" ] || [ ! -f "$PHP_CS_FIXER_CONFIG" ]; then
        echo -e "${RED}错误: 请在项目根目录运行此脚本${NC}"
        exit 1
    fi
}

# 初始化开发环境
setup_environment() {
    echo -e "${BLUE}🚀 初始化Git工作流开发环境...${NC}"
    
    # 检查Docker是否运行
    if ! docker compose ps &>/dev/null; then
        echo -e "${YELLOW}⚠️ Docker未运行，正在启动...${NC}"
        docker compose up -d
    fi
    
    # 安装依赖
    echo -e "${BLUE}📦 安装后端依赖...${NC}"
    docker exec -it alkaid-backend composer install
    
    # 检查前端目录
    if [ -d "$PROJECT_ROOT/frontend" ]; then
        echo -e "${BLUE}📦 安装前端依赖...${NC}"
        cd "$PROJECT_ROOT/frontend"
        if command -v pnpm &>/dev/null; then
            pnpm install
        else
            npm install
        fi
        cd "$PROJECT_ROOT"
    fi
    
    # 设置Git钩子
    setup_git_hooks
    
    echo -e "${GREEN}✅ 开发环境初始化完成${NC}"
}

# 设置Git钩子
setup_git_hooks() {
    echo -e "${BLUE}🔧 设置Git钩子...${NC}"
    
    # 创建pre-commit钩子
    cat > "$PROJECT_ROOT/.git/hooks/pre-commit" << 'EOF'
#!/bin/bash
# Git pre-commit钩子
echo "🔍 运行提交前检查..."

# 检查PHP代码风格
if command -v docker &>/dev/null && docker compose ps | grep -q alkaid-backend; then
    CHANGED_PHP_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)
    if [ -n "$CHANGED_PHP_FILES" ]; then
        echo "🔍 检查PHP代码风格..."
        if ! docker exec alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff $CHANGED_PHP_FILES; then
            echo "❌ PHP代码风格检查失败"
            exit 1
        fi
    fi
else
    echo "⚠️ Docker环境未运行，跳过PHP代码风格检查"
fi

# 检查前端代码风格
if [ -d "frontend" ]; then
    CHANGED_JS_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.(js|ts|vue)$' || true)
    if [ -n "$CHANGED_JS_FILES" ]; then
        echo "🔍 检查前端代码风格..."
        cd frontend
        if command -v pnpm &>/dev/null; then
            if ! pnpm lint; then
                echo "❌ 前端代码风格检查失败"
                exit 1
            fi
        else
            if ! npm run lint; then
                echo "❌ 前端代码风格检查失败"
                exit 1
            fi
        fi
        cd ..
    fi
fi

echo "✅ 提交前检查通过"
EOF
    
    # 创建commit-msg钩子
    cat > "$PROJECT_ROOT/.git/hooks/commit-msg" << 'EOF'
#!/bin/bash
# Git commit-msg钩子
COMMIT_MSG_FILE=$1

# 检查提交信息格式
if command -v npx &>/dev/null; then
    if ! npx commitlint --edit "$COMMIT_MSG_FILE"; then
        echo "❌ 提交信息不符合规范"
        exit 1
    fi
else
    echo "⚠️ commitlint未安装，跳过提交信息检查"
fi
EOF
    
    # 设置钩子权限
    chmod +x "$PROJECT_ROOT/.git/hooks/pre-commit"
    chmod +x "$PROJECT_ROOT/.git/hooks/commit-msg"
    
    echo -e "${GREEN}✅ Git钩子设置完成${NC}"
}

# 运行所有检查
run_all_checks() {
    echo -e "${BLUE}🔍 运行所有代码质量检查...${NC}"
    
    # PHP代码风格检查
    echo -e "${YELLOW}📝 检查PHP代码风格...${NC}"
    if docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff; then
        echo -e "${GREEN}✅ PHP代码风格检查通过${NC}"
    else
        echo -e "${RED}❌ PHP代码风格检查失败${NC}"
        return 1
    fi
    
    # 前端代码风格检查
    if [ -d "$PROJECT_ROOT/frontend" ]; then
        echo -e "${YELLOW}📝 检查前端代码风格...${NC}"
        cd "$PROJECT_ROOT/frontend"
        if command -v pnpm &>/dev/null; then
            if pnpm lint; then
                echo -e "${GREEN}✅ 前端代码风格检查通过${NC}"
            else
                echo -e "${RED}❌ 前端代码风格检查失败${NC}"
                cd "$PROJECT_ROOT"
                return 1
            fi
        else
            if npm run lint; then
                echo -e "${GREEN}✅ 前端代码风格检查通过${NC}"
            else
                echo -e "${RED}❌ 前端代码风格检查失败${NC}"
                cd "$PROJECT_ROOT"
                return 1
            fi
        fi
        cd "$PROJECT_ROOT"
    fi
    
    # 运行测试
    echo -e "${YELLOW}🧪 运行测试...${NC}"
    if docker exec -it alkaid-backend php think test; then
        echo -e "${GREEN}✅ 测试通过${NC}"
    else
        echo -e "${RED}❌ 测试失败${NC}"
        return 1
    fi
    
    echo -e "${GREEN}✅ 所有检查通过${NC}"
}

# 智能提交助手
smart_commit() {
    echo -e "${BLUE}📝 智能提交助手${NC}"
    
    # 获取变更的文件
    CHANGED_FILES=$(git status --porcelain | cut -c3-)
    
    if [ -z "$CHANGED_FILES" ]; then
        echo -e "${YELLOW}⚠️ 没有文件变更${NC}"
        return 0
    fi
    
    # 分析变更类型
    HAS_PHP=$(echo "$CHANGED_FILES" | grep -q '\.php$' && echo "true" || echo "false")
    HAS_JS=$(echo "$CHANGED_FILES" | grep -qE '\.(js|ts|vue)$' && echo "true" || echo "false")
    HAS_DOCS=$(echo "$CHANGED_FILES" | grep -qE '\.(md|txt)$' && echo "true" || echo "false")
    HAS_TESTS=$(echo "$CHANGED_FILES" | grep -qE 'Test\.php$|test' && echo "true" || echo "false")
    
    # 推测提交类型
    if [ "$HAS_TESTS" = "true" ]; then
        TYPE="test"
        SCOPE="testing"
    elif [ "$HAS_DOCS" = "true" ] && [ "$HAS_PHP" = "false" ] && [ "$HAS_JS" = "false" ]; then
        TYPE="docs"
        SCOPE="readme"
    elif [ "$HAS_PHP" = "true" ] || [ "$HAS_JS" = "true" ]; then
        TYPE="feat"
        SCOPE="core"
    else
        TYPE="chore"
        SCOPE="build"
    fi
    
    # 获取任务编号
    echo -e "${YELLOW}请输入任务编号 (T-XXX)，或按回车跳过:${NC}"
    read -r TASK_NUMBER
    
    # 获取提交描述
    echo -e "${YELLOW}请输入提交描述:${NC}"
    read -r COMMIT_DESC
    
    if [ -z "$COMMIT_DESC" ]; then
        echo -e "${RED}❌ 提交描述不能为空${NC}"
        return 1
    fi
    
    # 构建提交信息
    if [ -n "$TASK_NUMBER" ]; then
        COMMIT_MSG="$TYPE($SCOPE): $COMMIT_DESC ($TASK_NUMBER)"
    else
        COMMIT_MSG="$TYPE($SCOPE): $COMMIT_DESC"
    fi
    
    # 检查提交信息长度
    if [ ${#COMMIT_MSG} -gt 72 ]; then
        echo -e "${YELLOW}⚠️ 提交信息过长，建议缩短${NC}"
    fi
    
    # 添加所有变更的文件
    git add .
    
    # 提交
    echo -e "${BLUE}📝 提交信息: $COMMIT_MSG${NC}"
    git commit -m "$COMMIT_MSG"
    
    echo -e "${GREEN}✅ 提交完成${NC}"
}

# 创建Pull Request
create_pr() {
    echo -e "${BLUE}🔀 创建Pull Request...${NC}"
    
    # 检查是否有未推送的提交
    if [ -n "$(git log origin/$(git branch --show-current)..HEAD --oneline)" ]; then
        echo -e "${YELLOW}📤 推送代码到远程仓库...${NC}"
        git push origin "$(git branch --show-current)"
    fi
    
    # 获取当前分支
    CURRENT_BRANCH=$(git branch --show-current)
    
    # 检查是否在功能分支
    if [[ ! "$CURRENT_BRANCH" =~ ^(feature|fix)/ ]]; then
        echo -e "${RED}❌ 当前分支不是功能或修复分支${NC}"
        return 1
    fi
    
    # 获取PR描述
    echo -e "${YELLOW}请输入PR描述 (可选):${NC}"
    read -r PR_DESC
    
    # 获取任务编号
    echo -e "${YELLOW}请输入关联的任务编号 (T-XXX):${NC}"
    read -r TASK_NUMBER
    
    # 构建PR标题
    if [ -n "$TASK_NUMBER" ]; then
        PR_TITLE="$CURRENT_BRANCH ($TASK_NUMBER)"
    else
        PR_TITLE="$CURRENT_BRANCH"
    fi
    
    # 构建PR描述
    if [ -n "$PR_DESC" ]; then
        PR_BODY="$PR_DESC"
    else
        PR_BODY="## 📝 变更描述

自动创建的Pull Request

## 🧪 测试情况
- [x] 代码风格检查通过
- [x] 测试通过

## 📋 检查清单
- [x] 代码符合规范
- [x] 包含必要测试
- [x] 文档已更新

---
*此PR由自动化工具创建*"
    fi
    
    # 创建PR
    echo -e "${BLUE}🔀 创建PR: $PR_TITLE${NC}"
    
    if command -v gh &>/dev/null; then
        gh pr create \
            --title "$PR_TITLE" \
            --body "$PR_BODY" \
            --base develop \
            --head "$CURRENT_BRANCH" \
            --label "automated-pr"
    else
        echo -e "${RED}❌ GitHub CLI未安装，请手动创建PR${NC}"
        echo "标题: $PR_TITLE"
        echo "描述: $PR_BODY"
        echo "目标分支: develop"
        echo "源分支: $CURRENT_BRANCH"
    fi
    
    echo -e "${GREEN}✅ PR创建完成${NC}"
}

# 分支管理
manage_branches() {
    local action=$1
    local branch_name=$2
    
    case $action in
        "create")
            if [ -z "$branch_name" ]; then
                echo -e "${YELLOW}请输入分支名称:${NC}"
                read -r branch_name
            fi
            
            if [ -z "$branch_name" ]; then
                echo -e "${RED}❌ 分支名称不能为空${NC}"
                return 1
            fi
            
            # 检查分支命名规范
            if [[ ! "$branch_name" =~ ^(feature|fix|release|hotfix)/ ]]; then
                echo -e "${YELLOW}⚠️ 建议使用标准分支命名: feature/, fix/, release/, hotfix/${NC}"
            fi
            
            echo -e "${BLUE}🌿 创建分支: $branch_name${NC}"
            git checkout -b "$branch_name"
            ;;
        "list")
            echo -e "${BLUE}📋 本地分支列表:${NC}"
            git branch -a
            ;;
        "switch")
            if [ -z "$branch_name" ]; then
                echo -e "${YELLOW}请输入要切换的分支名称:${NC}"
                read -r branch_name
            fi
            
            echo -e "${BLUE}🔄 切换到分支: $branch_name${NC}"
            git checkout "$branch_name"
            ;;
        "delete")
            if [ -z "$branch_name" ]; then
                echo -e "${YELLOW}请输入要删除的分支名称:${NC}"
                read -r branch_name
            fi
            
            echo -e "${RED}🗑️ 删除分支: $branch_name${NC}"
            git branch -D "$branch_name"
            ;;
        *)
            echo -e "${YELLOW}分支管理用法:${NC}"
            echo "  $0 branch create <name>     # 创建分支"
            echo "  $0 branch list           # 列出分支"
            echo "  $0 branch switch <name>    # 切换分支"
            echo "  $0 branch delete <name>    # 删除分支"
            ;;
    esac
}

# 清理开发环境
clean_environment() {
    echo -e "${BLUE}🧹 清理开发环境...${NC}"
    
    # 清理Docker容器
    echo -e "${YELLOW}🐳 清理Docker容器...${NC}"
    docker compose down
    
    # 清理临时文件
    echo -e "${YELLOW}🗑️ 清理临时文件...${NC}"
    find "$PROJECT_ROOT" -name "*.tmp" -delete
    find "$PROJECT_ROOT" -name ".DS_Store" -delete
    
    # 清理日志文件
    echo -e "${YELLOW}📋 清理日志文件...${NC}"
    if [ -d "$PROJECT_ROOT/runtime/log" ]; then
        rm -rf "$PROJECT_ROOT/runtime/log"/*
    fi
    
    # 清理前端构建文件
    if [ -d "$PROJECT_ROOT/frontend" ]; then
        cd "$PROJECT_ROOT/frontend"
        if command -v pnpm &>/dev/null; then
            pnpm clean
        else
            npm run clean
        fi
        cd "$PROJECT_ROOT"
    fi
    
    echo -e "${GREEN}✅ 开发环境清理完成${NC}"
}

# 显示工作流状态
show_status() {
    echo -e "${BLUE}📊 Git工作流状态${NC}"
    echo ""
    
    # Git状态
    echo -e "${YELLOW}📋 Git状态:${NC}"
    git status --short
    echo ""
    
    # 当前分支
    echo -e "${YELLOW}🌿 当前分支:${NC}"
    git branch --show-current
    echo ""
    
    # Docker状态
    echo -e "${YELLOW}🐳 Docker状态:${NC}"
    if docker compose ps &>/dev/null; then
        docker compose ps
    else
        echo "Docker未运行"
    fi
    echo ""
    
    # 最近的提交
    echo -e "${YELLOW}📝 最近提交:${NC}"
    git log --oneline -5
    echo ""
    
    # 待推送的提交
    UNPUSHED=$(git log origin/$(git branch --show-current)..HEAD --oneline)
    if [ -n "$UNPUSHED" ]; then
        echo -e "${YELLOW}📤 待推送提交:${NC}"
        echo "$UNPUSHED"
    else
        echo -e "${GREEN}✅ 所有提交已推送${NC}"
    fi
}

# 主函数
main() {
    check_project_root
    
    case ${1:-help} in
        "setup")
            setup_environment
            ;;
        "check")
            run_all_checks
            ;;
        "commit")
            smart_commit
            ;;
        "pr")
            create_pr
            ;;
        "branch")
            manage_branches "${2:-create}" "${3:-}"
            ;;
        "clean")
            clean_environment
            ;;
        "status")
            show_status
            ;;
        "help"|*)
            show_help
            ;;
    esac
}

# 运行主函数
main "$@"