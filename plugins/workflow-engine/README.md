# AlkaidSYS Workflow Engine Plugin

> 工作流引擎插件 - 基于 Ingenious 改造

## 概述

本插件是基于 [Ingenious](https://gitee.com/ingenstream/ingenious) 工作流引擎改造而成，为 AlkaidSYS 系统提供完整的工作流能力。

## 特性

- ✅ 基于 BPMN 2.0 标准
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

## 许可证

本项目基于 [LICENSE](LICENSE) 许可证开源。

## 贡献

欢迎提交 Issue 和 Pull Request！

## 更新日志

### v1.0.0 (2025-12-16)
- 初始版本发布
- 完成基础架构搭建
