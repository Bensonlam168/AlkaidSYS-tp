# 低代码 Schema 解析器规范

## 1. 目标
- 将表单/集合 Schema 解析为三类产物：组件定义、验证规则（复用 T-050）、权限/可见性映射。
- 提供租户/站点隔离的缓存键，避免重复解析并防止串租。

## 2. API 与用法
- 后端控制器：`GET /v1/lowcode/forms/{name}?with_parsed=1[&with_validator=1]`
  - `parsed.components`：字段 → UI 组件、装饰器、必填标记、枚举等
  - `parsed.validator`：`rules/messages`（同 T-050 输出，带 tenant/site 上下文）
  - `parsed.permissions`：Schema 顶层/字段级 `x-permissions`
- 服务类：`Infrastructure\Lowcode\Schema\SchemaParser::parse($schema, $context, $cacheKey)`
  - `context`：`tenant_id/site_id`（必传以保证匹配租户上下文）
  - `cacheKey`：建议传 form_name/collection_name；缓存键为 `lowcode:schema_parser:{name}:t{tenant}:s{site}`

## 3. 解析规则
- 组件映射：读取 `x-component` / `x-decorator` / `x-component-props` / `x-decorator-props`，输出字段标题、类型、必填、枚举。
- 校验映射：调用 `FormValidatorManager::getDefinition()` 生成 `rules/messages`，包含 compare/tenantMatch/siteMatch 等扩展规则。
- 权限映射：读取 Schema 顶层或字段的 `x-permissions`，用于前端字段可见/禁用控制。

## 4. 缓存与失效
- 缓存 TTL：1 小时，键含租户/站点。
- 失效：表单 Schema 更新/删除/复制时调用 `FormSchemaManager::clearCache()` 同步删除 `lowcode:schema_parser:*`。

## 5. 前端接入建议
- 获取表单时同时传 `with_parsed=1`（如 `getLowcodeForm` 包装），用 `parsed.components` 渲染 UI，用 `parsed.validator` 生成前端校验，用 `parsed.permissions` 控制可见/禁用。
- 若未传 `with_parsed`，前端可回退到本地 Schema → UI 的映射，但优先使用后端解析结果以避免漂移。
