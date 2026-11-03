#!/bin/sh
# =============================================================================
# AlkaidSYS 后端镜像入口（RV-12）
#
# 职责只有一件：保证 vendor/services.php 在 `php think swoole` 启动之前就存在。
#
# 为什么需要它（缺了会怎样）：
#   vendor/services.php 是 `php think service:discover` 的产物，里面登记着 4 个
#   **vendor 包**服务（think\migration\Service、think\queue\Service、
#   think\swoole\Service、think\trace\Service）。框架的
#   vendor/topthink/framework/src/think/initializer/RegisterService.php 在
#   `is_file()` 为真时把它 include 进来注册 —— 文件不存在时**不会报错**，
#   只是静默少注册这几个服务，结果是 `php think swoole` 这个命令根本不存在，
#   容器起来就退出。所以「缺文件」必须是启动期的硬错误，不能靠运气。
#
# 为什么在运行期生成，而不是在构建期：
#   `php think service:discover` 不是「读一下 installed.json 然后写文件」这么轻 ——
#   它是 composer post-autoload-dump 里的 `@php think ...`，会先把整个 ThinkPHP
#   应用引导起来（App::initialize() → initializer/BootService → $app->boot()），
#   而 app/service.php 注册的 RedisHealthCheckService 在 boot 阶段要做一次 Redis
#   读写自检（config/session.php 恒为 type=cache + store=redis，因此这条自检
#   在任何环境都会跑）。构建期既没有 .env、也解析不到 compose 才有的 `redis`
#   主机名 → 自检抛异常 → `docker build` 必然失败（RV-12 就是这么来的）。
#   运行期则相反：docker-compose.yml 的 backend 用
#   `depends_on: redis: condition: service_healthy` 保证 Redis 先就绪。
#
# 幂等性（重要）：
#   已有可用的 services.php 时**不**执行 discover —— 否则每次容器启动都会多一次
#   完整的应用引导（含一次 Redis 读写自检），也会让「重启即换一份生成的代码」
#   这种不可重现行为悄悄发生。
#
# 失败语义：
#   `set -e` + 生成后自检。任何一步失败都以非 0 退出，**不吞错误** ——
#   带病启动（没有 services.php 就没有 swoole 命令，或被框架静默降级）比
#   启动失败更难排查；配合 compose 的 `restart: unless-stopped` 还能自愈重试。
#
# 与基础镜像 /entrypoint.sh 的关系：
#   本脚本**取代**了基础镜像的 /entrypoint.sh。基础镜像在 TASK 模式
#   （CMD 非空，本镜像正是这种）下最终也是 `exec "$@"`，它额外做的是加载
#   /usr/local/boot/*.sh（AUTORELOAD_PROGRAMS / DISABLE_DEFAULT_SERVER /
#   BLACKFIRE_* 等开关，均为空操作，见 Dockerfile 第 3 层的约束）。
#   若将来确实需要那些 boot 脚本，把最后一行改成 `exec /entrypoint.sh "$@"` 委托回去。
# =============================================================================
set -eu

# 与镜像 WORKDIR 一致。显式 cd 是为了不依赖调用方的工作目录
# （`php think` 需要在应用根目录下执行才能找到 ./think）。
cd /var/www/html

SERVICES_FILE="vendor/services.php"

log() {
    printf '[entrypoint] %s\n' "$*"
}

# 判断 services.php 是否「已经可用」：存在、非空、且 include 出来是**非空数组**。
# 为什么不只看文件是否存在：构建期会预置一个 `<?php return [];` 的占位文件
# （见 Dockerfile 第 6 层）。只看存在性就会把它当成已就绪，于是容器带着
# 「没有 swoole 服务」的状态启动 —— 而 `php think swoole` 此时并不存在。
services_ready() {
    [ -s "$SERVICES_FILE" ] || return 1
    # 注意 stdout 也要吞掉：include 一个语法错误的文件时，PHP 会先把已读到的内容
    # 打到 stdout 再报 parse error，只重定向 stderr 会把文件内容泄进容器日志。
    php -r '$s = @include $argv[1]; exit((is_array($s) && [] !== $s) ? 0 : 1);' "$SERVICES_FILE" >/dev/null 2>&1
}

# 空命令直接报错，而不是「什么都不做、exit 0」：
# 基础镜像的空 CMD 会走 SERVICE 模式（supervisord），本镜像不实现该模式，
# 静默成功会给编排层一个「容器正常退出」的假象。
if [ "$#" -eq 0 ]; then
    log "错误：没有收到任何要执行的命令（CMD 或 compose command 为空）" >&2
    exit 1
fi

if services_ready; then
    log "vendor/services.php 已就绪，跳过 service:discover（幂等）"
else
    log "vendor/services.php 缺失或为空，执行 php think service:discover"

    # discover 引导应用时，框架会 include 这个文件（initializer/RegisterService）。
    # 若它是半截/损坏的 PHP，include 会致命错误 —— 连 `php think service:discover`
    # 自己都起不来，而且下次启动还是同一个坏文件，会变成永久 crash loop
    # （file_put_contents 不是原子写，进程正好在写这 200 多字节时被杀就会这样）。
    # 所以先把「可 include 且返回数组」这件事兜住：不满足就覆盖成最小合法占位。
    # 覆盖**已存在**的文件只需要该文件自身的写权限，不需要 vendor/ 目录的写权限
    # （Dockerfile 第 6 层已把它 chown 给运行期用户，正是为此）。
    if ! php -r '$s = @include $argv[1]; exit(is_array($s) ? 0 : 1);' "$SERVICES_FILE" >/dev/null 2>&1; then
        printf '%s\n' '<?php' 'return [];' > "$SERVICES_FILE"
    fi

    php think service:discover

    # 生成后自检。ServiceDiscover 在 vendor/composer/installed.json 缺失时
    # **什么都不写却仍然 exit 0**，只信退出码会漏掉这种情况。
    if ! services_ready; then
        log "错误：service:discover 没有生成可用的 ${SERVICES_FILE}（请检查 vendor/composer/installed.json 是否存在、vendor/ 是否可写）" >&2
        exit 1
    fi

    log "vendor/services.php 生成完成"
fi

# 透传 CMD / compose 的 `command:`，必须用 "$@" 保留参数边界
# （生产 override 会传入 `php -d opcache.enable_cli=1 ... think swoole`）。
exec "$@"
