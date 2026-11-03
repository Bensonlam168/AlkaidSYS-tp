# AlkaidSYS 项目全貌（设计与现实对照）

> **2026-10-31 重启审计提示**：本文下方的完成度、迁移数、工作流状态和测试失败归因保留为 2026-10-30 的历史快照，不能作为当前生产就绪结论。当前证据与重启顺序见[企业生产级重启审计与重建路线](report/enterprise-production-restart-audit-2026-10-31.md)。本次确认：静态守卫可通过，但旧迁移仍有 MISSING 记录、工作流仍为演示内存态，测试隔离与安全边界尚需修复；不得据本文“业务缺陷 0”等旧表述判断可以上线。

> **文档定位**：根目录 [`README.md`](../README.md) 介绍的是**设计目标**（项目想成为什么）；
> 本文件补充**现实状态**（现在实际建成了什么），两者互补，不重复。
>
> 如果你只想快速回忆「这是个什么项目、做到哪一步了」，读这一份即可。

| 项目 | 内容 |
|---|---|
| 最后更新 | 2026-10-30 |
| 数据基线 | `develop` 分支（分支整理重建后） |
| 数据来源 | 全部为可复现命令实测，非引用既有文档 |
| 配套报告 | [代码质量审查](report/quality-review-superpowers-2026-10-30.md)、[文档质量分析](report/doc-review-design-docs-2026-10-30.md) |

---

## 一、一句话定义

**一个用 PHP 写的、低代码驱动的企业级 SaaS 框架** —— 不是成品软件，而是给开发者用来快速搭建 SaaS 应用的**底座**。

| 项 | 内容 |
|---|---|
| 中文名 | 瑶光系统（Alkaid = 北斗七星斗柄末端的星） |
| 定位 | 强大、现代、低代码的企业级 **SAAS 系统框架** |
| 目标用户 | 企业级 SaaS 应用开发者 |
| 开源协议 | MIT |
| 开发语言 | PHP 8.2+ / TypeScript |

**命名寓意**：开创、变动、冒险、创新。

---

## 二、它要解决什么问题

| 目标 | 文档原始表述 | 可验证性 |
|---|---|---|
| 快速开发 | 低代码设计，效率提升 10 倍（细化目标：10 天 → 2 小时，40-48 倍） | 营销性表述，无基准测试支撑 |
| 高性能 | 基于 Swoole 协程，目标 10K+ 并发 | 无压测报告留存 |
| 灵活扩展 | 插件化架构，功能按需装卸 | 插件机制已实现；但主应用实际未加载 workflow 插件 |

**参考对象**：NIUCLOUD（多租户 SaaS 框架）+ Vue Vben Admin（前端脚手架），定位为「融合两者优势并创新」。

---

## 三、七层架构

```
客户端层      Admin(Vben Admin 5) / PC(Vue 3) / 移动端(UniApp)
    ↓
API 网关层    Nginx + Swoole（路由分发 / 限流 / 认证）
    ↓
应用层        电商 / OA / CRM / ERP / CMS / AI / 低代码管理应用
    ↓
插件层        通用插件(支付/短信/存储) / 应用专属插件 / 低代码插件
    ↓
低代码基础层  Schema Manager / Collection Manager / 字段类型注册 / 校验器生成
    ↓
核心服务层    用户 / 租户 / 权限 / 应用 / 插件
    ↓
数据层        MySQL / Redis / RabbitMQ
```

`[依据: design/00-core-planning/01-alkaid-system-overview.md:105-115]`

---

## 四、三个核心设计创新

### 创新 1：应用 + 插件「两层生态」

| 层 | 是什么 | 示例 | 约束 |
|---|---|---|---|
| **应用（Application）** | 完整业务模块，可独立安装/卸载/启停 | 电商、OA、CRM、ERP、CMS、AI | 独立表、独立路由、独立菜单、独立前端、支持版本升级 |
| **插件（Plugin）** | 功能扩展件 | 支付、短信、存储、优惠券、审批流 | 挂载到应用或框架 |

第三块是**应用市场 + 插件市场 + 开发者中心**，构成生态闭环。

### 创新 2：低代码是「开发者工具」，不是「独立低代码平台」

文档特意强调的定位区分：

