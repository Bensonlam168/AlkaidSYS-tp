# 环境配置的三道校验：模板静态检查、生产预检与运行期检查

> ✅ **实现状态**：**已实现** —— 已实现：
> `php scripts/check-env-example.php --strict`（模板静态检查，检查 A~F）、
> `php scripts/check-env-production.php`（**生产预检，不启动 App**，因此不依赖 Redis / DB）、
> `php think env:check`（运行期检查，含生产必需项的取值规则与条件必需项告警）、
> 生产必需项的**单一真相源** `app\support\EnvRequirementCatalog`（命令与预检脚本共用一份规则）、
> 生产类环境的失败退出码与脱敏输出。
> 未实现：`docs/deployment/environment-variables.md` 仍由 `env:check --generate` 生成，
> 尚未包含 `DEFAULT_TENANT_ID` / `APP_ENV` 的新增行（该文件不在本规范维护范围内）；
> `scripts/check-env-example.php` 的 `REQUIRED_KEYS` 仍是一份独立清单（它是「模板必须有这些键」，
> 与「取值规则」语义不同，见 §8.4）；CI 尚未接入生产预检（方案见 §9）。

本文回答四个问题：三个校验工具**各自管什么**、**什么时候跑哪个**、**「生产必需」到底包不包含某个键**、
以及**规则养在哪里**（避免两处各写一份）。

---

## 1. 三个工具的职责边界（结论：不重叠、不矛盾）

| 维度 | `scripts/check-env-example.php` | `scripts/check-env-production.php` | `php think env:check` |
| --- | --- | --- | --- |
| 检查对象 | **版本库里的模板文本**：`.env.example` / `.env.production.example` | **某一个 env 文件**（默认 `.env.production`） | **生效配置**：`env()` 实际读到的值（`.env` 或 `PHP_ENV_NAME` 指定的文件 + 进程环境） |
| 是否需要启动 App | 不需要 | **不需要**（不 `new App()`、不 `initialize()`） | 需要（整个 `App::initialize()`） |
| 依赖 | 无（无 DB、无 Redis、无网络） | 无（无 DB、无 Redis、无网络） | **生产类环境要求 Redis 可达**（见 §2 前提 1） |
| 运行时机 | 提交前 / CI | 部署前（含「依赖还不通」的时刻） | 部署前（依赖已就绪时） |
| 判定内容 | 可解析 / 无伪键 / 注释字符规范 / 必需键存在 / 生产模板键覆盖 | 文件可解析 / 8 个生产必需项的**取值** / 条件必需项 | 键是否配置、取值是否合规（占位符、正整数、长度、root、bool）、条件必需项 |
| 失败语义 | 模板坏了 → 所有人都会踩 | 这份文件**照抄上生产**就会炸 | 这台机器的配置坏了 → 只有本次部署会炸 |
| 退出码 | 有问题即 1（`--strict` 下告警也算） | 不合格 / 读不到 / 解析失败即 1；`--strict` 下告警也算 | 生产类环境有不合格项即 1；非生产环境只提示 |
| CI 门禁 | `Env Example Consistency` | 尚未接入（方案见 §9） | 无（属部署侧动作） |

**为什么不重叠**：三者的输入根本不同 —— 一个看**模板文本**，一个看**某一份 profile 文件**，
一个看**进程里真正生效的值**。模板里写 `REPLACE_WITH_PRODUCTION_DB_NAME` 是**合法且必须**的
（模板就该写占位符），所以静态检查**不能**判值；反过来，运行期检查看不到「模板比示例少了 12 个键」，
因为缺失的键在运行期只是「没设置」。

一句话：**模板检查保「模板完整」，生产预检保「这份文件能上生产」，运行期检查保「这台机器的取值可用」。**

**为什么不矛盾**：静态检查要求「生产模板覆盖示例的每个键」，生产预检要求「这份文件的取值合规」，
运行期检查要求「这台机器上这些键生效且合规」。一个连「键都在」都过不了的环境，根本轮不到判值；
一个「键都在但仍是占位符」的环境正是生产预检与运行期检查要拦的。

**边界上需要人工意识到的点**：三个工具都**不**做「运行期实际值 ↔ 模板」的一致性比对，
也都不检查键集合是否齐全（除 `check-env-example.php` 的模板覆盖检查之外）。

