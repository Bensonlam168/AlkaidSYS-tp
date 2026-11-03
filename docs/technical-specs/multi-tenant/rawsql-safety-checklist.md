# 原生 SQL 多租户安全检查清单（RAWSQL-01）

> 适用范围：所有直接使用 `think\\facade\\Db` 或原生 SQL 的代码，包括业务服务、批处理、报表、运维脚本、CLI 命令等。
>
> 目标：在必须使用原生 SQL 时，仍然保证 **多租户隔离、安全与可观测性**，避免绕过 BaseModel/Repository 封装导致跨租户访问或审计缺失。

---

## 1. 使用前判断：是否真的需要原生 SQL？

- [ ] 优先评估是否可以使用：
  - 现有 **Domain/Repository** 抽象；
  - 低代码 **FormDataManager / BaseModel** 封装；
  - 已实现的查询服务或视图。
- [ ] 仅在以下场景下考虑原生 SQL：
  - 复杂报表或聚合查询，ORM 难以表达或性能不足；
  - 批处理/运维脚本，需要直接操作大批量数据；
  - 迁移或一次性修复脚本（需配套迁移/备份策略）。
- [ ] 若选择原生 SQL，应在代码注释中说明原因（Why Raw SQL）。

---

## 2. 多租户隔离要求（tenant_id / site_id）

- [ ] 所有 **租户级数据表** 的原生查询必须显式包含 `tenant_id = :tenantId` 过滤：
  - 不得依赖“默认库”或“默认连接”代替租户隔离；
  - 不得在无 `tenant_id` 过滤的前提下对业务表执行 `UPDATE`/`DELETE`/`TRUNCATE` 等操作。
- [ ] 如涉及站点维度，需根据设计文档判断是否同时过滤 `site_id`：
  - 例如：`WHERE tenant_id = :tenantId AND site_id = :siteId`；
  - 若为“跨站点聚合”，必须在注释和设计文档中明确说明用途与风险评估。
- [ ] 严禁在生产环境中对租户数据做 **全表扫描 + 手工过滤** 代替 SQL 级过滤。

---

## 3. 参数绑定与注入防护

- [ ] 所有原生 SQL 都必须使用 **参数绑定**，禁止字符串拼接用户输入：
  - ✅ `Db::query('SELECT * FROM orders WHERE tenant_id = :tenantId AND id = :id', [...]);`
  - ❌ `Db::query("SELECT * FROM orders WHERE tenant_id = $tenantId AND id = $id");`
- [ ] 禁止将未经过滤的 JSON、原始请求体直接拼接进 SQL；
- [ ] 特殊场景（如 `IN` 列表）应使用安全的占位符展开逻辑，并限制列表长度。

---

## 4. 审计与可观测性

- [ ] 关键原生 SQL 路径（批处理、报表、运维脚本）必须记录：
  - `trace_id`（请求链路追踪）；
  - `tenant_id` / `site_id`（多租户上下文）；
  - 操作人 / 执行主体（user_id / CLI operator）；
  - 影响范围（如涉及的表名、估计行数）。
- [ ] 建议通过统一的日志封装或中间件输出上述上下文，而非在每个调用点手写拼接。

---

## 5. 在 CI 中的静态扫描与人工审查流程

- [ ] 使用脚本列出原生 SQL 调用点：

  ```bash
  # 在 alkaid-backend 容器内执行
  docker exec alkaid-backend php scripts/check-rawsql-safety.php
  ```

  - 脚本将扫描 `app/`、`domain/`、`infrastructure/`、`tests/`、`scripts/` 目录下的 `.php` 文件；
  - 匹配入口包括：`Db::table(`、`Db::name(`、`Db::query(`、`Db::execute(`。

- [ ] 定期（例如每次大版本前或安全审计前）导出扫描结果，由架构师/安全负责人人工审查：
  - 按模块（报表/批处理/运维/业务服务）分类检查；
  - 核对是否满足本清单第 2、3、4 节要求；
  - 对不满足要求的调用点创建 Backlog 进行整改。

- [ ] 严格模式（可选，用于后续 CI Gate）：

  ```bash
  docker exec alkaid-backend php scripts/check-rawsql-safety.php --strict
  ```

  - 严格模式下，只要存在原生 SQL 调用，脚本即返回退出码 1；
  - 建议在完成一次全仓库梳理并建立“已确认安全的调用点”基线后，再逐步在 CI 中开启严格模式，并配套白名单或更精细的规则。

---

## 6. 典型高风险场景提示

- [ ] 报表/统计接口，直接对业务主表做聚合且缺少 `tenant_id` 过滤；
- [ ] 批量修复/迁移脚本，在无租户过滤的前提下执行 `UPDATE`/`DELETE`；
- [ ] 运维脚本在生产环境中对共享表做 `SELECT *` 全表导出；
- [ ] 临时代码/调试脚本遗留在仓库中，未加访问控制与审计日志；
- [ ] 对低代码动态表（`lc_{name}`）执行原生 SQL 而绕过 FormDataManager/多租户作用域。

如在以上场景中必须使用原生 SQL，务必在设计评审与代码审查阶段显式讨论，并通过本清单逐项确认安全性。

