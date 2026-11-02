# 镜像与编排的生产要求（ER-18）

> 🔶 **实现状态**：**部分实现** —— 已实现：`Dockerfile` 层缓存拆分、composer 固定版本、非 root 运行、
> `HEALTHCHECK`、`EXPOSE` 端口纠正、`.dockerignore`、`docker-compose.yml` 加固（restart / healthcheck /
> 资源限制 / 端口收敛 / 凭据参数化）、`docker-compose.prod.yml` 生产 override。
> 未实现：镜像签名与 SBOM、CVE 扫描门禁、Kubernetes 编排清单、静态资源 CDN 化。

本文是 `deployment-guidelines.md` 在**容器层**的落地细则：上面那份讲「部署流程与可观测性」的原则，
本文讲「镜像怎么造、编排怎么配、怎么验证」。两者冲突时，以本文的容器专属结论为准。

---

## 1. 一页结论

| 维度 | 改造前 | 改造后 |
| --- | --- | --- |
| 构建上下文 | 2.7 GB（整个仓库） | 排除 `frontend/`、`vendor/`、`runtime/`、`.git/` 等，仅剩约 0.3 GB |
| 依赖层缓存 | `COPY . .` 在 `composer install` 之前，改一行源码就重装依赖 | `composer.json/lock` 单独成层，源码改动不再击穿依赖层 |
| Composer | `composer:latest`（不可重现） | `composer:2.9.1`（与运行中容器实测版本一致） |
| 运行用户 | root | 非 root（`alkaid`，UID/GID 可构建期配置） |
| 健康检查 | 无 | 五个服务全部有 `healthcheck`，`depends_on` 用 `service_healthy` |
| 崩溃恢复 | 无 `restart` | `restart: unless-stopped` |
| 外部端口 | MySQL/Redis/RabbitMQ 全部 `0.0.0.0` | 一律 `127.0.0.1`；生产 override 完全不发布 |
| 凭据 | 明文写在 compose 里 | 开发用弱默认值、生产用 `${VAR:?}` 强制显式注入 |
| 声明的服务端口 | `EXPOSE 9501`（**实际未监听**） | `EXPOSE 8080`（与 `config/swoole.php` 一致） |
| `.env` | 随 `COPY . .` 打进镜像 | 被 `.dockerignore` 排除 |

---

## 2. 端口事实：9501 是错的，8080 才对

这是改造中发现的最容易致命的缺陷：**原 `Dockerfile` 声明的 `EXPOSE 9501` 与实际监听端口不符。**

三条独立证据：

1. `config/swoole.php` → `http.port = 8080`；
2. `deploy/nginx/alkaid.api.conf` → `upstream swoole_backend { server alkaid-backend:8080; }`；
3. 在运行中的容器内实测：

```bash
# 8080 可连（open），9501 拒绝连接（Connection refused）
docker exec alkaid-backend php -r '$s=@fsockopen("127.0.0.1",8080,$e,$m,1); echo $s ? "open" : "closed";'
```

`9501` 的来源是基础镜像 `phpswoole/swoole` 自带的演示服务 `/var/www/server.php`
（该演示服务由 `/etc/supervisor/service.d/swoole.conf` 启动），与本项目无关。
compose 里原来的 `"9501:9501"` 映射一直是**空映射**——宿主机 9501 端口被占着，但容器内没有进程在听。

正确的调用链：

```
客户端 → nginx:80/443 → (proxy_pass) → backend:8080 (Swoole) → ThinkPHP 路由
```

`EXPOSE` 本身不影响连通性（Docker 不靠它开端口），但它会误导所有人——运维会去连 9501，
监控会探 9501，而 9501 永远是 refused。已改为 `EXPOSE 8080`。

---

## 3. 可重现构建

### 3.1 固定 Composer 版本

```dockerfile
COPY --from=composer:2.9.1 /usr/bin/composer /usr/bin/composer
```

固定理由：`composer:latest` 会让**同一份 Dockerfile 在不同日期构建出不同镜像**，
而 Composer 2.x 的小版本会改变依赖解析策略与自动加载产物。
`2.9.1` 与运行中容器内 `composer --version` 的输出完全一致。

### 3.2 依赖层与源码层分离