---

## 2. 什么时候跑哪个（部署流水线建议顺序）

```text
提交前 / PR
  └─ php scripts/check-env-example.php --strict       # 模板没被改坏、键没丢

镜像构建 / 部署前（在目标环境或同构环境里）
  ├─ 1) php scripts/check-env-production.php --strict  # 只读 .env.production：不启动 App，Redis/DB 不通也能跑
  ├─ 2) php think env:check --strict                   # 依赖就绪后按「生效配置」再判一次（含进程环境）
  ├─ 3) php think migrate:run                          # 结构变更
  └─ 4) 起服务 → GET /readyz                            # 依赖（DB / Redis / 配置就绪）
```

四条必须知道的运行前提：

1. **`env:check` 自己不连库、不连 Redis，但它跑在一个会做启动期自检的进程里。**
   `app\provider\RedisHealthCheckService` 在 `cache.default === 'redis'`
   （**生产类环境被 `config/cache.php` 强制为 redis**）或 session 使用 `cache + redis` 时，
   启动期会做一次 Redis 读写自检，失败直接抛异常。因此：
   **生产类环境里 `REDIS_HOST` 不可达时，`env:check` 根本执行不到**（实测见 §3.4）。
   这不是信息丢失，但它意味着「最需要预检的时刻跑不了预检」—— 这正是 §3 那个脚本存在的理由。
2. `env:check` 读的 `.env` 与「能否解析」的检查对象是**仓库根 `.env`**；用 `PHP_ENV_NAME=<name>` 切换环境时，
   取值来自 `.env.<name>`，但「文件能否解析」这一段仍然看 `.env`（历史行为）。
   **生产预检不受这条限制**：`--env-file=<path>` 指哪个文件就读哪个文件。
3. **不要**在生产容器里靠 `--strict` 之外的宽松模式发布：非严格模式只保证「硬性不合格项」为 0，
   告警（如 `TRUSTED_PROXIES` 未配置、`APP_ENV` 取到未知值）不会阻断。
4. 生产预检与运行期检查**都不能**替代 `/readyz`：前者只读文件，后者不会替你验证 DB / Redis 连通性。

---

## 3. 生产预检脚本：`scripts/check-env-production.php`

### 3.1 用法与退出码

```bash
php scripts/check-env-production.php                            # 默认读 .env.production
php scripts/check-env-production.php --env-file=/etc/alkaid/.env.production
php scripts/check-env-production.php --env-file=.env.production.example   # 模板自检（预期失败）
php scripts/check-env-production.php --strict                   # 条件必需项的告警也算失败
```

| 退出码 | 含义 |
| --- | --- |
| `0` | 通过（`--strict` 下要求连告警都没有） |
| `1` | 存在不合格项；**或文件不存在 / 无法解析**（一律不伪装成通过）；或 `--strict` 下存在告警 |

三条设计决定，都是为了「结论不能被误读」：

1. **解析失败 = 退出码 1，而不是告警。** 它用 `parse_ini_file(..., INI_SCANNER_RAW)` —— 与
   `think\Env::load()` **完全相同的调用方式**，而后者是 `parse_ini_file(...) ?: []`：
   解析失败即整份配置静默失效（典型成因：`#` 注释行里的半角括号 / 引号 / 竖线 / 与号 / 美元符号）。
   这种情况报「通过」等于把最严重的故障当成好消息。
2. **文件不存在 = 退出码 1。** 没有文件就没有任何可判定的取值，「跳过」在这里是没有意义的宽容。
3. **解析失败时不逐键判定。** 一个键都读不到，继续判只会刷出 30 条误导性的「未配置或为空」，
   把真正的原因（第几行、哪个字符）埋掉。

### 3.2 输出示例（`--env-file=.env.production.example`，真实输出）

