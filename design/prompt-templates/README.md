# Prompt 模板库

本目录存放 AlkaidSYS 的标准化 Prompt 模板，供 AI 辅助开发流程与 MCP 工具引用。

- 分类
  - plugin/ 插件开发类模板
  - crud/   CRUD 代码生成模板
  - api/    API 设计与实现模板（预留）
  - test/   测试用例模板（预留）

- 使用方式
  1) 通过 MCP TemplateGeneratorTool 识别需求并选择模板
  2) 根据模板的 `parameters` 填充参数
  3) 依据模板给出的 `cli_command` 和步骤执行后续命令

- 参考链接
  - lowcode-overview.md 的 7.5 节（模板入口）
  - lowcode-cli-integration.md 的 2.7 节（mcp:template 命令）
