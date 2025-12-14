# AlkaidSYS 最佳实践指南

> **文档版本**：v1.0
> **创建日期**：2025-11-01
> **最后更新**：2025-11-01
> **维护者**：架构团队

---

## 📋 目录

- [1. 编码规范](#1-编码规范)
- [2. 测试策略](#2-测试策略)
- [3. 安全最佳实践](#3-安全最佳实践)
- [4. 性能优化指南](#4-性能优化指南)

---

## 1. 编码规范

### 1.1 PHP 编码规范

#### 1.1.1 命名规范

```php
<?php
// 文件命名：小写下划线分隔
// good: user_service.php
// bad: userService.php

// 类命名：PascalCase（首字母大写）
class UserService
{
    // 常量命名：全大写下划线分隔
    const DEFAULT_PAGE_SIZE = 20;
    const MAX_RETRY_COUNT = 3;

    // 属性命名：camelCase（驼峰命名）
    private $userRepository;
    private $cacheService;

    // 方法命名：camelCase
    public function getUserById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    // 私有/受保护方法：_ 前缀 + camelCase
    private function _validateUserData(array $data): bool
    {
        return !empty($data['name']) && !empty($data['email']);
    }

    // 接口命名：I 前缀 + PascalCase
    // good: IUserService
    // bad: UserServiceInterface
}

// 接口命名：I 前缀
interface IUserService
{
    public function getUserById(int $id): ?User;
    public function createUser(array $data): User;
}

// Trait 命名：X 前缀
trait XCacheable
{
    public function getCacheKey(): string
    {
        return get_class($this) . ':' . $this->id;
    }
}

// 抽象类命名：Abstract 前缀
abstract class AbstractService
{
    // ...
}
```

#### 1.1.2 代码格式化

```php
<?php
// 命名空间
namespace app\service\user;

// 使用声明
use app\model\User;
use app\repository\UserRepository;
use think\facade\Cache;

// 类定义
class UserService
{
    // 属性声明
    private $userRepository;

    // 构造函数
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    // 方法之间用一个空行分隔
    public function getUserById(int $id): ?User
    {
        $user = $this->userRepository->find($id);

        if ($user) {
            return $user;
        }

        return null;
    }

    // 参数对齐
    public function createUser(
        string $name,
        string $email,
        ?string $phone = null
    ): User {
        // 参数验证
        if (empty($name) || empty($email)) {
            throw new \InvalidArgumentException('Name and email are required');
        }

        // 创建用户
        $user = new User([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'status' => 1,
            'created_at' => time(),
        ]);

        return $user->save();
    }

    // 复杂条件使用括号
    public function isValidUser(array $data): bool
    {
        return (
            isset($data['name']) &&
            isset($data['email']) &&
            filter_var($data['email'], FILTER_VALIDATE_EMAIL)
        );
    }

    // 数组格式化
    public function formatUserList(array $users): array
    {
        return array_map(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status_text' => $this->getStatusText($user->status),
            ];
        }, $users);
    }
}
```

#### 1.1.3 注释规范

```php
<?php
/**
 * 用户服务类
 *
 * 提供用户相关的业务逻辑处理，包括用户创建、查询、更新、删除等功能
 *
 * @package app\service\user
 * @author  Alkaid Team
 * @since   1.0.0
 */
class UserService
{
    /**
     * 获取用户信息
     *
     * 根据用户 ID 获取用户详细信息
     *
     * @param int $id 用户 ID
     * @return User|null 用户信息，未找到返回 null
     * @throws \InvalidArgumentException 当用户 ID 无效时抛出
     * @throws \Exception 当数据库查询失败时抛出
     */
    public function getUserById(int $id): ?User
    {
        // 参数验证
        if ($id <= 0) {
            throw new \InvalidArgumentException('User ID must be greater than 0');
        }

        try {
            return $this->userRepository->find($id);
        } catch (\Exception $e) {
            Log::error('Failed to get user by id: ' . $id, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 创建用户
     *
     * 创建新用户并返回用户实例
     *
     * @param array $data 用户数据
     * @param array $data['name'] 用户姓名（必填）
     * @param array $data['email'] 用户邮箱（必填）
     * @param array $data['phone'] 用户电话（可选）
     * @return User 创建的用户实例
     * @throws \InvalidArgumentException 当数据无效时抛出
     */
    public function createUser(array $data): User
    {
        // TODO: 实现用户创建逻辑
    }

    /**
     * 批量获取用户列表
     *
     * @param array $params 查询参数
     * @param int $params['page'] 页码，默认 1
     * @param int $params['page_size'] 每页数量，默认 20
     * @param string $params['keyword'] 搜索关键词
     * @return array 用户列表
     */
    public function getUserList(array $params = []): array
    {
        // TODO: 实现用户列表查询
    }
}

/**
 * 用户模型
 *
 * @property int $id 用户 ID
 * @property string $name 用户姓名
 * @property string $email 用户邮箱
 * @property string $phone 用户电话
 * @property int $status 用户状态：1-正常，0-禁用
 * @property int $created_at 创建时间
 */
class User
{
    // ...
}
```

### 1.2 TypeScript 编码规范

#### 1.2.1 类型定义

```typescript
// 1. 接口定义
interface User {
  /** 用户 ID */
  id: number;
  /** 用户姓名 */
  name: string;
  /** 用户邮箱 */
  email: string;
  /** 用户电话（可选） */
  phone?: string;
  /** 用户状态 */
  status: UserStatus;
  /** 创建时间 */
  createdAt: Date;
}

// 2. 枚举定义
enum UserStatus {
  /** 正常 */
  Active = 1,
  /** 禁用 */
  Inactive = 0,
  /** 待验证 */
  Pending = 2,
}

// 3. 类型别名
type UserList = User[];
type CreateUserRequest = Omit<User, 'id' | 'createdAt'>;
type UpdateUserRequest = Partial<CreateUserRequest>;

// 4. 泛型定义
interface ApiResponse<T> {
  code: number;
  message: string;
  data: T;
  timestamp: number;
}

interface PaginatedResponse<T> {
  list: T[];
  total: number;
  page: number;
  pageSize: number;
}

// 5. 函数类型定义
interface UserService {
  getUserById(id: number): Promise<User | null>;
  createUser(data: CreateUserRequest): Promise<User>;
  updateUser(id: number, data: UpdateUserRequest): Promise<User>;
  deleteUser(id: number): Promise<boolean>;
  getUserList(params: ListParams): Promise<PaginatedResponse<User>>;
}

// 6. 组件 Props 类型
interface UserCardProps {
  /** 用户信息 */
  user: User;
  /** 是否显示操作按钮 */
  showActions?: boolean;
  /** 点击事件回调 */
  onEdit?: (user: User) => void;
  /** 删除事件回调 */
  onDelete?: (user: User) => void;
}

// 7. 组件 Emits 类型
interface UserCardEmits {
  (e: 'edit', user: User): void;
  (e: 'delete', user: User): void;
  (e: 'view', user: User): void;
}
```

#### 1.2.2 Vue 组件规范

```vue
<template>
  <div class="user-card">
    <!-- 头部信息 -->
    <div class="user-card__header">
      <h3 class="user-card__name">{{ user.name }}</h3>
      <a-tag :color="getStatusColor(user.status)">
        {{ getStatusText(user.status) }}
      </a-tag>
    </div>

    <!-- 用户详情 -->
    <div class="user-card__body">
      <p class="user-card__email">
        <mail-outlined />
        {{ user.email }}
      </p>
      <p v-if="user.phone" class="user-card__phone">
        <phone-outlined />
        {{ user.phone }}
      </p>
    </div>

    <!-- 操作按钮 -->
    <div v-if="showActions" class="user-card__actions">
      <a-button type="link" size="small" @click="handleEdit">
        <edit-outlined />
        编辑
      </a-button>
      <a-button type="link" size="small" danger @click="handleDelete">
        <delete-outlined />
        删除
      </a-button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { message } from 'ant-design-vue';
import {
  MailOutlined,
  PhoneOutlined,
  EditOutlined,
  DeleteOutlined,
} from '@ant-design/icons-vue';

// Props
interface Props {
  user: User;
  showActions?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  showActions: true,
});

// Emits
const emit = defineEmits<UserCardEmits>();

// 计算属性
const normalizedUser = computed(() => ({
  ...props.user,
  name: props.user.name.trim(),
  email: props.user.email.toLowerCase(),
}));

// 方法
const getStatusColor = (status: UserStatus): string => {
  const statusColors = {
    [UserStatus.Active]: 'green',
    [UserStatus.Inactive]: 'red',
    [UserStatus.Pending]: 'orange',
  };
  return statusColors[status] || 'default';
};

const getStatusText = (status: UserStatus): string => {
  const statusTexts = {
    [UserStatus.Active]: '正常',
    [UserStatus.Inactive]: '禁用',
    [UserStatus.Pending]: '待验证',
  };
  return statusTexts[status] || '未知';
};

// 事件处理
const handleEdit = () => {
  emit('edit', normalizedUser.value);
};

const handleDelete = async () => {
  try {
    // 显示确认对话框
    await message.confirm('确定要删除该用户吗？');
    emit('delete', normalizedUser.value);
  } catch {
    // 用户取消删除
  }
};

// 暴露方法给父组件
defineExpose({
  refresh: () => {
    console.log('刷新用户信息');
  },
});
</script>

<style scoped lang="scss">
.user-card {
  background: #fff;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  padding: 16px;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  &__name {
    margin: 0;
    font-size: 16px;
    font-weight: 500;
  }

  &__body {
    margin-bottom: 12px;

    p {
      margin: 0 0 8px;
      color: #666;
      display: flex;
      align-items: center;
      gap: 8px;
    }
  }

  &__actions {
    display: flex;
    gap: 8px;
  }
}
</style>
```

### 1.3 CSS 编码规范

#### 1.3.1 SCSS 规范

```scss
// 1. 变量命名
$primary-color: #1890ff;
$success-color: #52c41a;
$warning-color: #faad14;
$error-color: #f5222d;

$border-radius: 4px;
$box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);

$spacing-xs: 4px;
$spacing-sm: 8px;
$spacing-md: 16px;
$spacing-lg: 24px;
$spacing-xl: 32px;

$font-size-sm: 12px;
$font-size-base: 14px;
$font-size-lg: 16px;
$font-size-xl: 20px;

// 2. 混入定义
@mixin clearfix() {
  &::after {
    content: '';
    display: table;
    clear: both;
  }
}

@mixin ellipsis() {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@mixin button-variant($color, $background, $border) {
  color: $color;
  background-color: $background;
  border-color: $border;

  &:hover {
    background-color: lighten($background, 7.5%);
    border-color: lighten($border, 10%);
  }

  &:active {
    background-color: darken($background, 10%);
    border-color: darken($border, 10%);
  }
}

// 3. 组件样式
.user-card {
  // 使用 BEM 命名规范
  background: #fff;
  border: 1px solid #d9d9d9;
  border-radius: $border-radius;
  padding: $spacing-md;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: $spacing-sm;
    padding-bottom: $spacing-sm;
    border-bottom: 1px solid #f0f0f0;
  }

  &__name {
    margin: 0;
    font-size: $font-size-lg;
    font-weight: 500;
    @include ellipsis();
  }

  &__status {
    display: inline-block;
    padding: 2px 8px;
    font-size: $font-size-sm;
    border-radius: 2px;
    background: #f0f0f0;

    &--active {
      background: rgba($success-color, 0.1);
      color: $success-color;
    }

    &--inactive {
      background: rgba($error-color, 0.1);
      color: $error-color;
    }
  }

  &__body {
    margin-bottom: $spacing-md;
  }

  &__email,
  &__phone {
    margin: 0 0 $spacing-sm;
    color: #666;
    font-size: $font-size-base;
    display: flex;
    align-items: center;
    gap: $spacing-xs;
  }

  &__actions {
    display: flex;
    gap: $spacing-sm;
    padding-top: $spacing-sm;
    border-top: 1px solid #f0f0f0;
  }

  // 状态修饰符
  &--disabled {
    opacity: 0.6;
    pointer-events: none;
  }

  &--loading {
    pointer-events: none;
    cursor: not-allowed;
  }

  // 响应式设计
  @media (max-width: 768px) {
    padding: $spacing-sm;

    &__header {
      flex-direction: column;
      align-items: flex-start;
      gap: $spacing-xs;
    }

    &__actions {
      flex-direction: column;
    }
  }
}

// 4. 工具类
.text-center {
  text-align: center;
}

.text-left {
  text-align: left;
}

.text-right {
  text-align: right;
}

.m-0 {
  margin: 0;
}

.mt-sm {
  margin-top: $spacing-sm;
}

.mb-md {
  margin-bottom: $spacing-md;
}

.p-sm {
  padding: $spacing-sm;
}

.flex {
  display: flex;
}

.flex-center {
  display: flex;
  align-items: center;
  justify-content: center;
}

.hidden {
  display: none;
}

.visible {
  visibility: visible;
}

.invisible {
  visibility: hidden;
}
```

---

## 2. 测试策略

### 2.1 测试金字塔

```
                    /\
                   /  \
                  / E2E \
                 /________\
                /          \
               /  Integration \
              /______________\
             /                  \
            /     Unit Tests    \
           /______________________\
```

### 2.2 单元测试

#### 2.2.1 PHP 单元测试

```php
<?php
// tests/service/user/UserServiceTest.php

use PHPUnit\Framework\TestCase;
use app\service\user\UserService;
use app\repository\UserRepository;
use app\model\User;

/**
 * 用户服务单元测试
 *
 * @group user
 */
class UserServiceTest extends TestCase
{
    protected $userService;
    protected $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userService = new UserService($this->userRepository);
    }

    /**
     * 测试获取用户信息
     *
     * @test
     */
    public function testGetUserById(): void
    {
        // 准备数据
        $userId = 1;
        $expectedUser = new User([
            'id' => $userId,
            'name' => '张三',
            'email' => 'zhangsan@example.com',
        ]);

        // 设置模拟行为
        $this->userRepository
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo($userId))
            ->willReturn($expectedUser);

        // 执行测试
        $result = $this->userService->getUserById($userId);

        // 断言
        $this->assertNotNull($result);
        $this->assertEquals($userId, $result->id);
        $this->assertEquals('张三', $result->name);
        $this->assertEquals('zhangsan@example.com', $result->email);
    }

    /**
     * 测试创建用户（成功）
     *
     * @test
     */
    public function testCreateUserSuccess(): void
    {
        // 准备数据
        $userData = [
            'name' => '李四',
            'email' => 'lisi@example.com',
            'phone' => '13800138000',
        ];

        $expectedUser = new User($userData + ['id' => 1, 'status' => 1]);

        // 设置模拟行为
        $this->userRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->equalTo($userData))
            ->willReturn($expectedUser);

        // 执行测试
        $result = $this->userService->createUser($userData);

        // 断言
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('李四', $result->name);
    }

    /**
     * 测试创建用户（参数验证失败）
     *
     * @test
     * @dataProvider invalidCreateUserDataProvider
     */
    public function testCreateUserWithInvalidData(array $data, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->userService->createUser($data);
    }

    /**
     * 无效数据提供者
     */
    public function invalidCreateUserDataProvider(): array
    {
        return [
            'empty_name' => [
                ['name' => '', 'email' => 'test@example.com'],
                'Name and email are required',
            ],
            'empty_email' => [
                ['name' => 'Test', 'email' => ''],
                'Name and email are required',
            ],
            'invalid_email' => [
                ['name' => 'Test', 'email' => 'invalid-email'],
                'Invalid email format',
            ],
        ];
    }

    /**
     * 测试用户列表查询
     *
     * @test
     */
    public function testGetUserList(): void
    {
        // 准备数据
        $params = [
            'page' => 1,
            'page_size' => 20,
            'keyword' => '张',
        ];

        $expectedUsers = [
            new User(['id' => 1, 'name' => '张三', 'email' => 'zhangsan@example.com']),
            new User(['id' => 2, 'name' => '张四', 'email' => 'zhangsi@example.com']),
        ];

        $expectedTotal = 2;

        // 设置模拟行为
        $this->userRepository
            ->expects($this->once())
            ->method('getList')
            ->with(
                $this->equalTo($params['page']),
                $this->equalTo($params['page_size']),
                $this->equalTo($params['keyword'])
            )
            ->willReturn([
                'list' => $expectedUsers,
                'total' => $expectedTotal,
            ]);

        // 执行测试
        $result = $this->userService->getUserList($params);

        // 断言
        $this->assertCount(2, $result['list']);
        $this->assertEquals($expectedTotal, $result['total']);
        $this->assertEquals('张三', $result['list'][0]['name']);
    }

    protected function tearDown(): void
    {
        $this->userService = null;
        $this->userRepository = null;
        parent::tearDown();
    }
}
```

#### 2.2.2 TypeScript 单元测试

```typescript
// tests/services/userService.test.ts
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { UserService } from '@/services/userService';
import type { User, CreateUserRequest } from '@/types/user';

// 模拟 API
vi.mock('@/api/user', () => ({
  getUserById: vi.fn(),
  createUser: vi.fn(),
  getUserList: vi.fn(),
  updateUser: vi.fn(),
  deleteUser: vi.fn(),
}));

import { getUserById, createUser, getUserList, updateUser, deleteUser } from '@/api/user';

describe('UserService', () => {
  let userService: UserService;

  beforeEach(() => {
    vi.clearAllMocks();
    userService = new UserService();
  });

  describe('getUserById', () => {
    it('should return user when user exists', async () => {
      // 准备数据
      const userId = 1;
      const mockUser: User = {
        id: userId,
        name: '张三',
        email: 'zhangsan@example.com',
        status: UserStatus.Active,
        createdAt: new Date(),
      };

      (getUserById as vi.MockedFunction<typeof getUserById>).mockResolvedValue(mockUser);

      // 执行测试
      const result = await userService.getUserById(userId);

      // 断言
      expect(result).toEqual(mockUser);
      expect(getUserById).toHaveBeenCalledWith(userId);
      expect(getUserById).toHaveBeenCalledTimes(1);
    });

    it('should return null when user does not exist', async () => {
      // 准备数据
      const userId = 999;
      (getUserById as vi.MockedFunction<typeof getUserById>).mockResolvedValue(null);

      // 执行测试
      const result = await userService.getUserById(userId);

      // 断言
      expect(result).toBeNull();
    });

    it('should throw error when user id is invalid', async () => {
      // 执行测试并断言
      await expect(userService.getUserById(0)).rejects.toThrow('User ID must be greater than 0');
    });
  });

  describe('createUser', () => {
    it('should create user successfully', async () => {
      // 准备数据
      const userData: CreateUserRequest = {
        name: '李四',
        email: 'lisi@example.com',
        phone: '13800138000',
      };

      const mockCreatedUser: User = {
        id: 2,
        ...userData,
        status: UserStatus.Active,
        createdAt: new Date(),
      };

      (createUser as vi.MockedFunction<typeof createUser>).mockResolvedValue(mockCreatedUser);

      // 执行测试
      const result = await userService.createUser(userData);

      // 断言
      expect(result).toEqual(mockCreatedUser);
      expect(createUser).toHaveBeenCalledWith(userData);
      expect(result.id).toBe(2);
    });

    it('should throw error when name is empty', async () => {
      // 准备数据
      const invalidData = {
        name: '',
        email: 'test@example.com',
      } as CreateUserRequest;

      // 执行测试并断言
      await expect(userService.createUser(invalidData)).rejects.toThrow('Name is required');
    });

    it('should throw error when email is invalid', async () => {
      // 准备数据
      const invalidData = {
        name: 'Test',
        email: 'invalid-email',
      } as CreateUserRequest;

      // 执行测试并断言
      await expect(userService.createUser(invalidData)).rejects.toThrow('Invalid email format');
    });
  });

  describe('getUserList', () => {
    it('should return user list with pagination', async () => {
      // 准备数据
      const params = {
        page: 1,
        pageSize: 20,
        keyword: '张',
      };

      const mockResponse = {
        list: [
          {
            id: 1,
            name: '张三',
            email: 'zhangsan@example.com',
            status: UserStatus.Active,
            createdAt: new Date(),
          },
        ],
        total: 1,
        page: 1,
        pageSize: 20,
      };

      (getUserList as vi.MockedFunction<typeof getUserList>).mockResolvedValue(mockResponse);

      // 执行测试
      const result = await userService.getUserList(params);

      // 断言
      expect(result).toEqual(mockResponse);
      expect(result.list).toHaveLength(1);
      expect(result.total).toBe(1);
      expect(getUserList).toHaveBeenCalledWith(params);
    });

    it('should use default values when params not provided', async () => {
      // 执行测试
      const result = await userService.getUserList();

      // 断言
      expect(result.list).toBeDefined();
      expect(result.page).toBe(1);
      expect(result.pageSize).toBe(20);
    });
  });
});
```

### 2.3 集成测试

```php
<?php
// tests/integration/UserServiceIntegrationTest.php

use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * 用户服务集成测试
 *
 * @group integration
 */
class UserServiceIntegrationTest extends TestCase
{
    protected UserService $userService;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // 初始化数据库连接
        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=test_alkaid',
            'root',
            'password'
        );

        // 迁移测试数据库
        $this->migrateDatabase();

        // 创建服务实例
        $this->userService = new UserService(
            new UserRepository($this->pdo)
        );
    }

    protected function migrateDatabase(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS test_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                phone VARCHAR(20),
                status TINYINT DEFAULT 1,
                created_at INT NOT NULL
            );
        ";

        $this->pdo->exec($sql);
    }

    /**
     * 测试完整的用户创建流程
     */
    public function testCreateUserFlow(): void
    {
        // 1. 创建用户
        $userData = [
            'name' => '集成测试用户',
            'email' => 'integration@example.com',
            'phone' => '13900139000',
        ];

        $user = $this->userService->createUser($userData);

        // 2. 验证用户创建成功
        $this->assertNotNull($user->id);
        $this->assertEquals('集成测试用户', $user->name);
        $this->assertEquals('integration@example.com', $user->email);

        // 3. 从数据库查询用户
        $fetchedUser = $this->userService->getUserById($user->id);

        // 4. 验证数据一致性
        $this->assertNotNull($fetchedUser);
        $this->assertEquals($user->id, $fetchedUser->id);
        $this->assertEquals($user->name, $fetchedUser->name);

        // 5. 清理测试数据
        $this->pdo->exec("DELETE FROM test_users WHERE email = 'integration@example.com'");
    }

    /**
     * 测试用户列表查询（带搜索）
     */
    public function testGetUserListWithSearch(): void
    {
        // 1. 创建测试用户
        $this->createTestUsers();

        // 2. 执行搜索
        $result = $this->userService->getUserList([
            'page' => 1,
            'page_size' => 10,
            'keyword' => '测试',
        ]);

        // 3. 验证结果
        $this->assertGreaterThan(0, $result['total']);
        $this->assertLessThanOrEqual(10, count($result['list']));

        // 验证所有结果都包含搜索关键词
        foreach ($result['list'] as $user) {
            $this->assertStringContainsString('测试', $user['name']);
        }

        // 4. 清理测试数据
        $this->cleanupTestUsers();
    }

    protected function createTestUsers(): void
    {
        $users = [
            ['name' => '测试用户1', 'email' => 'test1@example.com'],
            ['name' => '测试用户2', 'email' => 'test2@example.com'],
            ['name' => '普通用户', 'email' => 'normal@example.com'],
        ];

        foreach ($users as $user) {
            $this->userService->createUser($user);
        }
    }

    protected function cleanupTestUsers(): void
    {
        $this->pdo->exec("DELETE FROM test_users WHERE email LIKE '%@example.com'");
    }

    protected function tearDown(): void
    {
        // 清理测试数据库
        $this->pdo->exec("DROP TABLE IF EXISTS test_users");

        parent::tearDown();
    }
}
```

### 2.4 端到端测试

```typescript
// tests/e2e/user.spec.ts
import { test, expect } from '@playwright/test';

test.describe('用户管理 E2E 测试', () => {
  test.beforeEach(async ({ page }) => {
    // 登录
    await page.goto('/login');
    await page.fill('[data-testid=username]', 'admin');
    await page.fill('[data-testid=password]', 'password123');
    await page.click('[data-testid=login-button]');
    await expect(page).toHaveURL('/dashboard');
  });

  test('用户列表页面', async ({ page }) => {
    // 导航到用户管理页面
    await page.click('[data-testid=user-menu]');
    await page.click('[data-testid=user-list]');

    // 验证页面标题
    await expect(page.locator('h1')).toContainText('用户列表');

    // 验证用户列表
    await expect(page.locator('[data-testid=user-table]')).toBeVisible();

    // 验证分页控件
    await expect(page.locator('[data-testid=pagination]')).toBeVisible();
  });

  test('创建新用户', async ({ page }) => {
    // 导航到用户创建页面
    await page.goto('/users/create');
    await expect(page.locator('h1')).toContainText('创建用户');

    // 填写表单
    await page.fill('[data-testid=name]', 'E2E测试用户');
    await page.fill('[data-testid=email]', 'e2e-test@example.com');
    await page.fill('[data-testid=phone]', '13900139000');

    // 选择状态
    await page.selectOption('[data-testid=status]', '1');

    // 提交表单
    await page.click('[data-testid=submit-button]');

    // 验证成功消息
    await expect(page.locator('[data-testid=success-message]'))
      .toContainText('用户创建成功');

    // 验证跳转
    await expect(page).toHaveURL(/\/users\/\d+$/);
  });

  test('编辑用户信息', async ({ page }) => {
    // 进入用户详情页
    await page.goto('/users/1');

    // 点击编辑按钮
    await page.click('[data-testid=edit-button]');

    // 修改用户信息
    await page.fill('[data-testid=name]', '修改后的用户名');
    await page.fill('[data-testid=email]', 'updated@example.com');

    // 保存修改
    await page.click('[data-testid=save-button]');

    // 验证成功消息
    await expect(page.locator('[data-testid=success-message]'))
      .toContainText('用户信息更新成功');

    // 验证页面内容更新
    await expect(page.locator('[data-testid=user-name]'))
      .toContainText('修改后的用户名');
    await expect(page.locator('[data-testid=user-email]'))
      .toContainText('updated@example.com');
  });

  test('删除用户', async ({ page }) => {
    // 进入用户详情页
    await page.goto('/users/2');

    // 点击删除按钮
    await page.click('[data-testid=delete-button]');

    // 确认删除
    await expect(page.locator('[data-testid=confirm-dialog]')).toBeVisible();
    await page.click('[data-testid=confirm-delete]');

    // 验证成功消息
    await expect(page.locator('[data-testid=success-message]'))
      .toContainText('用户删除成功');

    // 验证跳转到用户列表页
    await expect(page).toHaveURL('/users');
    await expect(page.locator('[data-testid=user-table] tr')).not.toContainText('用户2');
  });

  test('搜索用户', async ({ page }) => {
    // 进入用户列表页
    await page.goto('/users');

    // 输入搜索关键词
    await page.fill('[data-testid=search-input]', '张三');
    await page.press('[data-testid=search-input]', 'Enter');

    // 等待搜索结果
    await page.waitForSelector('[data-testid=user-table] tr');

    // 验证搜索结果
    const userRows = page.locator('[data-testid=user-table] tbody tr');
    const count = await userRows.count();

    for (let i = 0; i < count; i++) {
      await expect(userRows.nth(i)).toContainText('张三');
    }
  });
});
```

---

## 3. 安全最佳实践

### 3.1 输入验证与过滤

#### 3.1.1 服务端验证

```php
<?php
// app/validate/UserValidate.php

namespace app\validate;

use think\Validate;

/**
 * 用户验证器
 */
class UserValidate extends Validate
{
    protected $rule = [
        'name' => 'require|chsDash|length:2,50',
        'email' => 'require|email|unique:users,email',
        'phone' => 'mobile|unique:users,phone',
        'password' => 'require|length:6,32|alphaNum',
        'password_confirm' => 'require|confirm:password',
        'avatar' => 'file|fileExt:jpg,png,gif|fileSize:2M',
        'status' => 'in:0,1,2',
    ];

    protected $message = [
        'name.require' => '用户名不能为空',
        'name.chsDash' => '用户名只能包含中文、字母、数字和下划线',
        'name.length' => '用户名长度必须在2-50个字符之间',
        'email.require' => '邮箱不能为空',
        'email.email' => '邮箱格式不正确',
        'email.unique' => '邮箱已被使用',
        'phone.mobile' => '手机号格式不正确',
        'phone.unique' => '手机号已被使用',
        'password.require' => '密码不能为空',
        'password.length' => '密码长度必须在6-32个字符之间',
        'password.alphaNum' => '密码只能包含字母和数字',
        'password_confirm.require' => '确认密码不能为空',
        'password_confirm.confirm' => '两次输入的密码不一致',
        'avatar.file' => '请选择头像文件',
        'avatar.fileExt' => '头像格式只能为jpg、png或gif',
        'avatar.fileSize' => '头像大小不能超过2M',
        'status.in' => '状态值不正确',
    ];

    protected $scene = [
        'create' => ['name', 'email', 'phone', 'password', 'password_confirm', 'avatar'],
        'update' => ['name', 'email', 'phone', 'avatar'],
        'login' => ['email', 'password'],
    ];

    /**
     * 自定义验证：检查邮箱域名
     */
    protected function checkEmailDomain($value, $rule, $data = [])
    {
        $allowedDomains = ['alkaidsys.com', 'company.com'];
        $domain = substr(strrchr($value, '@'), 1);

        return in_array($domain, $allowedDomains);
    }

    /**
     * 自定义验证：检查密码强度
     */
    protected function checkPasswordStrength($value, $rule, $data = [])
    {
        // 至少包含一个大写字母
        if (!preg_match('/[A-Z]/', $value)) {
            return false;
        }

        // 至少包含一个小写字母
        if (!preg_match('/[a-z]/', $value)) {
            return false;
        }

        // 至少包含一个数字
        if (!preg_match('/\d/', $value)) {
            return false;
        }

        // 至少包含一个特殊字符
        if (!preg_match('/[^a-zA-Z0-9]/', $value)) {
            return false;
        }

        return true;
    }
}
```

#### 3.1.2 XSS 防护

```php
<?php
// app/service/core/security/XssProtectionService.php

namespace app\service\core\security;

/**
 * XSS 防护服务
 */
class XssProtectionService
{
    /**
     * 转义 HTML 特殊字符
     */
    public static function escapeHtml(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 过滤危险标签
     */
    public static function filterTags(string $input): string
    {
        // 允许的标签
        $allowedTags = '<p><br><strong><em><u><ul><ol><li><a><img><h1><h2><h3><h4><h5><h6>';

        return strip_tags($input, $allowedTags);
    }

    /**
     * 过滤危险属性
     */
    public static function filterAttributes(string $input): string
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($input, LIBXML_NOERROR | LIBXML_NOWARNING);

        $allowedAttributes = [
            'a' => ['href', 'title', 'target'],
            'img' => ['src', 'alt', 'width', 'height'],
            'p' => ['class'],
        ];

        foreach ($dom->getElementsByTagName('*') as $element) {
            $tagName = strtolower($element->tagName);

            // 移除所有属性
            while ($element->attributes->length > 0) {
                $element->removeAttribute($element->attributes->item(0)->name);
            }

            // 保留允许的属性
            if (isset($allowedAttributes[$tagName])) {
                foreach ($allowedAttributes[$tagName] as $attr) {
                    $value = $element->getAttribute($attr);
                    $value = self::sanitizeAttributeValue($tagName, $attr, $value);
                    if ($value) {
                        $element->setAttribute($attr, $value);
                    }
                }
            }
        }

        return $dom->saveHTML();
    }

    /**
     * 清理属性值
     */
    protected static function sanitizeAttributeValue(string $tag, string $attr, string $value): string
    {
        switch ($attr) {
            case 'href':
                // 检查链接协议
                if (!preg_match('/^(https?:|mailto:|tel:)/i', $value)) {
                    return '';
                }
                return $value;

            case 'src':
                // 检查图片源
                if (!preg_match('/^data:image\/(jpeg|png|gif);base64,/', $value) &&
                    !preg_match('/^(https?:)?\/\//i', $value)) {
                    return '';
                }
                return $value;

            case 'class':
                // 只保留字母、数字、空格和连字符
                return preg_replace('/[^a-zA-Z0-9\- ]/', '', $value);

            default:
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * 检测 XSS 攻击
     */
    public static function detectXss(string $input): bool
    {
        $xssPatterns = [
            '/<script[^>]*>.*?<\/script>/is',
            '/<iframe[^>]*>.*?<\/iframe>/is',
            '/javascript:/i',
            '/on\w+\s*=/i',
        ];

        foreach ($xssPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }
}
```

### 3.2 SQL 注入防护

```php
<?php
// app/service/core/security/SqlInjectionProtectionService.php

namespace app\service\core\security;

use think\db\Query;
use think\facade\Db;

/**
 * SQL 注入防护服务
 */
class SqlInjectionProtectionService
{
    /**
     * 构建安全的查询条件
     */
    public static function buildSafeConditions(array $conditions): array
    {
        $safeConditions = [];

        foreach ($conditions as $key => $value) {
            // 检查字段名（只允许字母、数字和下划线）
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Invalid field name: {$key}");
            }

            // 检查值类型
            if (is_string($value)) {
                // 字符串值需要转义
                $value = self::escapeString($value);
            } elseif (is_array($value)) {
                // 数组值检查每个元素
                foreach ($value as $index => $item) {
                    if (is_string($item)) {
                        $value[$index] = self::escapeString($item);
                    }
                }
            }

            $safeConditions[$key] = $value;
        }

        return $safeConditions;
    }

    /**
     * 转义字符串
     */
    protected static function escapeString(string $string): string
    {
        return Db::getConnection()->getPdo()->quote($string);
    }

    /**
     * 执行安全的查询
     */
    public static function safeQuery(string $table, array $conditions = [], array $options = []): array
    {
        $query = Db::table($table);

        // 添加条件
        $safeConditions = self::buildSafeConditions($conditions);
        foreach ($safeConditions as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        // 添加排序
        if (isset($options['order'])) {
            $order = $options['order'];
            if (is_string($order) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*/', $order)) {
                $direction = $options['direction'] ?? 'ASC';
                $query->order($order, $direction);
            }
        }

        // 添加限制
        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        // 添加分页
        if (isset($options['page'])) {
            $pageSize = $options['page_size'] ?? 20;
            $query->paginate([
                'list_rows' => $pageSize,
                'page' => $options['page'],
            ]);
        }

        return $query->select()->toArray();
    }

    /**
     * 使用参数化查询
     */
    public static function preparedQuery(string $sql, array $params = []): array
    {
        try {
            // 检查 SQL 是否包含危险关键字
            if (self::isDangerousQuery($sql)) {
                throw new \Exception('Potentially dangerous query detected');
            }

            // 执行预处理查询
            return Db::query($sql, $params);
        } catch (\Exception $e) {
            log_error('SQL query failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 检查危险查询
     */
    protected static function isDangerousQuery(string $sql): bool
    {
        $dangerousKeywords = [
            'union', 'select', 'insert', 'update', 'delete',
            'drop', 'create', 'alter', 'exec', 'execute',
            'information_schema', 'mysql', 'sys',
        ];

        $sqlLower = strtolower($sql);

        foreach ($dangerousKeywords as $keyword) {
            if (strpos($sqlLower, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
```

### 3.3 CSRF 防护

```php
<?php
// app/middleware/CsrfProtection.php

namespace app\middleware;

use think\facade\Session;
use think\facade\Request;

/**
 * CSRF 防护中间件
 */
class CsrfProtection
{
    public function handle($request, \Closure $next)
    {
        $method = $request->method();

        // GET 请求不检查 CSRF
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // 生成 CSRF Token
        $token = $this->generateToken();

        // 检查 CSRF Token
        if (!$this->validateToken($token)) {
            return json([
                'code' => 403,
                'message' => 'CSRF token mismatch',
            ], 403);
        }

        return $next($request);
    }

    /**
     * 生成 CSRF Token
     */
    protected function generateToken(): string
    {
        $token = Session::get('csrf_token');

        if (!$token || $this->isTokenExpired($token)) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
            Session::set('csrf_token_time', time());
        }

        return $token;
    }

    /**
     * 验证 CSRF Token
     */
    protected function validateToken(string $token): bool
    {
        $sessionToken = Session::get('csrf_token');
        $tokenTime = Session::get('csrf_token_time');

        // 检查 Token 是否匹配
        if (!hash_equals($sessionToken, $token)) {
            return false;
        }

        // 检查 Token 是否过期（默认 2 小时）
        if ($this->isTokenExpired($token)) {
            return false;
        }

        return true;
    }

    /**
     * 检查 Token 是否过期
     */
    protected function isTokenExpired(string $token): bool
    {
        $tokenTime = Session::get('csrf_token_time');
        $expireTime = config('app.csrf_expire', 7200); // 默认 2 小时

        return (time() - $tokenTime) > $expireTime;
    }
}
```

---

## 4. 性能优化指南

### 4.1 数据库优化

#### 4.1.1 查询优化

```sql
-- 1. 使用索引优化查询
-- 为经常查询的字段添加索引
ALTER TABLE users ADD INDEX idx_email (email);
ALTER TABLE users ADD INDEX idx_status_created (status, created_at);

-- 2. 优化分页查询
-- 不推荐： OFFSET 大量数据时性能差
SELECT * FROM users ORDER BY id LIMIT 20 OFFSET 1000000;

-- 推荐：使用子查询优化
SELECT * FROM users
WHERE id > (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1000000)
ORDER BY id LIMIT 20;

-- 3. 避免 SELECT *
-- 不推荐
SELECT * FROM users WHERE id = 1;

-- 推荐：只查询需要的字段
SELECT id, name, email FROM users WHERE id = 1;

-- 4. 使用 EXPLAIN 分析查询
EXPLAIN SELECT u.*, p.title
FROM users u
JOIN posts p ON u.id = p.user_id
WHERE u.status = 1;

-- 5. 优化 JOIN 查询
-- 添加必要的索引
ALTER TABLE posts ADD INDEX idx_user_id (user_id);
ALTER TABLE posts ADD INDEX idx_status_user (status, user_id);
```

#### 4.1.2 事务优化

```php
<?php
// app/service/user/UserBatchService.php

namespace app\service\user;

/**
 * 用户批量操作服务
 */
class UserBatchService
{
    protected $userModel;

    public function __construct()
    {
        $this->userModel = model('User');
    }

    /**
     * 批量更新用户状态
     */
    public function batchUpdateStatus(array $userIds, int $status): bool
    {
        // 1. 批量操作使用事务
        Db::startTrans();

        try {
            // 2. 分批处理，避免大事务
            $batchSize = 100;
            $batches = array_chunk($userIds, $batchSize);

            foreach ($batches as $batch) {
                // 3. 使用 WHERE IN 而不是循环更新
                Db::name('users')
                    ->whereIn('id', $batch)
                    ->update([
                        'status' => $status,
                        'updated_at' => time(),
                    ]);

                // 4. 释放内存
                unset($batch);
            }

            Db::commit();
            return true;

        } catch (\Exception $e) {
            Db::rollback();
            log_error('Batch update status failed', [
                'user_ids' => $userIds,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 批量插入用户
     */
    public function batchInsertUsers(array $users): array
    {
        // 1. 验证数据
        $this->validateUserData($users);

        Db::startTrans();

        try {
            $insertedIds = [];
            $batchSize = 100;

            // 2. 分批插入
            for ($i = 0; $i < count($users); $i += $batchSize) {
                $batch = array_slice($users, $i, $batchSize);

                // 3. 批量插入
                $result = Db::name('users')->insertAll($batch, true);

                if ($result) {
                    $insertedIds = array_merge($insertedIds, $result);
                }

                unset($batch);
            }

            Db::commit();
            return $insertedIds;

        } catch (\Exception $e) {
            Db::rollback();
            log_error('Batch insert users failed', [
                'user_count' => count($users),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 验证用户数据
     */
    protected function validateUserData(array $users): void
    {
        foreach ($users as $index => $user) {
            if (empty($user['name']) || empty($user['email'])) {
                throw new \InvalidArgumentException("User at index {$index} has missing required fields");
            }

            // 检查邮箱唯一性
            $existingUser = Db::name('users')
                ->where('email', $user['email'])
                ->find();

            if ($existingUser) {
                throw new \InvalidArgumentException("User at index {$index} has duplicate email");
            }
        }
    }
}
```

### 4.2 缓存优化

#### 4.2.1 缓存键设计

```php
<?php
// app/service/core/cache/CacheKeyService.php

namespace app\service\core\cache;

/**
 * 缓存键设计服务
 */
class CacheKeyService
{
    // 缓存键前缀
    const PREFIX_USER = 'user';
    const PREFIX_APPLICATION = 'app';
    const PREFIX_PLUGIN = 'plugin';
    const PREFIX_CONFIG = 'config';

    // 生成用户缓存键
    public static function getUserKey(int $userId): string
    {
        return self::buildKey(self::PREFIX_USER, $userId);
    }

    // 生成应用缓存键
    public static function getApplicationKey(string $appKey): string
    {
        return self::buildKey(self::PREFIX_APPLICATION, $appKey);
    }

    // 生成配置缓存键
    public static function getConfigKey(string $configKey): string
    {
        return self::buildKey(self::PREFIX_CONFIG, $configKey);
    }

    // 生成用户列表缓存键
    public static function getUserListKey(array $params): string
    {
        // 使用参数作为缓存键的一部分
        $paramsString = http_build_query($params);
        $paramsHash = md5($paramsString);

        return self::buildKey('user_list', $paramsHash);
    }

    // 生成统计缓存键
    public static function getStatsKey(string $type, array $params = []): string
    {
        $paramsString = !empty($params) ? http_build_query($params) : '';
        $paramsHash = md5($paramsString);

        return self::buildKey('stats', $type, $paramsHash);
    }

    // 构建缓存键
    protected static function buildKey(string ...$parts): string
    {
        return implode(':', ['alkaid'] + $parts);
    }

    // 生成用户列表缓存键（带租户隔离）
    public static function getTenantUserListKey(int $tenantId, array $params): string
    {
        $paramsString = http_build_query($params);
        $paramsHash = md5($paramsString);

        return self::buildKey('tenant', $tenantId, 'user_list', $paramsHash);
    }
}
```

#### 4.2.2 缓存更新策略

```php
<?php
// app/service/user/UserCacheService.php

namespace app\service\user;

/**
 * 用户缓存服务
 */
class UserCacheService
{
    protected $cacheService;
    protected $userModel;

    public function __construct()
    {
        $this->cacheService = app(CacheService::class);
        $this->userModel = model('User');
    }

    /**
     * 获取用户信息（带缓存）
     */
    public function getUserById(int $userId): ?array
    {
        // 1. 先从缓存获取
        $cacheKey = CacheKeyService::getUserKey($userId);
        $user = $this->cacheService->get($cacheKey);

        if ($user !== null) {
            return $user;
        }

        // 2. 缓存未命中，从数据库查询
        $user = $this->userModel->find($userId);

        if ($user) {
            // 3. 更新缓存
            $this->cacheService->set(
                $cacheKey,
                $user->toArray(),
                3600 // 缓存 1 小时
            );

            return $user->toArray();
        }

        return null;
    }

    /**
     * 更新用户信息（清除缓存）
     */
    public function updateUser(int $userId, array $data): bool
    {
        try {
            // 1. 更新数据库
            $result = $this->userModel->where('id', $userId)->update($data);

            if ($result) {
                // 2. 清除相关缓存
                $this->clearUserCache($userId);
                $this->clearUserListCache();
            }

            return $result;

        } catch (\Exception $e) {
            log_error('Update user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 清除用户缓存
     */
    public function clearUserCache(int $userId): void
    {
        $cacheKey = CacheKeyService::getUserKey($userId);
        $this->cacheService->delete($cacheKey);
    }

    /**
     * 清除用户列表缓存
     */
    public function clearUserListCache(): void
    {
        $this->cacheService->clear('user_list');
    }

    /**
     * 预热缓存
     */
    public function warmupUserCache(int $userId): void
    {
        $user = $this->userModel->find($userId);

        if ($user) {
            $cacheKey = CacheKeyService::getUserKey($userId);
            $this->cacheService->set(
                $cacheKey,
                $user->toArray(),
                3600
            );
        }
    }

    /**
     * 批量清除缓存
     */
    public function clearBatchUserCache(array $userIds): void
    {
        $cacheKeys = array_map(
            function ($userId) {
                return CacheKeyService::getUserKey($userId);
            },
            $userIds
        );

        $this->cacheService->deleteMultiple($cacheKeys);
    }

    /**
     * 缓存统计信息
     */
    public function getCacheStats(): array
    {
        return [
            'user_cache_keys' => $this->getUserCacheKeys(),
            'user_list_cache_keys' => $this->getUserListCacheKeys(),
            'total_keys' => count($this->getUserCacheKeys()) + count($this->getUserListCacheKeys()),
        ];
    }

    protected function getUserCacheKeys(): array
    {
        // 实际实现中需要遍历 Redis 查找匹配的键
        return [];
    }

    protected function getUserListCacheKeys(): array
    {
        return [];
    }
}
```

---

## 📝 实施检查清单

### 编码规范检查
- [ ] 所有 PHP 代码遵循 PSR-12 标准
- [ ] 所有 TypeScript 代码符合规范
- [ ] 所有 Vue 组件使用 Composition API
- [ ] 所有 SCSS 使用 BEM 命名规范
- [ ] 所有文件都有完整的注释

### 测试检查
- [ ] 单元测试覆盖率 ≥ 80%
- [ ] 关键业务逻辑有集成测试
- [ ] 关键用户流程有 E2E 测试
- [ ] 所有测试都能通过
- [ ] 测试数据已隔离

### 安全检查
- [ ] 所有输入都经过验证和过滤
- [ ] SQL 查询使用参数化查询
- [ ] 已启用 XSS 防护
- [ ] 已启用 CSRF 防护
- [ ] 敏感数据已加密

### 性能检查
- [ ] 数据库查询已优化
- [ ] 关键数据已缓存
- [ ] 静态资源已压缩
- [ ] 图片已懒加载
- [ ] API 响应时间 < 200ms

---

**最后更新**：2025-11-01
**文档版本**：v1.0
**维护者**：AlkaidSYS 架构团队
