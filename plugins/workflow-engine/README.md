# AlkaidSYS Workflow Engine Plugin

> 工作流引擎插件 - 基于 Ingenious 改造

## 概述

本插件是基于 [Ingenious](https://gitee.com/ingenstream/ingenious) 工作流引擎改造而成，为 AlkaidSYS 系统提供完整的工作流能力。

## 特性

- ✅ 支持 **BPMN 2.0 子集**导入/导出（`startEvent` / `endEvent` / `userTask` / `serviceTask` / `scriptTask` / `sendTask` / `exclusiveGateway` / `parallelGateway` / `sequenceFlow`，见 `src/Definition/BpmnConverter.php`；子集之外的元素导入时直接报错，不静默丢弃）
- ✅ 支持 Swoole 协程执行
- ✅ 多租户架构支持
- ✅ 表达式引擎集成
- ✅ 触发器系统
- ✅ 完整节点类型库
- ✅ 流程轨迹（事件时间线）：启动 / 任务完成 / 完成 / 拒绝四类事件落 `workflow_event_logs`，见下文「流程轨迹」

## 目录结构

```
workflow-engine/
├── src/                          # 核心源码
│   ├── Core/                     # 核心引擎
│   ├── Nodes/                    # 节点实现
│   ├── Triggers/                 # 触发器系统
│   ├── Expression/               # 表达式引擎
│   ├── Swoole/                   # Swoole 协程支持
│   ├── Multitenant/              # 多租户支持
│   ├── Integration/              # 集成适配层
│   └── Interfaces/               # 接口定义
├── config/                       # 配置文件
├── routes/                       # 路由定义
├── migrations/                   # 数据库迁移
├── tests/                        # 测试文件
└── docs/                         # 文档
```

## 安装

```bash
composer install
```

## 使用

详细使用说明请参考 [docs/](docs/) 目录。

## 流程轨迹（「历史」能力）

「历史」在本插件里的口径是**事件时间线**，不是实例快照副本：

- 实例状态本身由 `workflow_instances` 唯一保存（`status` / `started_at` / `completed_at` / `duration` / `tenant_id`），
  实例从不物理删除，因此**不再建** `workflow_instance_history` 那样的快照表（那会是一份只写一次、
  零读取方的死重副本）。
- 轨迹落 `workflow_event_logs`：一行一个事件，按 `created_at, id` 正序即得流程轨迹。
  实现在 `src/Services/ProcessInstanceHistoryService.php`，契约见
  `src/interface/services/IProcessInstanceHistoryService.php`。

| `event_type` | 含义 | 写入点 |
| --- | --- | --- |
| `instance_started` | 流程启动 | `Core/ProcessEngines::startProcessInstanceById()` |
| `task_completed` | 任务审批通过 | `Services/ProcessTaskService::finishProcessTask()` |
| `instance_completed` | 流程走到 end 并完成 | `Services/ProcessInstanceService::finishProcessInstance()` |
| `instance_rejected` | 流程被拒绝终止 | `Services/ProcessInstanceService::rejectProcessInstance()` |

两条硬约定：

1. **租户只认实例**：写事件的 `tenant_id` 取自实例本身（`workflow_instances.tenant_id`），
   缺失即抛异常，**绝不回落默认租户 1**。读轨迹 `timeline()` 则必须按**当前租户**过滤
   （租户 2 查租户 1 的实例得到空数组）。
2. **写入是旁路审计**：写事件失败**不会**让审批失败。三处调用点统一经
   `Services/Support/RecordsHistorySafely::recordHistorySafely()` 包裹，
   捕获异常并记 WARNING（带失败原因），主流程照常推进。

读取入口：`IProcessInstanceHistoryService::timeline(string $instanceId): array`。

> 为什么没有 `IProcessTaskHistoryService` 的实现：该接口（`created/updated/findById/list`）
> 全仓库零调用方。任务级事实已由 `workflow_tasks` 的
> `result/comment/completed_at/rejected_at/cancelled_at`（单条任务的结果与意见）
> 加本插件的 `task_completed` 事件（时间线上的顺序与操作人）覆盖，
> 再实现四个没人调用的方法只会制造第二份真相。

## 任务参与者解析（谁有权办这个任务）

节点上配置的「谁有权办」不再写死一个人，而是由
`interface/services/ITaskActorResolver` 解析成 `workflow_tasks` 的参与者列。

### 为什么必须「展开」而不是「存引用」

`ProcessTaskService::isAllowed()` 判断「你能不能办」的依据是
`ProcessTaskEntity::getActorIds()` = `assignee` ∪ `candidate_users`，
**完全不读 `candidate_groups`**。因此配 `role:admin` 时：

- 角色成员要被**展开成用户 ID** 写进 `candidate_users` —— 否则没有任何人获得权限；
- 原始引用 `role:admin` 原样写进 `candidate_groups` —— 用于展示「为什么是他」与事后审计。

两件事同时做，才有「配置生效 + 意图可追溯」。

### 规则

| 配置（`properties`） | 解析结果 |
| --- | --- |
| `assignee` = `5` / `user:5` | 用户 5；多个（`5,6`）时首个当处理人、其余进候选人 |
| `assignee` = `${expr}` | 表达式求值结果（可返回 ID 或 ID 数组） |
| `candidate_ext_users` = 逗号串 / 数组 | 逐个解析，支持 `user:` 前缀与 `${expr}` |
| `candidate_ext_groups` = `role:<slug>` | **本租户内**该角色的全部用户 ID |
| `candidate_ext_groups` = `dept:<id>` | 只识别、**不展开**（见下） |
| `candidate_ext_groups` = 裸值 | 按 `role:<值>` 处理；落库仍是原始串（本字段语义就是「组 = 角色」，`dept` 必须显式写前缀，因此无歧义） |

产出列的口径：

- `status`：解析出 `assignee` → `claimed`；只有候选人 → `pending`（候选池，谁抢谁办）；
- `candidate_users`：`assignee` + 候选人 + 展开结果的并集（去重、只留本租户内存在的用户）；
- `assignee_name` / 认领或完成时的姓名回填：取 `users.name`，为空回落 `username`；
- 无人可办时**不抛异常**：任务照常 `pending` 落库、流程继续推进，但记 WARNING
  （否则就是「任务生成了、没人能推」这种最难查的故障）。

### 失败口径（两类，别混）

- **抛 `LFlowException`**（改数据修不好的配置错误）：未知前缀（`post:` / `team:`）、前缀后为空、
  非数字用户、`${expr}` 求值失败 / 返回 null / 返回非数字、`candidate_groups` 里写表达式。
  静默变 null 会让任务「看起来指派了、实际没人」，所以宁可报错。
- **不抛，进 `warnings` 记 WARNING**（数据状态问题）：角色在本租户内不存在（文案点明可能是跨租户引用）、
  角色没有成员、`dept:` 无法展开、解析出的用户 ID 不属于本租户（**跨租户引用被拒绝并记录**）。

### `dept:` 为什么只能识别、不能展开

本仓库的表结构里**没有**用户与部门的关联：`users` 没有 `dept_id`，`depts` 没有指向用户的列，
也没有 `user_depts` 之类的关联表。所以 `dept:<id>` 只会校验部门是否属于本租户，然后记一条
「无法展开」的 WARNING 并返回空集 —— **不编人**。要按部门指派，请替换
`task_actor_directory`（接入组织架构服务），或改用 `role:<slug>`。

### 快照语义（已知边界）

角色成员是在**建任务那一刻**展开的快照。之后把人加进角色，不会自动进入已存在任务的
`candidate_users`；需要用 `addCandidateActor()` 追加或重新指派。这样 `isAllowed()`
保持「一次读表」，不必每次鉴权都展开角色（也就没有随之而来的租户越权风险）。

### 替换点（宿主可覆盖）

| 容器键 | 默认实现 | 用途 |
| --- | --- | --- |
| `task_actor_resolver` / `ITaskActorResolver::class` | `Services\DefaultTaskActorResolver` | 解析规则本身（接入自定义指派规则时替换） |
| `task_actor_directory` / `ITaskActorDirectory::class` | `Services\DefaultTaskActorDirectory` | 用户 / 角色 / 部门数据的读取口径（接入组织架构时替换） |

```php
WorkflowBootstrap::initialize([
    'task_actor_resolver'  => MyActorResolver::class,   // 或已构造的实例
    'task_actor_directory' => MyActorDirectory::class,
]);
```

两个实现登记了「短名 + 接口名」两份键（`taskActorResolver` / `taskActorDirectory` 与对应 FQCN），
与其它核心服务一样接受 `service_dependencies` 覆写；短名与接口名始终指向同一个实现，
所以引擎侧按接口名取、宿主按短名写，不会出现「各拿一半」。

### 已知未实现（如实说明）

- `assignee_form_key`（从表单字段取处理人）不解析：建任务时表单数据尚不可得，`form_data` 列同样留空；
- `assignment_handler` / `candidate_handler`（自定义解析类）：本仓库没有实现类，配置了也不会被调用；
- `description`：节点上没有对应属性，保持 `null`；
- `expire_time`：经 `ProcessFlowUtils::processTime()` 解析（运行时变量 / `30m` `2h` `1d` 这类时长 /
  绝对时间串），拿不到就保持 `null`，不猜。

## License & Attribution（许可证与归属）

本插件以 **Apache License, Version 2.0** 授权，许可证全文见 [LICENSE](LICENSE)。

**本插件是 [Ingenious](https://gitcode.com/motion-code) 工作流引擎的修改版（MODIFIED）。**
按 Apache License 2.0 第 4(b) 条要求声明如下：

| 项 | 内容 |
|---|---|
| 上游作品 | Ingenious 工作流引擎 |
| 上游版权 | Copyright (c) https://gitcode.com/motion-code  All rights reserved. |
| 上游作者 | Mr. April (405784684@qq.com) |
| 软著登记号 | 2024SR0694589 |
| 官方站点 | https://madong.tech |
| 上游仓库 | https://gitcode.com/motion-code |
| 许可证 | Apache License, Version 2.0 |

主要修改：命名空间由 `madong\ingenious` 改为 `Alkaid\Workflow\Engine`；
新增多租户适配、与 AlkaidSYS 的框架集成、Swoole 协程支持、依赖注入容器与
表达式引擎等能力。完整的第三方归属与修改清单见仓库根目录的
[NOTICE](../../NOTICE)。

`src/` 下 131 个源自上游的源文件保留了上游版权头（按 Apache License 2.0
第 4(c) 条要求，未作删改）。

## 贡献

欢迎提交 Issue 和 Pull Request！

## 更新日志

### v1.0.0 (2025-12-16)
- 初始版本发布
- 完成基础架构搭建