```text
Production Environment Precheck | 生产配置预检（不启动 App）
================================================================
仓库根: /var/www/html
环境文件: .env.production.example
运行方式: 不 new App() / 不 initialize() —— 因此不依赖 Redis、不依赖 DB
规则来源: app/support/EnvRequirementCatalog.php（与 php think env:check 同一份，零依赖）

检查 A  文件存在且可解析          OK
      目标文件: /var/www/html/.env.production.example
解析结果: 26 个键

检查 B  生产必需项取值            6 个错误
      覆盖: 8 个键（APP_ENV, APP_DEBUG, DEFAULT_TENANT_ID, DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET）
   [REQ] DEFAULT_TENANT_ID  当前值: "REPLACE_WITH_REAL_TENANT_ID"
      · 命中占位标记「REPLACE」：这是模板/示例值，必须替换为真实配置
      · 必须是正整数（> 0）：0、负数、小数与非数字都会被中间件判为「未配置」
      修复: DEFAULT_TENANT_ID 必须是 tenants 表中真实存在的租户 ID（正整数），例如 1；…
```

**在当前仓库上 `--env-file=.env.production.example` 返回 1 是预期结论，不是缺陷**：
模板里 6 个键仍是占位符（`DEFAULT_TENANT_ID` / `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` /
`JWT_SECRET`），而 `APP_ENV=production` 与 `APP_DEBUG=false` 是通过的 —— 也就是说失败原因确实是
「占位符没替换」，不是「模板写错了」。这条用例的价值在于：**任何把模板当配置的行为都必须被拦下**。

### 3.3 它**不能**做什么（边界，请勿当成「部署前万能检查」）

| 不能做 | 为什么 | 该由谁负责 |
| --- | --- | --- |
| 看到「运行期实际生效值」 | `env()` 的取值会叠加进程环境，`PHP_ENV_NAME` 还会整体替换环境文件名。脚本只读你指定的那一个文件 | `php think env:check`（读生效值） |
| 检查 DB / Redis 连通性、库名是否存在 | 它不建立任何连接 —— 这既是「不启动 App」的代价，也是它能在依赖不通时跑的原因 | `/readyz`、启动期自检 |
| 验证 `DEFAULT_TENANT_ID` 指向的租户真实存在 | 只验证「正整数」这个形态 | `/readyz`、首个业务请求 |
| 检查键集合是否齐全 | 它只判自己规则表里的 8 个键 | `scripts/check-env-example.php`（模板覆盖） |
| 判断 `DB_PASS` 是否足够强 | 只排除「空 / 占位符 / 仓库内公开的 dev 默认口令」 | 口令策略 + 人工 / 密钥管理 |

一句话：**它保证「这份配置的取值形态是对的」，不保证「这台机器现在能跑」。**

### 3.4 为什么不能直接用 `env:check`（实测证据）

同一份取值（`APP_ENV=production` + `REDIS_HOST=127.0.0.1` + 一个已关闭的端口）：

```bash
$ PHP_ENV_NAME=<production profile> php think env:check
  [RuntimeException]
  Redis health check failed (host=127.0.0.1, port=6399): Connection refused
$ echo $?
0        # 命令体一行都没执行（没有 == Summary ==、没有通过结论），但退出码仍是 0

$ php scripts/check-env-production.php --env-file=<same profile>
检查 A  文件存在且可解析          OK
检查 B  生产必需项取值            OK
检查 C  条件必需项                OK
✓ 全部检查通过 | All checks passed
$ echo $?
0        # 真的逐键判定过
```

两个结论：

1. **运行期检查在依赖不通时不可用**（异常发生在 `App::initialize()` 阶段，命令体执行不到）；
2. **该路径下 `php think env:check` 的退出码仍是 0** —— 框架只渲染异常、不设置退出码
   （`app\AppService` 只为「`.env` 解析失败」那条路径注册了 `exit(1)` 的 shutdown 钩子）。
   也就是说，部署脚本用 `env:check || exit 1` 判断，在「生产配置根本起不来」时**看到的是成功**。
   §9 的 CI 接入建议因此优先接**生产预检脚本**，而不是只在 CI 里跑 `env:check`。
   这条退出码问题本身尚未修复（属 `app/provider/**` 的启动期行为，需单独评审）。

---

## 4. 生产必需项清单与逐键理由

判定标准（三条同时满足才纳入）：**① 配错不会让进程启动失败；② 故障出现在第一个业务请求或第一次鉴权；
③ 有唯一正确的「可判定」取值形态（而不是调优空间）。**

