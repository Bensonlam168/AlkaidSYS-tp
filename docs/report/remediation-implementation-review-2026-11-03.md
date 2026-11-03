# 重启整改实施评审报告（2026-11-03）

| 项目 | 内容 |
| --- | --- |
| 评审目的 | 复核执行代理的整改，判断能否恢复开发及是否达到企业生产发布标准 |
| 评审日期 | 2026-11-03 |
| 原始审计基线 | `914f2b4fb2d410476b2035d77c1af636b39b1e0c` |
| 本次代码基线 | `develop`，`34ba19cf95be5bf6199c3bf3b654dd38b652ddd4` |
| 变更规模 | 92 个提交；排除插件 vendor 出库后，470 个文件，新增 47,089 行、删除 4,000 行 |
| 依据 | Always Rules、技术规格、设计契合度基线及上一轮 ER-01～ER-20 |
| 工作方式 | 主审与三个专项独立复核，源码调用链、容器内定向检查、安全替身探针、GitHub 只读核验 |
| 本次产出范围 | 评审和开发指引文档；未修改业务代码、依赖、配置或迁移账本 |

> **评审状态**：已完成上述提交的风险导向复核。本文是版本快照，不是上线批准。后续开发使用[开发指引](../todo/enterprise-restart-development-guide-2026-11-03.md)；原始问题背景见[企业生产级重启审计](enterprise-production-restart-audit-2026-10-31.md)。

## 1. 评审结论

**本轮整改有实质效果，已从“验证信号不可信”推进到“可以开展有门禁的整改开发”；尚不具备企业生产发布条件，也不宜全面铺开低代码和工作流业务开发。**

测试隔离、CI 解耦、迁移守卫、授权子集校验、停用主体检查和探针分工都值得保留。它们没有自动关闭资源所有权、凭据管理边界、并发撤权、Schema 发布和业务持久性问题。应继续在现有技术栈上分包收敛，当前证据不支持全盘重写。

| 决策 | 结论 | 条件 |
| --- | --- | --- |
| 接受本轮工程治理成果 | 有条件接受 | 保留有效修复；不能把“CI 已绿”扩写成“企业生产级整改完成” |
| 开展后续整改开发 | 可以 | 继续使用隔离测试资源；每包以行为验收收口 |
| 正常开发系统管理功能 | 有限开展 | 先补凭据管理、会话和授权边界；新功能不依赖尚未完成的能力 |
| 大范围开发低代码业务 | 暂缓 | SQL 组成、物理表所有权、Schema 发布与服务端授权先收敛 |
| 将工作流用于真实审批 | 暂缓 | 演示内存态、mock 数据节点、仓储及执行内核契约必须先解决 |
| 发布企业生产版本 | 不通过 | 本文 P0/P1 阻断和选定业务范围的端到端、部署、恢复证据尚未关闭 |

本次未确认新的“无需认证即可全面接管系统”的漏洞。文中的高风险路径均保留权限、部署或业务承诺前提；没有执行账号接管、注入载荷、跨租户删表或故障停机试验。

## 2. 方法与证据边界

### 2.1 评审范围

先读 Always Rules、Auto Guidelines、开发工作流、设计契合度报告、技术规范和阶段 A 方案，再对照提交与源码。重点覆盖认证/授权/租户、低代码/事件/工作流、测试/CI/交付。92 个提交包含大量格式化、文档和第三方依赖出库；本次没有声称对 4,740 个总变更文件逐行审计。

证据标签含义：**[源码]** 是可追踪调用链；**[实测]** 是本次命令结果；**[替身]** 是无业务写入的控制流或内存时序探针；**[远端]** 是 GitHub API/日志；**[实施记录]** 是代理台账，本次未完整重跑。后两种不能混成独立本地实测。

所有 PHP、Composer 和后端测试均在 `alkaid-backend` 内执行，PHP 8.2.29，项目目录 `/var/www/html`。没有执行迁移、seed、全量主测试、插件全套、Composer 安装/升级、生产栈启动或镜像构建。定向测试仅使用已审阅的隔离路径；临时探针采用替身仓储和内存缓存。

### 2.2 已独立确认的有效改进