```dockerfile
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-autoloader --no-scripts
COPY --chown=${UID}:${GID} . .
RUN composer dump-autoload --optimize --no-dev --no-interaction
```

两个容易踩的点：

- **必须保留 `--no-autoloader --no-scripts`**：执行 `composer install` 时 `app/`、`domain/`、
  `infrastructure/`、`plugins/` 还没进镜像，此时做优化 autoload 会生成残缺 classmap，
  `post-autoload-dump` 里的 `php think service:discover` / `php think vendor:publish` 也会因为
  源码不存在而生成错误的 `vendor/services.php`。因此这两步推迟到 `COPY . .` 之后。
- **不需要拷 `plugins/workflow-engine/composer.json`**：根 `composer.json` 从未 `require`
  这个包，只把它做了 PSR-4 映射（`Alkaid\Workflow\Engine\` → `plugins/workflow-engine/src/`），
  插件自己的依赖不参与根依赖解析，拷进来也只会是无效层。
  （代价见 §10 的已知问题：插件源码里 `use DI\...`、`use madong\...` 那些类，
  根 autoload 并**没有**提供。）

### 3.3 去掉 `--ignore-platform-reqs`

原写法用它掩盖扩展缺失——缺扩展时构建照样成功，然后在运行期炸。

实测在本镜像内执行平台检查：

```bash
docker exec alkaid-backend composer check-platform-reqs --no-dev
```

10 项 requirement（`php 8.2.29`、`ext-bcmath`、`ext-swoole 5.1.8`、`ext-pdo`、`ext-sockets`、
`ext-fileinfo`、`ext-iconv`、`ext-json`、`ext-ctype`、`ext-mbstring`）**全部 success**。
因此 `--ignore-platform-reqs` 已移除——保留它只会在扩展真的缺失时静默通过。

---

## 4. `.dockerignore`：首要目的是防凭据泄露，其次才是体积

见仓库根 `.dockerignore`（每条都写了「不排除会怎样」）。这里只说最容易忽视的一条：

`COPY . .` 会把**本地 `.env` 一起打进镜像层**。`.env` 里是 `DB_PASS`、`REDIS_PASSWORD`、
`JWT` 密钥。镜像层是**只增不减**的：后续层即使 `rm` 掉，仍然可以用 `docker history` 或解包图层
把凭据捞出来。这不是「镜像大了点」的问题，是**凭据泄露且不可撤销**。

所以 `.dockerignore` 里 `.env` 与 `.env.*` 必须排除，只放行 `.env.example`、
`.env.production.example` 两个仅含占位符的模板。

体积方面（实测）：

```text
frontend/ 1.8G   runtime/ 299M   vendor/ 84M   plugins/workflow-engine/vendor/ 50M
docs/ 44M   .git/ 38M   node_modules/ 37M   graphify-out/ 19M   tests/ 1.7M
```

合计约 2.37 GB，占构建上下文 2.7 GB 的绝大部分。

---

## 5. 非 root 运行

### 5.1 为什么可以安全切换

基础镜像的 `ENTRYPOINT` 是 `/entrypoint.sh`。它按 CMD 是否为空判定模式：

- CMD 为空 → `SERVICE` 模式 → 走 supervisord；
- CMD 非空 → `TASK` 模式 → 依次 source `/usr/local/boot/*.sh`，最后 `exec "$@"`。

本项目 CMD 是 `["php","think","swoole"]`（非空），走 **TASK 模式**：boot 脚本做的是
「把 `/etc/supervisor/task.d/*.conf` 拷到 `conf.d/`」（当前该目录没有 `.conf`，等于空操作），
然后直接 `exec php think swoole`。全程不需要 root。

实测佐证：运行中容器的 **PID 1 就是 `php think swoole`（Swoole manager），而不是 supervisord**，
`/var/run/supervisord.pid` 不存在。所以 `USER alkaid` 不改变启动形态，只改变属主。

Swoole 监听 8080（>1024），非 root 绑定无特权端口限制。

### 5.2 `runtime/` 必须可写

`runtime/` 被 `.dockerignore` 排除，镜像里没有这个目录，Dockerfile 里显式重建并授权：

```dockerfile
RUN mkdir -p runtime && chown -R "${UID}:${GID}" runtime
```

### 5.3 bind mount 的属主问题（开发环境最需要注意）

`docker-compose.yml` 为了热重载仍然挂载源码 `.:/var/www/html`，此时**镜像内的 chown 不起作用**，
容器用户必须能写宿主机上那份 `runtime/`：

- **macOS / Docker Desktop**：bind mount 的属主由虚拟化层接管，容器用户通常可直接写，一般无需处理；
- **Linux**：UID 必须对齐。仓库里的 `runtime/` 若属主是 `uid=501`、容器用户是 `uid=1000`，
  Swoole 启动会因为无法写缓存而报错。两种解法（任选其一）：

```bash
# 解法 A：按宿主机 uid/gid 构建镜像
DOCKER_UID=$(id -u) DOCKER_GID=$(id -g) docker compose build backend

# 解法 B：把宿主机 runtime/ 交给容器用户
sudo chown -R 1000:1000 runtime/
```

### 5.4 已知约束：不要带这些环境变量

TASK 模式下 boot 脚本里有一个 `enable-blackfire-agent.sh`，它在检测到 `BLACKFIRE_SERVER_ID` /
`BLACKFIRE_SERVER_TOKEN` 时会调用 `update-token.sh` 去写 `/etc/supervisor/conf.d` —— 非 root 会失败。
同理 `autoreload.sh` 需要 `AUTORELOAD_PROGRAMS` 且会 `apt-get install inotify-tools`（需要 root）。

**结论：非 root 镜像不接受 `AUTORELOAD_PROGRAMS`、`BLACKFIRE_*` 这几个变量。**
热重载请用 `config/swoole.php` 自带的 `hot_update`（它由 `APP_DEBUG=true` 驱动，
不依赖基础镜像的 autoreload）。

---

## 6. 健康检查与启动顺序

### 6.1 探针选择

| 服务 | 探针 | 为什么是它 |
| --- | --- | --- |
| mysql | `mysqladmin ping -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" --silent` | MySQL 8 官方镜像自带 `mysqladmin`；`--silent` 让成功时无输出 |
| redis | `redis-cli ping` | 镜像自带；返回 `PONG` 且退出码 0 |
| rabbitmq | `rabbitmq-diagnostics -q ping` | 比 `rabbitmqctl status` 轻，3.12-management-alpine 自带 |
| backend | `curl -fsS http://127.0.0.1:8080/healthz` | 见下 |
| nginx | `wget -q -O /dev/null http://127.0.0.1/healthz` | 端到端：能反代到后端并拿到 200 才算就绪 |

**backend 为什么探 `/healthz` 而不是 `/`、也不经 nginx：**

- 经 nginx 探针会把「nginx 挂了」误判成「后端挂了」，且容器内不一定装了验证 nginx 的工具；
- `/healthz` 是 `route/app.php` 注册的**专用 liveness 探针**（`app\controller\HealthController`）：
  不查库、不读业务缓存、不鉴权、无副作用，返回统一响应结构
  `{"code":0,"message":"ok","data":{"status":"ok"},"timestamp":…,"trace_id":…}`，
  并带 `Cache-Control: no-store`（探针结果被缓存住就会掩盖真实的宕机）；
- 实测（`alkaid-backend` 容器内，直连 8080）：`GET /healthz` → **200**；经 nginx → **200**；
  同一次请求在 ORM 的 SQL 监听器里**零条 SQL**（`tests/Feature/HealthzProbeTest`）；
- 对照：`/v1/admin/permission-monitor/health` 需要鉴权，实测返回 **401**，不适合做探针。

**历史用法 `/think` 已废弃（原因：它生产环境根本不注册）：**

`/think` 是 ThinkPHP 骨架自带的演示闭包（返回 `hello,ThinkPHP8!`），零业务价值，
且会把「本服务是 ThinkPHP」无条件暴露给外部。自本次改动起 `route/app.php` 只在
**非生产**（`APP_ENV` 不是 `production/prod/stage/staging`）注册 `/think` 与 `/hello/:name`，
并同时由 `app\controller\Index` 在控制器层对生产环境返回 404
（`url_route_must=false` 时 `/index`、`/index/hello` 仍会被自动解析 —— 只删路由堵不住）。
因此 `HEALTHCHECK` 若继续探 `/think`，在生产镜像里会**稳定失败**。

**局限（直说，必须知道）：`/healthz` 只证明「worker 能响应」，不证明依赖可用。**

| 依赖 | 探针是否需要 | 实测结论 |
| --- | --- | --- |
| MySQL | ❌ 不需要 | 把 DB 指到 `127.0.0.1:1`（无人监听）后 `/healthz` 仍 **200** |
| Redis | ⚠️ **需要** | 把 Redis 指到 `127.0.0.1:1` 后 `/healthz` 变 **500**（`RedisException: Connection refused`） |

Redis 这条硬依赖不是 `HealthController` 造成的，而是**全局中间件**造成的：
`app/middleware.php` 里的 `think\middleware\SessionInit` 会在每个请求上
`GET` / `DEL` 一个 `alkaid:session:<id>` 键（`config/session.php` 的
`type = cache`、`store = redis`），`redis-cli monitor` 实测可见；Redis 一挂，
它在**路由之前**就抛异常，任何控制器都救不回来。

结论与建议：

- 当前探针的语义其实是「worker + 会话存储都活着」。在 `docker compose` 下这只会让容器显示
  `(unhealthy)`（Docker 本身不会因 unhealthy 重启容器），影响的是监控告警与
  `depends_on: service_healthy` 的启动门；
- **但在 K8s / Swarm 里，liveness 失败会触发重启** —— 那会把「Redis 抖动」放大成
  「全部后端实例重启」。上 K8s 前必须二选一：
  1. 让探针绕过 `SessionInit`（在 `app/middleware.php` 的最前面加一个
     `HealthProbeBypass` 中间件，命中 `/healthz` 就直接返回 200）——
     这需要改动全局中间件注册，属于独立任务，本次**未做**；
  2. 把 `/healthz` 只配置成 **readiness** 探针，另配一个不依赖应用的存活探针
     （例如 `nc -z 127.0.0.1 8080`，只证明 Swoole 端口在听）。
- 无论如何都**不要**把 `/healthz` 当成「数据库/Redis 可用」的证据 ——
  那需要单独的 readiness 端点（逐项 check + 各自超时），当前没有。


### 6.2 `start_period` 为什么给这么长

| 服务 | start_period | 依据 |
| --- | --- | --- |
| mysql | 40s | 首次启动要初始化数据目录，冷启动常超过 20s |
| rabbitmq | 40s | Erlang 节点启动 + 元数据初始化 |
| backend | 60s | Swoole 冷启动 + DB/Redis 连接池预热 |
| nginx | 30s | 依赖 backend healthy，自身启动很快 |

`start_period` 内的失败不计入 `retries`，因此给足宽限不会让「慢启动」被误判成「不健康」。

### 6.3 级联语义（这是刻意的）

`nginx` 的探针是端到端的，所以**后端不健康时 nginx 也会被标成 unhealthy**。
这不是 bug：此时网关对外确实不可用，标成 unhealthy 才能被编排层/告警正确识别。

---

## 7. 资源限制

用 `deploy.resources.limits`（Compose v2 在非 swarm 模式下同样生效），数值依据：

| 服务 | 开发 | 生产 | 依据 |
| --- | --- | --- | --- |
| mysql | 1.5 CPU / 1 GB | 2 CPU / 2 GB | MySQL 8 官方建议 `innodb_buffer_pool_size` 约占可用内存 50%~70%；1 GB 上限下 buffer pool 约 512 MB，够本地与 CI 使用 |
| redis | 0.5 CPU / 256 MB | 1 CPU / 512 MB | 本项目 Redis 只做缓存/会话/锁，无大对象 |
| rabbitmq | 1 CPU / 512 MB | 1.5 CPU / 1 GB | Erlang VM 本身有基础内存占用 |
| backend | 2 CPU / 1 GB | 4 CPU / 2 GB | `worker_num = swoole_cpu_num()`；1 GB 覆盖 ThinkPHP + 连接池常驻 |
| nginx | 0.5 CPU / 128 MB | 1 CPU / 256 MB | 纯反向代理，无本地静态大文件服务 |

生产按实际 QPS 与数据量上调；上调前先看容器实际用量，不要凭感觉给。

---

## 8. 凭据管理

### 8.1 一个必须知道的坑：`.env` 会覆盖容器环境变量

ThinkPHP 的 `Env` 先取 `$_ENV`（容器环境变量），然后用 `.env` 文件的同名键**覆盖**
（`vendor/topthink/framework/src/think/Env.php`：`__construct()` 里 `$this->data = $_ENV;`，
`load()` 里 `$this->set($env)` 直接赋值）。

推论：**只要容器里存在 `.env` 且里面写了某个键，compose 的 `environment:` 对该键就无效。**
这也是为什么生产 override 要同时做两件事 —— 不挂源码（容器内没有 `.env`），
并显式 `APP_DEBUG: "false"`。只做后者是不够的。

### 8.2 三层策略

| 环境 | 做法 |
| --- | --- |
| 开发 | `docker-compose.yml` 里用 `${VAR:-开发默认值}`，默认值与改造前一致（`root`/`alkaid123`），开箱即用 |
| 生产·必填凭据 | `docker-compose.prod.yml` 用 `${VAR:?错误信息}`，变量缺失时 compose 直接报错退出，**不会**回落到弱默认值 |
| 应用配置 | 生产用 `env_file: .env.production`（运维从 `.env.production.example` 复制后填写，已被 `.gitignore` 的 `.env.*` 排除） |

验证 fail-fast 是否真的生效（下面这条**必须**失败，退出码非 0）：

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml config
# error while interpolating services.mysql.environment.MYSQL_PASSWORD:
#   required variable MYSQL_PASSWORD is missing a value: 生产环境必须显式提供 MYSQL_PASSWORD
```

### 8.3 仓库里不允许出现真实凭据

改造前 `docker-compose.yml` 里明文写着 `MYSQL_ROOT_PASSWORD: root`、`MYSQL_PASSWORD: alkaid123`、
`RABBITMQ_DEFAULT_PASS: alkaid123`。现在这些位置全部变成 `${VAR:-...}` 引用，
仓库里只剩**开发默认值**，真实口令一律走环境变量或 `env_file`。

---

## 9. 生产 override

启动方式（两个 `-f` 顺序不能反，后者覆盖前者）：

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

叠加后生效的差异：

| 项 | 开发 | 生产 override |
| --- | --- | --- |
| 源码挂载 | `.:/var/www/html` | **清空**（`!reset []`），跑镜像内代码 |
| 后端端口 | `127.0.0.1:8080` | **完全不发布** |
| MySQL/Redis/RabbitMQ 端口 | `127.0.0.1:port` | **完全不发布**，仅容器网络可达 |
| `APP_ENV` | `dev` | `production` |
| `APP_DEBUG` | 由 `.env` 决定（true） | 强制 `"false"` |
| OPcache | 默认（CLI 未启用） | `enable_cli=1`、`validate_timestamps=0` |
| 凭据 | 弱默认值 | `${VAR:?}` 强制注入 |
| 日志 | 默认 json-file 无上限 | `max-size=20m`、`max-file=5` |
| `security_opt` | 无 | `no-new-privileges:true` |
| 资源上限 | 见 §7 | 见 §7（更严） |

### 9.1 为什么用 `!reset` / `!override`

Compose 对 `ports`、`volumes` 这类**列表**默认是「追加并集」，override 里写一个更短的列表
并不会删掉基文件里的条目。要真正删掉必须显式声明：

- `ports: !reset []` —— 清空；
- `volumes: !override [...]` —— 整体替换。

这一点很容易写错：如果只在 prod 里写 `volumes: [配置文件]`，源码挂载**仍然在**，
生产容器会悄悄跑宿主机上的代码，回滚也就无从谈起。

### 9.2 OPcache 与热重载的关系（别把开发体验搞坏）

- 开发环境：`opcache.validate_timestamps=1`（镜像默认）。`config/swoole.php` 的 `hot_update`
  依靠文件 mtime 变化触发 worker reload，把 mtime 校验关掉会让「改完 2~5 秒自动生效」失效。
- 生产环境：`opcache.validate_timestamps=0`。代码在镜像内不可变，关闭 mtime 校验安全且更快。
- CLI 陷阱：`opcache.enable_cli` 默认 **Off**，而 `php think swoole` 跑的是 CLI SAPI ——
  不显式打开，OPcache 对 Swoole 服务**完全不起作用**。生产 override 通过启动参数打开：

```yaml
command: ["php", "-d", "opcache.enable_cli=1", "-d", "opcache.validate_timestamps=0", "think", "swoole"]
```

用 `-d` 传参而不是再挂一个 ini 文件，是为了少一个需要同步维护的产物。

---

## 10. 本地开发兼容方案

改造后端口只绑 `127.0.0.1`，**宿主机直连方式完全不变**：

```bash
mysql -h 127.0.0.1 -P 3306 -u root -p            # 仍然可用
redis-cli -h 127.0.0.1 -p 6379                   # 仍然可用
curl http://127.0.0.1/healthz                    # 经 nginx（80）
curl http://127.0.0.1:8080/healthz               # 直连后端 Swoole（绕过 nginx）
```

变的只是「同局域网的其他机器连不上了」，而这正是目的。

### 10.1 端口冲突怎么办

仓库里已有被 gitignore 的 `docker-compose.override.local.yml`，用于规避宿主机 6379 被
别的项目占用的情况。改造后请把它也写成只绑环回的形式：

```yaml
services:
  redis:
    ports: !override
      - "127.0.0.1:16379:6379"
```

（注意 `!override` 是必需的 —— 写成 `ports: [...]` 会与基文件的列表合并，
6379 依然被占用，这正是该文件头部注释已经记录过的坑。）

### 10.2 需要从别的机器/手机联调

只把 nginx 暴露出去即可，不要去放开数据库端口：

```bash
# nginx 仍绑 0.0.0.0（80/443），其它机器访问 http://<你的局域网IP>/healthz
```

---

## 11. 如何验证（上生产前必须实际执行）

以下命令**都不会**重建镜像，因此只做静态校验；带 ⚠️ 的必须在允许重建的环境里跑。

```bash
# 1) 编排语法与合并结果
docker compose config                                   # 开发
docker compose -f docker-compose.yml -f docker-compose.prod.yml config   # 生产（应因缺凭据而失败）

# 2) Dockerfile 静态检查（BuildKit，不产出镜像）
docker buildx build --check .

# 3) ⚠️ 真正构建一次，确认依赖层与源码层能跑通、services.php 能生成
docker compose build backend

# 4) ⚠️ 用新镜像起一套隔离实例（不要复用正在跑的容器名）
docker compose -p alkaidverify up -d
docker compose -p alkaidverify ps                    # 五个服务应全部 (healthy)
docker compose -p alkaidverify exec backend id       # 应显示 uid=1000 而非 root
docker compose -p alkaidverify exec backend php -r 'var_dump(is_writable("/var/www/html/runtime"));'
docker compose -p alkaidverify down -v

# 5) ⚠️ 生产镜像里不应存在 .env 与凭据
docker run --rm --entrypoint sh <image> -c 'ls -la /var/www/html/.env || echo "OK: no .env"'
docker history <image> --no-trunc | grep -i -E "password|secret" || echo "OK: no obvious secret in history"
```

第 4 步的 `ps` 是本次改造最需要人工确认的一项：`service_healthy` 的依赖关系、
健康探针、非 root 权限**只有真正起一次才知道对不对**。

---

## 12. 已知问题与未做项（直说）

1. **这些改动要生效必须重建镜像。** 本次改造只产出「产物 + 静态验证」，
   运行中的 `alkaid-backend` 仍跑旧镜像，`docker ps` 的 StartedAt 未变。
   `docker compose` 相关能力（healthcheck / restart / 端口收敛）也要在**下次 recreate** 时才生效。
2. **workflow-engine 插件在镜像内无法自动加载。** 插件源码 `use DI\Container`、
   `use madong\ingenious\...`，这些类只存在于 `plugins/workflow-engine/vendor/`（已在
   `.dockerignore` 中排除），而根 autoload **既没有 require 该包，也没有注册它的 autoloader**
   （实测 `vendor/composer/autoload_psr4.php` 无相关条目，全仓库也没有
   `require 'plugins/workflow-engine/vendor/autoload.php'`）。因此排除它与「不排除」行为一致。
   但若将来要真正启用 workflow 引擎，正确做法是把插件作为 path repository 加进根
   `composer.json` 的 `require`，让依赖统一进根 `vendor/` —— 这属于应用/依赖变更，不在 ER-18 范围。
3. **~~`.env.production.example` 缺 `DB_USER` / `DB_PASS` / `RABBITMQ_*`~~ —— 已修复（#3）。**
   该模板曾只有 `DB_HOST`、`REDIS_*`、`JWT_*` 等键，比 `.env.example` 少 12 个键，其中最危险的三个是：
   `DB_USER` / `DB_PASS` 缺失会让 `env('DB_USER', 'root')` 的默认值生效（**生产以 root、空口令连库**）；
   `DEFAULT_TENANT_ID` 缺失会让全局中间件 `TenantIdentify` fail-closed 抛异常
   （**所有不带 `X-Tenant-ID` 的请求，包括 `/healthz` 探针，全部 500**，容器永远 unhealthy）；
   `TRUSTED_PROXIES` 缺失会让经反代且开启限流的部署**全站共用一个 ip 桶**。
   更糟的是，**当时那份模板连 `parse_ini_file` 都过不去**：`#` 注释行里有半角括号与竖线，
   而 `think\Env::load()` 的实现是「解析失败即按空数组兜底」——照抄模板上生产会得到一份
   **所有键静默失效**的配置。

   现状（2026-11 修复）：
   - 模板已补齐全部 12 个键，并按 `.env.example` 的分组结构排列，每个键都注明了「不配会怎样」；
   - `DEFAULT_TENANT_ID` 的占位值刻意写成**非数字**（`REPLACE_WITH_REAL_TENANT_ID`），
     忘记替换时走 fail-closed（每个请求 500，立刻可见），而不是静默把流量归到租户 1；
   - 注释行里的半角括号/引号/竖线/与号/美元符号/等号已全部清除（前四个会让解析失败，最后一个是伪键）；
   - `scripts/check-env-example.php` 新增**检查 E**：`.env.example` 的每个键都必须出现在
     `.env.production.example` 里（单向），同时校验生产模板可解析、无伪键、注释规范。
     门禁 `Env Example Consistency` 因此同时守住两份模板，模板再也不可能「悄悄缺键」。

   **仍未覆盖（属于 compose 插值变量，不在本模板内）**：`docker-compose.prod.yml` 用 `:?` 语法强制要求
   `MYSQL_ROOT_PASSWORD` / `MYSQL_USER` / `MYSQL_PASSWORD` / `RABBITMQ_DEFAULT_USER` / `RABBITMQ_DEFAULT_PASS`。
   它们是 **compose 的插值变量**，只从 shell 环境或 compose 的项目级 `.env` 读取；
   写进 `.env.production` 不会满足它们，因为后者是容器的 `env_file`。详见 §8.2 的三层策略。
4. **`/healthz` 已落地（本次），但它不是纯 liveness 探针**：全局 `SessionInit` 会读 Redis 会话，
   因此 Redis 故障时探针会 500（实测，见 §6.1 的「局限」）。上 K8s 前必须先决定
   「探针绕过 SessionInit」还是「只当 readiness 用」，否则一次 Redis 抖动会变成全实例重启。
   旧用法 `/think` 已废弃：它在生产环境不再注册（`route/app.php` + `app\controller\Index` 双保险）。
5. **nginx 的 `root /var/www/alkaid/public` 在生产 override 下指向空目录**（源码挂载被清掉）。
   由于现在所有请求都 `proxy_pass` 到后端，实际不影响 API；但 `location ~* \.(jpg|...)$`
   这类静态资源规则会 404。静态资源应改由 CDN / 对象存储提供，属于后续工作。
6. **未做**：镜像签名（cosign）、SBOM、CVE 扫描门禁、多架构构建（当前仅 `linux/amd64`）、
   K8s 清单、日志驱动对接（ELK/Loki）。
7. **凭据仍是「环境变量」级别，不是 secrets 级别。** 环境变量会出现在
   `docker inspect`、`/proc/1/environ` 里。更强的方案是 Docker secrets / Vault，
   需要编排层支持，本次未做。

---

## 13. 相关文档

- [部署与运维指南](./deployment-guidelines.md) —— 部署流程、日志与可观测性的原则
- [部署与运维指南（中文）](./deployment-guidelines.zh-CN.md)
