# deploy/nginx 目录说明（T2-NGINX-GW 骨架）

本目录用于存放 AlkaidSYS 在 **T2-NGINX-GW** 任务中的 Nginx 网关配置骨架，作为后续网关接入、限流、灰度、访问日志等能力的基础。

目前包含：

- `alkaid.api.conf`：API 网关入口的 Nginx 配置骨架；
- （后续可选）如 `alkaid.ratelimit.conf`、`alkaid.upstream.conf` 等扩展配置。

## 1. 配置文件用途

`alkaid.api.conf` 用于：

- 为后端 Swoole HTTP 服务（端口来自 `config/swoole.php`，默认 8080）提供统一的域名入口；
- 在 Nginx 层完成 HTTP→HTTPS 跳转、基础 SSL 配置、静态资源缓存与 Gzip；
- 为后续的限流、灰度、健康检查等能力预留扩展点（通过注释中的 include 示例等方式）。

## 2. 多环境使用说明

### 2.1 本地开发（dev/local）

- 推荐域名：`api.alkaidsys.local`（可在 hosts 中指向 127.0.0.1）；
- 可在 `server_name` 中保留 `api.alkaidsys.local`；
- 如暂不使用 HTTPS，可：
  - 临时关闭 80→443 的重定向（注释掉第一个 `server { listen 80; ... return 301 ...; }`）；
  - 或参考配置中的注释，启用仅监听 80 端口并直接 `proxy_pass` 到 `swoole_backend` 的示例。

### 2.2 测试 / 预发（stage/testing）

- 推荐域名示例：
  - `api.test.alkaidsys.com`
  - `api.stage.alkaidsys.com`
- 建议开启 HTTPS，并在 80 端口上保留 301 跳转至 HTTPS；
- 在 `upstream swoole_backend` 中：
  - 将示例 `127.0.0.1:8080` 调整为实际后端 Swoole 实例地址；
  - 如有多实例，可增加多行 `server <ip>:8080 ...;` 以实现简单负载均衡；
- 日志路径可根据运维规范调整（例如写入到特定挂载卷）。

### 2.3 生产环境（prod）

- 推荐域名：`api.alkaidsys.com`；
- 必须启用 HTTPS，并谨慎配置 SSL 证书与协议；
- 建议：
  - 在 80 端口仅保留 `return 301 https://$host$request_uri;`；
  - 在 443 端口启用 `http2`、`TLSv1.2/TLSv1.3`、合理的 `ssl_ciphers`；
  - 对 access_log 进行切割与采集，纳入统一日志/监控系统；
- 如需接入 WAF / 全局 LB，可将本配置作为下游 Nginx 层的基础模板。

## 3. 需要替换的占位符清单

在实际部署前，请根据环境替换以下占位符：

- 域名相关：
  - `api.alkaidsys.local`
  - `api.test.alkaidsys.com`
  - `api.stage.alkaidsys.com`
  - `api.alkaidsys.com`
- SSL 证书路径：
  - `ssl_certificate /etc/nginx/ssl/REPLACE_ME_fullchain.crt;`
  - `ssl_certificate_key /etc/nginx/ssl/REPLACE_ME_privkey.key;`
- 后端地址：
  - `upstream swoole_backend` 中的 `server 127.0.0.1:8080 ...;` 行，应按实际 Swoole 服务 IP/端口调整；
- 日志路径（可选）：
  - `access_log /var/log/nginx/alkaid_api_access.log;`
  - `error_log  /var/log/nginx/alkaid_api_error.log warn;`
- 静态资源根路径：
  - `root /var/www/alkaid/public;` 如实际部署路径不同需同步调整。

## 4. 与部署文档的关系

本目录中的配置与以下设计/部署文档保持一致：

- `design/05-deployment-testing/14-deployment-guide.md`：
  - 该文档中的 **“Nginx 配置”** 小节提供了完整示例，本骨架是在其基础上的抽取与泛化；
  - 端口与后端角色（Nginx 作为网关，Swoole 作为应用服务器）的关系保持一致；
- `docs/todo/refactoring-plan.md` 中 T2-NGINX-GW 任务：
  - 本目录可视为该任务的「配置起点」，后续在限流/灰度/访问日志等能力落地时，可继续在该目录下拆分更细粒度的配置文件。

## 5. 预留的扩展点

在 `alkaid.api.conf` 中，通过注释形式预留了以下扩展点：

- **限流/灰度**：
  - 在 `location /` 内通过 `include /etc/nginx/conf.d/alkaid.ratelimit.conf;` 等方式引入后续限流/灰度策略；
- **健康检查**：
  - 可根据需要在 upstream 中增加 `max_fails` / `fail_timeout` 参数，或在上游 LB 层做健康检查；
