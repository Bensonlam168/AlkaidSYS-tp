# Git 工作流规范

## 概述

本文档定义了 AlkaidSYS 项目的 Git 工作流规范，包括分支策略、提交规范、Pull Request 流程和安全要求，旨在确保代码质量、协作效率和项目安全性。

## 分支策略

### 主要分支

| 分支名称 | 用途 | 保护级别 | 合并策略 |
|---------|------|---------|---------|
| `main` | 生产环境代码 | 最高保护 | 仅接受来自 `develop` 的 PR |
| `develop` | 开发环境代码 | 高保护 | 接受来自 `feature/*` 和 `fix/*` 的 PR |
| `release/*` | 发布准备分支 | 中保护 | 合并到 `main` 和 `develop` |

### 功能分支

- **命名规范**：
  - 功能分支：`feature/<scope>-<summary>`
  - 修复分支：`fix/<scope>-<summary>`
  - 热修复分支：`hotfix/<scope>-<summary>`

- **示例**：
  - `feature/auth-jwt-integration`
  - `fix/lowcode-tenant-isolation`
  - `hotfix/security-vulnerability`

### 分支保护规则

#### main 分支
- **禁止直接推送**：所有开发者禁止直接推送到 `main` 分支
- **PR 要求**：必须通过 Pull Request 合并
- **审查要求**：至少需要 2 个代码审查者批准
- **自动化检查**：所有 CI 检查必须通过
- **代码所有者**：核心模块变更需要代码所有者批准

#### develop 分支
- **PR 要求**：功能开发必须通过 PR 合并到 `develop`
- **审查要求**：至少需要 1 个代码审查者批准
- **自动化检查**：代码质量检查、测试必须通过

## 提交规范

### Conventional Commits

所有提交信息必须遵循 Conventional Commits 规范：

```
<type>(<scope>): <subject> (T-XXX)

<body>

<footer>
```

#### 提交类型

| 类型 | 描述 | 示例 |
|-----|------|------|
| `feat` | 新功能 | `feat(auth): 添加JWT认证 (T-001)` |
| `fix` | 修复问题 | `fix(db): 修复租户隔离问题 (T-002)` |
| `docs` | 文档更新 | `docs(api): 更新API文档 (T-003)` |
| `refactor` | 代码重构 | `refactor(lowcode): 重构字段管理器 (T-004)` |
| `test` | 测试相关 | `test(auth): 添加认证测试 (T-005)` |
| `chore` | 构建工具、依赖更新 | `chore(deps): 更新依赖版本 (T-006)` |
| `perf` | 性能优化 | `perf(cache): 优化缓存性能 (T-007)` |
| `style` | 代码格式调整 | `style(php): 修复代码格式 (T-008)` |

#### 作用域 (Scope)

推荐的作用域：
- `auth` - 认证授权相关
- `api` - API 接口相关
- `db` - 数据库相关
- `lowcode` - 低代码相关
- `workflow` - 工作流相关
- `security` - 安全相关
- `performance` - 性能相关
- `deployment` - 部署相关
- `testing` - 测试相关
- `git` - Git 配置相关

#### 任务编号要求

- **强制要求**：所有提交信息必须包含任务编号
- **格式**：`(T-XXX)`，其中 XXX 为三位数字
- **示例**：`feat(auth): 添加JWT认证 (T-001)`
- **关联**：任务编号必须与项目管理工具中的任务对应

#### 提交信息示例

```
feat(auth): 添加JWT双Token认证机制 (T-001)

- 实现Access Token和Refresh Token机制
- 添加Token刷新和黑名单功能
- 更新认证中间件支持新机制

Closes T-001
```

## Pull Request 流程

### PR 创建要求

#### 标题规范
- 格式：`<type>(<scope>): <subject> (T-XXX)`
- 必须与对应的分支名称和提交信息保持一致

#### 描述模板

