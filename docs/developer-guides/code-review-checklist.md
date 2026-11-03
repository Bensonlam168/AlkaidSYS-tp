# 代码审查清单 | Code Review Checklist

> Version: 1.0.0 | Last Updated: 2025-12-14

本清单用于 AlkaidSYS 项目的代码审查，确保代码质量和规范一致性。

## 1. 基础规范

### 1.1 PSR-12 合规性

- [ ] 文件使用 `<?php` 开头，无 BOM
- [ ] 新文件包含 `declare(strict_types=1);`
- [ ] 类名使用 `PascalCase`
- [ ] 方法名使用 `camelCase`
- [ ] 常量使用 `UPPER_SNAKE_CASE`
- [ ] 缩进使用 4 个空格
- [ ] 行长度不超过 120 字符
- [ ] 运行 `php-cs-fixer fix --dry-run` 无错误

### 1.2 命名规范

- [ ] 变量名有意义且描述性强
- [ ] 避免单字母变量（循环索引除外）
- [ ] 布尔变量使用 `is`/`has`/`can` 前缀
- [ ] 数据库表名使用 `snake_case` 复数
- [ ] 数据库字段使用 `snake_case`

## 2. PHP 8.2+ 现代特性

### 2.1 构造器属性提升

- [ ] 服务类使用构造器属性提升
- [ ] 依赖注入属性标记为 `readonly`
- [ ] 避免冗余的属性声明和赋值

### 2.2 类型声明

- [ ] 所有方法参数有类型声明
- [ ] 所有方法有返回类型声明
- [ ] 使用联合类型替代 `@param` 多类型注释
- [ ] 可空类型使用 `?Type` 或 `Type|null`

### 2.3 现代语法

- [ ] 多分支选择使用 `match` 表达式
- [ ] 链式空值检查使用 `?->` 操作符
- [ ] 有限状态集使用 `enum`
- [ ] 数组操作考虑 `array_map`/`array_filter`/`array_reduce`

## 3. 安全性

### 3.1 输入验证

- [ ] 所有用户输入经过验证
- [ ] SQL 查询使用参数绑定
- [ ] 敏感数据不记录到日志

### 3.2 认证与授权

- [ ] 受保护路由使用认证中间件
- [ ] 权限检查使用 `PermissionService`
- [ ] 不在控制器内硬编码权限判断

### 3.3 多租户隔离

- [ ] 业务查询包含 `tenant_id` 过滤
- [ ] 跨租户操作有明确授权检查
- [ ] 租户上下文通过统一服务获取

## 4. 代码质量

### 4.1 单一职责

- [ ] 类职责单一明确
- [ ] 方法长度不超过 50 行
- [ ] 避免过深的嵌套（最多 3 层）

### 4.2 依赖注入

- [ ] 使用构造函数注入
- [ ] 避免使用 `app()` 容器直接获取
- [ ] 避免静态方法调用服务

### 4.3 错误处理

- [ ] 使用自定义异常类
- [ ] 异常包含有意义的错误信息
- [ ] 不吞掉异常（空 catch 块）

## 5. 文档与注释

### 5.1 PHPDoc

- [ ] 公共方法有 PHPDoc 注释
- [ ] 复杂逻辑有行内注释
- [ ] 使用中英双语注释（推荐）

### 5.2 API 文档

- [ ] 新 API 更新 Swagger 注解
- [ ] 响应格式符合统一规范

## 6. 测试

### 6.1 测试覆盖

- [ ] 新功能有对应测试
- [ ] 修复 Bug 有回归测试
- [ ] 边界条件有测试覆盖

### 6.2 测试质量

- [ ] 测试方法名描述测试场景
- [ ] 使用 `@dataProvider` 减少重复
- [ ] Mock 外部依赖

## 7. Git 提交

### 7.1 提交信息

- [ ] 遵循 Conventional Commits 格式
- [ ] scope 在允许列表内
- [ ] 描述清晰简洁

### 7.2 变更范围

- [ ] 单次提交聚焦单一主题
- [ ] 不混合功能开发和格式修复
- [ ] 大变更拆分为多个小提交

## 8. 快速检查命令

```bash
# PSR-12 格式检查
docker exec alkaid-backend ./vendor/bin/php-cs-fixer fix --dry-run --diff

# 语言包一致性
docker exec alkaid-backend php think lang:check

# 环境变量完整性
docker exec alkaid-backend php think env:check

# 运行测试
docker exec alkaid-backend php think test
```

