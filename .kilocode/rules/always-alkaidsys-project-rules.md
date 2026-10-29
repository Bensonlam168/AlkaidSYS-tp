# AlkaidSYS 核心规则 (Core Rules)

> 本文件为 **核心规则**，在 AlkaidSYS 仓库的所有开发会话中始终生效。
> 这些规则是"硬约束"，优先级高于其他建议或代码现状，违反将导致设计偏差和质量问题。

## 指导原则

## 1. 总体原则

1. **唯一权威来源**：
   - 所有实现、重构必须优先参考：
     - `docs/technical-specs/**/*.md`
     - `design/**`
     - `.augment/skills/**` 与 Serena memories
     - `docs/report/design-compliance-audit-2025-12-14.md` 设计契合度审查报告
     - `.kilocode/rules/workflow-optimization.md` 工作流程优化体系
   - 不得仅以"当前代码长什么样"作为设计依据；当实现与上述文档/本规则不一致时，应视为"待修复的设计偏差"，而不是调整规则去迎合现状代码。

2. **Phase 区分**：
   - 明确区分 Phase 1（现状）与 Phase 2（目标能力）。
   - 未在任务上下文明确要求时，不得假定 Phase 2 能力已经落地并依赖之。

3. **低代码优先**：
   - 对动态业务实体与表结构，必须优先采用低代码框架（`Domain\\Lowcode` + `Infrastructure\\Lowcode`）。
   - 禁止为新业务直接设计与低代码无关的"散表 + 散 SQL"作为主数据模型。

4. **统一规则体系**：
   - 遵循三层规则结构：Core Rules > Implementation Guidelines > Process Workflow
   - 规则冲突时以高优先级为准，无法解决时通过技术委员会评审

---

## 2. 架构与职责

1. **分层架构**：
   - 严格区分：表现层/应用层、领域层、数据访问层、基础设施层、低代码层等。
   - 控制器只负责：路由、参数校验、调用 Service、统一响应；业务规则放到 Service/Domain。

