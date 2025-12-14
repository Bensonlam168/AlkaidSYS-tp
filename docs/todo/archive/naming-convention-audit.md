# AlkaidSYS-tp 命名规范审计报告（T1 阶段）

> 版本：v1.0  
> 范围：后端 PHP 代码、配置文件（config/*.php）、env 示例（.env.example 等）、部署与配置设计文档（design/05-*、design/04-* 等）  
> 目标：识别当前命名不一致与潜在风险点，为后续 T1/T2 统一命名与配置策略提供依据。

---

## 1. 总体命名规范原则（建议目标态）

1. **环境变量（ENV）命名**
   - 统一采用 **全大写 + 下划线** 风格：`APP_ENV`、`DB_HOST`、`JWT_ISSUER`、`JWT_SECRET`、`CACHE_DRIVER`、`REDIS_HOST` 等；
   - 禁止在新代码中使用 `foo.bar` 形式的小写点分隔 key（如 `jwt.secret`、`cache.driver`、`redis.host` 等）；
   - `.env.example` 作为 env 命名的“单一真相源”，所有文档与代码中的 env 名称应与其保持一致。

2. **配置文件与数组 key**
   - `config/*.php` 中的配置 key 推荐使用 **小写 + 下划线/无下划线** 风格（当前实现基本符合 ThinkPHP 默认约定，如 `default_app`、`default_timezone`）；
   - 对于引用 env 的配置，应优先复用已有 env（如 Redis 统一走 `REDIS_*`）。

3. **类 / 接口 / Trait 与命名空间**
   - 遵循 PSR-12：大驼峰类名（`JwtService`）、小驼峰方法名（`generateAccessToken`）、命名空间与目录结构对应（如 `Infrastructure\Auth\JwtService`）；
   - 插件/领域服务 Provider 推荐统一继承 `think\Service` 或 `Domain\DI\ServiceProvider` 并通过 `app/service.php` 注册。

4. **数据库表名与字段名（抽样原则）**
   - 表名采用蛇形复数：如 `collections`、`collection_fields`；
   - 字段名采用蛇形：如 `tenant_id`、`site_id`、`created_at`；
   - 低代码相关表（collection/field/relationship）在后续 T1-DOMAIN-CLEANUP 中统一整理。

---

## 2. 当前发现的命名不一致问题清单

### 2.1 环境变量命名相关

1. **N1：`jwt.secret` 使用小写点分隔（P0）**
   - 位置：`infrastructure/Auth/JwtService.php` 构造函数中：
     - `env('jwt.secret', 'your-secret-key-change-in-production')`；
   - 问题：
     - 与项目中其它 env（`JWT_ISSUER`、`CACHE_DRIVER`、`REDIS_HOST` 等）的 **大写下划线** 规范不一致；
     - `.env.example` 中并未提供 `jwt.secret` 示例，增加误配置风险；
   - 建议：
     - 统一改为 `env('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION')`，并在 `.env.example` 中新增 `JWT_SECRET` 示例与“生产必须修改”的警告注释。

2. **N2：文档中仍使用 `APP_JWT_SECRET` 与实现不一致（P1）**
   - 位置：
     - `design/05-deployment-testing/14-deployment-guide.md`：单机部署章节示例中使用 `APP_JWT_SECRET`；
     - `design/05-deployment-testing/17-configuration-and-environment-management.md`：环境变量矩阵中使用 `APP_JWT_SECRET`；
   - 问题：
     - 实际 JwtService 计划改为使用 `JWT_SECRET`；
     - 文档与实际 env 名称不一致，容易导致“文档照抄但代码不生效”的隐性故障；
   - 建议：
     - 将上述文档中的 `APP_JWT_SECRET` 全部统一为 `JWT_SECRET`，并明确说明其与 JwtService 的对应关系。

3. **N3：Swoole 相关 Redis 配置硬编码 IP/端口（P1）**
   - 位置：`config/swoole.php` 中 WebSocket room/ipc/lock 的 Redis 配置，例如：
     - `room.redis.host = '127.0.0.1'`，`room.redis.port = 6379` 等；
   - 问题：
     - 主业务缓存已经统一通过 `REDIS_HOST` / `REDIS_PORT` 等 env 管理；
     - Swoole Redis 使用硬编码地址，不利于 Docker/K8s 部署与多环境切换，且命名风格与全局 Redis 配置不一致；
   - 建议：
     - 中短期在文档中标注“当前为示例/默认值，不建议直接用于生产”；
     - 后续将其改为复用 `REDIS_HOST` / `REDIS_PORT` 或独立的 `SWOOLE_REDIS_HOST` / `SWOOLE_REDIS_PORT` env。

4. **N4：历史示例文件 `.example.env` 与当前规范不完全一致（P2）**
   - 位置：`.example.env`；
   - 特点：使用 `APP_DEBUG = true`、`DB_TYPE = mysql` 等早期示例格式，与当前 `.env.example` 的结构/说明不完全一致；
   - 建议：
     - 保留作为“历史示例”，在文档中弱化其重要性，明确以 `.env.example` 为准；
     - 后续如有精力，可统一更新或删除该文件以减少干扰。

### 2.2 配置文件与数组 key

- 当前 `config/app.php`、`config/cache.php`、`config/session.php` 等文件的 key 命名整体较为统一（小写 + 下划线），问题主要集中在 **引用 env 的 key 命名不统一**（见 N1~N3）。

### 2.3 类与命名空间

- 抽样检查 `Infrastructure/Auth/JwtService.php`、`Domain/DI/ServiceProvider.php`、`app/provider/CacheEnvironmentGuardService.php`，类名和命名空间基本符合 PSR-12，无明显命名冲突问题；
- 后续在 T1-DOMAIN-CLEANUP 中会系统梳理领域模型命名，这里暂不展开。

### 2.4 文档中的示例与实现的偏差

- 部分设计/部署文档仍沿用早期命名（如 `APP_JWT_SECRET`），需要随代码统一迁移到新的 env 命名（`JWT_SECRET`）。

---

## 3. 建议的统一规范方案（摘要）

1. **JWT 相关环境变量统一**
   - 运行时代码与所有文档统一采用：
     - `JWT_SECRET`：JWT 签名密钥（必须为强随机值，生产环境通过安全渠道配置）；
     - `JWT_ISSUER`：JWT issuer（iss），必须为完整域名/URL；
   - 禁止继续使用 `jwt.secret`、`APP_JWT_SECRET` 等旧命名。

2. **Redis 与 Swoole 相关环境变量统一**
   - 业务缓存与 Session：统一走 `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_DB`；
   - Swoole 扩展能力（room/ipc/lock）：短期保持示例配置，长期引入 `SWOOLE_REDIS_*` 或复用 `REDIS_*`，并在设计文档中明确。

3. **文档与 `.env.example` 的“一致性约束”**
   - 任一设计/部署文档中出现 env 名称时，必须在 `.env.example` 中存在对应项；
   - 反之，`.env.example` 新增的关键 env 需在设计文档中至少出现一次说明（如 JWT、Redis、缓存/Session 策略）。

---

## 4. 修复工作量预估与优先级（按问题）

| 编号 | 问题描述 | 位置示例 | 建议优先级 | 预估工作量 |
|------|----------|----------|------------|------------|
| N1 | `jwt.secret` 命名不规范 | `infrastructure/Auth/JwtService.php` | **P0** | < 0.5 天：修改 env key、更新 `.env.example`、回归启动 &登录流程 |
| N2 | 文档中 `APP_JWT_SECRET` 与实现不一致 | `design/05-deployment-testing/14-*.md`、`17-*.md` | **P1** | < 0.5 天：全局搜索替换为 `JWT_SECRET`，补充说明 |
| N3 | Swoole Redis 配置硬编码 | `config/swoole.php` | **P1** | 0.5–1 天：方案设计 + 配置调整 + 基础回归 |
| N4 | `.example.env` 与现行规范不一致 | `.example.env` | **P2** | < 0.5 天：更新或标注为历史示例 |

> 说明：本报告仅覆盖当前 T1 阶段与缓存/JWT/Session 相关的命名问题；领域模型与数据库命名将作为 T1-DOMAIN-CLEANUP 与后续阶段的重点内容另行审计。
