## 2025-11-26 开发待办已完成任务代码审查报告

### 1. 执行摘要
- 审查范围：docs/todo/development-backlog-2025-11-25.md 中所有标记为 ✅ 的任务：T-001, T-002, T-003, T-004, T-010, T-011, T-031, T-032, T-033, T-036, T-037, T-038, T-039, T-040, T-042, T-043, T-046。
- 依据：design/*、docs/technical-specs/*、docs/casbin/*、docs/todo/*、docs/report_archive/* 及对应实现代码与测试。
- 工具链：Serena view / codebase-retrieval、Context7（Casbin RBAC with Domains）、Exa-code（PSR-12 + php-cs-fixer 最佳实践），以及在 alkaid-backend 容器内实际尝试运行推荐测试命令（php think test）以验证当前测试入口的可用性。
- 总体评价：
  - 多租户与低代码（T-001~T-004）、权限与 Casbin（T-010~T-011）、DI 容器与应用系统（T-031, T-032, T-036）、技术规范（T-033, T-038~T-043）整体设计符合性高，代码质量佳，文档较完善。
  - 自动化测试：与各任务紧密相关的单元/特性/性能测试基本齐备，但当前容器内尚未注册统一的 php think test 命令，CI/本地无法按照 Always Rules 文档通过单一入口拉起完整 test suite，构成重要技术债务。
  - 未发现违反多租户隔离或权限模型的 Critical 级问题；存在少量 High/Medium 级问题，多集中在“测试体系整体健康度”和“部分高级特性尚未落地”。

### 2. 已完成任务概览（按任务）
- T-001 低代码 Collection 接口多租户 P0：多租户上下文贯穿 Controller / Service / FormDataManager，缓存 Key 带 tenantId，未发现跨租户数据风险。
- T-002 lowcode_collections 表租户化：表结构、唯一索引与 Repository 过滤与设计文档一致，历史数据统一归入 tenant_id/site_id=0。
- T-003 动态业务数据表多租户隔离：动态表补列补索引 + FormDataManager 行级过滤，对低代码数据访问形成完整多租户闭环。
- T-004 前端多租户上下文与请求头：Tenant Store + 请求拦截器 + E2E 测试与后端 Request/TenantIdentify 设计对齐。
- T-010 权限基线集成：PermissionService 以 slug=resource.action 为内部主键，对外返回 resource:action 数组，与权限规范一致。
- T-011 Casbin 授权引擎接入：DatabaseAdapter + CasbinService + 配置/文档/测试完整，RBAC with Domains 与 Casbin 官方模型一致。
- T-031 DI 容器增强：ServiceProviderInterface / AbstractServiceProvider / ServiceProviderManager / DependencyManager 形成插件化 DI 机制。
- T-032 测试与迁移体系补齐（局部）：DI 相关单元测试完善，迁移幂等/回滚策略在设计层面有清晰约定。
- T-033 技术规范文档重写与收敛：technical-specs/* 已转为“规范口吻 + Phase 1/2”权威标准，落地情况良好。
- T-036 应用系统基础设施：ApplicationInterface + BaseApplication + ApplicationManager + ApplicationServiceProvider + addons/apps/_template 形成完整应用生命周期框架。
- T-037 BaseModel 全局作用域与 CLI 行为：多租户/多站点全局作用域 + CLI 环境检测 + 列缓存，配套 BaseModelTest 验证核心行为。
- T-038 PHP 版本与 composer 配置基线：composer.json 与文档统一了 PHP 版本、扩展与质量工具要求。
- T-039 PHPDoc 规范与关键类文档：核心基础设施类（如 DI、应用系统、多租户、权限）具备中英双语 PHPDoc，与规范文档一致。
- T-040 环境变量与配置文档统一：.env 示例、配置文件与 deployment/technical-specs 文档在命名与含义上基本对齐。
- T-042 代码格式与常量集中管理：.php-cs-fixer.php 以 @PSR12 为基线，结合项目级 Always Rules，常量收敛到统一位置。
- T-043 路由与 API 规范对齐：docs/technical-specs/api/route-reference.md 与实际 route/* 定义在路径、方法、中间件上基本一致。
- T-046 测试基类与 CI 基线：ThinkPHPTestCase、BaseModelTest、DI/Application 测试等已建立，后续可扩展到更多模块。

### 3. 按任务详细审查（7 维度归纳）
- T-001
  - 代码质量：PSR-12（declare(strict_types=1); 命名规范；Controller 中通过 Request::tenantId()/siteId() 获取上下文，无硬编码 tenant_id；未发现异常字符或滥用 try/catch）。
  - 设计符合性：与《低代码 Collection 多租户改造多阶段路线》P0 阶段要求一致，聚焦接口与缓存多租户感知，不越权触及 DB schema。
  - 规范遵循：多租户 Always Rules（所有访问必须带 tenant_id/site_id）在接口层已落实；未新增对 Legacy CollectionManager 的依赖。
  - 测试与稳健性：低代码相关 Feature Test 覆盖主要路径，尚可在后续增加针对错误参数/无 tenant 场景的负向用例（Medium）。
- T-002
  - 代码质量：迁移脚本与 Repository 实现遵守数据库规范：表名/字段 snake_case，tenant_id/site_id + uk_tenant_name 复合索引设计合理。
  - 设计符合性：与 design/09-lowcode-framework/ 与 t003 schema plan 文档一致，历史数据采用 tenant_id=0/site_id=0 策略与规范一致。
  - 规范遵循：所有 Repository 查询均按 tenant_id 过滤，符合多租户隔离要求；迁移 up/down 均提供，具备结构级回滚能力。
  - 测试与稳健性：缺少针对迁移脚本本身的自动化测试，仅有文档层验证与人工演练建议（Medium）。
- T-003
  - 代码质量：CollectionManager::buildColumns/buildIndexes 与 FormDataManager 中的 SQL 明确写入/过滤 tenant_id/site_id，未见硬编码 tenant 值。
  - 设计符合性：与 t003 schema plan 中“动态表补列补索引 + 后续运行时访问改造”三阶段规划对齐，当前阶段已完成 schema 与运行时访问一体化。
  - 规范遵循：动态表复合索引 idx_tenant_id_id 符合数据库规范“tenant_id 放在复合索引前缀”的要求；未发现跨租户查询。
  - 测试与稳健性：FormDataManager 相关测试覆盖新增多租户行为仍有限，建议增加针对跨租户访问、防止越权删除的特性测试（Medium）。
- T-004
  - 代码质量：前端 Tenant Store 与请求拦截器实现结构清晰，命名规范；未发现硬编码租户 ID；E2E 测试脚本可读性良好。
  - 设计符合性：与多租户设计文档中“JWT 为唯一可信来源，未登录时可用 Header 作为上下文线索”的约定一致。
  - 规范遵循：请求头使用 X-Tenant-ID/X-Site-ID，兼容后端 Request 扩展；未发现额外非规范头部。
  - 测试与稳健性：E2E 覆盖“有/无租户上下文”两类场景，但尚未覆盖多应用入口与异常网络场景（Low）。
- T-010
  - 代码质量：PermissionService 内部以 permissions.slug=resource.action 作为主键，对外返回 resource:action 数组；实现简洁，命名清晰。
  - 设计符合性：与技术决策“后端 slug=resource.action，API 与前端统一使用 resource:action 权限码”完全一致。
  - 规范遵循：Permission 中间件支持 slug 与 code 两种格式并统一标准化，未引入新的权限编码体系。
  - 测试与稳健性：AuthPermissionIntegrationTest 等 Feature Test 覆盖典型路径；建议后续增加更细粒度的权限变更回归测试（Low）。
- T-011
  - 代码质量：CasbinService/DatabaseAdapter/config/casbin.php 多文件均符合 PSR-12 与项目双语 PHPDoc 规范；缓存键、模式配置未见硬编码魔法数。
  - 设计符合性：RBAC with Domains 模型与 Casbin 官方文档完全对齐，请求格式 user:{id}, tenant:{id}, resource, action 与 design/security-design.md 一致。
  - 规范遵循：三种运行模式（DB_ONLY/CASBIN_ONLY/DUAL_MODE）与安全设计中“灰度发布与性能对比”设计相符；日志与性能基准测试满足性能规范。
  - 测试与稳健性：CasbinServiceTest/DatabaseAdapterTest/CasbinPerformanceTest 等提供充足覆盖；策略变更回滚策略仍在 T-012 中挂账（Medium）。
- T-031
  - 代码质量：ServiceProviderInterface / AbstractServiceProvider / ServiceProviderManager / DependencyManager 命名与职责清晰，支持延迟加载与配置驱动注册。
  - 设计符合性：与 lowcode-framework-architecture 中对“插件服务提供者机制 + 懒加载”的要求基本一致，尚未实现更高级的自动依赖关系管理。
  - 规范遵循：PHPDoc 与 DI 指南文档一致，未引入新的容器实现；延迟加载基于 provides 列表与 isDeferred() 实现合理。
  - 测试与稳健性：ServiceProviderManagerTest/DependencyManagerTest 共 17 tests, 28 assertions，覆盖注册/延迟加载/幂等 boot；对于真实插件场景仍需后续集成测试（Medium）。
- T-032
  - 代码质量：测试代码结构规范，命名清晰，使用 PHPUnit 标准断言，无滥用 try/catch。
  - 设计符合性：与 testing-guidelines 中“为基础设施能力补齐单元测试”的要求一致，但尚未涵盖迁移脚本的自动化测试实现。
  - 规范遵循：测试命名、目录与技术规范一致；ThinkPHPTestCase 为后续集成测试提供良好基类。
  - 测试与稳健性：当前仅覆盖 DI 相关，低代码迁移与关键业务流测试仍依赖其他任务（High, 见整体测试部分）。
- T-033
  - 代码质量：technical-specs/* 文档结构与语气统一，使用“必须/应当/可以”+ Phase 1/Phase 2 明确约束，消除了旧版“设计 vs 实现”的混杂表述。
  - 设计符合性：以 design/* 为唯一上游，未再引入独立设计；多租户/权限/限流/数据库/性能规范彼此引用一致。
  - 规范遵循：API/安全/数据库/测试/部署等规范现已可作为日常开发统一标准。
  - 测试与稳健性：文档类任务，无直接自动化测试；建议在后续 CI 中加入规范文件格式与链接校验（Low）。
- T-036
  - 代码质量：ApplicationInterface/BaseApplication/ApplicationManager/ApplicationServiceProvider 代码风格统一，职责划分清晰；模板应用 Application.php 使用事务与事件，异常处理得当。
  - 设计符合性：与 application-system-design.md 一致，实现了发现/注册/安装/卸载/启用/禁用/升级的生命周期管理。
  - 规范遵循：应用级操作使用数据库 + 事件机制，遵守数据演进与审计规范；安装/升级脚本使用 SQL 文件与事件触发。
  - 测试与稳健性：BaseApplicationTest/ApplicationManagerTest 提供较好单元覆盖，但缺少真实业务应用的集成测试（Medium）。
- T-037
  - 代码质量：BaseModel 使用全局作用域封装 tenant/site 过滤，并通过列存在性缓存避免重复 schema 查询；CLI 环境下默认禁用作用域逻辑清晰。
  - 设计符合性：与多租户设计中“ORM 层自动附加 tenant_id/site_id，CLI 可显式关闭”的要求一致。
  - 规范遵循：测试 BaseModelTest 验证作用域启用/禁用与缓存行为，符合测试规范。
  - 测试与稳健性：尚未对大规模模型/高并发场景进行专门性能测试（Low）。
- T-038
  - 代码质量：composer.json 中 PHP 版本、依赖与 scripts 与技术规范一致；未见明显过时依赖。
  - 设计符合性：符合“统一 PHP 版本与基础依赖”的设计目标。
  - 规范遵循：结合 .php-cs-fixer.php 与 testing-guidelines，为代码风格与测试提供基础约束。
  - 测试与稳健性：当前容器内尚未注册统一的 php think test 命令（见第 4 节），说明测试基线与 Always Rules 文档之间仍存在缺口，后续需在统一测试入口打通后结合依赖升级结果进行一次回归评估（High）。
- T-039
  - 代码质量：核心基础设施类 PHPDoc 较为完备，中英双语注释提升可读性；未发现大量缺失 PHPDoc 的公共接口。
  - 设计符合性：与 PHPDoc 规范文档一致，重点覆盖对外 Service/Controller/中间件/API。
  - 规范遵循：命名、类型标注、@throws 等标签使用合理。
  - 测试与稳健性：文档类改进，对运行时行为无直接影响（Low）。
- T-040
  - 代码质量：.env 示例与配置文件中的键名规范统一，无明显硬编码机密信息。
  - 设计符合性：与 deployment-guidelines 与 security-guidelines 中关于环境变量的约定一致。
  - 规范遵循：多租户、Casbin、限流等关键变量均在文档中注明。
  - 测试与稳健性：缺少自动化校验（如缺失变量检测），属于后续可增强点（Low）。
- T-042
  - 代码质量：.php-cs-fixer.php 以 @PSR12 为基线，并增加 array_syntax/no_unused_imports/single_quote 等规则，与 Exa-code 收集的行业实践一致；常量集中管理避免魔法值散落。
  - 设计符合性：与 Always Rules 中“PSR-12 强制要求”和“唯一权威配置文件”的描述一致。
  - 规范遵循：格式化工具必须在 alkaid-backend 容器内运行的约束已写入项目规则。
  - 测试与稳健性：建议在 CI 中强制 dry-run 检查并阻断不合规范提交（Medium，若尚未接入）。
- T-043
  - 代码质量：route-reference 文档与 route/* 定义在路径/HTTP 方法/中间件使用上基本一致，示例清晰。
  - 设计符合性：与 API 设计规范中 route 命名、版本前缀、REST 语义要求一致。
  - 规范遵循：统一使用 ApiController 基类与中间件组合。
  - 测试与稳健性：建议增加自动化校验（如基于路由反射生成文档并对比），当前仍依赖人工同步（Medium）。
- T-046
  - 代码质量：ThinkPHPTestCase/BaseApplicationTest/DI 测试等结构良好，遵守 PHPUnit 与项目测试规范。
  - 设计符合性：与 testing-guidelines 中“提供框架级测试基类 + 补齐关键基础设施测试”的目标一致。
  - 规范遵循：测试命名、目录结构与覆盖范围合理。
  - 测试与稳健性：当前已具备基础测试基类与若干关键模块的单元/特性测试，但由于容器内尚未注册统一的 php think test 命令，整体 test suite 仍缺乏约定的一键入口，需在后续任务中补齐（High）。

### 3.1 组 D：基础设施 & 工具 & 规范专项审查

#### 3.1.1 组 D 已完成任务清单
- ✅ T-030 CLI 工具体系
- ✅ T-031 DI 容器增强
- ✅ T-032 测试与迁移体系补齐（局部）
- ✅ T-033 技术规范文档重写与收敛
- ✅ T-036 应用系统基础设施
- ✅ T-037 BaseModel 全局作用域与 CLI 行为
- ✅ T-038 PHP 版本与 composer 配置基线
- ✅ T-039 PHPDoc 规范与关键类文档
- ✅ T-040 环境变量与配置文档统一
- ✅ T-042 代码格式与常量集中管理
- ✅ T-043 路由与 API 规范对齐
- ✅ T-045 错误消息国际化与多语言支持
- ✅ T-046 测试基类与 CI 基线
- ✅ T-047 代码现代化与局部性能微优化

> 说明：上述任务的基础审查结论已在第 3 节按任务展开，本小节在此基础上补充分组视角下对组 D 关键任务的 7 维度专项分析，并聚焦基础设施稳定性与工具链易用性。

#### 3.1.2 重点任务深度审查（7 维度）

- **T-030 CLI 工具体系（P1）**
  - 代码质量：LowcodeCommand 与各 lowcode:* 命令均使用 `declare(strict_types=1);`，遵守 PSR-12，类/方法/变量命名统一，双语 PHPDoc 完整；公共输出/交互逻辑集中在基类（success/error/warning/info/section/ask/confirm/displayTable），降低重复；try/catch 仅在 execute 顶层用于统一异常输出，未出现滥用。
  - 设计符合性：与 design/09-lowcode-framework/45-lowcode-cli-integration.md 一致，实现了 lowcode:create-model/create-form/generate/migration:diff 等核心命令，命令选项覆盖多租户上下文、字段定义、Schema 漂移检查等关键场景；设计文档中的部分“占位命令”（如 mcp:code-validate、api:doc）尚未全部落地，属可选扩展而非当前实现缺陷。
  - 规范遵循：所有命令在处理业务数据前均通过 tenant_id/site_id 获取上下文或显式参数，未发现绕过多租户隔离或权限检查的路径；底层依赖统一走低代码领域/基础设施层（CollectionManager/FormSchemaManager 等），未新增对 Legacy Collection 类的依赖。
  - 功能完整性：支持模型/表单创建、代码生成及 Schema 漂移 diff，能够支撑典型低代码开发工作流；但尚未提供 end-to-end 的“一键应用初始化 + 文档生成 + 代码校验”闭环命令组合，后续可按设计文档规划逐步补齐（Medium）。
  - 测试与稳健性：已有 13 个单元测试、76 个断言覆盖成功/失败分支和字段解析等关键逻辑，但交互式流程（ask/confirm）及与真实数据库/迁移的集成路径覆盖有限；此外，由于统一测试命令 php think test 尚未注册，这些测试目前难以在 CI 中通过单一入口稳定执行（High，见第 4 节）。
  - 技术债务：
    - High：缺少统一测试入口（php think test），CLI 相关测试无法按 Always Rules 文档在 alkaid-backend 容器与 CI 中一键执行。
    - Medium：需要增加与低代码 Schema/迁移的集成测试（如 migration:diff --all --check 在真实多租户环境下的行为），以及交互式命令分支的自动化验证。
  - 文档与开发体验：T-030 完成报告与设计文档、Git 提交信息中均提供了完整使用示例（包括命令行示例与推荐工作流），整体开发体验良好；建议在文档中显式注明推荐的测试命令/脚本，以避免“文档推荐 php think test 但实际未注册”的混淆。

- **T-045 错误消息国际化与多语言支持（P3）**
  - 代码质量：LanguageService 结构清晰，使用常量 DEFAULT_LANG/SUPPORTED_LANGS 管理语言集合，方法职责单一，内部异常捕获范围限定在 Lang facade 与文件加载层；语言包文件（error.php/auth.php/common.php）命名统一，键名扁平且语义明确，未见明显硬编码散落于业务代码。
  - 设计符合性：整体方案与技术规范中“统一错误码 + 多语言错误消息 + Accept-Language 解析”的设计完全对齐：支持查询参数/Cookie/HTTP 头多信息源检测，提供 trans/transError API，并具备三层回退策略；Auth/Permission 中间件、ApiController/AuthController 与 ErrorCode 均已切换至 LanguageService，消除了硬编码英文/中文提示。
  - 规范遵循：错误响应遵守统一响应结构与错误码矩阵，Auth/Permission 中间件在 401/403 场景下分别返回 code=2001/2002，并通过语言包输出 message；config/lang.php 的 allow_lang_list/header_var/accept_language 映射与规范文档一致。
  - 功能完整性：当前支持 zh-cn/en-us 两种语言，以及参数占位符替换和 Accept-Language 中 q 值解析，能够满足现阶段对内/对外 API 的主要国际化需求；未来若引入更多语言或复杂规则，需要在 LanguageService 与语言包结构上进一步扩展（Low）。
  - 测试与稳健性：LanguageServiceTest 覆盖语言设置/支持检测/翻译/错误码翻译/缺失键行为/Accept-Language 解析等关键路径，单元层面较为完备；但缺少端到端测试来验证真实 HTTP 请求头/查询参数驱动下的语言选择，以及不同语言包之间 key 一致性的自动化校验（Medium）。
  - 技术债务：
    - Medium：缺少语言包 key 覆盖/一致性检查脚本（例如比较 zh-cn 与 en-us 文件差异、检测缺失键），目前主要依赖人工维护。
    - Low：尚未建立语言包变更流程与审核机制（如新增/修改翻译时的评审与回归检查）。
  - 文档与开发体验：T-045 完成报告提供了目录结构、API 使用示例和迁移说明，开发者可以较容易在业务代码中接入 LanguageService；建议在 docs/technical-specs 中补充“如何新增语言/消息 key”的流程指引。

- **T-047 代码现代化与局部性能微优化（P3）**
  - 代码质量：在 CollectionManager/RelationshipManager/FormSchemaManager 等服务类中使用构造器属性提升与 readonly 注入，减少样板代码并强化依赖不可变性；在 LanguageService/MigrationManager/PermissionService 中使用 match 表达式替代多分支 if-else/switch，使模式选择更集中清晰；整体代码风格与 PHP 8.2 现代化方向一致。
  - 设计符合性：与 code-review-issues-2025-11-23.md 中对“引入 PHP 8+ 新特性以提升可读性和类型安全”的建议吻合，并与项目技术规范中对最低 PHP 版本的要求兼容；改动主要集中在内部服务与工具类，未破坏公共 API 签名。
  - 规范遵循：全部改动文件遵守 PSR-12，注释与类型声明完整；未引入新的全局状态或绕过多租户/权限等高危路径。
  - 功能完整性：改动主要集中在实现细节层面（构造器与分支逻辑重写），不改变对外行为；根据提交说明，相应组件在修改前后的行为保持一致，但这依赖于现有测试用例的完备程度。
  - 测试与稳健性：提交信息宣称“所有现有测试通过”，且针对 CollectionManager/LanguageService 等已有单元测试；考虑到当前缺少统一测试入口（php think test），需要在 CI 中确保这些测试实际被执行，并对使用 array_reduce 的部分在大数据量情况下做一次性能回归评估（Medium）。
  - 技术债务：
    - Medium：部分“性能优化”手段（如用 array_reduce + 数组合并替代简单 foreach 赋值）在数据量较大时未必真正有性能收益，甚至可能带来细微开销与可读性损失，需要在后续规范中明确“何时推荐/不推荐使用此类写法”。
    - Low：尚未在代码规范文档中固化使用 match/readonly/array_reduce 等现代特性的推荐模式，存在团队风格不一致的风险。
  - 文档与开发体验：T-047 的 Git 提交说明和 backlog 记录对改动范围、收益与注意事项有较好描述；建议在 docs/technical-specs/code-style 中补充“现代 PHP 特性使用指引”章节，以本次改造经验沉淀最佳实践。

#### 3.1.3 组 D 其他任务整体评价

- T-031/T-032/T-033/T-036/T-037/T-038/T-039/T-040/T-042/T-043/T-046 的详细审查结论已在第 3 节给出。从组 D 视角综合来看：
  - DI 容器（T-031）、应用系统基础设施（T-036）和 BaseModel 全局作用域（T-037）为项目提供了较为稳固的基础设施骨架，设计与实现基本与 design/* 文档对齐，当前未发现 Critical 级实现偏差。
  - 测试与迁移体系补齐（T-032、T-046）在局部模块（DI、应用系统、多租户模型）上已经建立了良好基线，但由于缺少统一测试入口以及针对迁移脚本本身的自动化测试，整体测试体系仍处于“局部完善、全局待打通”的状态（High）。
  - 技术规范与代码/配置规范化（T-033, T-038~T-043）整体质量较高，为后续开发提供了清晰标准；需要通过 CI 强制执行（如 php-cs-fixer dry-run、路由文档自动校验、配置/环境检查），将这些规范转化为“违背即失败”的硬约束（Medium）。

### 4. 跨任务问题与严重性分级
- Critical：本次审查未发现直接违反多租户隔离或权限安全设计的实现问题。
- High：
  - 统一测试入口缺失：在 alkaid-backend 容器内执行 `docker exec alkaid-backend php think test` 时返回 `Command "test" is not defined`，当前仅存在若干 `test:*` 命令，导致无法按照 Always Rules 文档通过单一命令在容器/CI 中拉起完整 test suite。LowcodeCommand 早期存在的签名不兼容问题已在 T-030 中通过重命名为 displayTable 予以修复，但测试基线仍未闭环。
  - 关键迁移与权限策略回滚缺少系统化测试与自动化验证（与 T-012 相关）。
- Medium：
  - 多租户/低代码/应用系统等核心能力的集成级与性能级测试覆盖仍有限。
  - 路由文档与实际路由的一致性目前主要依赖人工同步，缺少自动校验。
  - .php-cs-fixer 规则尚未在 CI 中统一强制（假设尚未接入）。
  - 语言包当前主要依赖单元测试与人工维护，缺少自动化工具校验不同语言文件之间的 key 一致性和缺失键（Medium）。
- Low：
  - 前端多租户 E2E 测试场景覆盖可进一步丰富。
  - 环境变量与配置缺少自动化缺失检测。
  - 文档与 PHPDoc 仍有边缘模块可继续补齐。

### 5. 技术债务清单（节选）
- 测试体系：设计并实现统一的测试入口（推荐 php think test 或等价别名脚本），在 alkaid-backend 容器与 CI 中一键拉起完整 test suite；当前 php think test 命令尚未注册。（High）
- 权限策略：在 T-012 中补齐 Casbin 策略变更回滚策略与对应测试，并在文档中给出推荐操作流程。（Medium）
- 迁移测试：为关键多租户相关迁移脚本（T-002/T-003 等）补充自动化测试，验证幂等与回滚路径。（Medium）
- 集成与性能测试：围绕多租户隔离、权限、低代码数据访问与应用安装生命周期设计端到端与性能测试。（Medium）
- 文档与路由同步：为 route-reference 与实际 route/* 定义建立自动化对比或生成机制，降低漂移风险。（Medium）
- 配置与环境校验：在启动或 CI 阶段增加环境变量/配置完整性检查，提前捕获缺失或非法值。（Low）
- 国际化一致性：引入脚本检查不同语言包文件的 key 覆盖与一致性，并在 CI 中执行，降低遗漏或不一致风险。（Medium）
- 现代化规范：在代码规范文档中补充对 match/readonly/array_reduce 等现代特性的推荐使用模式，避免为追求新语法而牺牲可读性与性能。（Low）

### 6. 改进建议与行动项
- 短期（优先级高）：
  - 新建任务为 phpunit 提供统一的测试入口（如 php think test 命令或等价脚本），确保在 alkaid-backend 容器与 CI 中可以一键运行完整 test suite，并补充针对核心 CLI 命令与 LanguageService 的最小单元/集成测试，用于防止后续改动回归。
  - 为 Casbin 相关迁移/策略变更流程补充至少 1~2 个端到端测试用例（如权限变更后策略刷新与回滚）。
- 中期：
  - 为低代码动态表迁移与 FormDataManager 多租户行为补充 Feature Test，覆盖跨租户访问拒绝、租户切换等典型场景。
  - 在 CI 中接入 .php-cs-fixer --dry-run 与核心测试套件（Feature + Unit + 若干 Performance 基准）作为必经门槛。
  - 建立基于路由反射的自动化 route 文档生成/校验流程，减少 route-reference 漂移。
  - 引入语言包 key 一致性/缺失检测脚本，并在 CI 中强制执行，提升 i18n 维护质量。
- 长期：
  - 继续按技术规范推进 Phase 2 能力（如 Casbin 完全替代 DB-only、令牌桶限流、签名中间件等），并在 Backlog 中为每一类能力维护清晰的迁移计划与测试策略。
  - 在 docs/technical-specs/code-style 系列文档中沉淀“现代 PHP 特性使用指引”，结合 T-047 改造经验，形成团队统一的编码风格与性能考量。

### 7. 附录（主要参考文件索引）
- Backlog 与报告：docs/todo/development-backlog-2025-11-25.md，docs/todo/development-backlog-2025-11-23.md，docs/report_archive/*。
- 多租户与低代码：design/01-architecture-design/04-multi-tenant-design.md，design/09-lowcode-framework/40-lowcode-framework-architecture.md，docs/design/t003-dynamic-table-tenantization-schema-plan.md。
- 权限与 Casbin：design/04-security-performance/11-security-design.md，docs/casbin/README.md，docs/casbin/migration-guide.md，Infrastructure/Permission/*。
- DI 与应用系统：design/02-app-plugin-ecosystem/06-1-application-system-design.md，design/08-developer-guides/31-application-development-guide.md，Infrastructure/DI/*，Infrastructure/Application/*，addons/apps/_template/*。
- 规范与质量：docs/technical-specs/*，docs/technical-specs/testing/testing-guidelines.zh-CN.md，docs/technical-specs/database/database-guidelines.zh-CN.md，docs/technical-specs/performance/performance-guidelines.zh-CN.md，.php-cs-fixer.php。

