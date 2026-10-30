# AlkaidSYS 设计意图还原与实现差距全景报告

| 项目 | 内容 |
|---|---|
| 报告日期 | 2026-10-30 |
| 审查基线 | `develop` 分支（含 C-2 前端入库、C-4 注册安全修复） |
| 审查范围 | **184 份文档**：`design/` 73 份（50,390 行）+ `docs/technical-specs/` 39 份 + `docs/todo/` 25 份 + `docs/report/` 26 份 |
| 对照源码 | 后端 335 PHP / 57,376 行；插件 213 PHP（src）+ 51 测试；前端 1,678 文件；**31 迁移**（阶段 A 后，原 27） |
| 方法 | 8 个并行只读审计包逐份精读 + 主审对 10 项关键结论独立实测 |
| 能力项总数 | **320 项**（仅 00/01 架构、02/03 生态数据、04-07 安全运维三包；低代码与工作流另计） |
| 阶段 A 复验基线 | `Tests: 476, Errors: 22, Failures: 19, Risky: 4`（失败集去重后 41 项 < 42 基线，零新增；剩余均为既有跨测试污染） |
| 用途 | 为项目重新规划提供事实基线 |

> **阅读提示**：本报告正文记录的是 2026-10-30 首次审查的**原始快照**。凡阶段 A 已修复的条目，均以
> ~~删除线~~ + ✅ 标注并附提交号与独立复验证据；未标注者即为**仍然成立**的结论。

> **本报告不是又一份审计清单，而是一次「设计意图 vs 代码现实」的系统性重建设。**
> 它回答三个问题：这个项目原本想做什么？现在实际做到了什么？差距的性质是什么？

---

## 零、一页摘要

### 项目本来的样子（设计意图）

一个 **用 PHP 写的、低代码驱动的企业级 SaaS 框架**（不是成品软件）。五大支柱：

1. **低代码开发能力** —— 数据建模 / 表单设计器 / 页面设计器 / 流程设计器 四件套 + 8 类代码生成 + CLI
2. **应用 + 插件两层生态** —— 六大业务应用（电商/OA/CRM/ERP/CMS/AI）+ 通用/专属插件 + 应用市场/插件市场/开发者分成
3. **多租户 + 多站点** —— 三种隔离模式（共享库/独立库/混合）+ 站点级隔离
4. **工作流引擎** —— 10+ 触发器 / 22 种节点 / Swoole 协程执行 / 表达式引擎 + 3 个前端应用
5. **多端统一** —— Admin / PC / 小程序 / App / H5 共用一套 API

### 现在的真实样子

| 支柱 | 设计承诺 | 实际可用度 | 一句话判词 |
|---|---|---|---|
| 1. 低代码 | 4 设计器 + 8 产物 + 9 命令 | **后端 85%/60%/44%，前端 0%** | 建模与表单后端扎实；设计器与前端全缺 |
| 2. 应用+插件生态 | 6 应用 + 2 市场 + 分成 | **插件 55% / 应用 38% / 市场 0%**（原值，未重新计分） | 部件齐全但**未通电**（~~autoload 断链 + Provider 未注册~~ → 阶段 A 已接通；`install()` 仍缺表） |
| 3. 多租户+多站点 | 3 模式 + 站点管理面 | **多租户 54% / 多站点 44%**（原值，未重新计分） | 只有共享库；站点无管理面（~~租户上下文取值为 null~~ → C-7 已修） |
| 4. 工作流 | 引擎 + API + 3 应用 | **引擎库部分可用 / API 0% / 表 0%→表已建** | 界面能跑、数据仍为内存态、引擎 41% 文件加载即 Fatal |
| 5. 多端 | 5 端统一 | **Admin 1/5** | 只有 Admin 端验证过 API 契约 |

### 三个最要紧的事实

1. **存在 3 个已验证的 Critical 安全缺陷**（不止此前报告的 C-1/C-3），**均已于阶段 A 修复**：
   - JWT 密钥是仓库内公开字符串，已实测可伪造任意用户身份并成功冒充 → ✅ `a7cf49b`（生产 11/11 拒绝矩阵）
   - Refresh Token 可当 Access Token 用，已实测通过受保护接口 → ✅ `a7cf49b`（refresh→`/me` 由 200 变 401）
   - `$request->tenantId` 属性访问恒为 null，工作流 54 处租户过滤静默失效 → ✅ `7860fab`（残留 0）
   - **仍开放的 Critical 为 C-1（低代码标识符注入）与 C-3（插件 vendor 入库）**，不在阶段 A 范围

2. **工作流是「空转」的**：42 条路由 + 2,874 行控制器 + 9,911 行前端组件曾全部指向不存在的数据表；控制器把数据存在
   `protected static array`（源码注释自认 `In-memory storage for demo`）。
   ⚠️ **阶段 A 变化**：5 张表已由 `f3de7b0` 建出（`migrate:status` 31 up / 0 MISSING，回滚闭环已验证），
   但**代码层仍走内存态**（`ProcessDefineRepository` 全 TODO），"表建好了没人用" 成为新的落差形态。
   另：外键仅成功 2/10（见 R5）。

3. **文档体系存在系统性失真**：85 个标记 ✅ 的任务中仅 14 个（25%）有完成报告；工作流 TODO 的 14 项验收指标全绿而 17 个任务未开始；同一文档内并存「后端 100% 完成」与「后端 0% 缺失」。

---

## 一、审查方法

### 1.1 分包策略

按 `design/` 的目录语义切成 8 个非重叠工作包并行精读：

| 包 | 范围 | 文档数 | 能力项 |
|---|---|---|---|
| P1 | `00-core-planning` + `01-architecture-design` | 12 | 62 |
| P2 | `02-app-plugin-ecosystem` + `02-domain-layer` + `03-data-layer` | 12 | 97 |
| P3 | `04-security-performance` + `05-deployment-testing` + `06-ratelimit` + `07-integration-ops` | 17 | 161 |
| P4 | `06-frontend-design` | 9 | — |
| P5 | `09-lowcode-framework` | 12 | — |
| P6 | `08-developer-guides` + `10-batch-summaries` + `prompt-templates` + `sdk-builder` | 10 | — |
| P7 | `docs/technical-specs` | 39 | — |
| P8 | `docs/todo` + `docs/report` | 51 | — |

