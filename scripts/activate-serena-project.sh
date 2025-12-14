#!/bin/bash
# 激活 Serena 项目的脚本
# 
# 注意: 这个脚本通过修改配置文件来激活项目
# 推荐方式是在 Zed IDE 中使用 Serena 工具激活

set -e

PROJECT_NAME="AlkaidSYS-tp"
PROJECT_PATH="/workspace/projects/AlkaidSYS-tp"
CONFIG_FILE="/workspaces/serena/serena_config.docker.yml"

echo "╔══════════════════════════════════════════════════════════════════════╗"
echo "║                                                                      ║"
echo "║              激活 Serena 项目: ${PROJECT_NAME}                      ║"
echo "║                                                                      ║"
echo "╚══════════════════════════════════════════════════════════════════════╝"
echo ""

# 检查容器是否运行
if ! docker ps --format '{{.Names}}' | grep -q "^serena-serena-1$"; then
    echo "❌ Serena 容器未运行"
    echo "请先启动容器: cd /Users/Benson/Code/serena/serena && docker-compose up -d serena"
    exit 1
fi

echo "✅ Serena 容器运行正常"
echo ""

# 检查项目配置是否存在
if ! docker exec serena-serena-1 test -f "${PROJECT_PATH}/.serena/project.yml"; then
    echo "❌ 项目配置文件不存在: ${PROJECT_PATH}/.serena/project.yml"
    exit 1
fi

echo "✅ 项目配置文件存在"
echo ""

# 检查是否已经注册
if docker exec serena-serena-1 grep -q "name: ${PROJECT_NAME}" "${CONFIG_FILE}" 2>/dev/null; then
    echo "ℹ️  项目已经注册"
    echo ""
    echo "当前配置:"
    docker exec serena-serena-1 grep -A 2 "projects:" "${CONFIG_FILE}"
    echo ""
    echo "✅ 无需重复注册"
    exit 0
fi

echo "📝 注册项目到 Serena 配置..."
echo ""

# 备份配置文件
docker exec serena-serena-1 cp "${CONFIG_FILE}" "${CONFIG_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
echo "✅ 已备份配置文件"

# 使用 Python 脚本来正确修改 YAML
docker exec serena-serena-1 python3 << 'PYTHON_SCRIPT'
import yaml
from pathlib import Path

config_file = Path("/workspaces/serena/serena_config.docker.yml")

# 读取配置
with open(config_file, 'r') as f:
    config = yaml.safe_load(f)

# 添加项目
if 'projects' not in config:
    config['projects'] = []

# 检查是否已存在
project_exists = any(
    p.get('name') == 'AlkaidSYS-tp' 
    for p in config['projects']
)

if not project_exists:
    config['projects'].append({
        'name': 'AlkaidSYS-tp',
        'path': '/workspace/projects/AlkaidSYS-tp'
    })
    
    # 写回配置
    with open(config_file, 'w') as f:
        yaml.dump(config, f, default_flow_style=False, allow_unicode=True)
    
    print("✅ 项目已添加到配置")
else:
    print("ℹ️  项目已存在")

PYTHON_SCRIPT

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ 配置更新成功"
    echo ""
    echo "📋 当前注册的项目:"
    docker exec serena-serena-1 grep -A 5 "projects:" "${CONFIG_FILE}"
    echo ""
    echo "🔄 重启 Serena 容器以应用配置..."
    docker restart serena-serena-1
    echo ""
    echo "⏳ 等待容器启动..."
    sleep 10
    echo ""
    echo "✅ 项目激活完成!"
    echo ""
    echo "📊 验证:"
    echo "  1. 访问 Dashboard: http://localhost:24282/dashboard/index.html"
    echo "  2. 应该看到项目: ${PROJECT_NAME}"
    echo ""
else
    echo ""
    echo "❌ 配置更新失败"
    echo "请在 Zed IDE 中使用 Serena 工具激活项目"
    exit 1
fi

