# 配置与多环境管理设计

## 1. 背景与目标

AlkaidSYS 在不同环境（开发/测试/预发/生产）下运行，需要一套统一的 **配置与环境管理策略**，以确保：

1. 配置变更可控、可审计；
2. Swoole 常驻进程场景中配置更新安全、可预期；
3. 敏感配置（密钥、密码等）得到妥善保护；
4. 各环境之间的差异清晰可见，避免“配置漂移”。

## 2. 环境划分

建议至少划分以下环境：

- **dev**：开发环境，本地/共享开发环境，允许快速迭代和调试；
- **test**：功能测试环境，供测试人员进行集成和回归测试；
- **stage**：预发环境，与生产尽可能一致，用于发布前的综合验证；
- **prod**：生产环境，对外提供实际服务。

每个环境使用独立的数据库/缓存/MQ 实例或命名空间，禁止跨环境共享同一数据源。

## 3. 配置来源与加载顺序

1. **配置来源**：
   - 环境变量（ENV）；
   - 配置文件（如 `config/*.php`、`.env` 等）；
   - 配置中心（如后续引入 Nacos/Consul 等，可在本节扩展）。
2. **加载顺序建议**：
   1）加载基础配置文件（默认值）；
   2）加载按环境划分的配置（如 `config.prod.php`）；
   3）以环境变量覆盖最终结果（便于部署时注入差异配置）。

### 3.1 典型环境变量矩阵（示例）

> 说明：本小节仅给出关键配置项的示例矩阵，具体值需结合实际部署环境与安全策略确定。

| 变量名 | 说明 | dev | test | stage | prod |
|--------|------|-----|------|-------|------|
| `APP_ENV` | 运行环境标识 | `dev` | `test` | `stage` | `prod` |
| `APP_DEBUG` | 调试开关 | `true` | `false` | `false` | `false` |
| `DB_HOST` | 数据库主机 | `localhost` | `mysql.test` | `mysql.stage` | `mysql.prod` |
| `DB_DATABASE` | 数据库名 | `alkaid_dev` | `alkaid_test` | `alkaid_stage` | `alkaid_prod` |
| `REDIS_HOST` | Redis 主机 | `localhost` | `redis.test` | `redis.stage` | `redis.prod` |
| `QUEUE_CONNECTION` | 队列连接 | `sync` | `redis` | `redis` | `redis` |
| `JWT_SECRET` | JWT 密钥 | 本地随机值 | 测试专用密钥 | 预发密钥 | 生产密钥（仅限安全渠道管理） |
| `APP_ENCRYPT_KEY` | 加密密钥 | 本地随机值 | 测试专用密钥 | 预发密钥 | 生产密钥（仅限安全渠道管理） |

> 更多配置项可在进入实现阶段时结合《04-security-performance/10-non-functional-overview.md》《03-data-layer/13-data-evolution-bluebook.md》进一步细化。


## 4. Swoole 常驻进程场景下的配置更新

由于 Swoole 采用常驻进程模型，配置在进程启动后会常驻内存，因此：

1. **禁止在线直接修改配置文件并期待自动生效**：
   - 任意配置修改必须伴随进程重启或热重载操作；
2. **推荐策略**：
   - 通过进程管理工具（如 Supervisor/systemd 或自研管理脚本）执行“平滑重启”，逐步下线旧进程、拉起新进程；
   - 对于需频繁调整的非关键配置（如日志级别），可预留从配置中心拉取的能力，并设计明确的刷新机制。
3. **配置版本与审计**：
   - 配置变更（尤其是 prod 环境）需要在变更系统中登记，记录变更人、变更内容与回滚方案；
   - 重大配置变更前应在 stage 环境演练。

## 5. 敏感配置管理

1. 不将密钥、密码等敏感信息提交到代码仓库；
2. 优先使用环境变量或安全配置服务（如 Vault 等）管理敏感配置；
3. 在日志与错误提示中避免输出敏感配置值，仅记录配置项名称或脱敏信息。

## 6. 与部署与测试文档的关系

- 与 `05-deployment-testing/14-deployment-guide.md`：部署流程应明确“如何注入配置”和“如何重启服务使配置生效”，并在不同环境中遵守《04-security-performance/10-non-functional-overview.md》中关于容量规划、性能与可用性目标的约束；
- 与 `05-deployment-testing/15-testing-strategy.md`：测试策略应覆盖不同环境下的配置差异（例如开关类配置的多组合测试），并在 Stage/Prod 前对关键配置组合进行回归验证；
- 与 `04-security-performance/11-security-design.md`：敏感配置管理策略需与整体安全设计保持一致，特别是密钥/证书/连接串等的来源与轮换周期；
- 与 `03-data-layer/13-data-evolution-bluebook.md`：涉及数据库连接、分库分表、低代码 Schema 等相关配置变更时，应纳入统一的数据演进与回滚流程中进行评审与审计。

