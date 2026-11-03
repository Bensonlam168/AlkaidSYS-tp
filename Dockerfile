# syntax=docker/dockerfile:1.7
# =============================================================================
# AlkaidSYS 后端生产镜像（ER-18）
#
# 关键决策与依据（均可复现核对）：
#
# 1) EXPOSE 8080，不是 9501。
#    依据：config/swoole.php -> http.port = 8080；deploy/nginx/alkaid.api.conf
#    的 upstream 指向 `alkaid-backend:8080`；在运行中的容器内实测
#    `php -r 'fsockopen("127.0.0.1",8080)'` = open、9501 = Connection refused。
#    原 Dockerfile 的 EXPOSE 9501 是照抄基础镜像的默认演示服务端口，与实际
#    监听不符（9501 是 phpswoole 基础镜像自带 /var/www/server.php 的端口）。
#
# 2) 依赖层与源码层分离。
#    原写法 `COPY . .` 在 `composer install` 之前，导致改任意一行源码都要
#    重装全部依赖（本层约数百秒）。现在只把 composer.json/composer.lock
#    放进依赖层，源码改动只让后面的层失效。
#    注意：本项目**不需要**一并拷 `plugins/workflow-engine/composer.json` ——
#    根 composer.json 从未 require 该包，只做了 PSR-4 映射
#    （`Alkaid\Workflow\Engine\` -> plugins/workflow-engine/src/），
#    插件自身的依赖不参与根依赖解析。
#
# 3) composer 固定到 2.9.1，不再用 composer:latest。
#    依据：运行中容器内 `composer --version` = 2.9.1（2025-11-13）；
#    Docker Hub 上 `composer:2.9.1` 标签确已存在（registry 查询通过）。
#    latest 会让同一份 Dockerfile 在不同时间构建出不同镜像，不可重现。
#
# 4) 非 root 运行。
#    UID/GID 做成构建参数：本地开发是 bind mount 宿主机源码，容器用户必须
#    与宿主机属主对齐才能写 runtime/（Linux 上尤其明显，见 README/部署文档）。
#
# 5) 去掉 --ignore-platform-reqs。
#    原写法用它掩盖扩展缺失。实测在本镜像内
#    `composer check-platform-reqs --no-dev` 的 10 项 requirement
#    （php 8.2.29 / ext-bcmath / ext-swoole 5.1.8 / ext-pdo / ext-sockets ...）
#    全部 success，因此不再需要忽略平台检查 —— 保留它只会让「扩展真的缺了」
#    这件事在构建期静默通过、在运行期炸掉。
#
# 6) HEALTHCHECK 直连容器内 8080 的 /healthz（**liveness**）。
#    /healthz 是 route/app.php 注册的专用 liveness 探针（app\controller\HealthController），
#    **不查库、不读缓存业务键、不鉴权**，也不经 nginx，实测容器内 8080 直连返回 200。
#    它由全局中间件 app\middleware\HealthProbeBypass 在 SessionInit **之前**短路，
#    因此 Redis 抖动不会把它打成 500（否则 Docker/K8s 会把「Redis 抖动」当成「进程死了」）。
#    ⚠️ 不要在这里改成 /readyz：readiness 失败意味着「别给我流量」，不是「重启我」。
#       Dockerfile HEALTHCHECK 的语义与 liveness 一致（失败 → unhealthy），
#       readiness 只能在 K8s/Swarm 的 readinessProbe 里表达（见
#       docs/technical-specs/deployment/image-and-orchestration-production-requirements.md §6.1.2）。
#    历史用法是框架演示路由 /think —— 零业务价值的示例闭包，且本镜像
#    `ENV APP_ENV=production` 下它根本不会注册（route/app.php），因此不能继续依赖它。
#    对照：/v1/admin/permission-monitor/health 需要鉴权（401），不适合做探针。
#    基础镜像自带 /usr/bin/curl，这里仍显式 apt 安装 curl，避免依赖基础镜像
#    的「偶然具备」。
# =============================================================================

FROM phpswoole/swoole:5.1-php8.2

# 与宿主机对齐的 uid/gid（bind mount 场景下需可写 runtime/）
ARG UID=1000
ARG GID=1000

# composer 固定小版本：与容器内实测版本一致，杜绝 latest 漂移
COPY --from=composer:2.9.1 /usr/bin/composer /usr/bin/composer