- **访问日志增强**：
  - 已在 `alkaid.api.conf` 中通过 `log_format alkaid_api_json` 定义 JSON 格式访问日志，记录 trace-id、userId、tenantId、siteId、rate_limited 等字段，并作为 80/443 端口 server 的 access_log 默认格式；
  - 后续如需将不同类型的流量拆分到多份日志，可在本目录中新增独立的 `*.log.conf` 文件，并在主配置中通过 `include` 方式接入。

后续如需进一步细化，可在本目录中新增独立的 *.conf 文件，并在 Nginx 主配置中通过 `include` 方式接入。

## 6. Docker Compose 集成示例

以下示例展示如何在现有 `docker-compose.yml` 基础上增加一个 Nginx 服务，并将本目录中的配置文件挂载到容器内，适用于 **dev/local 环境快速验证**。

> ⚠️ 注意：本示例仅用于本地开发 / 验证。在 stage/prod 环境，建议使用独立 Nginx 部署或 Kubernetes Ingress，并由运维团队根据实际架构调整。

### 6.1 示例 docker-compose 片段

```yaml
version: '3.8'

services:
  # 现有服务（节选）
  mysql:
    image: mysql:8.0
    container_name: alkaid-mysql

  redis:
    image: redis:7.2-alpine
    container_name: alkaid-redis

  rabbitmq:
    image: rabbitmq:3.12-management-alpine
    container_name: alkaid-rabbitmq

  backend:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: alkaid-backend
    depends_on:
      - mysql
      - redis
      - rabbitmq
    # Swoole HTTP 端口（需与 config/swoole.php 中 http.port 保持一致，默认 8080）
    ports:
      - "9501:9501"   # 示例：保留原有 CLI/调试端口
    volumes:
      - .:/var/www/html

  # 新增 Nginx 网关服务（dev/local 验证用）
  nginx:
    image: nginx:1.24-alpine
    container_name: alkaid-nginx
    depends_on:
      - backend
    ports:
      - "80:80"
      - "443:443"
    volumes:
      # 将骨架配置挂载到默认站点配置位置
      - ./deploy/nginx/alkaid.api.conf:/etc/nginx/conf.d/default.conf:ro
      # 将代码目录中的 public 目录挂载到容器内，以便服务静态资源
      - .:/var/www/alkaid:ro
      # 可选：将本地证书目录挂载到容器内，替换 alkaid.api.conf 中的证书路径
      # - ./deploy/nginx/certs:/etc/nginx/ssl:ro
```

> 说明：
> - 上述片段中仅保留与 Nginx 相关的关键配置，实际项目中可直接在现有 `docker-compose.yml` 中追加 `nginx` 服务段；
> - `backend` 服务的容器名为 `alkaid-backend`，Nginx 通过 `127.0.0.1:8080` 或 docker 网络内部地址访问 Swoole 端口，具体取决于 Swoole 启动方式（容器内还是宿主机）；
> - 若 Swoole 运行在 `backend` 容器内并监听 `0.0.0.0:8080`，可在 upstream 中使用 `alkaid-backend:8080` 作为后端地址，并确保 Nginx 和 backend 处于同一 docker 网络。

### 6.2 启动与验证

1. 启动完整服务栈（含 Nginx）：

   ```bash
   docker-compose up -d
   ```

2. 在本机 hosts 文件中添加本地域名映射（以 macOS/Linux 为例）：

   ```bash
   sudo sh -c 'echo "127.0.0.1 api.alkaidsys.local" >> /etc/hosts'
   ```

3. 通过浏览器或 curl 访问：

   ```bash
   curl -k -sS -D - "https://api.alkaidsys.local/debug/session-redis"
   ```

   - 若配置正确，请求将通过 Nginx 转发到 backend 中的 Swoole/HTTP 服务；
   - 返回结果应与直接访问 Swoole/HTTP 服务时一致（参考部署指南中的验收步骤）。

### 6.3 注意事项

- 本示例默认：
  - backend 容器中运行 Swoole HTTP 服务，监听 `0.0.0.0:8080`；
  - Nginx 与 backend 处于同一 Docker 网络（默认 bridge 网络已满足，除非显式修改）。
- 如使用的是 PHP-FPM 而非 Swoole，可将 upstream 和 `proxy_pass` 调整为 FastCGI/Unix Socket 形式，本示例不展开。
- 在 stage/prod 环境：
  - 建议由运维团队根据实际网络与安全要求，选择更合适的部署方式（独立 Nginx、Kubernetes Ingress、云负载均衡等）；
  - 本示例仅作为本地验证与 PoC 的参考，并非生产部署模板。


## 7. Nginx 运维与迁移指南（dev/local → stage → prod）

本节面向运维 / SRE，说明在不同环境下的典型部署形态、端口映射关系以及从“直连 backend”迁移到“统一从 Nginx 入口访问”的推荐步骤与回滚预案。

### 7.1 dev/local 环境

