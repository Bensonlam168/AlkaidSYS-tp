# AlkaidSYS 低代码架构详解

## 低代码系统概述
AlkaidSYS 的低代码系统是核心功能之一，提供动态数据建模、表单设计和数据管理能力。

## 核心组件架构

### 1. Collection（集合）系统
Collection 是低代码系统的核心概念，代表一个动态创建的数据表。

#### 控制器层
**文件**: `app/controller/lowcode/CollectionController.php`

**主要方法**:
- `index()` - 获取集合列表（支持分页、搜索、排序）
- `read($id)` - 获取单个集合详情（包含字段和关系）
- `save()` - 创建新集合
- `update($id)` - 更新集合信息
- `delete($id)` - 删除集合（同时删除物理表）

#### 服务层
**文件**: `infrastructure/Lowcode/Collection/Service/CollectionManager.php`

**核心功能**:
- `create(array $data)` - 创建集合并生成物理表
- `get(int $id)` - 获取集合信息（带缓存）
- `update(int $id, array $data)` - 更新集合
- `delete(int $id)` - 删除集合和物理表
- `list(array $params)` - 列表查询
- `buildColumns(array $fields)` - 构建数据库列定义
- `cache($key, $data)` - 缓存管理
- `clearCache(int $id)` - 清除缓存

**依赖注入**:
- `SchemaBuilder` - 数据库结构构建器
- `CollectionRepository` - 集合仓储
- `FieldRepository` - 字段仓储
- `RelationshipRepository` - 关系仓储

#### 仓储层
**文件**: `infrastructure/Lowcode/Collection/Repository/CollectionRepository.php`

**职责**: 
- 集合元数据的CRUD操作
- 数据库查询封装

#### 领域模型
**文件**: `domain/Lowcode/Collection/Model/Collection.php`

**接口**: `domain/Lowcode/Collection/Interfaces/CollectionInterface.php`

### 2. Field（字段）系统
Field 系统提供丰富的字段类型支持，每种字段类型都有特定的验证规则和数据库映射。

#### 字段工厂
**文件**: `infrastructure/Lowcode/Collection/Field/FieldFactory.php`

**功能**: 根据字段类型创建对应的字段实例

#### 抽象基类
**文件**: `infrastructure/Lowcode/Collection/Field/AbstractField.php`

**核心方法**:
- `getColumnDefinition()` - 获取数据库列定义
- `validate($value)` - 验证字段值
- `transform($value)` - 转换字段值
- `getValidationRules()` - 获取验证规则

#### 字段类型清单

| 字段类型 | 类名 | 数据库类型 | 用途 |
|---------|------|-----------|------|
| String | StringField | VARCHAR | 短文本 |
| Text | TextField | TEXT | 长文本 |
| Integer | IntegerField | INT | 整数 |
| Bigint | BigintField | BIGINT | 大整数 |
| Decimal | DecimalField | DECIMAL | 小数 |
| Boolean | BooleanField | TINYINT | 布尔值 |
| Date | DateField | DATE | 日期 |
| Datetime | DatetimeField | DATETIME | 日期时间 |
| Timestamp | TimestampField | TIMESTAMP | 时间戳 |
| Json | JsonField | JSON | JSON数据 |
| File | FileField | VARCHAR | 文件路径 |
| Image | ImageField | VARCHAR | 图片路径 |
| Select | SelectField | VARCHAR | 下拉选择 |
| Radio | RadioField | VARCHAR | 单选 |
| Checkbox | CheckboxField | VARCHAR | 复选框 |

#### 字段仓储
**文件**: `infrastructure/Lowcode/Collection/Repository/FieldRepository.php`

**功能**:
- 字段元数据的CRUD操作
- 按集合ID查询字段列表

#### 字段管理服务
**文件**: `infrastructure/Lowcode/Collection/Service/FieldManager.php`

**功能**:
- 字段的创建、更新、删除
- 字段验证
- 字段类型转换

### 3. Relationship（关系）系统
管理集合之间的关联关系。

#### 关系类型
**文件**: `domain/Lowcode/Collection/Enum/RelationType.php`

**支持的关系类型**:
- `ONE_TO_ONE` - 一对一
- `ONE_TO_MANY` - 一对多
- `MANY_TO_ONE` - 多对一
- `MANY_TO_MANY` - 多对多

#### 关系模型
**文件**: `domain/Lowcode/Collection/Model/Relationship.php`

**接口**: `domain/Lowcode/Collection/Interfaces/RelationshipInterface.php`

#### 关系仓储
**文件**: `infrastructure/Lowcode/Collection/Repository/RelationshipRepository.php`