### 1.2 判定口径

- **✅ 已实现**：有可加载/可执行的代码实体，且有明确入口
- **🔶 部分**：实体存在但链路不完整（缺持久化 / 缺接线 / 缺前端）
- **❌ 未实现**：无代码实体
- **⚠️ 冲突**：设计与代码互相矛盾
- **📄 流程类**：文档为组织约定，无代码对应物

**实现度算法**：`(✅×1 + 🔶×0.5 + ❌×0) / 能力项数`

### 1.3 主审独立验证清单

以下 10 项由主审亲自复跑命令确认，不依赖子包结论：

| # | 验证对象 | 结果 |
|---|---|---|
| 1 | `ProcessEngines.php` 命名空间大小写 | `namespace ...Engine\core`（小写）vs 目录 `Core` → Linux 必 miss ✓ |
| 2 | `madong\ingenious` 可加载性 | `class_exists('...model\NodeModel')` → **Fatal: Trait not found** ✓ |
| 3 | `$request->tenantId` 属性访问 | 方法=1，属性=**NULL** ✓ |
| 4 | JWT `JWT_SECRET` 运行时值 | `len=25`，值 = `CHANGE_THIS_IN_PRODUCTION` ✓ |
| 5 | **伪造 token 冒充用户** | 伪造 user_id=101014 → **HTTP 200，返回该用户真实资料** ✓ |
| 6 | **refresh token 当 access token** | `/v1/auth/me` → **HTTP 200** ✓ |
| 7 | workflow 表是否存在 | `SHOW TABLES LIKE '%workflow%'` → **0 行** ✓ |
| 8 | `ProcessDefineRepository` 实现 | `findById()` → `// TODO: 实现数据访问逻辑; return null;` ✓ |
| 9 | iframe 插件加载器 | `frontend/packages/plugin-sdk/` **1,079 行**，已被 `web-antd` 依赖 ✓（**推翻我此前「未实现」结论**） |
| 10 | 钩子触发点 | `do_action(` = 0，`apply_filter(` = 0 ✓ |

---

## 二、设计意图还原

从 184 份文档中提炼出的完整设计意图，按「能力域」组织。

### 2.1 产品定位（`00-core-planning`）

| 维度 | 设计内容 |
|---|---|
| 定位 | 强大、现代、低代码的**企业级 SaaS 框架**（开发者工具，非独立低代码平台） |
| 参考 | NIUCLOUD（多租户 SaaS）+ Vue Vben Admin（前端） |
| 目标用户 | 企业级 SaaS 应用开发者 |
| 量化目标 | 1000+ 租户 / 10K+ 并发 / P95<500ms / 效率提升 10~72 倍 |
| 阶段规划 | 5 阶段 53~68 周（基建 11 周 → 核心插件 16 周 → 完整功能 13 周 → 优化 13 周 → 插件混合 15 周） |

### 2.2 架构意图（`01-architecture-design`）

设计了一个**分层**（文档自称 7 层，实列 8 层，末尾对比表又写 6 层）：

```
客户端层(Admin/PC/小程序/App/H5)
  → 接入层(Nginx+Swoole)
  → 应用层(addons/applications/ 下 6 类专业应用)
  → 插件层(addons/plugins/{universal,app-specific})
  → 低代码层(Collection/Field/Relationship/Schema/Validator)
  → 核心服务层(BaseService: User/Tenant/Auth/Application/Plugin/Market)
  → 数据访问层(BaseModel 租户作用域)
  → 数据层(MySQL 主从/Redis/RabbitMQ)
```

**关键设计承诺**：
- **多租户 3 模式**：共享库 / 独立库（建库+建用户+切连接）/ 混合（按 plan 决定）
- **租户识别 4 方式**：域名 / 子域名 / X-Tenant-Code / Token
- **多站点**：域名识别 + X-Site-Code + 默认站点三级
- **应用+插件两层**：应用 5 方法生命周期 + 插件钩子（Action/Filter/Event）
- **微服务拆分**：按业务域拆 User/Tenant/Auth/File/Message/Search 服务

### 2.3 低代码意图（`09-lowcode-framework` + `01-architecture-data/08`）

「四四矩阵」：

| 层 | 内容 |
|---|---|
| **设计器 ×4** | 表单设计器（JSON→AntD）、页面设计器（search/table/toolbar→列表页）、流程设计器（nodes/edges→LogicFlow）、数据模型设计器 |
| **生成器 ×4** | 代码生成 / Schema 解析 / 校验器生成 / 迁移草稿生成 |
| **产物 ×8** | migration / model / controller / service / validate / route / api / view |
| **CLI ×9** | install / create-model / create-form / create-workflow / generate / schema-sync / mcp:template / init:app / migration:diff |

**关键约束**：dev/test 允许运行时 DDL；**预发/生产禁止 DDL，只允许生成迁移候选脚本**。

### 2.4 工作流意图（`09-lowcode-framework/44~51`）

v2.0（47 号文档）设计的完整链路：

```
表单提交 → 事件发布 → 触发器订阅 → WorkflowEngine 图遍历
  → 节点执行（22 种，含 PENDING 人工任务）→ ExecutionContext 变量
  → 持久化（5 张表）→ 前端 3 应用（审批/自动化/混合）
```

**v1.0 → v2.0 的真实改进**（4 处）：节点抽象（PENDING 状态）、HumanTask 任务模型、表达式安全（去掉字符串替换+eval）、强类型 ExecutionContext。
**v1.0 → v2.0 的倒退**（1 处）：v2 的 5 张表设计**全部丢失 `tenant_id`**。

### 2.5 生态意图（`02-app-plugin-ecosystem`）

| 层 | 设计 |
|---|---|
| 应用 | 6 大类，独立表/路由/菜单/前端，`manifest.json` + 5 生命周期方法 |
| 插件 | 通用 + 应用专属，`plugin.json` + 钩子注册 + 依赖检查 |
| 市场 | 应用市场 + 插件市场：搜索/分类/推荐/详情/评价/安装/`package_hash` 校验 |
| 开发者生态 | L1-L4 等级 + 15%~30% 分成 + 提现 + 开发者中心 |

### 2.6 质量与安全意图

