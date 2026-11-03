#!/bin/bash

# 工作流引擎测试执行脚本
# Workflow Engine Test Runner

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="${PROJECT_ROOT}/tests"
VENDOR_DIR="${PROJECT_ROOT}/vendor"

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
工作流引擎测试工具

用法: $0 [选项] [命令]

命令:
    all             运行所有测试
    unit            运行单元测试
    integration     运行集成测试
    feature         运行功能测试
    coverage        运行测试并生成覆盖率报告
    clean           清理测试缓存和报告

选项:
    -h, --help      显示此帮助信息
    -v, --verbose   详细输出
    --filter        指定测试过滤器
    --stop-on-fail  在第一个失败时停止

示例:
    $0 all                    # 运行所有测试
    $0 unit                   # 运行单元测试
    $0 coverage --html        # 生成HTML覆盖率报告
    $0 unit --filter "testContainer"  # 运行特定测试

EOF
}

# 检查依赖
check_dependencies() {
    if ! command -v php &> /dev/null; then
        print_error "PHP 未安装或未在 PATH 中"
        exit 1
    fi

    if [ ! -f "${VENDOR_DIR}/autoload.php" ]; then
        print_warning "Composer 依赖未安装，正在运行 composer install..."
        cd "$PROJECT_ROOT"
        composer install --no-interaction --prefer-dist
        if [ $? -ne 0 ]; then
            print_error "Composer 依赖安装失败"
            exit 1
        fi
    fi

    if [ ! -f "${VENDOR_DIR}/bin/phpunit" ]; then
        print_error "PHPUnit 未安装"
        exit 1
    fi
}

# 运行测试
run_tests() {
    local test_type=$1
    local filter=$2
    local verbose=$3
    local stop_on_fail=$4

    print_info "开始运行${test_type}测试..."

    cd "$PROJECT_ROOT"

    # 构建PHPUnit命令
    local cmd="${VENDOR_DIR}/bin/phpunit"

    # 添加测试目录
    case $test_type in
        "unit")
            cmd="$cmd tests/Unit"
            ;;
        "integration")
            cmd="$cmd tests/Integration"
            ;;
        "feature")
            cmd="$cmd tests/Feature"
            ;;
        "all")
            cmd="$cmd tests"
            ;;
    esac

    # 添加过滤器
    if [ -n "$filter" ]; then
        cmd="$cmd --filter '$filter'"
    fi

    # 添加详细输出
    if [ "$verbose" = "true" ]; then
        cmd="$cmd -vvv"
    fi

    # 添加停止选项
    if [ "$stop_on_fail" = "true" ]; then
        cmd="$cmd --stop-on-failure"
    fi

    # 执行测试
    if eval "$cmd"; then
        print_success "${test_type}测试通过"
        return 0
    else
        print_error "${test_type}测试失败"
        return 1
    fi
}

# 生成覆盖率报告
generate_coverage() {
    local format=$1

    print_info "生成${format}覆盖率报告..."

    cd "$PROJECT_ROOT"

    local cmd="${VENDOR_DIR}/bin/phpunit --coverage-${format} tests/coverage/coverage.${format}"

    case $format in
        "html")
            cmd="$cmd tests/coverage/html"
            ;;
        "clover")
            cmd="$cmd tests/coverage/clover.xml"
            ;;
        "xml")
            cmd="$cmd tests/coverage/coverage.xml"
            ;;
    esac

    if eval "$cmd"; then
        print_success "覆盖率报告已生成: tests/coverage/"
        return 0
    else
        print_error "覆盖率报告生成失败"
        return 1
    fi
}

# 清理测试缓存和报告
clean_tests() {
    print_info "清理测试缓存和报告..."

    cd "$PROJECT_ROOT"

    # 清理PHPUnit缓存
    if [ -d ".phpunit.cache" ]; then
        rm -rf .phpunit.cache
        print_info "已清理 PHPUnit 缓存"
    fi

    # 清理覆盖率报告
    if [ -d "tests/coverage" ]; then
        rm -rf tests/coverage
        print_info "已清理覆盖率报告"
    fi

    # 清理临时文件
    find tests -name "*.tmp" -delete 2>/dev/null || true

    print_success "清理完成"
}

# 主函数
main() {
    # 默认参数
    COMMAND="all"
    FILTER=""
    VERBOSE="false"
    STOP_ON_FAIL="false"
    COVERAGE_FORMAT=""

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
            --filter)
                FILTER="$2"
                shift 2
                ;;
            --stop-on-fail)
                STOP_ON_FAIL="true"
                shift
                ;;
            all|unit|integration|feature)
                COMMAND="$1"
                shift
                ;;
            coverage)
                COMMAND="coverage"
                shift
                if [ "$1" == "html" ] || [ "$1" == "clover" ] || [ "$1" == "xml" ]; then
                    COVERAGE_FORMAT="$1"
                    shift
                fi
                ;;
            clean)
                COMMAND="clean"
                shift
                ;;
            *)
                print_error "未知参数: $1"
                show_help
                exit 1
                ;;
        esac
    done

    # 检查依赖
    check_dependencies

    # 执行命令
    case $COMMAND in
        all|unit|integration|feature)
            run_tests "$COMMAND" "$FILTER" "$VERBOSE" "$STOP_ON_FAIL"
            ;;
        coverage)
            if [ -z "$COVERAGE_FORMAT" ]; then
                COVERAGE_FORMAT="html"
            fi
            generate_coverage "$COVERAGE_FORMAT"
            ;;
        clean)
            clean_tests
            ;;
        *)
            print_error "未知命令: $COMMAND"
            exit 1
            ;;
    esac
}

# 执行主函数
main "$@"