#### 关系管理服务
**文件**: `infrastructure/Lowcode/Collection/Service/RelationshipManager.php`

**功能**:
- 创建关系定义
- 更新关系配置
- 删除关系
- 查询关系数据

### 4. Form Designer（表单设计器）
提供可视化表单设计和数据管理能力。

#### 表单结构管理
**文件**: `infrastructure/Lowcode/FormDesigner/Service/FormSchemaManager.php`

**功能**:
- 表单结构的创建和管理
- 表单字段配置
- 表单布局设计

#### 表单数据管理
**文件**: `infrastructure/Lowcode/FormDesigner/Service/FormDataManager.php`

**功能**:
- 表单数据的CRUD操作
- 数据验证
- 数据转换

#### 表单验证管理
**文件**: `infrastructure/Lowcode/FormDesigner/Service/FormValidatorManager.php`

**功能**:
- 验证规则管理
- 自定义验证器

#### 验证规则生成器
**文件**: `infrastructure/Lowcode/FormDesigner/Service/FormValidatorGenerator.php`

**功能**:
- 根据字段类型自动生成验证规则
- 支持自定义验证规则
- 基于JSON Schema的验证

#### 表单结构仓储
**文件**: `infrastructure/Lowcode/FormDesigner/Repository/FormSchemaRepository.php`

### 5. Migration Manager（迁移管理器）
**文件**: `infrastructure/Lowcode/Collection/Service/MigrationManager.php`

**功能**:
- 数据库结构变更管理
- 迁移历史记录
- 回滚支持

### 6. Schema Builder（结构构建器）
**文件**: `infrastructure/Schema/SchemaBuilder.php`

**接口**: `domain/Schema/Interfaces/SchemaBuilderInterface.php`

**核心方法**:
- `createTable(string $tableName, array $columns)` - 创建数据表
- `dropTable(string $tableName)` - 删除数据表
- `hasTable(string $tableName)` - 检查表是否存在
- `addColumn(string $tableName, array $column)` - 添加字段
- `dropColumn(string $tableName, string $columnName)` - 删除字段
- `hasColumn(string $tableName, string $columnName)` - 检查字段是否存在
- `assertCanRunDDL()` - DDL操作安全检查

**安全特性**:
- DDL操作环境检查（禁止在生产环境直接执行）
- 操作日志记录
- 链路追踪支持

## 数据流程

### 创建集合流程
1. 用户通过API提交集合定义（名称、描述、字段列表）
2. `CollectionController::save()` 接收请求
3. `CollectionManager::create()` 处理业务逻辑
4. `CollectionRepository` 保存集合元数据
5. `FieldRepository` 保存字段定义
6. `SchemaBuilder::createTable()` 创建物理表
7. 返回创建结果

### 更新集合流程
1. 用户提交更新数据
2. `CollectionController::update()` 接收请求
3. `CollectionManager::update()` 处理更新
4. 比较字段变更
5. `SchemaBuilder` 执行DDL操作（添加/删除字段）
6. 更新元数据
7. 清除缓存
8. 返回更新结果

### 删除集合流程
1. 用户请求删除集合
2. `CollectionController::delete()` 接收请求
3. `CollectionManager::delete()` 处理删除
4. 删除关联的关系定义
5. 删除字段定义
6. `SchemaBuilder::dropTable()` 删除物理表
7. 删除集合元数据
8. 清除缓存
9. 返回删除结果

## 缓存策略
- **缓存前缀**: `lowcode:collection:`
- **缓存TTL**: 3600秒（1小时）
- **缓存键格式**: `{prefix}{collection_id}`
- **缓存内容**: 集合信息、字段列表、关系列表
- **缓存清除时机**: 创建、更新、删除操作后

## 多租户支持
- 所有数据表都包含 `tenant_id` 字段
- 通过 `TenantIdentify` 中间件自动识别租户
- 数据库操作自动添加租户过滤条件
- 支持租户级别的数据隔离

## 扩展点
1. **自定义字段类型**: 继承 `AbstractField` 实现新的字段类型
2. **自定义验证器**: 实现 `ValidatorGeneratorInterface` 接口
3. **自定义关系类型**: 扩展 `RelationType` 枚举
4. **事件钩子**: 在关键操作点触发事件（创建、更新、删除）

## 最佳实践
1. 字段名使用小写字母和下划线
2. 集合名使用单数形式
3. 避免在生产环境直接执行DDL操作
4. 使用缓存提高查询性能
5. 定期清理无用的集合和字段
6. 为重要操作添加事件监听