| 域 | 设计承诺 |
|---|---|
| 安全 | JWT（Access 2h / Refresh 7d）+ Casbin RBAC + AES-256 加密 + 数据脱敏 + XSS/CSRF + API 签名防重放 + 审计落库 |
| 性能 | Swoole 协程 + 连接池 + Swoole Table L1 缓存 + 路由缓存 + 队列异步 |
| 监控 | trace_id 全链路 + JSON 日志 + Prometheus/Grafana + 告警分级 |
| 测试 | 覆盖率 >80%（Service>90%/Model>85%/Controller>75%）+ 四层套件 + 压测 + 安全扫描 |

---

## 三、实现状态矩阵

### 3.1 按能力域

| 能力域 | 设计文档 | 实现度 | ✅ / 🔶 / ❌ | 判定依据 |
|---|---|---|---|---|
| **实施计划/路线图** | 01-MASTER-PLAN | **52%** | 12 / 6 / 11 | 29 项能力，缺 3 个 CLI 命令 + 管理应用 + 存储抽象 + 多级缓存 + 队列监控 |
| **系统概览/愿景** | 01-system-overview | **38%** | 1 / 7 / 4 | 12 项能力，缺 3 隔离模式 + 多应用 + 市场生态 + 可视化设计器 |
| **技术选型** | 02-TECH-SELECTION | **95%** | 9 / 1 / 0 | 版本基本对齐（Pinia 2→3 漂移） |
| **依赖清单** | 03-PROJECT-DEPS | **67%** | 2 / 4 / 0 | 12 处版本/包名不符，2 处不存在的能力 |
| **术语表** | 99-GLOSSARY | **73%** | 8 / 3 / 2 | 引用不存在的表 `lowcode_workflow_executions`；ABAC 零实现 |
| **整体架构** | 02-architecture | **50%** | 4 / 8 / 4 | 应用层 4 目录 + BaseService + 微服务 + Swoole Table 全缺 |
| **技术栈详述** | 03-tech-stack | **50%** | 3 / 4 / 3 | Swoole Table / Co::join / PC 端 / UniApp 缺 |
| **多租户** | 04-multi-tenant | **54%** | 6 / 2 / 5 | 隔离模式 2/3（连 schema 列都没有）、识别 2/4、写校验、分区表 |
| **多站点** | 05-multi-site | **44%** | 3 / 2 / 4 | 三级识别、CRUD 服务、站点路由、Nginx 绑定全缺 |
| **应用与插件** | 06-app-plugin | **69%** | 4 / 3 / 1 | 六大应用 0/6；`addons/plugins/` 空 |
| **多端** | 07-multi-terminal | **57%** | 3 / 2 / 2 | PC/移动端缺；`/api` 前缀与 `code=200` 不符 |
| **低代码平台** | 08-low-code | **67%** | 2 / 2 / 2 | 页面设计器缺；生成器 3/8；前端 0 |
| **应用系统** | 06-1 | **38%** | 1 / 4 / 3 | 契约/管理器齐；`applications`/`app_configs` 表缺 |
| **插件系统** | 06-2 | **55%** | 3 / 5 / 2 | 钩子/异步/监控完整；隔离模式/组件加载缺 |
| **应用市场** | 06-3 | **0%** | 0 / 0 / 7 | 全库零匹配 |
| **插件市场** | 06-4 | **0%** | 0 / 0 / 7 | 同上 |
| **开发者生态** | 06-5 | **6%** | 0 / 1 / 7 | 仅 4 个 CLI 存在 |
| **依赖规范** | 06-6 | **10%** | 0 / 1 / 4 | manifest 字段写了但无读取校验 |
| **数据库设计** | 09-database | **25%** | 1 / 1 / 4 | 核心 8 表名对数不对；13 张市场表全缺；15 张实际表未收录 |
| **API 设计** | 10-api | **43%** | 4 / 2 / 6 | 响应/错误码/限流落地；`/api` 前缀、版本中间件、Swagger 缺 |
| **迁移策略** | 11-migration | **77%** | 7 / 2 / 1 | DDL guard 扎实；`executeSqlFile` 旁路；无 `down()` |
| **多租户数据规范** | 12-multi-tenant-spec | **25%** | 1 / 2 / 5 | 7 表缺 site_id、3 表无租户维度、全库 INT 非 BIGINT |
| **数据演进蓝皮书** | 13-bluebook | **46%** | 1 / 3 / 1 | 流程性；跨库迁移/演练/回滚无产物 |
| **安全设计** | 04-11 | **55%** | 8 / 6 / 6 | JWT/错误码/Trace/SQLi ✅；加密/脱敏/XSS/CSRF/签名/审计表 ❌ |
| **性能优化** | 04-12 | **25%** | 0 / 5 / 5 | 无协程、无 Swoole Table、无预热、无压测 |
| **监控日志** | 04-13 | **25%** | 1 / 3 / 6 | 仅 AccessLog；LogService/APM/metrics 缺 |
| **安全基线** | 04-14 | **36%** | 0 / 5 / 2 | 密钥护栏缺失 |
| **可观测性** | 04-15 | **29%** | 0 / 4 / 3 | trace 有；指标/告警/spanId 缺 |
| **部署指南** | 05-14 | **42%** | 3 / 5 / 5 | Guard ✅；Dockerfile/K8s/api:doc 缺 |
| **测试策略** | 05-15 | **45%** | 3 / 3 / 4 | 四层套件 ✅；覆盖率/压测/安全扫描 ❌ |
| **开发流程** | 05-16 | **40%** | 2 / 4 / 4 | commitlint/CS fixer ✅；release.sh/husky 缺 |
| **配置管理** | 05-17 | **42%** | 1 / 2 / 3 | `config.prod.php` 不存在 |
| **限流** | 06-ratelimit | **83%** | 9 / 2 / 1 | 令牌桶+Lua 超设计；Nginx 层失效、默认关闭 |
| **系统集成** | 07-25 | **19%** | 0 / 3 / 5 | 支付/短信/OSS 全缺 |
| **数据迁移工具** | 07-26 | **17%** | 1 / 1 / 7 | 6 个脚本不存在 |
| **培训材料** | 07-27 | **36%** | 0 / 3 / 4 | PPT/视频/PDF 无素材 |
| **运维手册** | 07-28 | **29%** | 0 / 1 / 6 | 脚本/ansible/Prometheus 缺 |
| **维护指南** | 07-29 | **14%** | 1 / 1 / 9 | 9 个脚本 0 落地 |
| **项目总结** | 07-30 | **35%** | 0 / 6 / 4 | 微服务/市场/性能数字不成立 |
| **低代码-数据建模** | 42 | **85%** | — | 39 类全在；18 字段类型；4 关系类型 |
| **低代码-表单设计器** | 43 | **55%** | — | 后端 6 类全实现；前端 0 组件 |
| **低代码-Schema 解析** | 40 | **70%** | — | `SchemaParser` 在；类名/接口与设计不符 |
| **低代码-代码生成** | 45 | **60%** | — | 4 生成器；缺 model/view |
| **低代码-CLI** | 45 | **44%** | — | 4/9 命令 |
| **低代码-管理应用** | 46 | **0%** | — | 无任何低代码前端页面 |
| **工作流-引擎库** | 47 | **61%** | — | 213 文件中 88 个因 madong 不可用 |
| **工作流-API** | 47/48 | **0%（可用）** | — | 路由/控制器齐但**仍为内存态**；表已于 `f3de7b0` 建出但代码未接 |
| **工作流-前端** | 48 | **40% 实体 / 0% 接入** | — | 组件库 9,911 行但零引用 |
| **工作流-E2E** | 51 | **4/9 跳** | — | 第 5 跳（事件发布）断裂 |

