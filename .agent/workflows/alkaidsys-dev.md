---
description: Workflow for developing a ThinkPHP + Swoole project, referencing design documents in the "design" folder. Agent uses this to identify relevant workflows.
---

# ThinkPHP + Swoole 项目开发工作流规则  

## 1. 文档参考  
- 所有开发需参考 `design` 文件夹中的系列文档（如架构设计、接口规范、业务逻辑等）。  
- 文档路径：`项目根目录/design/`。  
- all_design_files.txt


## 2. 技术栈遵循  
- 后端框架：ThinkPHP（版本需与项目一致，如 v8.x）。  
- 异步/高性能层：Swoole（需遵循 Swoole 最佳实践，如协程使用、进程管理等）。  


## 3. 开发流程  
1. **需求分析**：参考 `design` 中的需求文档，明确功能目标。  
2. **代码实现**：  
   - 控制器/模型：遵循 ThinkPHP 的 MVC 规范。  
   - 异步任务：使用 Swoole 协程或进程处理高并发场景。  
3. **测试验证**：结合 Swoole 的异步特性，编写压力测试/功能测试。  
4. **文档同步**：若需更新设计文档，需在 `design` 文件夹中维护。 
5. **项目报告**：所有项目报告文档请在 'docs/'目录下生成，可适当在这目录下新建文件夹以分类归集。


## 4. 约束与最佳实践  
- 禁止直接修改 `design` 中的核心设计文档（如需变更，需走评审流程）。  
- Swoole 相关代码需添加注释，说明协程/进程的作用。  
- ThinkPHP 代码需遵循 PSR 规范（如命名空间、代码风格）。

## 5. 总体命名规范原则（建议目标态）

- **环境变量（ENV）命名**
   - 统一采用 **全大写 + 下划线** 风格：`APP_ENV`、`DB_HOST`、`JWT_ISSUER`、`JWT_SECRET`、`CACHE_DRIVER`、`REDIS_HOST` 等；
   - 禁止在新代码中使用 `foo.bar` 形式的小写点分隔 key（如 `jwt.secret`、`cache.driver`、`redis.host` 等）；
   - `.env.example` 作为 env 命名的“单一真相源”，所有文档与代码中的 env 名称应与其保持一致。

- **配置文件与数组 key**
   - `config/*.php` 中的配置 key 推荐使用 **小写 + 下划线/无下划线** 风格（当前实现基本符合 ThinkPHP 默认约定，如 `default_app`、`default_timezone`）；
   - 对于引用 env 的配置，应优先复用已有 env（如 Redis 统一走 `REDIS_*`）。

- **类 / 接口 / Trait 与命名空间**
   - 遵循 PSR-12：大驼峰类名（`JwtService`）、小驼峰方法名（`generateAccessToken`）、命名空间与目录结构对应（如 `Infrastructure\Auth\JwtService`）；
   - 插件/领域服务 Provider 推荐统一继承 `think\Service` 或 `Domain\DI\ServiceProvider` 并通过 `app/service.php` 注册。

- **数据库表名与字段名（抽样原则）**
   - 表名采用蛇形复数：如 `collections`、`collection_fields`；
   - 字段名采用蛇形：如 `tenant_id`、`site_id`、`created_at`；

## 6. 建议的统一规范方案（摘要）

- **JWT 相关环境变量统一**
   - 运行时代码与所有文档统一采用：
     - `JWT_SECRET`：JWT 签名密钥（必须为强随机值，生产环境通过安全渠道配置）；
     - `JWT_ISSUER`：JWT issuer（iss），必须为完整域名/URL；
   - 禁止继续使用 `jwt.secret`、`APP_JWT_SECRET` 等旧命名。

- **Redis 与 Swoole 相关环境变量统一**
   - 业务缓存与 Session：统一走 `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_DB`；
   - Swoole 扩展能力（room/ipc/lock）：短期保持示例配置，长期引入 `SWOOLE_REDIS_*` 或复用 `REDIS_*`，并在设计文档中明确。

- **文档与 `.env.example` 的“一致性约束”**
   - 任一设计/部署文档中出现 env 名称时，必须在 `.env.example` 中存在对应项；
   - 反之，`.env.example` 新增的关键 env 需在设计文档中至少出现一次说明（如 JWT、Redis、缓存/Session 策略）。