#!/bin/bash
# Serena CLI - Docker 包装脚本
# 
# 这个脚本用于在命令行中调用 Docker 容器中的 Serena
# 
# 使用方式:
#   ./scripts/serena-cli.sh project health-check
#   ./scripts/serena-cli.sh project index
#   ./scripts/serena-cli.sh --help

set -e

# 配置
CONTAINER_NAME="serena-serena-1"
PROJECT_PATH_IN_CONTAINER="/workspace/projects/AlkaidSYS-tp"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 打印带颜色的消息
print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# 检查 Docker 容器是否运行
check_container() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        print_error "Serena 容器未运行: ${CONTAINER_NAME}"
        print_info "请先启动 Serena 容器"
        exit 1
    fi
}

# 显示帮助信息
show_help() {
    cat << EOF
Serena CLI - Docker 包装脚本

使用方式:
  $0 <serena-command> [arguments...]

常用命令:
  project health-check    检查项目健康状态
  project index           重新索引项目
  project list            列出所有项目
  --help                  显示此帮助信息

示例:
  $0 project health-check
  $0 project index
  $0 project list
  $0 --version

注意:
  - 此脚本会自动使用容器内的项目路径
  - 容器名称: ${CONTAINER_NAME}
  - 项目路径: ${PROJECT_PATH_IN_CONTAINER}

EOF
}

# 主函数
main() {
    # 检查参数
    if [ $# -eq 0 ] || [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
        show_help
        exit 0
    fi

    # 检查容器
    print_info "检查 Serena 容器状态..."
    check_container
    print_success "容器运行正常"

    # 构建命令
    local cmd="serena"
    
    # 处理特殊命令
    if [ "$1" = "project" ]; then
        case "$2" in
            health-check|index)
                # 这些命令需要项目路径
                cmd="$cmd $1 $2 ${PROJECT_PATH_IN_CONTAINER}"
                shift 2
                ;;
            *)
                # 其他 project 命令
                cmd="$cmd $@"
                ;;
        esac
    else
        # 非 project 命令
        cmd="$cmd $@"
    fi

    # 执行命令
    print_info "执行命令: ${cmd}"
    echo ""

    # 检查是否在交互式终端中
    if [ -t 0 ]; then
        docker exec -it ${CONTAINER_NAME} ${cmd}
    else
        docker exec ${CONTAINER_NAME} ${cmd}
    fi
}

# 运行主函数
main "$@"