| 改进 | 当前证据 | 可接受的结论 |
| --- | --- | --- |
| 测试库隔离 | [ThinkPHPTestCase](../../tests/ThinkPHPTestCase.php#L149) 过滤 DB 环境覆盖、配置显式连接；[CliProcess](../../tests/Support/CliProcess.php) 为子进程生成独立环境 | 不能继续说“主测试仍默认写开发库”；名字检查只是第一层，应继续分离账号与实例权限 |
| 测试拒绝开发库 | 本次反射调用守卫：`alkaid_sys` 被拒，`alkaid_sys_test` 被接受 | fail-closed 分支真实存在；此探针未做写库对照 |
| 事务隔离钩子 | [DatabaseTransactions](../../tests/Traits/DatabaseTransactions.php) 使用 PHPUnit Before/After 属性 | 消除同名 setUp 覆盖 trait 导致隔离失效的一类问题；DDL 仍不能依赖普通事务回滚 |
| CI 测试恢复执行 | 当前提交远端 Unit、Feature、Legacy 步骤全部成功；无风格 job 前置依赖 | 真实运行已证实，不能继续沿用“测试一直被 skip”的旧结论 |
| 空跑转失败 | 根配置及插件配置已有 failOnEmptyTestSuite、failOnRisky、failOnWarning；Legacy 已进套件 | 该类假绿明显收敛；Skipped/Incomplete/Deprecation 仍有独立口径 |
| 迁移账本守卫 | 检查 F 和默认 dry-run 的协调工具已加入 | 可以发现新孤儿记录；既有 `20251125` 仍在有期限豁免中 |
| 主体状态 | [PrincipalStateChecker](../../infrastructure/Auth/PrincipalStateChecker.php#L103) 接入 Access 与 Refresh | 用户/租户停用、删除、过期会在状态检查处拒绝；密码变更和站点生命周期仍需补齐 |
| 主要赋权入口 | [GrantCeilingService](../../infrastructure/Permission/Service/GrantCeilingService.php#L91)、角色/用户控制器接线 | 提交权限/角色超出操作者权限集的直接提权已得到针对性约束 |
| 租户参数 | 核心仓储签名必填、Role 更新 tenant 条件、集合删除传 tenant | 原漏传 tenant 导致元数据跨租户访问的入口有修复；不等于所有 site/后台任务边界已完成 |
| 缓存清理 | 权限键族白名单、SCAN、主要写入口失效、缓存旁路异常与核心异常分开 | 优于整库清理和完全不失效；并发旧值回写问题仍在 |
| 事件失败 | EventPayload 记录监听器失败，AsyncEventJob 据此重试，失败字段实际进入日志正文 | 已解决旧“监听器失败但误判成功”分支；可靠投递仍未闭环 |
| 镜像基础 | `.dockerignore`、8080 对齐、非 root、生产去源码挂载与后台端口 | 改造方向有效；当前 Dockerfile 和生产入口仍需修复 |
| 探针语义 | `/healthz` 与 `/readyz` 分开，Session 前旁路 | 运行期 Redis 抖动不应再通过会话路径拖垮 liveness；启动和整条生产路径未实测 |
| 插件治理 | 嵌套 vendor 跟踪数为 0；原 DynamicPropsTrait 可加载，FQCN 守卫存在 | 保留依赖出库与命名空间迁移成果；生产宿主依赖未闭合 |

## 3. 本轮必须处理的评审发现

编号 RV-01～RV-20 独立于原 ER 编号。P0 为优先封闭的高风险组成边界；P1 为所选范围的生产阻断；P2 为需排期的治理或受限场景问题。“本轮剩余”包含未完成的原问题，“补充发现”不代表漏洞由本轮提交引入。

### RV-01：凭据管理绕过授予边界（P1，补充发现）

**[源码]** [重置密码路由](../../route/admin.php#L235) 只要求 `user.write`；[UserController](../../app/controller/admin/UserController.php#L487) 仅确认目标属于当前租户，第 505–510 行更新密码并返回新密码，未检查操作者能否管理该目标主体。对比 setRoles 的授予子集校验，第 448 行已经接线。

**触发与影响**：若一个委派管理员只有 `user.write`，同租户另有更高权限的活跃账号，前者可以重置后者密码并以其身份登录。该路径无需提交任何超限角色，因此新增 grant ceiling 不会阻止。未实际重置任何账号，也未证明跨租户接管。

**要求与验收**：建立“可管理主体”的应用服务边界，覆盖密码、登录标识、MFA、状态和角色；弱主体不能修改强主体凭据。用双主体用例验证越权重置零写入、合法委派成功、平台账号受独立保护。仅给重置端点增加通用权限名不构成验收。

### RV-02：最终 JWT 站点没有经过当前有效性和用户授权检查（P1，本轮剩余）

**[源码]** [SiteIdentify](../../app/middleware/SiteIdentify.php#L101) 验证原始头，未带站点头时第 109–122 行直接继续；[Auth](../../app/middleware/Auth.php#L198) 随后用 JWT 覆盖 tenant/site，主体检查只收 user/tenant；[refresh](../../app/controller/AuthController.php#L335) 原样续签旧 site。

**[替身]** 本次把验签和用户/租户状态替换为受控返回，调用真实中间件顺序：先输出 `site_without_header=0`，Auth 后输出 `effective_site_after_auth=4294967294`。这只证明最终站点没有被该链校验，不代表这个虚构站点拥有业务数据。

**影响**：非零站点 Token 签发后，即使站点停用、删除或改变归属，省略站点头仍可能继续访问和刷新。已有头部格式、存在与归属检查应保留，但不能替代最终身份检查。

**要求与验收**：认证后对最终 tenant/site 校验存在、归属、状态和用户站点权限；登录、注册、刷新统一解析规则。覆盖无头、相同头、冲突头、站点停用/删除/迁移以及 `site_id=0` 的明确语义。

### RV-03：缓存清除后仍可能被并发旧读取回写（P1，本轮剩余）

**[源码]** [PermissionCacheService](../../infrastructure/Permission/Service/PermissionCacheService.php#L206) 先 fetch，再无版本检查写入 L1/L2；[Invalidator](../../infrastructure/Permission/Cache/PermissionCacheInvalidator.php#L119) 只删除现有键。清理异常被记录后返回失败统计，业务调用通常仍返回成功。

**[替身]** 内存缓存中执行“读取旧快照→模拟另一请求撤权并清空全部缓存→返回旧快照并回写→再次取权”，输出 `cached_permissions_after_revocation=["review:write"]`、`authoritative_fetch_called=no`。未操作 Redis 或角色表。这证明了旧读在清除后回写的确定时序，尚未进行跨进程压力复现。

**影响**：顺序撤权测试成功不足以证明跨 worker/实例立即生效。缓存删除失败但旧值仍可读取时，也可能继续授权。

**要求与验收**：撤权事务提升权威权限版本/epoch，缓存键或值绑定版本，读取与回写校验版本；定义缓存故障安全策略。用屏障控制旧读与提交顺序，验证多进程、删除失败、恢复后均不恢复旧权限。

### RV-04：密码重置和账号恢复未吊销旧会话（P1，本轮剩余）

**[源码]** [updatePassword](../../infrastructure/User/Repository/UserRepository.php#L404) 只更新密码；[状态校验](../../infrastructure/Auth/PrincipalStateChecker.php#L115) 没有会话版本或安全戳。现有会话测试还把“恢复 active 后同一 Token 再次可用”作为预期。

**影响**：密码重置用于处置泄漏时，旧 Access/Refresh 仍可能继续使用；临时停用再恢复后，未过期的旧会话可复活。逐请求检查停用状态本身有效，剩余问题是吊销语义。

**要求与验收**：定义重置密码、改密码、锁定、恢复及强制登出的会话版本策略。重置后旧 Access/Refresh 均拒绝；恢复账号只允许新登录建立新会话。同步更新测试预期，不能保留相互冲突的契约。

### RV-05：Refresh 轮换仍不是原子单次消费（P1，原问题未处理）

**[源码]** [validateRefreshToken](../../infrastructure/Auth/JwtService.php#L365) 只读白名单；[控制器](../../app/controller/AuthController.php#L335) 签发两个新 Token 后才删除旧记录。校验、签发、消费未形成原子协议。

**要求与验收**：同一 Refresh 多进程并发只能产生一个有效后继；制定 token family、重复使用、失败重试和后继登记规则。不能以顺序重放测试代替并发验证。本次未执行并发刷新攻击。

### RV-06：低代码标识符仍可进入 SQL 组成（P0，原问题未处理）

**[源码]** [CollectionController](../../app/controller/lowcode/CollectionController.php#L126) 接收 `table_name`；[Collection](../../domain/Lowcode/Collection/Model/Collection.php#L54) 直接赋值；[CollectionManager](../../infrastructure/Lowcode/Collection/Service/CollectionManager.php#L83) 先检查表存在；[SchemaBuilder](../../infrastructure/Schema/SchemaBuilder.php#L148) 拼接 `SHOW TABLES LIKE`，第 260 行拼接列检查。

**前提与影响**：持有 `lowcode.write` 的调用者可影响 SQL 组成部分。生产 DDL 拒绝文案的修复不保护这段更早执行的查询。本次未投递注入载荷，未证明任意堆叠语句可执行。

**要求与验收**：统一标识符验证与保留资源保护，数据值参数化；限制自定义物理表映射。HTTP、服务直接调用、SchemaBuilder、迁移生成均覆盖引号、反引号、通配符、控制字符、长度和保留字段。

### RV-07：租户元数据删除已修，物理共享表所有权仍未建立（P1，本轮剩余）

**[源码]** [TableNameGenerator](../../infrastructure/Lowcode/Collection/Service/TableNameGenerator.php#L20) 默认共享 `lc_{name}`；创建复用已有表未验证 Schema；[delete](../../infrastructure/Lowcode/Collection/Service/CollectionManager.php#L255) 已正确传 tenant 删除元数据，但第 259–260 行仍可删除整张表；接口 `drop_table` 默认 true。

**影响与边界**：同名异构模型冲突仍在；DDL 允许环境下删除一租户可能损伤共享物理资源。生产 DDL guard 会阻断 DROP，不能声称已证明生产跨租户删表。

**要求与验收**：先接受物理表所有权 ADR，再改实现和历史映射；默认集合删除处理授权范围内元数据/数据，物理销毁走受控发布。覆盖同名同构、同名异构、删一方、并发建模与旧库迁移。

### RV-08：Schema 发布一致性仍缺失（P1，原问题未处理）

**[源码]** [CollectionManager](../../infrastructure/Lowcode/Collection/Service/CollectionManager.php#L55) 在普通事务内执行 MySQL DDL，并在第 107、110 行缓存/触发事件，第 114 行才 commit；[SchemaVersionService](../../infrastructure/Lowcode/Schema/SchemaVersionService.php#L65) 读取最大版本后加一，第 90–100 行吞异常并返回 version 0。

[FieldManager](../../infrastructure/Lowcode/Collection/Service/FieldManager.php#L123) 的更新仍未应用 `$changes`，ALTER 为 TODO；目前不能据此声称已经存在一个可工作的 HTTP 字段更新端点。[migration:diff --all](../../app/command/lowcode/MigrationDiffCommand.php#L83) 仍只读取默认第一页 20 项。

**要求与验收**：形成草稿、校验、计划、审核、应用、激活及失败恢复协议；DDL 与业务事务分工，版本分配并发安全、关键记录失败阻断激活。验证 DDL 后失败、版本冲突、缓存故障、超过 20 个集合、旧库升级与重入。租户参数改造不能作为该项关闭证据。

### RV-09：字段权限仍是 UI 元数据（P1，若首期承诺字段授权）

**[源码]** [FormDataManager](../../infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php#L98) 按字段是否属于集合筛选写入；第 139–145 行读取未按调用者裁剪字段，Schema 解析出的可见/禁用属性没有形成服务端授权。

**要求与验收**：明确模型、记录、字段三层规则；敏感字段的 HTTP、导出、批量、流程和任务读写走统一策略。前端继续复用 `validator.rules/messages` 与 `parsed.components/permissions`，前端隐藏不能作为数据保护。

### RV-10：工作流修复停留在基础整理，真实执行链仍缺失（P1，若纳入首期）

**[源码/实测]** [实例控制器](../../app/controller/workflow/WorkflowInstanceController.php#L48) 等仍使用 static 数组，集成 Repository 为桩；[数据操作节点](../../plugins/workflow-engine/src/Nodes/Types/DataOperationNode.php#L613) 的插入返回随机 ID，查询返回空数组，更新/删除返回模拟成功。

根 autoload 探针：原 `Alkaid\Workflow\Engine\libs\traits\DynamicPropsTrait` 可加载，`DI\ContainerBuilder` 和 `madong\helper\Dict` 不可加载。根 Composer 只映射源码，没有完整安装和装载插件依赖；[WorkflowContainer](../../plugins/workflow-engine/src/Container/WorkflowContainer.php#L113) 默认将多个 service 指向接口。不可据此断言现有每个工作流端点都会 500，缺依赖影响启用相应内核的路径。

台账记载插件独立套件 `1043 tests / 3046 assertions / 24 errors`，本次未重跑，属于实施记录。主 CI 绿不包含这套独立测试。

**要求与验收**：先决定唯一执行内核、宿主依赖、定义版本、实例/任务/历史契约及首期范围；未完成入口关闭或明确不可用。验收真实申请→审批→低代码数据变化→worker/进程重启恢复→并发与重复投递不重复执行。把 DI errors 机械归零不能替代这个闭环。

### RV-11：异步重试修复有效，DLQ 失败仍丢失可重放消息（P1，关键消息场景）

**[源码]** [AsyncEventJob](../../domain/Event/AsyncEventJob.php#L79) 已识别 payload 监听器失败并重试；但第 106–134 行达到上限后，即使死信记录失败仍删除任务。[EventMonitor](../../infrastructure/Event/EventMonitor.php#L84) 还会内部吞下死信写库异常，以日志替代。对应单测明确要求“DLQ 失败也 delete”，所以单测通过证明当前约定，不能证明生产可靠性。

**影响与条件**：监听器持续失败且 DLQ 不可写时，队列记录被确认删除，库中没有可重放死信；日志为尽力写入。默认 [queue](../../config/queue.php#L14) 仍为 sync，Redis 队列地址写死 localhost。RabbitMQ 容器存在不代表可靠异步已经启用。

**要求与验收**：区分尽力通知与关键业务事件；关键消息在持久隔离存储确认成功前不 ACK，失败时退避、告警；事务后可靠投递/Outbox、消费者幂等和重放共同验收。不要把“避免无限重试”解释为允许无持久归宿地删除。

### RV-12：Dockerfile 源码层仍在构建期启动应用（P1，本轮交付未闭环）

**[源码]** [Dockerfile](../../Dockerfile#L137) install 已有 `--no-scripts`，但第 153 行 dump-autoload 未加；根 Composer post-autoload-dump 会运行 service:discover，进而触发 Redis boot 自检。构建期没有应用环境文件和运行栈 Redis 名称。仓库 CI 注释已有同路径构建失败日志，本次未重新 build。

**要求与验收**：构建期不访问运行依赖；完整处理服务发现和发布资源在构建/启动中的归属，不能只删脚本后遗漏生成物。从干净检出构建镜像，再用外置配置启动、检查平台依赖和业务冒烟。runner PHP job 的成功不能代替镜像验收。

### RV-13：生产 env_file 的外部依赖地址被继承配置覆盖（P1，本轮生产编排缺陷）

**[源码/实测]** [基础 compose](../../docker-compose.yml#L155) 在 backend.environment 写死 `mysql/redis/rabbitmq`；[生产 overlay](../../docker-compose.prod.yml#L92) 增加 env_file，仅覆盖 APP_ENV/DEBUG。Compose 的 environment 优先于 env_file。

本次在 `/tmp` 用配置副本与虚构外部地址执行 `docker compose config --no-interpolate --format json`，退出 0，结果仍为 `DB_HOST=mysql`、`REDIS_HOST=redis`、`APP_ENV=production`。未读取或打印真实凭据，未启动服务。

**要求与验收**：生产连接参数只有一套明确来源，消除开发 environment 残留。用与开发不同的外部地址验证最终渲染及容器有效配置，同时校验 DB 名、账号与 Redis DB；仅查看模板文件不足以验收。

### RV-14：生产 Nginx 仍使用开发预检和 TLS 骨架（P1，独立 origin / TLS 入口条件）

**[源码]** [生产 overlay](../../docker-compose.prod.yml#L154) 挂载 `alkaid.api.conf`；该文件 [map](../../deploy/nginx/alkaid.api.conf#L64) 只允许 localhost/127.0.0.1，OPTIONS 在第 92–100 行直接 204，生产应用 CORS 配置无法修复这个提前响应。第 153 行监听 443 无 ssl，证书行仍被注释。

**影响**：前端与 API 使用不同生产 origin，Authorization 等请求触发预检后会被浏览器阻断；无上游 TLS 终止时不能提供预期 HTTPS。有上游 TLS 的部署应明确拓扑，本次未证明实际外部入口没有 TLS。

**要求与验收**：建立实际域名、TLS 终止、可信代理和 CORS 一致的入口配置。不要直接换入包含固定上游占位地址的另一份 prod.conf。经真实 Nginx 验证合法/非法 Origin、OPTIONS、鉴权请求、上传和 HTTPS。

### RV-15：锁文件漂移与生产依赖公告仍未处置（P1，原问题未处理）

**[实测]** `composer validate --no-check-publish --no-interaction` exit 2，明确报告 lock 与 json 不同步；`composer audit --locked --no-dev --format=json --no-interaction` exit 1，9 个生产包共 24 条公告：Critical 1、High 3、Medium 16、Low 3、未评级 1。

| 包 | 锁定版本 | 公告数 | 最高/相关级别 |
| --- | --- | --- | --- |
| firebase/php-jwt | v6.11.1 | 1 | Low |
| guzzlehttp/guzzle | 7.10.0 | 9 | High |
| guzzlehttp/psr7 | 2.8.0 | 4 | Medium |
| league/flysystem | 3.30.2 | 1 | Low |
| phpseclib/phpseclib | 3.0.47 | 5 | High |
| predis/predis | v3.2.0 | 1 | Critical，CVE-2026-84372 |
| symfony/cache | v7.3.6 | 1 | Medium |
| symfony/expression-language | v7.3.2 | 1 | 未评级，CVE-2026-109449 |
| symfony/process | v7.3.4 | 1 | Medium |

公告命中不等于应用已可利用；需要检查真实调用、部署平台和输入可信性。主门禁未阻断该风险；[PR 安全扫描](../../.github/workflows/branch-protection.yml#L368) 对 audit 失败只警告。

**要求与验收**：先恢复锁文件一致性，再分批修补生产依赖并回归；每条例外记录可达性、缓解、负责人和到期日，不能全局忽略。插件依赖与第三方许可证来源另行核对；本次不作法律结论。

### RV-16：主前端会话和权限路由契约仍不一致（P1，原问题未处理）

**[源码]** [auth API](../../frontend/apps/web-antd/src/api/core/auth.ts#L37) 丢弃 refresh_token，第 51、60 行调用错误刷新/注销路径，并将 withCredentials 放入 body；实际刷新是 `/v1/auth/refresh` 且要求 Refresh Authorization，后端无 logout 路由。[拦截器](../../frontend/apps/web-antd/src/api/request.ts#L65) 把刷新响应视为单 Token。[admin 路由](../../frontend/apps/web-antd/src/router/routes/modules/admin.ts#L25) 仍硬编码 admin/test_admin，与 Always Rules 的权限码依据冲突。

自动刷新默认关闭，不应声称每次登录都立即失败；本地清空状态也不等于服务端会话吊销。

**要求与验收**：先定令牌保存、刷新并发和注销协议，再修前后端；菜单/按钮以统一权限码判断。浏览器验收登录、过期续期、并发 401、撤权、切租户/站点和注销后旧 Token 失效。

### RV-17：绿灯范围仍小于交付范围，前端 CI 已实测失败（P1/P2，按发布范围）

**[远端]** 当前主提交后端 CI：Unit 512 tests/2175 assertions，含 3 skipped、3 incomplete、32 PHPUnit deprecations；Feature 223/2179；Legacy 4/17。合计 739 tests/4371 assertions，不含 Integration、E2E 和插件独立套件。台账 812/4780 是另一运行口径，本次未全量重跑。

[前端工作流](../../.github/workflows/frontend-ci.yml#L27) 用 setup-node 的 npm cache 查根目录锁文件；最新前端 run 在 setup 阶段因找不到 package-lock/yarn.lock 失败，测试和构建未执行。后续还存在 Node 18 不满足 engines ≥20.10、`pnpm typecheck` 根脚本不存在、pnpm 版本来源冲突、构建产物路径和 echo 部署占位。

**要求与验收**：按选定发布范围加入 Integration/E2E、主应用使用的插件能力、生产镜像与前端构建验收；为 skipped/incomplete 明确台账和有效期。未纳入的能力必须关闭或声明不可用。修前端 CI 后先实跑再设 required，避免永久 pending/失败锁库。

### RV-18：日志、探针和可信 IP 尚未形成生产运维闭环（P1/P2）

**[源码]** [log 配置](../../config/log.php#L20) 默认 File，max_files=0；Nginx 写自定义普通日志文件。生产 overlay 移除 backend 卷而未接入日志采集；Docker json-file 轮转只约束 stdout/stderr，不约束上述文件。重建可能丢日志，长时间运行也缺磁盘上界。外部采集系统是否存在，本次未取得证据。

[readyz](../../app/controller/HealthController.php#L479) 对外返回异常原文前 200 字符，探针旁路又跳过 AccessLog/RateLimit；当前入口未限制探针网络访问。[AccessLog](../../app/middleware/AccessLog.php#L121) 仍直接取 XFF 第一项，与已修复的 RateLimit 可信代理算法不一致。限流的 user scope 当前是诚实关闭/跳过，tenant scope 仍在 Auth 前消费头部租户，不能宣称认证后配额已落实。

**要求与验收**：统一可信客户端地址解析；探针限制内部访问，响应使用稳定原因码、详情入日志；建立结构化采集、留存、轮转、重建追溯和磁盘预算。readiness 的 PDO 连接超时不等于端到端查询 deadline，文档“最坏约 4 秒”还需受控故障试验。备份恢复、RPO/RTO 和告警演练仍是上线必需证据。

### RV-19：表单严格租户校验发生在持久化调用之后（P2，本轮契约缺陷）

**[源码/替身]** [FormSchemaManager](../../infrastructure/Lowcode/FormDesigner/Service/FormSchemaManager.php#L72) 先 save，第 79 行才 requireTenantId；前置验证只用 empty。替身仓储下传入非空非法值时，输出 `form_save_calls_before_rejection=1`，随后报 tenant_id 非 numeric。

真实 Repository 对值先转 int，但真实落库结果还取决于字段和数据库约束，本次未做写库证明。HTTP 控制器会用服务器上下文覆盖 tenant，不能把该内部顺序缺陷描述为已经证明的 HTTP 伪造租户漏洞。

**要求与验收**：在任何 Repository 调用前校验、归一化并复用同一个 tenant 值；非法参数零持久化调用。覆盖字符串、小数、指数、空值和合法整数。

### RV-20：能力与未决事项台账仍会掩盖真正的开发阻断（P2，治理）

**[源码/远端]** [未决台账](../todo/open-items-2026-11-03.md) 开头仍写领先远端 299 个提交，但 §1 已解决；“集中两处”忽略上述安全、低代码和生产阻断。§3.9/3.10 在后续提交已修，表格仍列未做。项目全貌又混有历史快照和局部新指标。

当前 API 已能读取分支保护，不能继续写“本地无法读取，只能人工确认”：develop/main 均配置七项 required，develop strict=true、main strict=false；两者 enforce_admins=false，未要求 PR review，规则集列表为空。现有 CODEOWNERS 文件不能据此被认为已强制评审。

**要求与验收**：以“能力/承诺/入口/证据/阻断/负责人”维护当前状态；历史报告保留历史。本文附 ER 映射与后续指引，不能再以“小项已划掉”推导“剩余只有插件 DI”。平台权限与全局权限元数据边界也要纳入：权限详情仍[返回全局角色关联](../../infrastructure/Permission/Repository/PermissionRepository.php#L302)，租户接口应裁剪。

## 4. 原 ER-01～ER-20 的复核状态

“部分”表示有效修复可保留，但原验收条件尚未全部满足；“已实现，验收待补”专门区分源码落实与生产证明。

| 原编号 | 本次状态 | 主要依据 / 剩余 |
| --- | --- | --- |
| ER-01 SQL 组成 | 未处理 | RV-06，DDL 文案修复不覆盖前置查询 |
| ER-02 共享表所有权 | 未处理 | RV-07，tenant 元数据隔离不代表物理资源所有权 |
| ER-03 MISSING 迁移 | 守卫已实现，历史未协调 | 检查 F 本次通过但明确打印 `20251125` 豁免，至 2027-02-28 |
| ER-04 会话生命周期 | 部分 | 用户/租户状态检查有效；RV-02/04，站点、密码变更和旧会话复活 |
| ER-05 平台/租户授予边界 | 部分 | 赋权子集有效；RV-01 凭据管理绕过，平台主体和全局元数据边界仍需确定 |
| ER-06 撤权缓存 | 部分 | 主要入口已接清除；RV-03 并发/故障仍未收敛 |
| ER-07 站点和租户上下文 | 部分 | tenant 必填和 Swoole 判据有效；RV-02/19，CLI 默认关闭作用域等边界待验收 |
| ER-08 Refresh 原子轮换 | 未处理 | RV-05 |
| ER-09 限流信任边界 | 部分 | XFF 算法已修；user scope 关闭，认证后 tenant/user 配额、日志 IP 未统一 |
| ER-10 Schema 一致性 | 未处理 | RV-08 |
| ER-11 字段授权 | 未处理 | RV-09，按首期承诺决定是否阻断该能力 |
| ER-12 工作流执行链 | 基础整理，核心未实现 | RV-10，不能用命名空间修复替代持久执行链 |
| ER-13 异步失败 | 部分 | 失败识别和重试有效；RV-11 的持久投递、DLQ、幂等仍需落实 |
| ER-14 前端协议 | 未处理核心问题 | RV-16，站点持久化和权限编辑局部修复不代表会话闭环 |
| ER-15 测试资源隔离 | 主要实现，扩大验收 | DB/子进程隔离与守卫已实现；需独立账号、DDL/Redis/队列/文件资源的并发隔离证据 |
| ER-16 CI 信号 | 主要修复，发布覆盖不足 | 当前远端确实成功；RV-17，尚未覆盖完整交付 |
| ER-17 依赖与供应链 | 部分 | vendor 出库有效；RV-15，lock 和公告未处理，许可证仍待核实 |
| ER-18 生产产物 | 部分 | 基础加固有效；RV-12/13/14 阻断 |
| ER-19 运维证明 | 部分建设，无生产验收 | 探针已实现；RV-18、恢复/容量/故障演练缺证据 |
| ER-20 能力台账 | 未完成统一闭环 | 旧 open-items 是实施备忘，不能代替完整能力台账；本表提供当前起点 |

**关于 BaseModel 的附加边界**：HTTP 下已确认表有 tenant 列而无上下文时会拒绝；CLI 默认关闭作用域。列探测失败时当前仍不加过滤，注释推定业务 SQL 也必失败，这不是普遍可证明的安全契约。新业务不要在未经专项验收前依赖这个抽象作为唯一隔离措施；本次未证明实际生产模型已借此越租户。

## 5. 本次核验记录

### 5.1 本地命令与安全探针

表内后端命令均以 `docker exec alkaid-backend` 为前缀，前端命令在 `frontend/` 内执行。检查的“通过”只覆盖脚本实际检查项，含显式豁免。

| 命令 / 探针 | 结果 | 解释 |
| --- | --- | --- |
| `php scripts/check-docs.php --strict` | exit 0，新增两份文档及更新入口后扫描 215 文件 | 扫描范围、链接、围栏和示例类引用均通过 |
| `php scripts/check-phpunit-config.php --strict` | exit 0 | 插件和性能变体有显式豁免；性能变体仍有安全属性差异 |
| `php scripts/check-env-example.php --strict` | exit 0 | 模板一致性不等于实际生产配置安全 |
| `php scripts/check-migrations.php --strict` | exit 0 | 38 个迁移文件；F 中旧版本 `20251125` 仍豁免 |
| `php scripts/check-migration-data.php --strict` | exit 0 | 7/7 数据型迁移参与；未重新执行迁移或协调账本 |
| `php think lang:check` | exit 0 | en-us、zh-cn 一致 |
| `php think route:verify --strict` | exit 0 | 当前开发环境 121/121；不代表 production 路由数量相同 |
| `composer validate --no-check-publish --no-interaction` | exit 2 | lock 漂移 |
| `composer audit --locked --no-dev --format=json --no-interaction` | exit 1 | 24 公告 / 9 生产包，见 RV-15 |
| `./vendor/bin/phpunit --filter 'RateLimitClientIpTest\|BaseModelTenantScopeGuardTest\|ThrowableDegradationTest' --no-progress --do-not-cache-result` | exit 0，31 tests / 104 assertions | 32 条 PHPUnit 弃用提示，未计为失败 |
| `./vendor/bin/phpunit --filter EventFailureObservabilityTest --no-progress --do-not-cache-result` | exit 0，13 tests / 85 assertions | 同样报告 32 条弃用提示；DLQ 删除约定见 RV-11 |
| 库名守卫反射探针 | exit 0，开发库拒绝、测试库接受 | 没有写库 |
| 上下文顺序/表单顺序替身探针 | exit 0 | RV-02、RV-19；不代表攻击 HTTP 实测 |
| 内存权限缓存时序探针 | exit 0 | RV-03；未接 Redis/业务库 |
| 临时 compose 渲染 | exit 0，外部地址被覆盖 | RV-13；未启动栈 |
| 根 loader 探针 | exit 0，trait 可加载、DI/helper 缺失 | RV-10；不等于所有 API 失败 |
| `pnpm exec vitest run apps/web-antd/src/views/system/permission/__tests__/permission-form.test.ts packages/stores/src/modules/tenant.test.ts --dom` | 75 秒超时，无完成结果 | 未认定前端通过或断言失败；未遗留该测试进程 |
| `php scripts/check-rawsql-safety.php --strict` | exit 1，154 文本命中 | 人工审查清单，连注释也命中；不能把 154 当作 154 个 SQL/越租户漏洞 |

原始本地日志、公告 JSON、探针与远端快照暂存于 `/tmp/alkaid-remediation-review-2026-11-03/`，该路径会被清理，不能充当长期版本证据。关键结果已写入本文；后续实施应将脱敏验收结果随对应 PR 保存。

### 5.2 真实 CI 和仓库设置

当前 SHA 的八个 check-runs 全部 success，分属五份工作流：[Backend CI](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41593269528)、[Migrations Check](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41593269535)、[Docs Check](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41593269520)、[PHPUnit Config Check](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41593269569)、[Env Example Check](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41593269881)。不要再笼统称为“所有 CI 全绿”。

最新触及前端的 [Frontend CI/CD](https://github.com/Bensonlam168/AlkaidSYS-tp/actions/runs/41580892839) 对应较早提交 `1e5c00e`，结论 failure：两个 job 的 setup-node 查 npm 锁文件失败；构建/性能/部署 skipped。当前 SHA 没触发该路径工作流，不能把其缺席视为已修复。

只读 API 已确认：develop 与 main 各配置 AGENTS.md 列出的七项 required；develop 要求最新基分支，main 不要求；两者管理员约束未开启、PR review 未要求，仓库 rulesets 为空。未修改远端设置。单维护者不应直接开启一个自己无法满足的自审门禁，应先确定第二评审人或透明的例外流程。

### 5.3 本次没有取得的发布证据

全量随机顺序测试的本轮独立结果、旧库副本升级与恢复、真实多进程刷新/撤权、隔离 Redis/队列故障、生产镜像 build/start、生产域名浏览器链路、负载和容量、备份恢复与 RPO/RTO、实际告警接收、第三方许可证清单。台账或“存在配置文件”不能替代这些证据。

## 6. 对后续开发的裁决

优先顺序应是：**可信台账与发布范围 → 凭据/会话/授权边界及 SQL 封闭 → 物理资源与 Schema 发布 → 可构建产物和真实入口 → 一个可持久业务切片 → 恢复/故障/容量演练 → 小范围上线。** 镜像/配置和前端 CI 可作为独立包并行推进，但不能以它们转绿替代业务边界修复。

不要继续围绕 `.gitignore` 死规则、文档行数和插件 error 数做无终点清扫；这些项目可以排期，当前阻断是可触发的权限/数据/交付问题。也不要为获取绿灯而删除失败用例、吞异常或把尚未实现的生产能力仅改成“部分实现”。

具体任务包、先后依赖、ADR 决策、每包交付物、验收矩阵和执行代理交接模板见[后续开发指引](../todo/enterprise-restart-development-guide-2026-11-03.md)。需要业务方最终确定的只有首个业务切片、工作流是否必需、site 授权范围、实际部署/存量数据、SLO/容量和 RPO/RTO；不妨碍先完成已明确的缺陷修复设计。
