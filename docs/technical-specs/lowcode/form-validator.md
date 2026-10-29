# 低代码表单验证器规范

## 1. 目标与范围

- 基于 JSON Schema 自动生成后端校验规则与错误消息。
- 前后端校验一致：前端优先使用后端导出的 `validator` 定义，避免规则漂移。
- 多租户安全：租户/站点伪造直接返回 2002。

## 2. 规则映射（后端）

后端通过 `Infrastructure\\Lowcode\\FormDesigner\\Service\\FormValidatorGenerator` 生成规则：

- `required` → `require`
- `type=string` → `string`，`minLength/maxLength` → `min/max`
- `type=integer/number` → `integer/number`，`minimum/maximum` → `egt/elt`
- `type=boolean` → `boolean`
- `type=array` → `array`，`minItems/maxItems` → `min/max`
- `format` → `email/url/date/datetime/uuid/mobile`
- `pattern` → `regex`
- `enum` → `in`
- `x-compare` → `compare:op,field`
- `x-rules/x-messages` → 规则与消息扩展
- `x-tenant/x-site` 或字段名 `tenant_id/site_id` → `tenantMatch/siteMatch`

## 3. 前后端一致性输出

接口支持按需返回后端规则定义：

```
GET /v1/lowcode/forms/{name}?with_validator=1
GET /v1/lowcode/forms/{name}?with_parsed=1  # 同时返回组件/权限映射
```

响应体示例（省略无关字段）：

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "name": "product_form",
      "schema": { "type": "object", "properties": { "...": "..." } },
      "validator": {
        "rules": {
          "name": "require|min:2",
          "tenant_id": "require|number|tenantMatch"
        },
        "messages": {
        "name.min": "名称长度不能少于2个字符",
        "tenant_id.tenantMatch": "租户无权访问该数据（code 2002）"
      }
    }
  },
  "timestamp": 1710000000,
  "trace_id": "trace-xxx"
}
```

前端应优先使用 `validator.rules/messages` 生成校验器；当未传 `with_validator` 时，则以 Schema 自行生成。若需要 UI 权限与组件元数据，可同时传 `with_parsed=1`，读取返回的 `parsed.components/permissions/validator`。

## 4. 错误码约定

- 参数/表单校验失败：`code = 422`，HTTP 422（统一验证错误）。
- 租户/站点伪造：`code = 2002`，HTTP 403（权限不足）。

## 5. 扩展点

- 自定义校验：在 Schema 字段上添加 `x-rules` / `x-messages`。
- 跨字段比较：使用 `x-compare` 指定比较操作与目标字段。
