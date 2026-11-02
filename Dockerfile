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
# 6) HEALTHCHECK 直连容器内 8080 的 /healthz。
#    /healthz 是 route/app.php 注册的专用 liveness 探针（app\controller\HealthController），
#    **不查库、不读缓存业务键、不鉴权**，也不经 nginx，实测容器内 8080 直连返回 200。
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
# --no-autoloader --no-scripts 是必须的：此刻 app/、domain/、infrastructure/、
# plugins/ 都还没进来，
#   - `--optimize-autoloader` 会据此生成残缺的 classmap；
#   - post-autoload-dump 的 `php think service:discover` / `php think
#     vendor:publish` 也需要源码存在才能正确生成 vendor/services.php。
# 因此这两件事都推迟到源码就位后（下一个 RUN）执行。
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

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p runtime \
    && chown -R "${UID}:${GID}" runtime

# 生产默认值：开发环境由 docker-compose.yml 的 environment（APP_ENV=dev）覆盖，
# 且 .env 只会覆盖它自己定义过的键（Env::load 合并到 $_ENV 之上）。
ENV APP_ENV=production

USER alkaid

# 与 config/swoole.php http.port 及 nginx upstream 对齐（原值 9501 是错的）
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["php", "think", "swoole"]
