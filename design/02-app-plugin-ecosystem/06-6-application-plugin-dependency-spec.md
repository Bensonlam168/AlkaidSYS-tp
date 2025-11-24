# 应用与插件依赖管理规范

## 📋 文档信息

| 项目 | 内容 |
|------|------|
| **文档名称** | 应用与插件依赖管理规范 |
| **文档版本** | v1.0 |
| **创建日期** | 2025-11-18 |
| **关联文档** | 01-architecture-design/06-application-plugin-system-design.md、02-app-plugin-ecosystem/06-1-application-system-design.md、06-2-plugin-system-design.md |

## 🎯 设计目标

1. 规范应用 / 插件之间以及与核心框架之间的版本依赖关系；
2. 在 **安装 / 启用 / 升级** 时避免出现不兼容组合；
3. 为后续实现“应用与插件版本兼容矩阵”提供统一元数据基础。

## 1. 版本号与兼容策略

1. 全局采用 **Semantic Versioning (SemVer)**：`MAJOR.MINOR.PATCH`
   - `MAJOR`：不兼容变更（Breaking Change）；
   - `MINOR`：向后兼容的新功能；
   - `PATCH`：向后兼容的 Bug 修复。
2. 兼容性判断以 **框架版本 + 应用版本 + 插件版本** 三元组为基础：
   - 核心框架：`framework_version`；
   - 应用：`app.version`；
   - 插件：`plugin.version`。

## 2. 应用 manifest.json 依赖字段规范

应用元数据示例已在 `06-1-application-system-design.md` 中给出，这里对关键依赖字段做规范说明：

- `min_framework_version`: string
  - 应用支持的 **最小框架版本**，如 `"1.0.0"`；
- `max_framework_version`: string
  - 应用支持的 **最大框架版本**，如 `"2.0.0"`；
- `dependencies.apps`: string[]
  - 依赖的其他应用 key 列表，例如 `"crm"`、`"oa"` 等；
- `dependencies.plugins`: string[]
  - 依赖的插件 key 列表，例如 `"payment-wechat"`、`"sms-aliyun"` 等。

**约束：**

1. 安装 / 启用应用时，必须满足：
   - `framework_version` ∈ [`min_framework_version`, `max_framework_version`]；
   - `dependencies.apps` 中列出的应用均已安装且处于可用状态；
   - `dependencies.plugins` 中列出的插件均已安装且版本满足各自 plugin.json 约束。
2. 升级应用时：
   - 需同时检查目标版本的 `min_framework_version` / `max_framework_version` 是否覆盖当前框架版本；
   - 若不满足，应阻止升级并给出明确错误信息。

## 3. 插件 plugin.json 依赖字段规范

插件元数据字段与应用类似，建议包含：

- `key`: string - 插件唯一标识，如 `"payment-wechat"`；
- `type`: string - `"universal"` 或 `"app-specific"`；
- `version`: string - 插件当前版本；
- `min_framework_version` / `max_framework_version`: 同应用；
- `dependencies`: 对象，推荐结构：

```json
{
  "framework": {
    "min": "1.0.0",
    "max": "2.0.0"
  },
  "apps": ["ecommerce"],
  "plugins": ["payment-core"]
}
```

**约束：**

1. 通用插件（`type = "universal"`）
   - 只能依赖核心框架和其他通用插件，不得反向依赖具体应用；
2. 应用专属插件（`type = "app-specific"`）
   - 至少依赖一个应用 key（如 `"ecommerce"`），否则无法安装；
   - 安装/启用前必须检查目标应用是否已安装且版本满足要求。

## 4. 安装 / 启用 / 升级时的依赖校验流程

1. **安装应用**：
   1) 读取 manifest.json；
   2) 校验框架版本范围；
   3) 校验依赖应用 / 插件是否已安装；
   4) 若有不满足条件的依赖，安装流程应中止并返回详细错误。
2. **启用应用或插件**：
   1) 再次校验依赖（避免中途被卸载 / 禁用）；
   2) 对不满足条件的依赖，应禁止启用并提示管理员处理。
3. **升级应用或插件**：
   1) 预先模拟升级后的版本矩阵；
   2) 校验所有依赖方（被依赖的插件/应用）是否仍兼容；
   3) 对可能导致不兼容的升级应提供“阻止 + 明确提示”，必要时允许“强制升级 + 风险确认”。

## 5. 版本兼容矩阵（建议方向）

后续可在应用市场 / 插件市场实现一份可视化的“版本兼容矩阵”，大致维度如下：

- 行：核心框架版本；
- 列：应用 / 插件版本；
- 单元格：兼容 / 不兼容 / 未测试（可用颜色或图标标记）。

本规范侧重于 **字段与校验规则**，具体 UI 呈现可在 `06-3-application-market-design.md`、`06-4-plugin-market-design.md` 中扩展。