| 键 | 生产规则 | 为什么算「生产必需」 |
| --- | --- | --- |
| `APP_ENV` | 必须是生产类取值（`production` / `prod` / `stage` / `staging`） | 它决定 `config/cache.php` 是否强制 redis、`config/swoole.php` 是否热重载、异常页是否只给通用文案。写错（`prodcution`）或留空时这些策略**静默**退回开发行为，而进程照常启动 |
| `APP_DEBUG` | 非空、**必须为 false** | `true` 时错误页把堆栈、SQL、绝对路径回显给调用方；`config/database.php` 的 `trigger_sql` 缺省取 `true`，所以「没设置」比「显式 false」更危险 |
| `DEFAULT_TENANT_ID` | 非空、非占位符、正整数 | `TenantIdentify` 是**全局**中间件，未配置即 fail-closed 抛异常：所有不带 `X-Tenant-ID` 的请求（含探针）全部 500，且**进程能正常启动** |
| `DB_HOST` | 非空、非占位符 | 占位符/写错 → 每条 SQL 都连不上，全站 500；进程启动不受影响 |
| `DB_NAME` | 非空、非占位符 | 端口能连上不代表库名对，报错在第一个查询（`Unknown database`） |
| `DB_USER` | 非空、非占位符、**非 `root`** | `root` 是全库权限；应用侧任何注入或泄露直接扩散到整库 |
| `DB_PASS` | 非空、非占位符、**非仓库内公开的 dev 默认口令** | 空口令 / 复用 `.env.example` 与 compose 里的公开口令，等于没有口令 |
| `JWT_SECRET` | 非空、非占位符、**≥32 字节** | 占位符是仓库内公开字符串，等于任何人可伪造任意用户 token；由于 `JwtService` 是惰性构造，进程不失败，**第一个鉴权请求**才被拒 |

`APP_ENV` 的一条特殊语义：它的规则在 `env:check` 里**恒真**（`$isProduction` 本来就由同一个取值算出），
所以它不会改变命令的任何输出；真正的负载在**生产预检脚本**侧（那里强制按生产判定）。

规则与运行期实现同源、只是**更早**：

- `JWT_SECRET`：与 `Infrastructure\Auth\JwtService::assertProductionSecretIsSecure()`（空 / 公开占位符 / `<32` 字节）判定一致；
- `APP_DEBUG`：与 `HealthController::checkConfig()` 的就绪判定一致；
- `DEFAULT_TENANT_ID`：与 `TenantIdentify::getDefaultTenantId()`（`null` / `''` / `int <= 0` 一律 fail-closed）一致。

也就是说，本清单**只把已有的运行期约束提前**，没有发明新规则。若某天运行期规则变了，这里必须同步改。

### 非生产环境的行为

同一套规则在非生产环境**降级**，逐条声明处理方式（`production_rules` 值的第二个维度）：

| 处理方式 | 用于哪些规则 | 理由 |
| --- | --- | --- |
| `warn`（提示，不阻断） | `not_empty` / `not_placeholder` / `positive_int` | 「配错了在哪个环境都是错的」：占位符租户 ID 在 dev 也会让请求 500，值得提醒 |
| `ignore`（静默） | `not_root` / `not_dev_default_credential` / `min_length:32` / `bool_false` / `production_env` | 这些是**文档化的本地默认值**：dev 里 `DB_USER=root`、`DB_PASS=root`、`APP_DEBUG=true`、短 dev 密钥、`APP_ENV=dev` 都正确；对它们刷告警只会训练开发者忽略输出 |

因此 dev 环境的 `env:check` 仍然 `exit 0`（唯一会看到的提示是「JWT_SECRET 仍是 `CHANGE_THIS_IN_PRODUCTION`」）。
注意 `--strict` 会把 `warn` 级提示升格为失败 —— 这正是「严格模式 = 我要求生产级配置」的语义。

**生产预检脚本没有这个降级**：它固定按生产标准判定（否则「`APP_ENV` 写错」会顺带把其它键全部放过）。

---

## 5. `TRUSTED_PROXIES`：条件必需，不做硬性必填

**结论：条件必需 —— 仅当「生产类环境」且「`RATELIMIT_ENABLED=true`」时才必需；缺失只告警。**

