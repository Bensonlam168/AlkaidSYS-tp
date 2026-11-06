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