```markdown
## 变更描述
简要描述本次PR的主要变更内容

## 变更类型
- [ ] 新功能 (feat)
- [ ] 问题修复 (fix)
- [ ] 文档更新 (docs)
- [ ] 代码重构 (refactor)
- [ ] 性能优化 (perf)
- [ ] 其他 (chore)

## 测试情况
### 测试命令
```bash
# 后端测试
docker exec -it alkaid-backend php think test --testsuite=Feature

# 前端测试
cd frontend && pnpm test:unit
```

### 测试结果
- [ ] 后端单元测试通过
- [ ] 前端单元测试通过
- [ ] 集成测试通过
- [ ] 手动测试通过

### 测试覆盖率
- 新增测试用例：X个
- 覆盖率变更：X% → Y%

## 影响范围
- [ ] 后端 API
- [ ] 前端 UI
- [ ] 数据库结构
- [ ] 配置文件
- [ ] 文档

## 检查清单
- [ ] 代码符合PSR-12规范
- [ ] 已通过PHP-CS-Fixer检查
- [ ] 已通过ESLint检查
- [ ] 已添加必要的测试用例
- [ ] 已更新相关文档
- [ ] 已验证多租户功能
- [ ] 已验证权限控制

## 相关任务
- 关联任务：T-XXX
- 依赖任务：T-YYY（如有）

## 截图（如适用）
如有UI变更，请提供截图：

## 其他说明
其他需要说明的事项
```

### PR 审查流程

#### 审查者要求
1. **代码质量审查**
   - 检查代码风格和规范
   - 验证逻辑正确性
   - 确认安全性考虑

2. **功能审查**
   - 验证功能实现符合需求
   - 检查边界条件处理
   - 确认错误处理

3. **测试审查**
   - 检查测试覆盖率
   - 验证测试用例完整性
   - 确认测试可重复执行

4. **文档审查**
   - 检查API文档更新
   - 验证代码注释完整性
   - 确认README等文档更新

#### 审查检查点

- [ ] 代码符合项目编码规范
- [ ] 逻辑实现正确且高效
- [ ] 安全性考虑充分
- [ ] 测试覆盖充分
- [ ] 文档更新完整
- [ ] 向后兼容性考虑
- [ ] 性能影响可接受

## 安全要求

### 敏感信息处理

#### 禁止提交的内容
- 密码、密钥、Token等敏感信息
- 数据库连接字符串
- 第三方服务的私有凭证
- 临时调试代码

#### 环境变量管理
- 所有敏感配置必须通过环境变量传递
- `.env.example` 作为环境变量的"单一真相源"
- 禁止在代码中硬编码敏感信息

### 代码安全检查

#### 自动化安全扫描
CI/CD 流程包含以下安全检查：

1. **敏感信息泄露检查**
   ```bash
   # 检查可能的敏感信息泄露
   grep -r -E "(password|secret|key|token).*=.*['\"]" --include="*.php"
   ```

2. **SQL注入风险检查**
   ```bash
   # 检查直接使用超全局变量
   grep -r -E "(\$_GET|\$_POST|\$_REQUEST)" --include="*.php"
   ```

3. **硬编码密钥检查**
   ```bash
   # 检查硬编码的密钥或密码
   grep -r -E "(API_KEY|SECRET_KEY|PASSWORD|TOKEN).*=.*['\"]" --include="*.php"
   ```

4. **文件权限检查**
   ```bash
   # 检查可写的PHP文件
   find . -name "*.php" -perm /o+w -not -path "./vendor/*"
   ```

5. **调试代码检查**
   ```bash
   # 检查调试代码残留
   grep -r -E "(var_dump|print_r|debug|console\.log)" --include="*.php"
   ```

#### 依赖漏洞扫描

1. **PHP依赖扫描**
   ```bash
   composer audit
   ```

2. **前端依赖扫描**
   ```bash
   cd frontend && pnpm audit
   ```

### 权限控制

#### 分支权限
- `main` 分支：仅维护者可推送
- `develop` 分支：核心开发者可推送
- 功能分支：所有开发者可推送

#### 代码所有者
- 核心模块变更需要代码所有者批准
- 代码所有者列表在项目配置中维护
- 紧急情况下可使用紧急审批流程

## 代码质量要求

### PHP代码规范

