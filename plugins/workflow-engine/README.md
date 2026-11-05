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
