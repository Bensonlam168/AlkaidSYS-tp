+# 环境配置的两道校验：模板静态检查与运行期检查

> 🔶 **实现状态**：**已实现** —— 已实现：`php scripts/check-env-example.php --strict`（模板静态检查，检查 A~F）、
> `php think env:check`（运行期检查，含生产必需项的取值规则与条件必需项告警）、
> 生产类环境的失败退出码与脱敏输出。未实现：生产必需清单的**单一真相源**（两份清单仍在两个文件里，
> 见 §7）；`docs/deployment/environment-variables.md` 仍由 `env:check --generate` 生成，
> 尚未包含本轮新增的 `DEFAULT_TENANT_ID` 行（该文件不在本规范维护范围内）。

本文回答三个问题：两个校验工具**各自管什么**、**什么时候跑哪个**、以及「生产必需」到底包不包含某个键。

---

## 1. 两个工具的职责边界（结论：不重叠、不矛盾）

| 维度 | `php scripts/check-env-example.php` | `php think env:check` |
| --- | --- | --- |
| 检查对象 | **文件**：`.env.example` / `.env.production.example` | **生效配置**：`env()` 实际读到的值（`.env` 或 `PHP_ENV_NAME` 指定的文件 + 进程环境） |
| 运行时机 | 提交前 / CI（纯静态，无数据库、无网络） | 部署前（需要在目标环境里跑，读得到真实值） |
| 判定内容 | 可解析 / 无伪键 / 注释字符规范 / 必需键存在 / 生产模板键覆盖 | 键是否配置、取值是否合规（占位符、正整数、长度、root、bool）、条件必需项 |
| 失败语义 | 模板坏了 → 所有人都会踩 | 这台机器的配置坏了 → 只有这次部署会炸 |
| 退出码 | 有问题即 1（`--strict` 下告警也算） | 生产类环境有不合格项即 1；非生产环境只提示 |
| CI 门禁 | `Env Example Consistency` | 无（属部署侧动作） |

**为什么不重叠**：两者的输入根本不同 —— 前者看**版本库里的模板文本**，后者看**进程实际拿到的值**。
模板里写 `REPLACE_WITH_PRODUCTION_DB_NAME` 是**合法且必须**的（模板就该写占位符），所以静态检查
**不能**判值；反过来，运行期检查看不到「模板是否比示例少了 12 个键」，因为缺失的键在运行期只是「没设置」。
一句话：**静态检查保「模板完整」，运行期检查保「这台机器的取值可用」。**

**为什么不矛盾**：静态检查要求「生产模板覆盖示例的每个键」，运行期检查要求「生产环境里这些键的取值合规」。
一个连「键都在」都过不了的环境，根本轮不到判值；一个「键都在但仍是占位符」的环境正是运行期检查要拦的。

**边界上唯一需要人工意识到的点**：`env:check` 不检查 `.env.production` 是否存在、也不检查键是否齐全
（它只检查自己定义表里的键），也**不**做「运行期值 ↔ 模板」的一致性比对 —— 那是 §7 的建议内容。

---

## 2. 什么时候跑哪个（部署流水线建议顺序）

```text
提交前 / PR
  └─ php scripts/check-env-example.php --strict     # 模板没被改坏、键没丢

镜像构建 / 部署前（在目标环境或同构环境里）
  ├─ 1) php think env:check --strict                 # 生产类环境：不合格即中止发布
  ├─ 2) php think migrate:run                        # 结构变更
  └─ 3) 起服务 → GET /readyz                          # 依赖（DB / Redis / 配置就绪）
```

三条必须知道的运行前提：

1. **`env:check` 自己不连库、不连 Redis**，但它跑在一个**会**做启动期自检的进程里：
   `app/provider/RedisHealthCheckService` 在 `cache.default === 'redis'`（**生产类环境被 `config/cache.php` 强制为 redis**）
   或 session 使用 `cache + redis` 时，启动期会做一次 Redis 读写自检，失败直接抛异常。
   因此：**生产类环境里 `REDIS_HOST` 不可达时，`env:check` 根本执行不到**（报错信息里带 host/port，仍然可定位）。
   这不算信息丢失 —— 它恰好解释了为什么 `REDIS_*` **没有**进「生产必需」清单（见 §4）。
2. `env:check` 读的 `.env` 与「能否解析」的检查对象是**仓库根 `.env`**；用 `PHP_ENV_NAME=<name>` 切换环境时，
   取值来自 `.env.<name>`，但「文件能否解析」这一段仍然看 `.env`（这是 R37 的历史行为）。
3. **不要**在生产容器里靠 `--strict` 之外的宽松模式发布：非严格模式只保证「硬性不合格项」为 0，
   告警（如 `TRUSTED_PROXIES` 未配置、`APP_ENV` 取到未知值）不会阻断。

---

## 3. 生产必需项清单与逐键理由