判定依据（`config/ratelimit.php` 的 `trusted_proxies` 注释 + `app/middleware/RateLimit::resolveClientIp()`）：

- 缺失时**不会报错**，只会让限流的 ip 维度静默退化成「全站共用一个桶」（不采信 `X-Forwarded-For`，用 TCP 对端地址），
  表现为经反代后整站 429；
- 空值本身是**安全默认值**（不信任任何 XFF）。填错（例如填一个大网段）比留空更糟：它把伪造 `X-Forwarded-For` 的能力交回客户端；
- 「是否经反向代理」是**部署拓扑**问题，不是「是不是生产」问题 —— 客户端直连的部署本就不需要它。

因此它**不进**必填集合，由 `env:check` 的 `Conditional (production)` 段与生产预检脚本的「检查 C」输出告警：

```text
  ⚠ TRUSTED_PROXIES: 未配置：经反向代理且开启限流时，所有客户端会共用同一个 IP 限流桶（可能整站 429）
      条件: 仅当「请求经反向代理」且「RATELIMIT_ENABLED=true」时必需；客户端直连可忽略本条
      修复: 填真实反向代理 / 负载均衡器地址（逗号分隔，支持 IPv4 / IPv6 / CIDR），例如 10.0.0.5
```

条件不成立时两个工具都输出 `跳过（条件不成立）`（而不是静默消失），让「检查过它」这件事在输出里可见。

---

## 6. 刻意**不**纳入必需集合的键（逐个给结论）

| 键 | 结论 | 理由 |
| --- | --- | --- |
| `CACHE_DRIVER` | 调优/拓扑项 | 生产类环境被 `config/cache.php` **强制为 redis**，这个键在生产的实际影响已被覆盖；单实例用 `file` 也是合法部署 |
| `REDIS_*` | 已由启动期自检覆盖 | `RedisHealthCheckService` 在启动期做读写自检并 fail-closed（含 host/port），重复登记只会产生第二处真相 |
| `DB_PORT` / `DB_CHARSET` / `DB_PREFIX` / `DB_TYPE` | 非必需 | 有安全且通用的默认值（3306 / utf8mb4 / 空前缀 / mysql），属于部署细节 |
| `CORS_ALLOWED_ORIGINS` | 保留原 `production_warning`（只告警，仅在 `env:check` 里） | 是否需要跨域取决于前端是否同源部署；硬性必填会把「同源部署」误判为不合格。它**不在** catalog 里，因此生产预检不判它 |
| `ALLOW_RUNTIME_DDL` | 不检查 | 生产类环境即便设为 `true` 也会被 `SchemaBuilder` 拦截（纵深防御已在代码里），不必重复 |

---

## 7. 输出与脱敏约定

- **逐条列出**：哪个键（`✗ KEY:` / `[REQ] KEY` 前缀）、当前值、为什么不合格、怎么改（`修复:`）。
- **脱敏是硬要求**：键名命中 `/(?:SECRET|PASSWORD|PASS|TOKEN|PRIVATE_KEY|API_KEY)/i` 时，
  只输出**前 2 个字符 + 总长度**，例如 `"ro"***（已脱敏：共 4 字符）`。
  原因：`env:check` 的输出会进 CI 日志，生产预检的输出会进发布日志。
  脱敏实现在 `EnvRequirementCatalog::describeValue()`（**一处实现、两个消费者共用**）。
- 非敏感键全量显示，但**截断到 64 字符**并去掉控制字符，避免一条日志裂成多行。
- 失败时退出码为 **1**；非生产环境的 `env:check` 只提示，退出码保持 **0**。
- 生产预检的结论文案与退出码**必须一致**：不出现「exit 0 但显示检查未通过」的矛盾输出
  （告警未阻断时输出 `✓ 全部检查通过（另有 N 个告警，未阻断；--strict 下会失败）`）。

---

## 8. 单一真相源（**已实施**）

### 8.1 之前有什么问题

「哪些键是生产必需、各自什么规则、不合格怎么改」原先只写在
`app\command\EnvCheckCommand` 的 `$envDefinitions` 里。任何第二个消费者只有两条路：
**复制一份**（必然漂移）或**把自己的判定偷偷写松**（门禁形同虚设）。
上一轮就明确记录过这个风险（当时是 §7「建议·未实施」）。