# 以 root 构建是刻意的：依赖安装与 chown 需要；运行期才切到非特权用户
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# 第 1 层：系统依赖（变化频率最低，放最前，尽量命中缓存）
# - curl 供 HEALTHCHECK 使用
# - apt-get clean + 清空 lists 是为了压层体积：不清理会留下数十 MB 的包索引，
#   它们在运行期完全无用
# -----------------------------------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        curl \
    && docker-php-ext-install zip pdo pdo_mysql bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# 第 2 层：OPcache 配置
#
# 关于 validate_timestamps：这里**故意保持 1**。
#   think-swoole 的热重载（config/swoole.php -> hot_update，仅 APP_DEBUG=true
#   时启用）依赖按 mtime 轮询源码文件来触发 worker reload。把
#   opcache.validate_timestamps 设成 0 会让开发环境的「改完 2~5 秒自动生效」
#   直接失效，属于把开发体验搞坏。
#   生产环境由 docker-compose.prod.yml 用启动参数覆盖为 0（代码在镜像内不可变，
#   关闭 mtime 检查是安全的，且能省掉每次 stat 的开销）。
#
# 关于 enable_cli：PHP 默认 opcache.enable_cli=Off，而 `php think swoole` 跑的
#   正是 CLI SAPI —— 也就是说默认状态下 Swoole 服务**并没有**用上 OPcache。
#   这里同样不改默认值（保持与合并前行为一致），由生产 override 显式打开，
#   避免在开发环境引入「改代码后仍命中旧 opcode」的困惑。
# -----------------------------------------------------------------------------
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/zz-alkaid-opcache.ini

# -----------------------------------------------------------------------------
# 第 3 层：非特权用户
#
# 说明（为什么不是简单加一行 USER）：基础镜像的 ENTRYPOINT 是 /entrypoint.sh。
# 它按 CMD 是否为空判定 TASK/SERVICE 模式；本项目 CMD 非空，走 TASK 模式 ——
# 该模式下它只做两件事：把 /etc/supervisor/task.d/*.conf 拷进 conf.d（当前为空），
# 然后 `exec "$@"`。全程不需要 root，所以 USER 切换是安全的，PID 1 仍然是
# `php think swoole`（实测运行中容器 PID 1 就是 swoole manager，而非 supervisord）。
# 约束：不要把 AUTORELOAD_PROGRAMS / BLACKFIRE_* 等需要写 /etc/supervisor 的
# 环境变量带进来，否则非 root 下 entrypoint 会失败（见部署文档「已知约束」）。
# uid/gid 1000 在基础镜像内未被占用（实测 /etc/passwd 中无 >=1000 的普通用户）。
# -----------------------------------------------------------------------------
RUN groupadd -g "${GID}" alkaid \
    && useradd -u "${UID}" -g "${GID}" -m -d /home/alkaid -s /usr/sbin/nologin alkaid

# -----------------------------------------------------------------------------
# 第 4 层：依赖（只有 composer.json / composer.lock 变化才会失效）
#
# --no-autoloader --no-scripts 是必须的，两条理由不同：
#   1) 此刻 app/、domain/、infrastructure/、plugins/ 都还没进来，
#      `--optimize-autoloader` 会据此生成**残缺的 classmap**；
#   2) post-autoload-dump 是 `@php think service:discover` + `@php think vendor:publish`，
#      而这两条在构建期**一条都不能跑**：
#        - service:discover 会引导整个应用并触发 Redis 自检 → 构建必崩（详见第 5 层）；
#        - vendor:publish 只在 config/{queue,swoole,trace}.php **不存在**时才写入，
#          这三份配置都已随源码进版本库（实测输出 `File ... exist!`），
#          所以构建期跑它是空操作，跳过不会少任何文件。
# 于是：autoloader 推迟到源码就位后的下一个 RUN 生成；
#       services.php 推迟到容器启动时由 docker/docker-entrypoint.sh 生成。
# -----------------------------------------------------------------------------
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-autoloader \
        --no-scripts \
    && composer clear-cache

# -----------------------------------------------------------------------------
# 第 5 层：源码
# --chown 让运行期用户直接拥有源码；runtime/ 在 .dockerignore 里被排除，
# 必须在镜像内重建（否则 Swoole 启动即无写权限）。
# -----------------------------------------------------------------------------
COPY --chown=${UID}:${GID} . .