> - **开发者工具**：帮助开发者快速开发应用和插件，**而非独立的低代码平台**
> - **插件化设计**：低代码能力以插件形式提供，可选安装
> - **CLI 集成**：通过 CLI 命令快速创建数据模型、表单、工作流
> - **可视化界面**：可选安装低代码管理应用，提供可视化设计器

`[依据: design/00-core-planning/01-alkaid-system-overview.md:126-132]`

即：数据建模、表单设计器、工作流引擎**本身都是插件**，装不装由用户决定。

### 创新 3：多租户 + 多站点双维度隔离

- **多租户**：设计支持 3 种隔离模式 —— 共享库 / 独立库 / 混合
- **多站点**：同一租户下多站点，独立域名、独立配置、数据隔离

---

## 五、技术栈（实际版本，非文档声明）

| 层 | 技术 | 文档声明 | **实际** | 一致性 |
|---|---|---|---|---|
| 语言 | PHP | 8.2+ | **8.2.29** | ✅ |
| 后端框架 | ThinkPHP | 8.0 | **^8.0** | ✅ |
| 常驻运行时 | Swoole | 5.0（22 处） | **5.1**（Dockerfile `phpswoole/swoole:5.1-php8.2`） | ⚠️ 版本号滞后 |
| 数据库 | MySQL | 8.0 | **8.0.46** | ✅ |
| 缓存 | Redis | 6.0（4 份）/ 7.0（2 份） | **7.2.12** | ⚠️ 文档不一致 |
| 队列 | RabbitMQ | — | **3.12** | — |
| 权限 | PHP-Casbin | — | **^3.0** | — |
| 前端框架 | Vue | 3 | **3.5.24** | ✅ |
| 前端脚手架 | Vben Admin | 5 | **5.x** | ✅ |
| UI 库 | Ant Design Vue | 4.x | **4.2.6** | ✅ |
| 网关 | Nginx | — | **1.24** | — |

`[依据: composer.json、docker-compose.yml、Dockerfile、容器内 php -v / redis-server --version / mysql --version]`

---

## 六、设计 vs 实现对照（核心章节）

### 6.1 已建成（后端核心）

| 模块 | 实现证据 | 完成度 |
|---|---|---|
| **低代码核心** | `infrastructure/Lowcode/` 39 个 PHP：Collection（Field/Repository/Service）、FormDesigner、Schema（Parser/Version）、Generator；`app/command/lowcode/` 4 个 CLI 命令 | **85%** |
| **多租户** | 27 个迁移中 16 个含 `tenant_id`、8 个含 `site_id`；`TenantAwareDb` 强制注入过滤；FormDataManager/CollectionRepository/PermissionService/UserRepository 四组件过滤齐全 | **80%** |
| **事件与钩子** | `domain/Event/` 6 文件 + `infrastructure/Event/EventMonitor` + `Plugin/Hook/` 4 文件；3 张表（事件指标/死信/告警/钩子监控） | **80%** |
| **权限 Casbin** | `infrastructure/Permission/` 6 文件 + `config/casbin.php` + `casbin-model.conf` + `CasbinController` + **8 个测试**（含模式切换与缓存降级） | **75%** |
| **系统管理** | `app/controller/admin/` 6 控制器（Dept/Menu/Permission/Role/Tenant/User）+ `domain/Organization/` + 4 个迁移 | **70%** |
| **工作流引擎** | `plugins/workflow-engine/` **276 个自有 PHP**，7 层结构（Definition/Execution/Expression/Nodes/Integration/Multitenant/Monitor），5 个迁移，**51 个自带测试** | **70%** |
| **插件系统** | `infrastructure/Plugin/` 18 个 PHP（Contract/Enum/Exception×4/Hook×4/Metadata/Manager/Scanner/TypeDetector/Repository×2）+ 2 个迁移 | **65%** |
| **前端（系统管理）** | `web-antd/src` 89 文件（81 个 `.vue/.ts`）/ 8,217 行；`api/system/{dept,menu,role,permission,tenant,user}.ts` + `views/system/*` CRUD 页 | **55%** |

### 6.2 建了但没接上