### 8.2 现在的结构

```text
app/support/EnvRequirementCatalog.php        ← 唯一一份：8 个键 + 规则 + 提示 + 脱敏 + 求值
        ├── app/command/EnvCheckCommand.php            （composer autoload，读生效配置，按分类排版输出）
        └── scripts/check-env-production.php           （require 源文件，读文件，按清单排版输出）
```

分工：

| 层 | 由谁负责 |
| --- | --- |
| 键清单 / 规则名 / 规则实现 / 脱敏 / 提示文案 | `EnvRequirementCatalog`（**零依赖**：不 import ThinkPHP 类、不调 `env()`、不碰容器） |
| 取值来源 | 命令：`env()`；脚本：`parse_ini_file(..., INI_SCANNER_RAW)` |
| 排版与退出码 | 各自负责（命令用 `✗/⚠` + Summary；脚本用 `检查 A/B/C` + `[TAG]`） |

**零依赖是刻意的**：脚本要在「Redis / DB 可能根本不通」的时刻运行，甚至要在
`composer install` 之前运行（部署流水线的第一步）。因此脚本不 require `vendor/autoload.php`，
而是直接 `require_once app/support/EnvRequirementCatalog.php`（若 composer autoload 已注册则优先用它）。

### 8.3 落地后的三条保证（都有测试钉住）

| 保证 | 钉住它的测试 |
| --- | --- |
| catalog 里每个键都必须在命令的定义表里有分类/描述（否则它不会出现在 `env:check` 输出里） | `tests/Unit/Command/EnvRequirementCatalogConsistencyTest::testCommandDefinitionsCoverEveryCatalogKey` |
| 命令里的 `production_rules` / `production_hint` **就是** catalog 里的那份（合并来的，不是副本） | 同上 `::testMergedRulesAndHintsMatchCatalog` |
| 声明的规则名都已实现（拼错会「永远通过」） | 同上 `::testEveryDeclaredRuleIsImplemented` |

另外，脚本自身的行为（含「Redis 不可达仍能跑」）由
`tests/Unit/Scripts/EnvProductionPrecheckTest` 用**真实子进程**覆盖。

### 8.4 尚未收敛的第四份清单

`scripts/check-env-example.php` 的 `REQUIRED_KEYS`（`DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` /
`DB_CHARSET` / `JWT_SECRET`）**没有**并入 catalog，理由是它回答另一个问题：
「**模板文件**里必须有这些键」（键存在性，`DB_CHARSET` 也在其中），而 catalog 回答
「**取值**是否合格」（`DB_CHARSET` 刻意不判值）。
把两者强行合并会逼着 `DB_CHARSET` 要么进 catalog（违反 §6 的结论）要么从模板检查里消失（丢失保护）。
若将来要收敛，正确的形状是 catalog 增加一个 `template_required => bool` 维度，而不是把两份清单取并集 ——
那需要单独一轮评审（`check-env-example.php` 是 CI 的 required 门禁）。

---

## 9. CI 接入建议（**方案，尚未落地**）

### 9.1 建议：给生产预检单独一个 job，而不是塞进 `env-check.yml`

`env-check.yml`（check 名称 `Env Example Consistency`）现在跑的是**模板**静态检查，
语义是「版本库里的模板没被改坏」。把「某份 `.env.production` 的取值是否合格」塞进同一个 job 会让
同一个 check 名称同时代表两件事，出问题时分不清是哪一层红。

而且 CI 里没有真实的 `.env.production`（它在 `.gitignore` 里）。CI 上唯一有意义的生产预检形态是
**用 fixtures 断言脚本的判定逻辑**（占位符必红、合规必绿、注释坏了必红），
这本质上属于测试而不是「检查本仓库的配置」—— 它已经被 §8.3 的 PHPUnit 用例覆盖。

### 9.2 如果仍要接成一个 check，建议的最小 diff（**未应用**）

