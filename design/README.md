# AlkaidSYS 设计文档总览（design/）

本文件用于为 `design/` 目录提供一个总览和导航入口，帮助阅读者快速理解各子目录的用途和推荐阅读顺序。

## 1. 推荐阅读顺序

1. `00-core-planning`：总体规划与技术选型；
2. `01-architecture-design`：整体架构与多租户/多站点设计；
3. `03-data-layer`：数据库与 API 设计；
4. `04-security-performance`：安全、性能与可观测性；
5. `05-deployment-testing`：部署、测试与开发流程；
6. `06-frontend-design`：前端整体架构与工程实践；
7. `02-app-plugin-ecosystem`：应用与插件生态设计；
8. `07-integration-ops`：集成、运维与项目交付；
9. `08-developer-guides`：应用/插件开发指南；
10. `09-lowcode-framework`：低代码与工作流子系统设计；
11. `10-batch-summaries`：阶段性批次总结与回顾。

## 2. 子目录说明

- **00-core-planning**
  - 项目总体规划、系统概览、技术选型确认、依赖清单；
  - 包含：
    - `01-MASTER-IMPLEMENTATION-PLAN.md`：主实施计划；
    - `01-alkaid-system-overview.md`：系统概览；
    - `02-TECHNOLOGY-SELECTION-CONFIRMATION.md`：技术选型确认；
    - `03-PROJECT-DEPENDENCIES.md`：依赖与版本的单一真相源；
    - `99-GLOSSARY.md`：术语表（Glossary）。

- **01-architecture-design**
  - 整体架构、多租户、多站点、插件体系等高层设计；
  - 建议先读 `02-architecture-design.md`，再阅读多租户/多站点/插件等专题文档。

- **02-app-plugin-ecosystem**
  - 应用与插件生态、应用市场/插件市场设计、开发者生态建设等。

- **03-data-layer**
  - 数据库与数据访问层设计；
  - 包含：
    - `09-database-design.md`：数据库总体设计；
    - `10-api-design.md`：API 设计；
    - `11-database-evolution-and-migration-strategy.md`：数据库演进与迁移策略。

- **04-security-performance**
  - 安全、性能优化与监控相关设计；
  - 包含：
    - `11-security-design.md`：安全设计；
    - `12-performance-optimization.md`：性能优化；
    - `13-monitoring-logging.md`：监控与日志；
    - `14-security-baseline-and-dependency-upgrade.md`：安全基线与依赖升级策略；
    - `15-observability-and-ops-design.md`：可观测性与运维设计。

- **05-deployment-testing**
  - 部署指南、测试策略、开发流程与环境管理；
  - 包含：
    - `14-deployment-guide.md`：部署指南；
    - `15-testing-strategy.md`：测试策略；
    - `16-development-workflow.md`：开发工作流；
    - `17-configuration-and-environment-management.md`：配置与多环境管理设计。

- **06-frontend-design**
  - 管理端、Web 端、移动端前端架构与工程实践，包括状态管理、路由、组件、构建、测试等。

- **07-integration-ops**
  - 系统集成、数据迁移、培训、运维手册与项目总结等。

- **08-developer-guides**
  - 应用开发指南、插件开发指南等面向开发者的说明文档。

- **09-lowcode-framework**
  - 低代码平台与工作流子系统的详细设计，包括：
    - 总体架构与概览；
    - 数据建模、表单设计器、工作流引擎；
    - 工作流前端应用（LogicFlow 设计器等）；
    - 端到端链路：表单 → Collection → 工作流。

- **10-batch-summaries**
  - 各批次设计与实现过程中的阶段性总结与最终总结。

- **prompt-templates**
  - 与 PromptX/AI 助手相关的提示词模板，按 api/crud/plugin 等场景划分。

- **sdk-builder**
  - SDK 构建相关的说明与示例，包括 README 与示例代码结构。

## 3. 使用建议

- 新成员可按“推荐阅读顺序”从上到下依次熟悉平台；
- 在阅读各专题文档时，遇到术语不清楚时可回查 `00-core-planning/99-GLOSSARY.md`；
- 对低代码与工作流感兴趣的读者，可重点阅读 `09-lowcode-framework` 目录下的文档，并结合数据层、安全与部署相关文档一起理解。