| 项 | 事实 |
|---|---|
| **工作流双实现并存** | 插件版 `plugins/workflow-engine`（276 PHP）**未被主应用加载**：`grep -rn 'plugins/' app config infrastructure domain route` → 0 命中；`config/plugin.php:7` 指向 `addons/plugins`（空目录）；插件 `routes/` 仅 `.gitkeep`。主应用跑的是原生 `route/workflow.php` + `app/controller/workflow/`（4 控制器） |
| **工作流前端组件库未接入** | `plugins/workflow-engine/packages/workflow-ui/`（39 文件，31 个 ts/vue，9,911 行，含 `WorkflowDesigner.vue` 与 8 类节点组件）**未被主前端引用** |

### 6.3 只画了图纸

| 设计内容 | 文档位置 | 实现状态 |
|---|---|---|
| 独立数据库 / 混合隔离模式 | `01-architecture-design/04-multi-tenant-design.md:358-600`（模式 2 起、模式 3 至约 600 行） | ❌ 27 个迁移全在同一 schema，无建库/切库 DDL |
| 页面设计器 | `01-architecture-design/08-low-code-design.md:599-714`（约 115 行） | ❌ 无任何对应目录；文档 `:791` 还将其列为竞品优势「✅ 更强大」 |
| 电商/OA/CRM/ERP/CMS/AI 六大应用 | `01-architecture-design/06-application-plugin-system-design.md` | ❌ 无目录；`addons/apps/` 仅 `_template` 模板骨架 |
| 应用市场 / 插件市场 | `02-app-plugin-ecosystem/06-3`、`06-4`（共 2,374 行） | ❌ `market`/`marketplace` 全库零匹配 |
| 移动端 / PC 客户端 | `06-frontend-design/18`、`19` | ❌ 仅 Admin 端有业务代码 |

### 6.4 完成度汇总

| 模块 | 实现度 |
|---|---|
| 低代码框架 | 85% |
| 事件与钩子 | 80% |
| 多租户 | 80% |
| 权限 Casbin | 75% |
| 系统管理 | 70% |
| 工作流（后端） | 70% |
| 插件系统 | 65% |
| 前端 | 55% |
| 应用/插件市场 | 0% |

**后端约 72%，整体约 60%。**

---

## 七、当前处于什么阶段

> **已是一个「有实质内容的低代码 SaaS 后端骨架」——数据建模能建表、表单能渲染、权限能鉴权、事件能分发、工作流引擎写完了——但前端几乎没长出来，且工程治理问题比写代码更紧急。**

### 工程现状（客观数字）

| 指标 | 数值 |
|---|---|
| 后端 PHP | 434 文件 / 102,217 行 |
| 迁移 | 38 个，`migrate:status` 全部 up（另有 1 条孤儿 applied 记录 `20251125` 显示 **MISSING**，属改名遗留，已登记 `scripts/check-migrations.php` 的 `ORPHAN_APPLIED_EXEMPTIONS` 豁免至 2027-02-28） |
| 测试 | 812 用例 / 4,780 断言，**0 失败 / 0 错误**（另有 3 skipped、3 incomplete、32 PHPUnit Deprecations） |
| 设计文档 | 73 份 |
| 技术规格 | 42 份 |
| 最大阻塞项 | 4 个 Critical（**已修复 3 个**：C-2 前端入库、C-3 vendor 出库（`268389e`）、C-4 跨租户注册） |

### 三个比写代码更紧急的问题

| # | 问题 | 状态 |
|---|---|---|
| 1 | **`frontend/` 曾悬空为 gitlink** —— 1,273 个前端源文件不在版本库，一次 `git clean -fdx` 即永久丢失 | ✅ **已修复（2026-10-30，`bc2357e`）**：1,678 文件 / 8.49 MB 已入库 |
| 2 | **`plugins/workflow-engine/vendor` 曾入库** —— 4,270 文件 / 39.28 MB，占出库前全仓跟踪内容的 64.2%（字节口径；按文件数为 59.0%） | ✅ **已修复（2026-11-02，`268389e`）**：4,270 个已跟踪文件出库，跟踪文件数 7,237 → 2,967、净删 513,900 行、跟踪字节 61.22 MB → 21.95 MB；新增 `plugins/**/vendor/` 忽略规则，顺带清掉 `vendor/madong/helper` 那条 mode 160000 的坏 gitlink |
| 3 | **`/auth/register` 跨租户账号注入** —— 无 Auth 中间件 + 客户端可控 `X-Tenant-ID` | ✅ **已修复（2026-10-30）**：改为域名绑定/服务端配置解析，403 + code 2008 |