> **阶段 A 对上述矩阵的影响（分数未重算，仅标注事实变化）**
>
> | 行 | 变化 |
> |---|---|
> | 插件系统 06-2 | `plugin\` PSR-4 已补（`4392b0f`），"autoload 断链"一条消失；钩子/异步/监控结论不变 |
> | 应用系统 06-1 | Provider 已接线、`ApplicationManager` 可从容器解析；`applications`/`app_configs` 表**仍缺** → 38% 不变 |
> | 多租户 04-multi-tenant | 54 处 `$request->tenantId` 属性访问改为方法调用（`7860fab`），"识别"链路可用；三种隔离模式仍缺 |
> | 迁移策略 11-migration | 新增 4 个迁移均带 `up()`+`down()` 且回滚闭环已验证；**既有 27 个迁移仍无 `down()`** |
> | 工作流-API | 5 表已建、`migrate:status` 31 up；但 `ProcessDefineRepository` 全 TODO，**可用度仍为 0** |

### 3.2 三包加权总计

| 包 | 能力项 | ✅ | 🔶 | ❌ | 实现度 |
|---|---|---|---|---|---|
| P1 核心规划+架构 | 62 | 28 | 30 | 34 | **≈62%**（去重后 ≈60%） |
| P2 生态+数据层 | 97 | 13 | 23+6⚠️ | 38 | **≈34%** |
| P3 安全+运维 | 161 | 29 | 52 | 72 | **≈38%** |

**综合判断**：后端核心技术（低代码/多租户/权限/事件）约 **70%**；生态与运维层约 **30%**；前端约 **25%**（Admin 端有业务页面，其余 4 端为 0）。
**整体交付完成度约 55~60%**，与既有 `PROJECT-OVERVIEW.md` 的「后端 72% / 整体 60%」基本吻合，但**生态层比我此前估计的更空**（市场 0%、开发者生态 6%）。

---

## 四、新发现的 Critical 安全问题（本轮实测）

这三项**不在**此前质量审查报告的 4 个 Critical 中，是本轮设计-源码对照时发现的。

### C-5 【Critical·已实测】JWT 签名密钥为仓库内公开字符串 → 完整身份伪造

```php
// infrastructure/Auth/JwtService.php:54
$this->secret = (string) Env::get('JWT_SECRET', 'CHANGE_THIS_IN_PRODUCTION');
// .env.example:14  同样的值
```

**运行时实测**：
```
Env::get('JWT_SECRET') → len=25, value='CHANGE_THIS_IN_PRODUCTION'
```

**攻击实证**（用公开密钥自行签发 token）：

| 步骤 | 请求 | 结果 |
|---|---|---|
| 基线 | 正常 access token → `/v1/auth/me` | HTTP 200 ✓ |
| 攻击 | 伪造 `user_id=999999`（不存在） | HTTP 400「用户不存在」← **签名已被接受** |
| 攻击 | 伪造 **真实存在的** `user_id=101014` | **HTTP 200，返回该用户真实资料，tenant=1** |

**结论**：任何人拿到源码即可伪造任意用户的 JWT，**完全绕过认证**。
**修复**：`APP_ENV=production` 且密钥为空/等于默认值时**启动即抛异常**；`.env.example` 改为空值 + 说明。

### C-6 【Critical·已实测】Refresh Token 可当 Access Token 使用

```php
// app/middleware/Auth.php:141
$payload = $this->jwtService->validateToken($token);   // ← 无 type 校验

// infrastructure/Auth/JwtService.php:155  存在但未被调用
public function validateAccessToken(string $token): array {
    $payload = $this->validateToken($token);
    if (($payload['type'] ?? null) !== 'access') { throw ...; }
}
```

**实测**：用 `generateRefreshToken()` 产出的 token 访问 `/v1/auth/me` → **HTTP 200**。

**结论**：7 天有效期的 refresh token 可越权访问全部业务接口，Access Token 的 2 小时 TTL 形同虚设。
**修复**：`Auth::handle()` 改用 `validateAccessToken()`（一行改动）。

### C-7 【Critical·代码路径已确认】`$request->tenantId` 属性访问恒为 null → 租户过滤静默失效

```php
// app/Request.php:23    protected $tenantId;          ← 受保护属性
// app/Request.php:73    public function tenantId(...)  ← 方法

