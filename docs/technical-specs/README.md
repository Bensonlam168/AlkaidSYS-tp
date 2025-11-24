# Technical Specifications | 技术规范

[English](#english) | [中文](#chinese)

---

## English

This directory contains the technical specifications and guidelines for the AlkaidSYS project.

### Contents

#### 1. [API Specification](./api/api-specification.md)
- RESTful Standards
- Unified Response Formats
- Versioning
- Error Handling & Trace ID
- Rate Limiting (Phase 1 / Phase 2)

#### 2. [Code Style Guide](./code-style/general-guidelines.md)
- PHP Standards (PSR-12)
- Naming Conventions
- Directory Structure
- Backend & Frontend Guidelines

#### 3. [Security Guidelines](./security/security-guidelines.md)
- Authentication (JWT + Refresh Token)
- Authorization (RBAC, Casbin Phase 2)
- API Security (Rate Limiting, Signatures)
- Security Error Codes

#### 4. [Database Design Guidelines](./database/database-guidelines.md)
- Schema & Naming Conventions
- Multi-tenant Keys & Isolation
- Indexing & Migration Management
- Performance & Query Optimization

#### 5. [Testing Guidelines](./testing/testing-guidelines.md)
- Unit / Integration / Feature Tests
- Database & Migration Testing
- CI Requirements & Coverage Baseline

#### 6. [Deployment & Ops Guidelines](./deployment/deployment-guidelines.md)
- Environment Variables & Configuration
- Logging, Metrics & Trace IDs
- Cache / Session / Queue Backends

#### 7. [Low-code Development Guidelines](./lowcode/lowcode-guidelines.md)
- Collection/Field/Relationship Modeling
- Domain\Lowcode as Single Source of Truth
- Legacy Stack Usage Restrictions

#### 8. [Performance & Scalability Guidelines](./performance/performance-guidelines.md)
- Database Indexing & Query Patterns
- Caching & Rate Limiting Strategy
- Runtime & Capacity Planning

#### 9. [Git Workflow & Commit Guidelines](./git/git-workflow-guidelines.md)
- Branching Model (main/develop/feature/fix/hotfix/release)
- Commit Message Convention (Conventional Commits)
- Merge Strategy & Protected Branches

### Related Documentation

- [Deployment Guide](../other-docs/deployment-guide.md)

---

## Chinese

本目录包含 AlkaidSYS 项目的技术规范和指南文档。

### 目录

#### 1. [API 设计规范](./api/api-specification.zh-CN.md)
- RESTful 标准
- 响应格式
- 版本管理
- 限流策略

#### 2. [代码风格指南](./code-style/general-guidelines.zh-CN.md)
- PHP 标准（PSR-12）
- 命名约定
- 目录结构
- 前端开发规范

#### 3. [安全设计规范](./security/security-guidelines.zh-CN.md)
- 认证机制（JWT + Refresh Token）
- 授权机制（RBAC，Casbin Phase 2）
- API 安全（限流、签名等）
- 安全错误码

#### 4. [数据库设计规范](./database/database-guidelines.zh-CN.md)
- 表结构与命名约定
- 多租户键与隔离
- 索引与迁移管理

#### 5. [测试规范](./testing/testing-guidelines.zh-CN.md)
- 单元 / 集成 / 特性测试
- 数据库与迁移测试
- CI 要求与覆盖率基线

#### 6. [部署与运维规范](./deployment/deployment-guidelines.zh-CN.md)
- 环境变量与配置
- 日志、指标与 Trace ID
- 缓存 / 会话 / 队列后端

#### 7. [低代码开发规范](./lowcode/lowcode-guidelines.zh-CN.md)
- 集合/字段/关系建模
- Domain\\Lowcode 作为唯一事实来源
- Legacy 技术栈使用限制

#### 8. [性能与扩展性规范](./performance/performance-guidelines.zh-CN.md)
- 数据库索引与查询模式
- 缓存与限流策略
- 运行时与容量规划

#### 9. [Git 工作流与提交规范](./git/git-workflow-guidelines.zh-CN.md)
- 分支模型（main/develop/feature/fix/hotfix/release）
- 提交消息约定（Conventional Commits）
- 合并策略与保护分支规则

### 相关文档

- [部署指南](../other-docs/deployment-guide.zh-CN.md)