```diff
@@ .github/workflows/env-check.yml 的 jobs 段 @@
  jobs:
    env-example-check:
      name: Env Example Consistency
      ...

+   # 生产预检脚本的「判定逻辑」门禁：用仓库内的模板与一次性的临时文件做断言。
+   # 放在独立 job 而不是上面的 job 里：check 名称必须能唯一指出「哪一层红了」。
+   env-production-precheck:
+     name: Env Production Precheck (script behaviour)
+     runs-on: ubuntu-latest
+     if: github.repository == 'Bensonlam168/AlkaidSYS-tp'
+     steps:
+       - uses: actions/checkout@v4
+       - uses: shivammathur/setup-php@v2
+         with:
+           php-version: '8.2'
+           coverage: none
+       # 1) 模板自检：必须失败（模板里全是占位符），失败即「脚本确实在判值」
+       - name: Template must be rejected
+         run: |
+           if php scripts/check-env-production.php --env-file=.env.production.example; then
+             echo "❌ 模板里全是占位符，预检却通过了 —— 判定逻辑失效"
+             exit 1
+           fi
+       # 2) 合规文件：必须通过（值全部写死在这里，不读任何真实密钥）
+       - name: Compliant file must pass
+         run: |
+           tmp="$(mktemp)"
+           cat > "$tmp" <<'ENV'
+           APP_ENV=production
+           APP_DEBUG=false
+           DEFAULT_TENANT_ID=1
+           DB_HOST=db-primary.internal
+           DB_NAME=alkaid_prod
+           DB_USER=alkaid_app
+           DB_PASS=placeholder-for-ci-only
+           JWT_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
+           ENV
+           php scripts/check-env-production.php --env-file="$tmp"
```

**不接成 required 的理由**（比「为什么该接」更重要）：这条 check 只证明「脚本的判定逻辑没坏」，
它**看不到**任何真实部署的配置；把它设为 required 只会给人一种「生产配置已被检查」的错觉。
真正必须做的是**在部署流水线里跑**
`php scripts/check-env-production.php --env-file="$ENV_FILE"`（失败即中止发布），
那属于仓库外部的部署编排，不是 CI 的 check。

### 9.3 为什么它比 `env:check` 更适合做部署前的必过项

| 维度 | 生产预检脚本 | `env:check` |
| --- | --- | --- |
| 依赖 | 无（不启动 App） | 生产类环境要求 Redis 可达 |
| 失败退出码 | 恒为 1（含解析失败 / 文件缺失） | 生产类环境不合格为 1，但**启动期异常路径实测退出码为 0**（§3.4） |
| 能指定文件 | `--env-file` | 只能读 `.env` / `PHP_ENV_NAME` |
| 输出 | 逐条列出 + 修复建议 | 同（更详细，含 27 个键的全量体检） |

结论：**部署前**用生产预检（它一定能给出结论）；**依赖就绪后**再用 `env:check`
（它能发现脚本看不到的东西：进程环境覆盖、以及 19 个非生产必需键的缺失）。

---

## 10. 已知限制（直说）

1. **两个工具都不查数据库**：无法验证 `DEFAULT_TENANT_ID` 指向的租户是否真实存在（只验证形态）。
   真实存在性由 `/readyz` 与首个业务请求暴露。
2. **`env:check` 在生产类环境依赖 Redis 可达**（§2 前提 1）；Redis 不可达时命令执行不到。
   生产预检脚本可以在这种时刻跑，但它看不到「实际生效值」，两者不能互相替代（§3.3）。
3. **启动期异常路径的退出码问题是既有缺陷**：实测 `php think env:check` 在 Redis 自检失败时
   以 **0** 退出（§3.4）。修复点在 `app/provider/**` 的启动期行为，需单独评审后再动。
4. **`--strict` 的语义变化**：`warn` 级提示（如 dev 的 `JWT_SECRET` 占位符）在 `--strict` 下会失败。
   先前在 dev 跑 `--strict` 是绿的，现在会红 —— 这是刻意的：严格模式就是「按生产标准判」。
5. **`docs/deployment/environment-variables.md` 由 `env:check --generate` 生成**，
   新增的 `DEFAULT_TENANT_ID` / `APP_ENV` 行需由该命令重新生成后回填（该文件不在本规范维护范围）。
6. **`REQUIRED_KEYS` 仍是独立清单**（§8.4），收敛它需要改一个 CI required 门禁，属单独评审。
