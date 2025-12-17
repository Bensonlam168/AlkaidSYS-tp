#!/bin/bash

# 工作流引擎数据库迁移执行脚本
# Workflow Engine Database Migration Runner

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIGRATION_DIR="${PROJECT_ROOT}/migrations"

# 打印带颜色的消息
print_message() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

print_info() {
    print_message "${BLUE}" "ℹ️  $1"
}

print_success() {
    print_message "${GREEN}" "✅ $1"
}

print_warning() {
    print_message "${YELLOW}" "⚠️  $1"
}

print_error() {
    print_message "${RED}" "❌ $1"
}

# 显示帮助信息
show_help() {
    cat << EOF
工作流引擎数据库迁移工具

用法: $0 [选项] [命令]

命令:
    run         执行所有待执行的迁移
    rollback    回滚最后一个迁移
    reset       重置数据库（危险操作！）
    status      显示迁移状态
    create <name> 创建新的迁移文件

选项:
    -h, --help      显示此帮助信息
    -v, --verbose   详细输出
    --dry-run       试运行模式（不实际执行）

示例:
    $0 run                    # 执行所有迁移
    $0 status                 # 查看迁移状态
    $0 create add_new_table   # 创建名为 add_new_table 的迁移文件

EOF
}

# 检查依赖
check_dependencies() {
    if ! command -v php &> /dev/null; then
        print_error "PHP 未安装或未在 PATH 中"
        exit 1
    fi

    if [ ! -f "${PROJECT_ROOT}/vendor/autoload.php" ]; then
        print_warning "Composer 依赖未安装，正在运行 composer install..."
        cd "$PROJECT_ROOT"
        composer install --no-interaction --prefer-dist
        if [ $? -ne 0 ]; then
            print_error "Composer 依赖安装失败"
            exit 1
        fi
    fi
}

# 获取ThinkPHP迁移命令
get_migration_command() {
    cd "$PROJECT_ROOT"
    php think migrate:run
}

# 执行迁移
run_migrations() {
    print_info "开始执行工作流引擎数据库迁移..."

    # 检查迁移目录
    if [ ! -d "$MIGRATION_DIR" ]; then
        print_error "迁移目录不存在: $MIGRATION_DIR"
        exit 1
    fi

    # 检查迁移文件
    migration_files=$(find "$MIGRATION_DIR" -name "*.php" -type f | sort)
    if [ -z "$migration_files" ]; then
        print_warning "没有找到迁移文件"
        return 0
    fi

    print_info "找到迁移文件:"
    echo "$migration_files" | while read -r file; do
        echo "  - $(basename "$file")"
    done

    # 检查是否试运行
    if [ "$DRY_RUN" = "true" ]; then
        print_warning "试运行模式，不会实际执行迁移"
        return 0
    fi

    # 执行迁移
    cd "$PROJECT_ROOT"

    # 检查ThinkPHP配置
    if [ ! -f "config/database.php" ]; then
        print_error "数据库配置文件不存在: config/database.php"
        exit 1
    fi

    print_info "正在执行迁移..."
    if php think migrate:run; then
        print_success "迁移执行完成"
    else
        print_error "迁移执行失败"
        exit 1
    fi
}

# 回滚迁移
rollback_migration() {
    print_info "回滚最后一个迁移..."

    if [ "$DRY_RUN" = "true" ]; then
        print_warning "试运行模式，不会实际执行回滚"
        return 0
    fi

    cd "$PROJECT_ROOT"
    if php think migrate:rollback; then
        print_success "迁移回滚完成"
    else
        print_error "迁移回滚失败"
        exit 1
    fi
}

# 重置数据库
reset_database() {
    print_warning "这将删除所有工作流引擎数据表！"
    read -p "确定要继续吗？(输入 'yes' 确认): " confirm

    if [ "$confirm" != "yes" ]; then
        print_info "操作已取消"
        return 0
    fi

    print_info "正在重置数据库..."

    if [ "$DRY_RUN" = "true" ]; then
        print_warning "试运行模式，不会实际执行重置"
        return 0
    fi

    cd "$PROJECT_ROOT"
    if php think migrate:reset; then
        print_success "数据库重置完成"
    else
        print_error "数据库重置失败"
        exit 1
    fi
}

# 显示迁移状态
show_status() {
    print_info "检查迁移状态..."

    cd "$PROJECT_ROOT"
    php think migrate:status
}

# 创建新迁移文件
create_migration() {
    local name=$1

    if [ -z "$name" ]; then
        print_error "请提供迁移文件名称"
        exit 1
    fi

    # 生成时间戳
    timestamp=$(date +"%Y%m%d%H%M%S")
    filename="${MIGRATION_DIR}/${timestamp}_${name}.php"

    # 检查目录是否存在
    if [ ! -d "$MIGRATION_DIR" ]; then
        mkdir -p "$MIGRATION_DIR"
    fi

    # 创建迁移文件
    cat > "$filename" << EOF
<?php

declare(strict_types=1);

use think\migration\db\Column;
use think\migration\Migrator;

/**
 * $name
 *
 * @package plugins\\workflow-engine\\migrations
 */
class $(echo "$name" | sed 's/_/./g' | awk '{for(i=1;i<=NF;i++)sub(/./,toupper(substr($i,1,1)),$i)}1' | sed 's/_//g') extends Migrator
{
    /**
     * 执行迁移
     */
    public function change(): void
    {
        // 在这里添加您的迁移逻辑
    }
}
EOF

    print_success "迁移文件已创建: $filename"
}

# 验证迁移文件语法
validate_migrations() {
    print_info "验证迁移文件语法..."

    local errors=0

    find "$MIGRATION_DIR" -name "*.php" -type f | while read -r file; do
        if ! php -l "$file" > /dev/null 2>&1; then
            print_error "语法错误: $file"
            ((errors++))
        fi
    done

    if [ $errors -eq 0 ]; then
        print_success "所有迁移文件语法正确"
    else
        print_error "发现 $errors 个语法错误"
        exit 1
    fi
}

# 主函数
main() {
    # 默认参数
    DRY_RUN="false"
    VERBOSE="false"
    COMMAND=""

    # 解析参数
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                show_help
                exit 0
                ;;
            -v|--verbose)
                VERBOSE="true"
                shift
                ;;
            --dry-run)
                DRY_RUN="true"
                shift
                ;;
            run|rollback|reset|status)
                COMMAND="$1"
                shift
                ;;
            create)
                if [ -z "$2" ]; then
                    print_error "create 命令需要迁移名称参数"
                    exit 1
                fi
                COMMAND="create"
                MIGRATION_NAME="$2"
                shift 2
                ;;
            *)
                print_error "未知参数: $1"
                show_help
                exit 1
                ;;
        esac
    done

    # 检查命令
    if [ -z "$COMMAND" ]; then
        print_error "请提供命令"
        show_help
        exit 1
    fi

    # 执行命令
    case $COMMAND in
        run)
            check_dependencies
            validate_migrations
            run_migrations
            ;;
        rollback)
            check_dependencies
            rollback_migration
            ;;
        reset)
            check_dependencies
            reset_database
            ;;
        status)
            check_dependencies
            show_status
            ;;
        create)
            check_dependencies
            create_migration "$MIGRATION_NAME"
            ;;
        *)
            print_error "未知命令: $COMMAND"
            exit 1
            ;;
    esac
}

# 执行主函数
main "$@"