- 推荐域名：`api.alkaidsys.local`（在本机 `/etc/hosts` 中映射到 `127.0.0.1`）。
- 典型端口链路：
  - 宿主机 80 → Nginx 容器 80 → upstream `swoole_backend`（`alkaid-backend:8080`）。
  - 如需本地 HTTPS，可额外挂载自签名证书，并在 443 端口启用 `ssl_certificate` 配置。
- 访问示例：
  - `http://api.alkaidsys.local/debug/session-redis`
  - 或结合 hosts：`curl -sS -D - "http://api.alkaidsys.local/debug/session-redis"`
- 建议：
  - dev/local 可仅启用 HTTP（80），以降低本地开发成本；
  - HTTPS 验证可在需要时单独启用，自签名证书挂载目录示例见本 README 第 6 章。

### 7.2 stage 环境

- 典型域名：`api.stage.alkaidsys.com`（可根据实际调整，例如 `api.test.alkaidsys.com`）。
- 推荐端口链路：
  - 公网 / 内网 443 → Nginx 443（终止 TLS）→ upstream `swoole_backend`（一个或多个后端实例，通常监听 8080）。
- 访问示例：
  - 日常业务：`https://api.stage.alkaidsys.com/v1/...`
  - T1-SESSION-REDIS 验收（受控时间窗口 + 仅内网访问）：`https://api.stage.alkaidsys.com/debug/session-redis`。
- 证书：
  - 建议使用 Lets Encrypt 或企业正式证书；
  - 更新证书时仅需在 Nginx 层替换证书文件并 reload 配置。

### 7.3 prod 环境

- 典型域名：`api.alkaidsys.com`。
- 端口链路：
  - 公网 443 → 外层 LB / WAF（可选）→ Nginx 443 → upstream 后端实例（8080）；
  - 可按实际情况将本 Nginx 配置作为“应用层网关”，上游再叠加云厂商 SLB / WAF。
- 访问示例：
  - 业务流量：`https://api.alkaidsys.com/v1/...`
- 证书：
  - 使用企业正式证书，严格控制私钥访问权限；
  - 建议结合统一证书管理平台或自动化更新机制。

### 7.4 从直连 backend 迁移到统一 Nginx 入口

1. **部署 Nginx 网关**
   - 按本目录提供的 `alkaid.api.conf` 在目标环境部署 Nginx；
   - upstream 指向现有 backend（Swoole HTTP、PHP-FPM 等），先以“旁路”方式部署（暂不切换真实业务流量）。

2. **验证 Nginx → backend 链路**
   - 选取健康检查接口或 `/think` 等轻量接口验证：
     - 直接访问 backend：`curl http://<backend_host>:8080/think`
     - 通过 Nginx 访问：`curl -k -sS -D - https://<stage 或 prod 域名>/think`
   - 对于 T1-SESSION-REDIS，可在受控条件下通过 `/debug/session-redis` 做一次端到端验证，确保 Nginx 转发、Trace 中间件、Session/Redis 均工作正常。

3. **切换流量入口到 Nginx**
   - 更新 DNS 或上游 LB 配置，将 API 域名指向 Nginx 所在的 VIP / 节点；
   - 观察一段时间（建议 30 分钟–数小时）内的错误率、响应时间与访问日志，确保无明显异常。

4. **逐步收紧 backend 直连能力**
   - 在迁移初期可以保留 backend 的直连（例如内网 IP + 端口），以便紧急排查；
   - 当确认 Nginx 稳定后，可以：
     - 在防火墙 / 安全组中限制 backend 端口仅对 Nginx 或特定运维网段开放；
     - 或在 LB 层取消对 backend 端口的直接暴露。

### 7.5 回滚与故障预案

1. **快速回滚到直连模式**
   - 保留一份“直连 backend”时的 DNS / LB 配置；
   - 如 Nginx 层出现重大故障，可在短时间内将流量切回 backend 直连（或上游 LB 直接指向 backend 实例）。

2. **Nginx 故障排查清单**
   - 检查配置语法：`nginx -t`；
   - 检查 upstream 健康状态：
     - 是否能从 Nginx 容器 / 机器上直接 curl backend；
     - upstream 地址是否写错（IP、端口、域名）；
   - 检查证书与 TLS：
     - 证书路径是否存在、权限是否正确；
     - 浏览器/客户端是否报告证书错误；
   - 检查日志：
     - `error_log` 中的报错信息；
     - 访问日志中的状态码分布（4xx/5xx 抖动情况）。

3. **与应用层联动**
   - 如同时启用了应用层 AccessLog / RateLimit 中间件（T2-RATELIMIT-LOG），建议：
     - 在 Nginx 访问日志与应用层访问日志中均记录 trace-id，方便跨层追踪；
     - 在回滚或灰度切换时，通过 trace-id 核对请求是否被正确路由。

> 提示：以上迁移与回滚方案为通用建议，具体实施时请结合公司实际运维体系（CI/CD、变更审核流程、监控告警平台等）进行细化与审批。