---

## 八、重启开发的推荐顺序

| 阶段 | 事项 | 预估 |
|---|---|---|
| **P0** | ~~修 `frontend` gitlink、移出 vendor、注册接口加校验~~ ✅ **已完成**（2026-10-30 `bc2357e` 前端入库 / 2026-11-02 `268389e` vendor 出库 / 2026-10-30 注册接口改域名绑定并返回 403 + code 2008）—— **三项均已完成，不再是待做项**；原事项文字保留以免丢失历史 | 1-2 天 |
| **P1** | 修复测试隔离基建（`ThinkPHPTestCase` 的容器复用 + `Request` 上下文泄漏） | 1 周 |
| **P2** | 打通「系统管理」模块端到端（前后端全通），验证架构成立 | 2 周 |
| **P3** | 工作流取舍：接入主应用 或 归档只留原生实现 | 1 周 |
| **P4** | 按设计补前端（低代码设计器、工作流管理） | 2-4 周 |

---

## 九、相关文档导航

### 想了解设计意图
- [系统概览](../design/00-core-planning/01-alkaid-system-overview.md)（七层架构、生态定位）
- [主实施计划](../design/00-core-planning/01-MASTER-IMPLEMENTATION-PLAN.md)（⚠️ 任务勾选状态未回填，勿据此判断进度）
- [架构设计](../design/01-architecture-design/02-architecture-design.md)
- [低代码框架架构](../design/09-lowcode-framework/40-lowcode-framework-architecture.md)

### 想了解当前质量
- [代码质量审查报告](report/quality-review-superpowers-2026-10-30.md)（7 Critical / 9 Important / 17 Minor）
- [文档质量分析报告](report/doc-review-design-docs-2026-10-30.md)（22 项问题）
- [低代码架构规范](technical-specs/lowcode/lowcode-architecture-guidelines.md)（含 Legacy→新架构迁移映射表）

### 想了解具体任务历史
- `docs/todo/plan/` —— 12 份（其中 T-056 ~ T-066 任务完成报告 10 份，另有开发计划与项目状态报告各 1 份）
- `docs/report/` —— 29 份审计/分析报告

---

## 十、如何验证本页数字

所有数字可用以下命令复现：

```bash
# 后端规模
find app domain infrastructure config route database tests -name '*.php' | wc -l
find app domain infrastructure config route database tests -name '*.php' -exec cat {} + | wc -l

# 文档规模
find design -name '*.md' | wc -l
find docs -name '*.md' -not -path 'docs/report_archive/*' | wc -l

# 迁移
find database/migrations -name '*.php' | wc -l
docker exec alkaid-backend php think migrate:status | grep -cE '^\s+up\s'

# 测试
docker exec alkaid-backend php think test

# 前端是否在版本库（关键验证）
git ls-files frontend | wc -l                              # 期望 1679（已修复；修复前为 1）
git ls-tree HEAD frontend                                  # 期望 040000 tree（非 160000 commit）
git ls-files | grep -c node_modules                        # 期望 0（产物不入库）

# vendor 是否入库
# 期望 0 个文件 / 0 MB（已于 2026-11-02 出库，见第七章第 2 项）
# 注：无输入时 awk 不累加 n，输出形如「 0 MB」（计数列为空），s/1048576 为 0 即达标
git ls-tree -r -l HEAD -- plugins/workflow-engine/vendor | awk '{s+=$4;n++}END{print n, s/1048576" MB"}'

# 工作流插件是否被加载
grep -rn 'plugins/' --include='*.php' app config infrastructure domain route | wc -l   # 期望 0
```

---

## 十一、维护约定

- 本页数字**必须**来自可复现命令，不接受引用其他文档的二手数据
- 每次完成 P0/P1 级别修复后，同步更新第六、七章
- 若发现本页与代码不符，**以代码为准**，并修正本页

---

**文档创建**：2026-10-30
**维护者**：项目维护团队
