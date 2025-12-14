# 语言包贡献指南 | Language Pack Contribution Guide

本文档说明如何为 AlkaidSYS 添加或修改语言包。

## 目录结构

```
app/lang/
├── zh-cn/          # 中文（基准语言）
│   ├── auth.php    # 认证相关
│   ├── common.php  # 通用文本
│   └── error.php   # 错误消息
├── en-us/          # 英文
│   ├── auth.php
│   ├── common.php
│   └── error.php
└── {locale}/       # 其他语言
```

## 基准语言

- 中文 (`zh-cn`) 是基准语言
- 所有新增的 key 必须首先在 `zh-cn` 中定义
- 其他语言的 key 必须与 `zh-cn` 保持一致

## 工作流程

### 1. 添加新的翻译 key

```bash
# 1. 在 zh-cn 中添加新 key
# app/lang/zh-cn/auth.php
return [
    'login_successful' => '登录成功',
    'new_key' => '新增文本',  // ← 新增
];

# 2. 检查一致性
docker exec alkaid-backend php think lang:check

# 3. 同步到其他语言（dry-run）
docker exec alkaid-backend php think lang:sync

# 4. 同步到其他语言（写入）
docker exec alkaid-backend php think lang:sync --write

# 5. 手动翻译 TODO 标记
# app/lang/en-us/auth.php
return [
    'login_successful' => 'Login successful',
    'new_key' => 'New text',  // ← 翻译 TODO
];
```

### 2. 修改现有翻译

直接编辑对应语言文件中的值：

```php
// app/lang/en-us/auth.php
return [
    'login_successful' => 'Successfully logged in',  // 修改
];
```

### 3. 删除翻译 key

从所有语言文件中删除相同的 key：

```bash
# 删除后运行检查确保一致
docker exec alkaid-backend php think lang:check
```

## 命令参考

### lang:check - 一致性检查

```bash
# 检查所有语言包一致性
php think lang:check

# 使用 en-us 作为基准
php think lang:check --base=en-us

# 显示修复建议
php think lang:check --fix
```

### lang:sync - 同步缺失 key

```bash
# 预览同步（不写入）
php think lang:sync

# 写入同步
php think lang:sync --write

# 使用 en-us 作为基准
php think lang:sync --base=en-us --write
```

## 最佳实践

1. **命名规范**
   - 使用小写字母和下划线：`login_successful`
   - 使用点号分隔层级：`validation.required`
   - 避免使用复数形式作为 key

2. **值的规范**
   - 使用占位符而非字符串拼接：`'Hello, :name!'`
   - 保持句子完整，避免碎片化
   - 中文不使用标点前后空格

3. **提交规范**
   - 语言包变更使用 `feat(i18n)` 或 `fix(i18n)` 前缀
   - 一次提交只修改一种类型的变更

## Git Hook 集成（可选）

在 `.husky/pre-commit` 中添加：

```bash
#!/bin/sh
docker exec alkaid-backend php think lang:check
```

这将在每次提交前自动检查语言包一致性。

## 添加新语言

1. 创建新的语言目录：`app/lang/{locale}/`
2. 运行同步命令生成占位文件：
   ```bash
   docker exec alkaid-backend php think lang:sync --write
   ```
3. 翻译所有 `TODO:` 标记的内容
4. 运行检查确保完整：
   ```bash
   docker exec alkaid-backend php think lang:check
   ```

## 常见问题

### Q: 如何查看缺失的 key？

```bash
docker exec alkaid-backend php think lang:check --fix
```

### Q: 同步后文件格式不对怎么办？

```bash
docker exec alkaid-backend ./vendor/bin/php-cs-fixer fix app/lang/
```

### Q: CI 检查失败怎么办？

1. 运行 `php think lang:check` 查看问题
2. 修复缺失的 key 或删除多余的 key
3. 重新提交