// 控制器里的写法（54 处）
$tenantId = $request->tenantId ?? null;   // ← 属性访问
if ($tenantId !== null) { /* 租户过滤 */ }  // ← 永不进入
```

**实测**：
```
$r->setTenantId(1);
$r->tenantId()  → 1      （方法调用，正确）
$r->tenantId    → NULL   （属性访问，走 __get → middleware('tenantId')）
```

**影响分布**：

| 位置 | 数量 | 后果 |
|---|---|---|
| `app/controller/workflow/*.php` | **50 处** | 租户过滤全部跳过 → 跨租户可读定义/实例/任务/触发器；`save()` 写入 `tenant_id=null`；操作人恒为 `'system'` |
| `app/controller/admin/{User,Role,Menu,Dept}Controller.php` | 4 处 | 回退到 `header('X-Tenant-Id')`（客户端可控）→ 再回退到**硬编码 `1`** |

**对照**：低代码控制器用的是 `$request->tenantId()`（方法），行为正确——所以问题是**两套写法并存**，不是普遍缺陷。

**修复**：54 处改为方法调用（`$request->tenantId()`）。但注意：改对后工作流内存里混住的多租户数据会立刻被过滤，需与「工作流转 DB」一起做。

---

## 五、实现缺口清单

### P0 — 阻断可用性或数据安全

| # | 缺口 | 证据 | 难度 |
|---|---|---|---|
| 1 | ~~**JWT 公开密钥 + 无 fail-fast**（C-5）~~ ✅ **已修复** `a7cf49b` | 生产 11/11 拒绝矩阵；dev 空密钥回退；实测伪造 token 不再生效 | — |
| 2 | ~~**Refresh 当 Access 用**（C-6）~~ ✅ **已修复** `a7cf49b` | 实测 refresh→`/v1/auth/me` 从 200 变 **401** | — |
| 3 | ~~**租户上下文属性访问失效**（C-7）~~ ✅ **已修复** `7860fab` | 54 处改方法调用，残留 0；跨租户读取用例通过 | — |
| 4 | ~~**工作流 5 张表从未创建**~~ ✅ **已修复** `f3de7b0` | 5 张表建成；`migrate:status` 31 条 up / 0 MISSING；回滚闭环（run→rollback→run）通过。**外键仅成功 2/10**，其余 8 条因 `varchar(50)`/`bigint` vs `int unsigned` 类型不匹配被跳过，见 R5 | — |
| 5 | **插件 41% 源码加载即 Fatal** | `class_exists(model\NodeModel)` → Trait not found；88/213 文件依赖 `madong\ingenious` | 🔴 高（补依赖或删半边） |
| 6 | **工作流 API 全内存态** | 7 处 `protected static array`；`ProcessDefineRepository` 全 TODO（表已建，但代码仍走内存态） | 🔴 高（需实现编排器+持久化） |
| 7 | **DDL guard 有两处旁路** | `hasTable`/`hasColumn` 无 guard；`BaseApplication::executeSqlFile()` 直连 `Db::execute` | 🟢 低-中 |
| 8 | **应用系统接线**部分修复 `4392b0f` | Provider 已接线并可解析 `ApplicationManager`；但 `applications`/`app_configs` 表仍缺 → `install()/uninstall()` 不可用；`menus.app_key` 列仍缺 | 🟡 中（剩余为建表+列） |
| 9 | ~~**插件 autoload 断链**~~ ✅ **已修复** `4392b0f` | `plugin\` → `addons/plugins/` 已入 PSR-4；测试夹具 `addons/plugins/_fixture/` 作为活体证明 | — |
| 10 | **钩子零触发点** | `do_action(`=0、`apply_filter(`=0；`app/event.php` 监听全空 | 🟡 中 |

### 阶段 A 已完成（2026-10-30）

| # | 项 | 提交 | 独立复验 |
|---|---|---|---|
| A1 | C-5 JWT 密钥强度校验 | `a7cf49b` | 生产 11/11 拒绝矩阵；反向用例放行；dev 空密钥签发→校验闭环 |
| A2 | C-6 令牌类型校验 | `a7cf49b` | refresh→`/v1/auth/me` 401/2001；access→200；refresh 接口本身未受影响 |
| A3 | C-7 租户上下文 54 处修正 | `7860fab` | 属性式残留 0；跨租户隔离用例 2/2 通过；四个工作流控制器均可加载 |
| A4 | 工作流 5 表纳入主迁移链 + 回滚能力 | `f3de7b0` | 5 表建成；31 up / 0 MISSING；run→rollback→run 闭环；冒烟测试 5/20；外键 2/10（见 R5） |
| A5 | 插件 autoload + 应用服务提供者接线 | `4392b0f` | `make(ApplicationManager)` 可解析；`PluginAutoloadTest` 4 + `ServiceProviderWiringTest` 4 |
| A6 | 文档围栏回归修正 | `0549910` | `check-docs.php --strict` 通过 |
| A7 | 前端权限路由按角色 slug 生效 | `1b01842` | `/v1/auth/me` 返回 `role_slugs:["admin","test_admin"]` 且保留 `roles:[1,3]`；`AuthMeRoleSlugsTest` 2/2 |

**过程中新发现并修复的既有缺陷**（不在原 32 项缺口清单内，因被认证层 401 掩盖）：

| # | 缺陷 | 影响 | 提交 |
|---|---|---|---|
| N1 | `WorkflowDefinitionController::validate()` 与父类 `BaseController::validate(array $data, ...)` 签名不兼容 | PHP 声明期 **Fatal** → definitions 全部接口不可用（一旦通过鉴权） | `7860fab` |
| N2 | `ValidationResult::getInfo()` 不存在（正确为 `getInfos()`） | `/validate` 端点 500 | `7860fab` |
| N3 | `transformDefinitionToArray()` 对 string 日期调用 `format('c')` | 所有 definitions 响应 500 | `7860fab` |
| N4 | **`claim()` 授权 fail-open**：守卫 `!empty(users) && !empty(groups)` 要求两类候选**都**非空才拦截 | 仅有候选用户或仅有候选组时守卫被跳过 → 同租户任意登录用户可**认领/审批**该任务（RED 阶段实测越权返回 200 并真实改写 `assignee`） | `4b5f0cc` |
| N5 | `checkTaskAccess()` 算出的 `$canAccess` 是**死代码**，从未被使用 | 未认领且仅设候选组的任务任何人可访问 | `4b5f0cc` |
| N6 | `addTaskHistory(string $userId)` 与 `userId()` 返回 `int` 冲突 | 所有**成功**的任务变更动作原为 500（失败路径反而正常） | `4b5f0cc` |
| N7 | `AbstractServiceProvider::singleton()` 绑定闭包形参无类型 | `make()` 抛 `InvalidArgumentException: method param miss:app` → 所有走该基类的 provider 都不可用 | `4392b0f` |
| N8 | 迁移 5 调用 `optimizeWorkflowEventLogsIndexes()`，实际方法名多一个 `s` | 原样执行报 undefined method；且 `createPartialIndexes()` 用的 `CREATE INDEX ... WHERE` MySQL 8 不支持 | `f3de7b0` |
| N9 | 迁移 3 给 3 张表硬写字符串主键，而主键是 `int unsigned` 自增 | `STRICT_TRANS_TABLES` 下必然 1366 失败 | `f3de7b0` |

**评审发现但未修（明确遗留）**：

| # | 缺陷 | 风险 | 建议 |
|---|---|---|---|
| ~~R1~~ | ~~`$request->userGroups` 属性访问 + `claim()` fail-open~~ ✅ **已修复** `4b5f0cc` | 新增 `Request::userGroups()`（租户隔离取角色 slug，实例级缓存，异常失败安全）+ 守卫改 `\|\|` + `checkTaskAccess()` 按三条语义重写 + `addTaskHistory` 放宽为 `int\|string` | — |
| R2 | 生产密钥校验发生在 `JwtService` 惰性构造，非启动期 | 生产表现为「进程正常启动、鉴权请求失败」，运维易误判 | 把校验前移到 `app/provider.php` 或 `AppService::boot()` |
| R3 | `.env.example` 注释中的 ASCII `)` 会让 `parse_ini_file` 整份返回 false | 已在本轮修正；建议写入文档维护约定 | 在 `AGENTS.md` 文档维护节加一条 |
| R4 | `admin` 4 控制器与 workflow 其余 3 控制器的租户行为无测试覆盖 | 改动无回归保护 | 补 admin 端点测试 |
| R5 | **工作流外键仅 2/10**：`workflow_tasks.instance_id`、`workflow_event_logs.instance_id/task_id` 为 `varchar(50)`；`created_by`/`assignee`/`user_id` 为 `bigint`(signed)，与 `int unsigned` 主键不匹配 | 8 条外键被跳过 → 无 DB 级引用完整性，可能产生孤儿行 | 先判定 `instance_id` 语义（若设计上存引擎侧字符串实例号，应**去掉该外键**而非改类型），其余改 `int unsigned` |
| R6 | `migrations` 表**无 `batch` 列**，无参 `migrate:rollback` 只回滚「版本号最大的一个」 | 后续开发执行无参 rollback 会先回滚 `20251220000005`（本轮实测已发生一次误回滚，已恢复并核验：列/索引/数据一致性均完好） | 补 `batch` 列，或把本组迁移重编号到当前最大版本之后 |
| R7 | `AppService::boot()` 每请求 `scandir(addons/apps)` | 本机 bind-mount 3~5ms/请求，原生 Linux 预计微秒级 | 按进程缓存 discovered 结果 |
| R8 | `addon\` 命名空间**仍无** PSR-4 映射（A5 只加了 `plugin\`） | `addons/apps/_template/Application.php` 声明 `namespace addon\template` 但目录是 `_template` → `class_exists` 恒 false | 单独立项修正命名空间与目录约定 |
| R9 | `workflow_definitions` **无独立 `edges` 列**，连线由 `nodes_config` JSON 承载 | 与设计文档若假定独立 edges 表/列则不符 | 核对设计后统一口径 |

---

### P1 — 设计承诺大面积落空

| # | 缺口 | 说明 |
|---|---|---|
| 11 | **市场域 0%** | 应用市场/插件市场 12 张表 + 全部 API + 前端 + 分成：全缺 |
| 12 | **六大业务应用 0%** | `addons/apps/` 仅 `_template` |
| 13 | **低代码前端 0%** | 无表单设计器 UI、无 Schema 渲染器、无页面设计器、无管理应用 |
| 14 | **流程设计器未接入** | `workflow-ui` 39 文件 9,911 行，`frontend/` 零引用 |
| 15 | **多端 1/5** | 只有 Admin；PC/小程序/App/H5 全无 |
| 16 | **多租户隔离模式 2/3 缺失** | `tenants` 表无 `isolation_mode` 列（schema 层不支持） |
| 17 | **多站点无管理面** | 无 SiteService/SiteConfigService/站点路由；`SiteIdentify` 只读 header |
| 18 | **前端权限路由未启用** | `accessMode='frontend'` 但 `admin.ts` 6 个路由**无 `authority`** → 任何登录用户可见全部系统菜单 |
| 19 | **多租户字段系统性不达标** | 7 表缺 `site_id`、3 表无租户维度、全库 `INT` 而非 `BIGINT UNSIGNED` |
| 20 | **生成器 3/8 产物** | 缺 migration/model/service/validate/api/view → 生产「禁 DDL 只生成迁移」的唯一合法路径断头 |
| 21 | **安全防护层整体缺失** | AES-256/脱敏/XSS/CSRF/API 签名防重放/审计表 全 0 |
| 22 | **可观测性缺 2/3** | 无 metrics/告警/Prometheus；日志非 JSON |
| 23 | **路由文档覆盖率 24.6%** | 29 条文档化 vs 118 条实际 → `route:verify` CI 永久红 |
| 24 | **密码策略未实现** | 规范 MUST 8 位含大小写/数字/特殊字符；实际仅校验非空 |

### P2 — 质量与一致性

| # | 缺口 |
|---|---|
| 25 | 测试隔离缺陷（跨测试污染，42~43 失败）；`assertDatabaseHas` = 0 |
| 26 | 471 处原生 SQL 无 CI 扫描门禁 |
| 27 | 限流默认关闭（生产零限流）；Nginx 层 `limit_req_zone` 仅存在于注释 |
| 28 | Casbin 默认 `DB_ONLY` + `enabled=false`，Phase 2 目标态未启用 |
| 29 | 无健康/就绪探针；DB/Redis/HTTP 超时全为 0 |
| 30 | 运维/部署/迁移脚本 12 个全缺（`deploy-single.sh`/`backup.sh`/`release.sh` 等） |
| 31 | 88 张 `lc_*` 测试表残留无清理机制 |
| 32 | `domain/Field/` 6 个 Legacy 类未删（计划中「清理批次1」未执行） |

---

## 六、设计文档自身的缺陷（跨包汇总）

### 6.1 数量与分布

| 包 | 设计缺陷数 | 最严重项 |
|---|---|---|
| P1 | 25 | 层数自相矛盾（7/8/6 层）；ROI 计算错误；虚构压测数据（8523 rps） |
| P2 | 26 | **ANSI 转义序列写入正文**；同一文档两个互斥 `alkaid_plugins` 定义；软删除零实现却列为优势 |
| P3 | 50 | 安全文档把公开密钥写成默认值；65 行损坏文本；`cache:clear` 命令不存在 |
| P5 | 18 | 工期 24 周 vs 10 个月；**块引用被插进 PHP 代码围栏内部**（我上轮引入） |
| P6 | 33 | Element Plus 三处互斥；批次总结自评段落逐字复制；FINAL「100% 完成」 |
| P7 | 4 类冲突 + 17 项 MUST 未落实 | `security-guidelines` 自身 `:48` vs `:85` 矛盾；实现 100% 落在被禁一侧 |
| P8 | 18 组结论冲突 + 7 项结构问题 | 同文件内「后端 100%」与「后端 0%」并存 |

**合计约 174 项设计文档缺陷**。

### 6.2 五个系统性失真模式

**模式 1：「文档写完」被当成「能力交付」**
BATCH-1~4 + FINAL 五份总结用同一个 `✅` 符号表示两种语义。FINAL-SUMMARY 的「完成进度 100% ✅」是全套文档中最具误导性的单句——它实际只指文档，但视觉上暗示项目已交付。

**模式 2：批次总结之间从未交叉校对**
四份 BATCH 总结的自评段落是**逐字相同的模板粘贴**；Element Plus 在三处互斥（31 示例用、BATCH-3:338 推荐、FINAL:169 明令禁止）；文档总账三处不一致（35 vs 实际 73）。

**模式 3：设计引用不存在的资产**
`app\common\*`（15 处）、`addons/applications/`（实际 `addons/apps/`）、`lowcode_workflow_executions` 表、`TenantDatabaseService`、`CoreAddonBaseService`、`SYSTEM` 级 API、`cache:clear` 命令……文档以「规范」语气陈述并不存在的东西。

**模式 4：验收阈值无采集手段**
「覆盖率 >80%」「QPS>10K」「P95<500ms」「效率提升 40~72 倍」——全部无测量产物。唯一性能基线文档（`docs/performance/performance-baseline-2025-12-14.md`）里**没有**这些数字。

**模式 5：状态单向升级**
85 个 ✅ 任务中，没有一个后来被降级（仅 T-034 因外部审查强制降级）。T-060 的 `route:verify` 门禁已失效，但状态仍是 ✅。

---

## 七、对既有报告的勘误

### 7.1 我此前报告中的错误

| # | 我的表述 | 实际 | 性质 |
|---|---|---|---|
| 1 | 「主应用未加载 workflow 插件」 | 未作为**插件**加载，但**作为类库**被根 composer PSR-4 引入，6 个类在用 | 表述不准确 |
| 2 | 「iframe 加载器未实现」 | `frontend/packages/plugin-sdk/` 1,079 行，已被 `web-antd` 依赖，有示例页 + e2e | **结论错误** |
| 3 | 「design/ 仅 1 处任务号 T-202」 | `T-202` 来自正则误匹配 `REPORT-2025-01-20`；真实唯一引用是 `design/README.md:10 → T-118` | **身份错误**（数量巧合） |
| 4 | 「工作流插件 276 PHP 齐备」 | 213 在 `src/`，其中 **88 个（41%）加载即 Fatal** | 高估可用性 |
| 5 | 「42 个失败全是污染，业务缺陷 0」 | 失败确实以污染为主，但**掩盖了 C-5/C-6/C-7 三个真实缺陷**——没有测试保护它们 | 结论过于乐观 |
| 6 | 「P0 修复后文档围栏全部配平」 | 我上轮插入的「实现状态校正」块引用被插进了 **40/42/45 三份文档的 PHP 代码围栏内部** | **我引入的新缺陷**（已修正：三处 banner 移至围栏前，`check-docs.php --strict` 通过） |

### 7.2 历史报告的偏差（供参考）

| 报告 | 声称 | 实际 |
|---|---|---|
| `design-compliance-audit-2025-12-14` | 多租户完成度 ~90% | 54%；3 种隔离模式仅 1 种 |
| `workflow-engine-implementation-todo` | 后端 100% 完成（Week2/3） | 同文件 1039 行写「后端 0% 完全缺失」 |
| `workflow-ui-implementation-summary` | 完成度 92% | 同日另一报告称 60%（差 32 个百分点） |
| `FINAL-SUMMARY` | 完成进度 100% | 整体约 55~60% |
| T-107 | `TestCase.php` 8178 行 | 271 行（6523 是**字节数**，单位混淆虚增 30 倍） |

---

## 八、重新规划建议

### 8.1 规划原则（基于本次发现的教训）

1. **先修「通电」，再建「新楼」** —— 大量资产因接线缺失而不可达（autoload 断链、Provider 未注册、钩子零触发）。修复接线成本远低于新建。
2. **以「可运行」为验收单位，不以「代码存在」为验收单位** —— 工作流的 2,874 行控制器在「能返回 200」的意义上是可运行的，但在「能持久化」的意义上是 0。
3. **砍掉幻觉范围** —— 6 大业务应用、2 个市场、5 端、微服务、开发者分成，这些不是「待做」，而是「不在本期」。明确划出并写进文档，避免继续消耗注意力。
4. **安全优先于功能** —— C-5/C-6 是「一行改动即可关闭全站」的级别，任何功能开发都应让位于它们。

### 8.2 推荐的四阶段路线

#### 阶段 A：止血（1 周）— 目标：让系统「不危险、不撒谎」

| # | 任务 | 依据 | 验收标准 |
|---|---|---|---|
| A1 | JWT 密钥 fail-fast | C-5 | 生产环境用默认密钥时**启动失败**；测试用例覆盖 |
| A2 | Auth 改用 `validateAccessToken` | C-6 | refresh token 访问业务接口返回 401；用例覆盖 |
| A3 | 54 处 `$request->tenantId` → `tenantId()` | C-7 | 工作流跨租户读取用例失败（证明过滤生效） |
| A4 | workflow 5 个迁移复制到 `database/migrations/` + 修 FK 类型 | P0-4 | `migrate:status` 出现 5 张 workflow 表 |
| A5 | `plugin\` 补 PSR-4 映射 + `ApplicationServiceProvider` 注册 | P0-8/9 | `class_exists` 通过；应用生命周期可执行 |
| A6 | ✅ **已完成** 修正我引入的 3 处代码围栏破坏（40/42/45） | 勘误 7.1-#6 | `check-docs.php --strict` 已通过 |
| A7 | 前端权限路由启用（`admin.ts` 补 `authority`） | P1-18 | 非管理员登录看不到系统菜单 |

**阶段 A 产出**：系统不再可被冒充、租户隔离生效、工作流有表、插件能加载、文档不撒谎。

#### 阶段 B：通电（2-3 周）— 目标：让既有资产真正可用

| # | 任务 | 说明 |
|---|---|---|
| B1 | 工作流 Repositories 实现（4 个 TODO → DB） | 换成 DB 后 42 条路由才有意义 |
| B2 | 工作流控制器改为经 Repository（去静态数组） | 与 B1 一起做 |
| B3 | 最小编排器：`WorkflowEngine::execute()` 图遍历 + 节点执行 | 这是「工作流」与「CRUD 界面」的分界线 |
| B4 | 低代码 → 事件 → 触发器接线（3 行 `Event::trigger` + 1 个监听器） | 打通 51 号文档第 5 跳 |
| B5 | 钩子触发点接入业务（登录/订单/支付等 4-6 处） | 让 18 个插件类有存在意义 |
| B6 | 测试隔离基建修复（`ThinkPHPTestCase` 每用例新建 App / `tearDown` 清理 bind） | 让 42~43 个失败归零，恢复测试可信度 |

**阶段 B 产出**：工作流能真正跑起来并持久化；插件系统有触发点；测试重新可信。

#### 阶段 C：补齐可用闭环（3-4 周）— 目标：让「低代码」对用户可见

| # | 任务 |
|---|---|
| C1 | 系统管理模块端到端验证（前后端全通），作为架构成立性证明 |
| C2 | 低代码表单渲染器（前端 Schema → AntD 组件）—— 让后端已有的 `with_validator`/`with_parsed` 有消费方 |
| C3 | 流程设计器接入（`workflow-ui` 加入 `frontend/pnpm-workspace.yaml` + 一个页面） |
| C4 | 生成器补 migration 产物（打通生产「禁 DDL → 生成迁移」的合法路径） |
| C5 | 统一响应/错误码/分页契约收口（`PermissionMonitorController` 15 处 + 错误码双口径） |

#### 阶段 D：明确砍掉或不排期

**建议正式宣布「不在本期」并在文档中标注**：

| 项 | 理由 |
|---|---|
| 六大业务应用（电商/OA/CRM/ERP/CMS/AI） | 6 个业务域的工作量远超框架本身；框架价值不依赖它们 |
| 应用市场 / 插件市场 / 开发者分成 | 12 张表 + 全套 API + 前端；应在有真实插件生态后再做 |
| 微服务拆分 | 当前单体 + Swoole 够用；拆分会把复杂度提前引入 |
| PC / 小程序 / App / H5 四端 | 先把 Admin 端做扎实 |
| 多租户独立库模式 / 混合模式 | 需要建库+切连接+迁移路由，且当前无租户规模压力 |
| SDK / MCP 工具链 / 提示词模板消费端 | 生态前置条件不成立 |

### 8.3 立即可做的三件「一行改动」

| # | 改动 | 位置 | 关闭的问题 |
|---|---|---|---|
| 1 | ✅ **已完成**（`a7cf49b`）`Auth.php:141` 改 `validateAccessToken()` | `app/middleware/Auth.php` | C-6（refresh 越权） |
| 2 | `composer.json` autoload 加 `"plugin\\": "addons/plugins/"` | `composer.json` | 插件 autoload 断链 |
| 3 | `admin.ts` 6 个路由加 `authority` | `frontend/apps/web-antd/src/router/routes/modules/admin.ts` | 前端权限路由失效 |

### 8.4 需要你决策的事项

1. **工作流取舍**：投入 B3（编排器）让它真跑，还是**冻结**（保留 CRUD 界面、明确标注「演示态」）？
   前者 2-3 周，后者 0 成本但工作流模块长期半死。
2. **插件引擎取舍**：补 `madong/ingenious` 依赖（未知第三方质量）还是**删除那 88 个文件**只留可用子集？
   建议删——依赖一个未安装的第三方包是负债。
3. **测试隔离**：现在修（B6，1 周）还是等 C 阶段一起修？
   建议现在修——否则后续所有开发都无法验证。
4. **砍范围确认**：8.2 阶段 D 的 6 项是否同意正式「不在本期」？

---

## 九、未覆盖与未验证

| # | 事项 | 原因 |
|---|---|---|
| 1 | P4（前端）与 P5（低代码/工作流）的逐份文档能力清单明细 | 已读但未逐条转录（本报告取其汇总与关键结论） |
| 2 | 213 个插件 PHP 的功能正确性 | 仅验证可加载性，未逐个审阅逻辑 |
| 3 | 51 个插件自带测试的实际通过率 | 未运行（插件需独立 phpunit 配置） |
| 4 | 88 个 `lc_*` 测试表的内容与归属 | 仅统计数量 |
| 5 | 前端 1,678 文件中「Vben 脚手架 vs 本项目定制」的精确逐文件归属 | 仅抽样判定（4 处定制点已确认） |
| 6 | `madong/ingenious` 是否存在于 Packagist、质量如何 | 需外网 |
| 7 | 生产/stage 环境实际部署形态 | 只有本地 dev compose |
| 8 | 业务/商业逻辑合理性（分成比例、市场规则） | 属产品决策 |
| 9 | P7 报告的「471 处原生 SQL 中多少缺 tenant_id」 | 需逐文件语义审查 |

---

**报告生成时间**：2026-10-30
**审查方式**：8 个并行只读审计包（184 份文档）+ 主审 10 项独立实测
**配套报告**：
- `quality-review-superpowers-2026-10-30.md`（代码质量，4 Critical / 9 Important / 17 Minor）
- `doc-review-design-docs-2026-10-30.md`（文档质量，22 项）
- `PROJECT-OVERVIEW.md`（项目全貌速览）