判定标准（三条同时满足才纳入）：**① 配错不会让进程启动失败；② 故障出现在第一个业务请求或第一次鉴权；
③ 有唯一正确的「可判定」取值形态（而不是调优空间）。**

| 键 | 生产规则 | 为什么算「生产必需」 |
| --- | --- | --- |
| `DEFAULT_TENANT_ID` | 非空、非占位符、正整数 | `TenantIdentify` 是**全局**中间件，未配置即 fail-closed 抛异常：所有不带 `X-Tenant-ID` 的请求（含探针）全部 500，且**进程能正常启动** |
| `DB_HOST` | 非空、非占位符 | 占位符/写错 → 每条 SQL 都连不上，全站 500；进程启动不受影响 |
| `DB_NAME` | 非空、非占位符 | 端口能连上不代表库名对，报错在第一个查询（`Unknown database`） |
| `DB_USER` | 非空、非占位符、**非 `root`** | `root` 是全库权限；应用侧任何注入或泄露直接扩散到整库 |
| `DB_PASS` | 非空、非占位符、**非仓库内公开的 dev 默认口令** | 空口令 / 复用 `.env.example` 与 compose 里的公开口令，等于没有口令 |
| `JWT_SECRET` | 非空、非占位符、**≥32 字节** | 占位符是仓库内公开字符串，等于任何人可伪造任意用户 token；由于 `JwtService` 是惰性构造，进程不失败，**第一个鉴权请求**才被拒 |
| `APP_DEBUG` | 非空、**必须为 false** | `true` 时错误页把堆栈、SQL、绝对路径回显给调用方；`config/database.php` 的 `trigger_sql` 缺省取 `true`，所以「没设置」比「显式 false」更危险 |

规则与运行期实现同源、只是**更早**：

- `JWT_SECRET`：与 `Infrastructure\Auth\JwtService::assertProductionSecretIsSecure()`（空 / 公开占位符 / `<32` 字节）判定一致；
- `APP_DEBUG`：与 `HealthController::checkConfig()` 的就绪判定一致；
- `DEFAULT_TENANT_ID`：与 `TenantIdentify::getDefaultTenantId()`（`null` / `''` / `int <= 0` 一律 fail-closed）一致。

也就是说，本清单**只把已有的运行期约束提前**，没有发明新规则。若某天运行期规则变了，这里必须同步改。

### 非生产环境的行为

同一套规则在非生产环境**降级**，逐条声明处理方式（`production_rules` 的第二个维度）：

| 处理方式 | 用于哪些规则 | 理由 |
| --- | --- | --- |
| `warn`（提示，不阻断） | `not_empty` / `not_placeholder` / `positive_int` | 「配错了在哪个环境都是错的」：占位符租户 ID 在 dev 也会让请求 500，值得提醒 |
| `ignore`（静默） | `not_root` / `not_dev_default_credential` / `min_length:32` / `bool_false` | 这些是**文档化的本地默认值**：dev 里 `DB_USER=root`、`DB_PASS=root`、`APP_DEBUG=true`、短 dev 密钥都正确；对它们刷告警只会训练开发者忽略输出 |

因此 dev 环境的 `env:check` 仍然 `exit 0`（唯一会看到的提示是「JWT_SECRET 仍是 `CHANGE_THIS_IN_PRODUCTION`」）。
注意 `--strict` 会把 `warn` 级提示升格为失败 —— 这正是「严格模式 = 我要求生产级配置」的语义。

---

## 4. `TRUSTED_PROXIES`：条件必需，不做硬性必填

**结论：条件必需 —— 仅当「生产类环境」且「`RATELIMIT_ENABLED=true`」时才必需；缺失只告警。**

判定依据（`config/ratelimit.php` 的 `trusted_proxies` 注释 + `app/middleware/RateLimit::resolveClientIp()`）：

- 缺失时**不会报错**，只会让限流的 ip 维度静默退化成「全站共用一个桶」（不采信 `X-Forwarded-For`，用 TCP 对端地址），
  表现为经反代后整站 429；
- 空值本身是**安全默认值**（不信任任何 XFF）。填错（例如填一个大网段）比留空更糟：它把伪造 `X-Forwarded-For` 的能力交回客户端；
- 「是否经反向代理」是**部署拓扑**问题，不是「是不是生产」问题 —— 客户端直连的部署本就不需要它。

因此它**不进**必填集合，只由 `env:check` 的 `Conditional (production)` 段输出告警并说明条件：

```text
== Conditional (production) | 条件必需项 ==
  ⚠ TRUSTED_PROXIES: 未配置：经反向代理且开启限流时，所有客户端会共用同一个 IP 限流桶（可能整站 429）
      条件: 仅当「请求经反向代理」且「RATELIMIT_ENABLED=true」时必需；客户端直连可忽略本条
      修复: 填真实反向代理 / 负载均衡器地址（逗号分隔，支持 IPv4 / IPv6 / CIDR），例如 10.0.0.5
```