#### PSR-12遵循
- 所有PHP代码必须符合PSR-12编码规范
- 新文件必须包含 `declare(strict_types=1);`
- 使用PHP-CS-Fixer进行格式检查

#### 代码风格检查
```bash
# 只读检查（推荐在CI中使用）
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff

# 自动修复（本地开发使用）
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix
```

### 前端代码规范

#### TypeScript/JavaScript规范
- 使用ESLint进行代码检查
- 使用Prettier进行代码格式化
- 遵循Vue 3 + Composition API最佳实践

#### 代码风格检查
```bash
cd frontend && pnpm lint
cd frontend && pnpm format
```

### 测试要求

#### 测试覆盖率
- 新功能必须包含相应的测试用例
- 测试覆盖率不得低于80%
- 核心模块测试覆盖率不得低于90%

#### 测试执行
```bash
# 后端测试
docker exec -it alkaid-backend php think test

# 特定测试套件
docker exec -it alkaid-backend php think test --testsuite=Feature

# 前端测试
cd frontend && pnpm test:unit
```

## CI/CD 集成

### 自动化检查

所有PR必须通过以下自动化检查：

1. **代码质量检查**
   - PHP代码风格检查
   - 前端代码风格检查
   - 提交信息规范检查

2. **安全扫描**
   - 敏感信息泄露检查
   - 依赖漏洞扫描
   - 代码安全规则检查

3. **测试执行**
   - 单元测试执行
   - 集成测试执行
   - 测试覆盖率检查

4. **构建验证**
   - 后端构建验证
   - 前端构建验证

### 失败处理

当任何检查失败时：
- PR自动标记为失败状态
- 阻止合并到受保护分支
- 提供详细的失败信息和建议

## 常见问题与解决方案

### 提交信息问题

#### 问题：提交信息不符合规范
**解决方案**：
```bash
# 修改最新提交信息
git commit --amend -m "feat(auth): 添加JWT认证 (T-001)"

# 如果已经推送，创建新的修正提交
git commit -m "fix(commit): 修正提交信息格式 (T-XXX)"
```

### 分支管理问题

#### 问题：分支命名不规范
**解决方案**：
```bash
# 重命名分支
git branch -m old-branch-name feature/auth-jwt-integration

# 推送新分支
git push origin feature/auth-jwt-integration
```

### 代码质量问题

#### 问题：代码风格检查失败
**解决方案**：
```bash
# 自动修复代码风格
docker exec -it alkaid-backend ./vendor/bin/php-cs-fixer fix

# 前端代码格式化
cd frontend && pnpm format

# 重新提交
git add .
git commit -m "style(code): 修复代码格式 (T-XXX)"
```

## 工具配置

### Git Hooks

项目使用以下Git Hooks：

1. **pre-commit**
   - 代码风格检查
   - 提交信息格式验证

2. **pre-push**
   - 基础测试执行
   - 敏感信息检查

### IDE集成

推荐配置以下IDE插件：

1. **VS Code**
   - PHP Intelephense
   - ESLint
   - Prettier
   - GitLens

2. **PhpStorm**
   - PHP Code Sniffer
   - .env files support
   - Git integration

## 培训与支持

### 新成员培训

新团队成员需要完成以下培训：

1. **Git基础培训**
   - 分支策略理解
   - 提交规范学习
   - PR流程熟悉

2. **项目特定培训**
   - 代码质量工具使用
   - 安全要求理解
   - CI/CD流程熟悉

### 支持渠道

- **技术支持**：项目负责人
- **流程咨询**：技术负责人
- **工具问题**：DevOps团队

## 文档维护

### 更新频率

- **季度审查**：每季度审查和更新本文档
- **重大变更**：项目流程重大变更时及时更新
- **反馈收集**：持续收集团队反馈并改进

### 版本控制

本文档版本控制：
- 当前版本：v1.0.0
- 更新日期：2025-12-17
- 维护责任人：技术负责人

---

**注意**：本文档是项目Git工作流的权威指南，所有团队成员必须严格遵守。如有疑问或建议，请通过项目内部渠道提出讨论。