# --no-scripts 是**必须的**，不是「顺手省点时间」（RV-12）：
#   根 composer.json 的 post-autoload-dump 是 `@php think service:discover` +
#   `@php think vendor:publish`，而 `php think` 会把整个应用引导起来
#   （App::initialize() -> initializer/BootService -> $app->boot()）。app/service.php
#   注册的 RedisHealthCheckService 在 boot 阶段要做一次 Redis 读写自检，且
#   config/session.php 恒为 type=cache + store=redis —— 也就是说**这条自检在任何
#   环境都不跳过**。构建期没有 .env，也解析不到 compose 才有的 `redis` 主机名
#   → 自检抛异常 → 构建必然失败。不是「某些环境偶发」，是必然。
#   因此这里只生成 autoloader（源码已就位，classmap 是完整的），
#   services.php 的生成挪到容器启动时（docker/docker-entrypoint.sh，第 7 层）。
RUN composer dump-autoload --optimize --no-dev --no-interaction --no-scripts \
    && mkdir -p runtime \
    && chown -R "${UID}:${GID}" runtime

# -----------------------------------------------------------------------------
# 第 6 层：让运行期用户可写 vendor/services.php（RV-12）
#
# 为什么需要写权限：services.php 改由 entrypoint 在容器启动时生成（第 7 层），
# 而运行期用户是 alkaid、整个 vendor/ 是 root 所有（第 4 层 composer install 以
# root 执行）。两条路里选后者：
#   a) 整份 vendor/ chown 给 alkaid —— 运行期进程就能改写任何依赖代码，镜像的
#      可重现性直接作废；而且 chown -R 要给 vendor 下每个文件各记一条属主变更
#      （本地 vendor 实测 8132 个文件，生产 --no-dev 会少一些），层体积与构建时间都白涨；
#   b) **只预创建这一个文件**并 chown 它本身（本层做法）—— 不需要 vendor/ 目录的
#      写权限：file_put_contents() 覆盖一个**已存在**的文件只需要该文件的写权限。
#
# 为什么预置 `return [];` 而不是一个 0 字节空文件（这点很关键）：
#   - 0 字节文件会让框架 initializer/RegisterService 里的
#     `array_merge($services, include $file)` 拿到 int 1 → TypeError，
#     连 `php think service:discover` 自己都起不来（实测：include 空文件返回 1）；
#   - `return [];` 是合法数组，discover 可以正常引导并覆盖它；
#   - 它同时**不含任何服务**，所以 entrypoint 不能只看「文件存在」就跳过生成，
#     否则容器会带着空 services.php 启动 —— 而 `php think swoole` 命令根本不存在。
# -----------------------------------------------------------------------------
RUN printf '%s\n' \
        '<?php' \
        '' \
        '// 构建期占位：运行期由 docker/docker-entrypoint.sh 执行 php think service:discover 覆盖。' \
        'return [];' \
        > vendor/services.php \
    && chown "${UID}:${GID}" vendor/services.php \
    && chmod 0644 vendor/services.php

# -----------------------------------------------------------------------------
# 第 7 层：ENTRYPOINT —— 在容器启动时生成 vendor/services.php（RV-12）
#
# 必须在 `USER alkaid` **之前**：/usr/local/bin 是 root 所有的，切换用户后写不进去。
# 用 `COPY --chmod=755` 而不是再跟一条 `chmod`：少一层，且脚本权限在构建期就固定，
# 不取决于宿主机上那份文件的 umask/可执行位。
# 为什么是 entrypoint 而不是构建期生成，见第 5 层的说明（构建期引导应用必崩）。
#
# ⚠️ 本 ENTRYPOINT **取代**了基础镜像的 /entrypoint.sh。对 TASK 模式（CMD 非空，
#    本镜像正是这种）两者等价 —— 都以 `exec "$@"` 收尾；差别是基础镜像的
#    /usr/local/boot/*.sh 不再执行。那些脚本只在 SERVICE 模式（CMD 为空）、或设置了
#    AUTORELOAD_PROGRAMS / DISABLE_DEFAULT_SERVER / BLACKFIRE_* 时才做事，而第 3 层
#    已明确本镜像不使用这些开关。若将来要用，把 entrypoint 最后一行改成
#    `exec /entrypoint.sh "$@"` 委托回基础镜像即可。
# -----------------------------------------------------------------------------
COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# 生产默认值：开发环境由 docker-compose.yml 的 environment（APP_ENV=dev）覆盖，
# 且 .env 只会覆盖它自己定义过的键（Env::load 合并到 $_ENV 之上）。
ENV APP_ENV=production

USER alkaid

# 与 config/swoole.php http.port 及 nginx upstream 对齐（原值 9501 是错的）
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["php", "think", "swoole"]