2. **低代码真实来源**：
   - 低代码 Schema（集合/字段/关系）是动态实体的"单一事实来源"。
   - 新代码不得再引入对以下 Legacy 类的**新增依赖**：
     - `Domain\\Model\\Collection`
     - `Infrastructure\\Collection\\CollectionManager`
     - 旧版 `Infrastructure\\Field\\FieldTypeRegistry`
   - 低代码表单校验必须保持前后端一致：前端获取表单详情调用 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1`，使用返回的 `validator.rules/messages` 与 `parsed.components/permissions`，前端本地校验仅作兜底，禁止自造与后端不一致的规则。

3. **微服务与插件架构**：
   - 新功能优先考虑插件化实现，遵循应用插件系统设计
   - 核心服务保持稳定，扩展功能通过插件机制实现
   - 插件必须遵循统一的接口规范和生命周期管理

---

## 3. 数据库与多租户

1. **命名与字段**：
   - 表名使用 `snake_case` 复数；字段使用 `snake_case`。
   - 所有租户级业务表必须包含 `tenant_id`，必要时包含 `site_id`。

2. **租户隔离**：
   - ORM 与原生 SQL 访问必须始终包含租户（和站点）范围过滤：`tenant_id`（和 `site_id`）。
   - 禁止对跨租户数据做无范围更新或查询。
   - 所有数据库连接必须基于租户上下文进行连接池管理

3. **迁移唯一入口**：
   - 所有结构变更（表/字段/索引/约束）必须通过迁移脚本完成。
   - 禁止在生产或共享环境直接执行手写 DDL。
   - 迁移脚本必须包含回滚策略和数据完整性检查

4. **数据库性能优化**：
   - 所有查询必须包含适当的索引使用
   - 禁止 N+1 查询问题，必须使用预加载或 JOIN 优化
   - 大数据量操作必须使用分页或流式处理

---

## 4. API 与错误码

1. **RESTful 路由**：
   - 必须遵守 API 规范文档；路径与方法需符合 REST 语义。
   - 禁止使用 `action` 查询参数承载 CRUD 语义（如 `?action=delete`）。
   - API 版本必须通过路径管理（如 `/api/v1/`），不支持通过参数版本控制

2. **统一响应结构**：
   - 所有 JSON 响应必须使用统一结构：`code/message/data/timestamp(/trace_id)`。
   - 控制器必须使用统一基类与封装 Helper，不得随意 `return json([...])`。
   - 成功响应：`code = 0`，失败响应：`code != 0`

3. **错误码矩阵**：
   - 认证/授权/限流等错误必须使用文档规定的错误码，例如：
     - 2001 未授权（Token 问题）
     - 2002 权限不足
     - 2003 限流触发
     - 2004 参数验证失败
     - 4xx/5xx 必须带 `trace_id`

4. **API 文档同步**：
   - 所有 API 变更必须同步更新 OpenAPI/Swagger 文档
   - 使用自动化工具生成 API 文档，保持文档与代码一致性

---

## 5. 认证、权限与安全

1. **JWT 双 Token**：
   - 统一使用 Access Token + Refresh Token 机制，`iss` 与环境变量 `JWT_ISSUER` 一致。
   - 刷新时必须轮换 Refresh Token，并将旧 Token 加入黑名单。
   - Token 必须包含过期时间、签发时间、权限范围等标准声明

2. **Auth / Permission 中间件**：
   - Auth 失败 → HTTP 401 + `code = 2001`；
   - Permission 失败（已登录无权限） → HTTP 403 + `code = 2002`；
   - 错误响应结构必须与 API 规范一致，并包含 `trace_id`。

3. **权限码格式**：
   - 内部权限 slug：`resource.action`，如 `forms.view`（DB 内部字段）。
   - 面向前端/客户端权限码：`resource:action`，如 `forms:view`。
   - 路由中间件使用内部 slug，API 返回给前端使用 `resource:action`。
   - 禁止引入新的权限编码体系（如 `AC_xxx` 或其他格式）。

4. **统一授权服务**：
   - 控制器与中间件只能通过统一的 `PermissionService`（或等价抽象）检查权限。
   - 禁止直接查询角色/权限表在控制器内做授权判断。

5. **限流与安全**：
   - 必须遵守安全规范中关于限流、签名、加密、脱敏等约定。
   - 高风险接口不得绕过限流或签名机制。
   - 所有敏感数据必须脱敏处理，不得在日志中输出明文

6. **安全审计**：
   - 所有关键操作必须记录审计日志，包含操作者、时间、操作内容、IP 地址
   - 审计日志必须不可篡改，支持长期存储和查询

---

## 6. 前后端集成

1. **权限集成**：
   - 后端返回权限数组时，必须使用 `resource:action` 格式，供 Vben Access Store 使用。
   - 前端权限判断只能依赖该权限数组和统一工具函数，不得硬编码 role 名判断权限。

2. **多租户上下文**：
   - 前端需通过统一 store 管理 `tenant_id`/`tenant_code`，并按规范在请求中携带。
   - 后端必须基于请求上下文进行租户隔离。

3. **状态管理**：
   - 前端状态管理统一使用 Pinia + 持久化插件
   - 敏感数据（token 等）不做明文持久化
   - 状态变更必须支持时间旅行和调试

---

## 7. 测试与质量

1. **测试覆盖**：
   - 新功能（尤其是认证/权限/限流/迁移）必须增加或更新自动化测试。
   - 测试覆盖率不得低于 85%，关键路径必须达到 95% 以上。
   - 所有测试必须包含正常流程、异常流程、边界条件测试

2. **质量门禁**：
   - 代码质量分数不得低于 8.5/10
   - 安全漏洞扫描不得有高风险及以上问题
   - 性能回归不得超过 10%
   - 架构合规性检查不得有严重违规

3. **测试环境一致性**：
   - 测试环境必须与生产环境保持一致的配置和数据结构
   - 使用容器化测试环境，确保环境隔离和可重现性

---

## 8. Git 工作流

1. **分支策略**：
   - 必须遵守仓库 Git 工作流与分支策略，禁止向受保护分支直推。
   - 功能开发使用 `feature/<scope>-<summary>` 分支命名
   - 修复使用 `fix/<scope>-<summary>` 分支命名

2. **提交规范**：
   - 使用 Conventional Commits 格式：`<type>(<scope>): <subject>`
   - 提交信息 header ≤ 72 字符
   - 必须包含关联的 issue/任务编号

3. **PR 要求**：
   - PR 必须包含清晰描述、关联 issue/任务、测试命令与结果
   - 有 `frontend/` UI 变更需附截图
   - PR 粒度要小而清晰，每个 PR 聚焦一个明确主题

---

## 9. 本地 Docker 开发环境与命令执行规范

1. **容器与命名**：
   - 后端 PHP 应用容器固定使用 `alkaid-backend`（见根目录 `docker-compose.yml`）。
   - 相关基础服务容器命名：`alkaid-mysql`、`alkaid-redis`、`alkaid-rabbitmq`、`alkaid-nginx`。
   - 在宿主机确认容器时，优先使用：`docker compose ps` 或 `docker ps --filter "name=alkaid-backend"`。

2. **命令执行原则**：
   - 所有后端 CLI 操作（测试、迁移、seed、缓存清理、命令行工具、Composer 安装等）**必须在 `alkaid-backend` 容器内执行**，禁止直接在宿主机执行 `php think ...`。
   - 优先使用 `alkaid-dev-tools` 统一工具链替代直接命令执行
   - Augment/Serena 在调用终端运行测试、迁移等命令时，必须构造 `docker exec ...` 形式的命令。

3. **测试命令模板（开发环境）**：
   - 运行全部测试：`alkaid-dev-tools test:run --suite="all"`
   - 指定测试文件：`alkaid-dev-tools test:run --file="<test_file_path>"`
   - 特性测试套件：`alkaid-dev-tools test:run --suite="Feature"`
   - 单个测试方法：`alkaid-dev-tools test:run --filter="<method_pattern>"`
   - 生成覆盖率：`alkaid-dev-tools test:coverage --report="html"`

4. **迁移与数据库相关命令**：
   - 运行迁移：`alkaid-dev-tools migrate:run`
   - 回滚迁移：`alkaid-dev-tools migrate:rollback --step=<n>`
   - 查看迁移状态：`alkaid-dev-tools migrate:status`
   - 运行数据填充：`alkaid-dev-tools seed:run`

5. **其他常用命令**：
   - 进入容器 Shell：`docker exec -it alkaid-backend bash`
   - 查看后端容器日志：`docker logs -f alkaid-backend`
   - 安装后端 Composer 依赖：`docker exec -it alkaid-backend composer install`
   - 执行一次性维护脚本：`docker exec -it alkaid-backend php path/to/script.php`

6. **自动化与 CI 提示**：
   - 在非交互环境（CI 或无 TTY 的执行环境）中，可以去掉 `-it`，使用 `docker exec alkaid-backend ...`。
   - 但无论本地/CI，**一律禁止**直接在宿主机运行 `php think test`、`php think migrate:run` 等命令。

---

## 10. 代码格式与质量工具

1. **PSR-12 强制要求**：
   - 所有 PHP 代码必须符合 PSR-12 编码规范。
   - 新增或修改的 PHP 文件在提交前，应通过自动化工具进行格式检查和（必要时）修复。
   - 所有新文件必须使用 `declare(strict_types=1);` 声明

2. **PHP-CS-Fixer 配置与使用**：
   - 项目根目录的 `.php-cs-fixer.php` 是 PHP-CS-Fixer 的**唯一权威配置来源**，禁止在其他位置新增平行配置文件。
   - 代码格式检查与修复必须使用该配置文件中约定的规则集（包含 `@PSR12`、`array_syntax`、`no_unused_imports`、`single_quote` 等）。
   - 优先使用 `alkaid-dev-tools check:style --fix` 自动修复

3. **命令执行环境约束**：
   - 所有 PHP-CS-Fixer 相关命令必须在 `alkaid-backend` 容器内执行，例如：
     - 只读检查：`alkaid-dev-tools check:style --dry-run`
     - 自动修复：`alkaid-dev-tools check:style --fix`
   - 禁止在宿主机直接调用 `php-cs-fixer` 针对项目代码进行操作。

4. **CI 集成要求**：
   - CI 流程中应包含基于 `.php-cs-fixer.php` 的只读格式检查步骤，例如：
     - `alkaid-dev-tools check:style --dry-run --report-format="github"`
   - 对于大规模历史代码格式修复，应单独规划迁移批次，不得与业务功能改动混合在同一提交中。

5. **静态分析**：
   - 所有 PHP 代码必须通过 PHPStan 静态分析，不得有 0 级错误
   - 新增代码必须解决所有静态分析警告
   - 定期进行技术债务分析和重构

---

## 11. 性能与监控

1. **性能基准**：
   - 所有 API 接口必须建立性能基准，响应时间不得超过预设阈值
   - 数据库查询必须进行性能分析，避免慢查询
   - 前端页面加载时间必须监控和优化

2. **监控与告警**：
   - 关键业务指标必须实时监控，设置合理告警阈值
   - 系统资源使用率（CPU、内存、磁盘）必须监控
   - 错误率必须监控，超过阈值时自动告警

3. **性能优化**：
   - 定期进行性能测试和优化
   - 使用缓存策略减少数据库访问
   - 异步处理长时间运行的任务

---

## 12. 文档与知识管理

1. **文档同步**：
   - 所有架构、API、设计变更必须同步更新相关文档
   - 使用自动化工具生成文档，确保文档与代码一致性
   - 文档必须包含示例代码和最佳实践

2. **知识沉淀**：
   - 重要决策和设计思路必须记录在 ADR（架构决策记录）中
   - 技术问题和解决方案必须记录在知识库中
   - 定期整理和归档项目经验

---

## 13. 合规检查清单

所有开发活动必须通过以下合规检查：

- [ ] 代码符合 PSR-12 规范
- [ ] 测试覆盖率达到 85% 以上
- [ ] 通过安全漏洞扫描
- [ ] 遵循低代码优先原则
- [ ] 包含完整的租户隔离
- [ ] 使用统一的错误码和响应格式
- [ ] 包含适当的权限检查
- [ ] 包含必要的审计日志
- [ ] 通过性能基准测试
- [ ] 文档与代码保持同步

---

## 14. 违规处理

1. **违规等级**：
   - **严重**：违反核心架构原则、安全要求、多租户隔离
   - **重要**：影响代码质量、测试覆盖率、性能标准
   - **一般**：文档不完整、命名不规范、工具使用不当

2. **处理流程**：
   - 严重违规：立即阻止合并，必须修复后重新提交
   - 重要违规：在 PR 中明确标注修复计划和时间
   - 一般违规：在后续迭代中逐步修复

3. **违规记录**：
   - 所有违规必须记录在项目质量报告中
   - 定期分析违规趋势，制定改进措施
   - 严重违规必须进行根因分析和预防措施

---

**核心原则**：这些规则是项目成功的基石，违反任何一条都将导致技术债务累积、质量下降和团队协作效率降低。所有团队成员必须严格遵守，并在发现潜在违规时及时提出和改进。