条件不成立时该段输出 `· TRUSTED_PROXIES: 非生产环境不评估` 或 `· ... RATELIMIT_ENABLED 未开启，本项不被消费`，
让「检查过它」这件事在输出里可见，而不是悄悄跳过。

---

## 5. 刻意**不**纳入必需集合的键（逐个给结论）

| 键 | 结论 | 理由 |
| --- | --- | --- |
| `CACHE_DRIVER` | 调优/拓扑项 | 生产类环境被 `config/cache.php` **强制为 redis**，这个键在生产的实际影响已被覆盖；单实例用 `file` 也是合法部署 |
| `REDIS_*` | 已由启动期自检覆盖 | `RedisHealthCheckService` 在启动期做读写自检并 fail-closed（含 host/port），重复登记只会产生第二处真相 |
| `DB_PORT` / `DB_CHARSET` / `DB_PREFIX` / `DB_TYPE` | 非必需 | 有安全且通用的默认值（3306 / utf8mb4 / 空前缀 / mysql），属于部署细节 |
| `CORS_ALLOWED_ORIGINS` | 保留原 `production_warning`（只告警） | 是否需要跨域取决于前端是否同源部署；硬性必填会把「同源部署」误判为不合格 |
| `APP_ENV` | 不新增阻断 | 但**新增一条告警**：取值不在词表（`prodcution` 之类笔误）时提示，或**未设置**时提示「本命令按 local、而 JwtService / HealthController 按 production」——否则笔误会让整套生产校验静默跳过 |
| `ALLOW_RUNTIME_DDL` | 不检查 | 生产类环境即便设为 `true` 也会被 `SchemaBuilder` 拦截（纵深防御已在代码里），不必重复 |

---

## 6. 输出与脱敏约定

- **逐条列出**：哪个键（`✗ KEY:` 前缀）、当前值、为什么不合格、怎么改（`修复:`）。
- **脱敏是硬要求**：键名命中 `/(?:SECRET|PASSWORD|PASS|TOKEN|PRIVATE_KEY|API_KEY)/i` 时，
  只输出**前 2 个字符 + 总长度**，例如 `"ro"***（已脱敏：共 4 字符）`。原因：`env:check` 的输出会原样进 CI 日志。
- 非敏感键全量显示，但**截断到 64 字符**并去掉控制字符，避免一条日志裂成多行。
- 失败时退出码为 **1**（沿用既有约定）；非生产环境只提示，退出码保持 **0**。

---

## 7. 单一真相源：建议（本轮**未实施**）

现状是两份清单、两个文件：

- `scripts/check-env-example.php` 的 `REQUIRED_KEYS`（**模板文件**必须有这些键）；
- `app/command/EnvCheckCommand.php` 的 `$envDefinitions`（**运行期**这些键的取值规则）。

它们目前不会漂移，因为回答的问题不同（键集合 vs 取值）。真正会漂移的是**未来的「生产必需」清单**：
运行期与文档、与部署脚本各写一份时，总会有一份落后。

如需收敛（建议在动它之前先确认收益），最小改动方案是：

1. 新增 `app/support/EnvRequirementCatalog.php`（纯静态数组，不依赖容器）：以键为键，记录
   `production_required` / `production_rules` / `production_hint`；
2. `EnvCheckCommand::$envDefinitions` 只保留「分类 / 类型 / 描述 / 默认值」，生产规则从 catalog 合并；
3. `scripts/check-env-example.php` 的 `REQUIRED_KEYS` 改为「catalog 中 `template_required=true` 的子集」。

不改架构也能接受的替代方案：在 `env:check` 里对「catalog 中存在但定义表里没有」的键报错，
用测试钉住两边键集合的关系。**本轮不做的原因**：`scripts/check-env-example.php` 不在本次改动范围内，
而它在 CI 里是 required 门禁，改它需要单独一轮评审。

---

## 8. 已知限制（直说）

1. **`env:check` 不查数据库**：无法验证 `DEFAULT_TENANT_ID` 指向的租户是否真实存在（只验证形态）。
   真实存在性由 `/readyz` 与首个业务请求暴露。
2. **生产类环境依赖 Redis 可达**（§2 第 1 条）：Redis 不可达时命令执行不到，报的是 Redis 自检错误。
3. **`--strict` 的语义变化**：`warn` 级提示（如 dev 的 `JWT_SECRET` 占位符）在 `--strict` 下会失败。
   先前在 dev 跑 `--strict` 是绿的，现在会红 —— 这是刻意的：严格模式就是「按生产标准判」。
4. **`docs/deployment/environment-variables.md` 由 `env:check --generate` 生成**，本轮新增的
   `DEFAULT_TENANT_ID` 行需由该命令重新生成后回填（该文件不在本规范维护范围）。
