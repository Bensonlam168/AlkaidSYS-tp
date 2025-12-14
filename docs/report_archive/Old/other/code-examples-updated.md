# AlkaidSYS 代码示例更新指南

> **文档版本**：v1.0
> **创建日期**：2025-11-01
> **最后更新**：2025-11-01
> **维护者**：架构团队

---

## 📋 目录

- [1. 概述](#1-概述)
- [2. 修正示例代码](#2-修正示例代码)
- [3. TypeScript 类型定义完善](#3-typescript-类型定义完善)
- [4. API 优化](#4-api-优化)
- [5. 响应式数据处理](#5-响应式数据处理)

---

## 1. 概述

本文档基于分析报告中发现的问题，提供了修正后的代码示例。所有示例均符合以下标准：

- ✅ 使用 Ant Design Vue 4.x（替代 Element Plus）
- ✅ 完整的 TypeScript 类型定义
- ✅ Vue 3 Composition API
- ✅ TypeScript 严格模式

---

## 2. 修正示例代码

### 2.1 PC 客户端设计示例修正

#### ❌ 修正前（使用 Element Plus）

```vue
<!-- 错误：使用 Element Plus -->
<template>
  <el-table :data="tableData" v-loading="loading">
    <el-table-column prop="name" label="姓名" />
    <el-table-column prop="email" label="邮箱" />
  </el-table>
</template>

<script setup lang="ts">
// 缺少类型定义
const loading = ref(false);
const tableData = ref([]);
</script>
```

#### ✅ 修正后（使用 Ant Design Vue）

```vue
<!-- 正确：使用 Ant Design Vue 4.x -->
<template>
  <a-table
    :data-source="tableData"
    :columns="columns"
    :loading="loading"
    :row-key="record => record.id"
    :pagination="pagination"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'name'">
        {{ record.name }}
      </template>
      <template v-else-if="column.key === 'email'">
        {{ record.email }}
      </template>
    </template>
  </a-table>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue';
import type { TableColumnType } from 'ant-design-vue';

// 完整的类型定义
interface User {
  id: number;
  name: string;
  email: string;
  status: number;
}

const loading = ref(false);
const tableData = ref<User[]>([]);

const columns: TableColumnType[] = [
  {
    title: '姓名',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: '邮箱',
    dataIndex: 'email',
    key: 'email',
  },
];

const pagination = reactive({
  current: 1,
  pageSize: 20,
  total: 0,
  showSizeChanger: true,
  showQuickJumper: true,
});
</script>
```

### 2.2 Header 组件修正

#### ❌ 修正前（API 不一致）

```vue
<!-- 错误：Vue 2 语法和 Element Plus -->
<template>
  <header class="header">
    <el-dropdown @command="handleUserCommand">
      <span class="user-info">
        <el-avatar :size="24" :src="userInfo?.avatar" />
        <span class="username">{{ userInfo?.nickname }}</span>
        <el-icon><ArrowDown /></el-icon>
      </span>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="profile">个人中心</el-dropdown-item>
          <el-dropdown-item divided command="logout">退出登录</el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
  </header>
</template>
```

#### ✅ 修正后（Vue 3 + Ant Design Vue）

```vue
<template>
  <header class="header">
    <div class="header-top">
      <div class="container">
        <div class="header-top-left">
          <span>欢迎来到 AlkaidSYS 商城</span>
        </div>
        <div class="header-top-right">
          <template v-if="isLoggedIn">
            <a-dropdown @command="handleUserCommand">
              <span class="user-info">
                <a-avatar :size="24" :src="userInfo?.avatar" />
                <span class="username">{{ userInfo?.nickname }}</span>
                <down-outlined />
              </span>
              <template #overlay>
                <a-menu @click="({ key }) => handleUserCommand(key)">
                  <a-menu-item key="profile">个人中心</a-menu-item>
                  <a-menu-divider />
                  <a-menu-item key="logout">退出登录</a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
          </template>
          <template v-else>
            <a href="/login">登录</a>
            <span class="divider">|</span>
            <a href="/register">注册</a>
          </template>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { DownOutlined } from '@ant-design/icons-vue';

// 类型定义
interface UserInfo {
  id: number;
  nickname: string;
  avatar?: string;
}

interface UserStore {
  isLoggedIn: boolean;
  user: UserInfo | null;
  logout(): void;
}

const router = useRouter();

// 模拟状态管理
const authStore = {
  isLoggedIn: true,
  user: { id: 1, nickname: '张三', avatar: '/avatar.jpg' },
  logout: () => {},
};

const isLoggedIn = computed(() => authStore.isLoggedIn);
const userInfo = computed(() => authStore.user);

const handleUserCommand = (command: string) => {
  switch (command) {
    case 'profile':
      router.push('/user/profile');
      break;
    case 'logout':
      authStore.logout();
      router.push('/login');
      break;
  }
};
</script>

<style scoped lang="scss">
.header {
  background-color: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);

  .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
  }

  .user-info {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;

    .username {
      color: #666;
    }
  }

  .divider {
    margin: 0 8px;
    color: #ddd;
  }
}
</style>
```

### 2.3 商品列表组件修正

#### ❌ 修正前（Vue 2 + Element Plus）

```vue
<template>
  <div class="product-list">
    <div class="container">
      <div class="list-layout">
        <aside class="list-sidebar">
          <el-slider
            v-model="priceRange"
            range
            :min="0"
            :max="10000"
            :step="100"
          />
        </aside>
        <main class="list-main">
          <el-radio-group v-model="sortBy">
            <el-radio-button value="default">默认</el-radio-button>
            <el-radio-button value="price_asc">价格升序</el-radio-button>
          </el-radio-group>
          <el-table :data="products" v-loading="loading">
            <el-table-column prop="name" label="商品名称" />
            <el-table-column prop="price" label="价格" />
          </el-table>
          <el-pagination
            v-model:current-page="currentPage"
            :total="total"
          />
        </main>
      </div>
    </div>
  </div>
</template>

<script setup>
// Vue 2 语法，缺少类型定义
const loading = ref(false);
const products = ref([]);
const total = ref(0);
const currentPage = ref(1);
const priceRange = ref([0, 10000]);
const sortBy = ref('default');
</script>
```

#### ✅ 修正后（Vue 3 + Ant Design Vue）

```vue
<template>
  <div class="product-list">
    <div class="container">
      <div class="list-layout">
        <!-- 侧边栏筛选 -->
        <aside class="list-sidebar">
          <div class="filter-section">
            <h3>价格筛选</h3>
            <a-slider
              v-model:value="priceRange"
              range
              :min="0"
              :max="10000"
              :step="100"
              @change="handlePriceChange"
            />
            <div class="price-range-text">
              ¥{{ priceRange[0] }} - ¥{{ priceRange[1] }}
            </div>
          </div>
        </aside>

        <!-- 商品列表 -->
        <main class="list-main">
          <!-- 排序栏 -->
          <div class="list-toolbar">
            <div class="toolbar-left">
              共 <span class="highlight">{{ total }}</span> 件商品
            </div>
            <div class="toolbar-right">
              <a-radio-group v-model:value="sortBy" @change="handleSortChange">
                <a-radio-button value="default">默认</a-radio-button>
                <a-radio-button value="price_asc">价格升序</a-radio-button>
                <a-radio-button value="price_desc">价格降序</a-radio-button>
              </a-radio-group>
            </div>
          </div>

          <!-- 商品网格 -->
          <div v-loading="loading" class="product-grid">
            <ProductCard
              v-for="product in products"
              :key="product.id"
              :product="product"
            />
          </div>

          <!-- 分页 -->
          <div class="list-pagination">
            <a-pagination
              v-model:current="currentPage"
              v-model:page-size="pageSize"
              :total="total"
              :show-size-changer="true"
              :page-sizes="[20, 40, 60, 80]"
              show-quick-jumper
              show-total
            />
          </div>
        </main>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { TableColumnType } from 'ant-design-vue';

// 类型定义
interface Product {
  id: number;
  name: string;
  price: number;
  stock: number;
  image?: string;
}

interface Category {
  id: number;
  name: string;
}

interface ProductListParams {
  page?: number;
  page_size?: number;
  category_id?: number;
  min_price?: number;
  max_price?: number;
  sort_by?: string;
  keyword?: string;
}

// 模拟 API
const getProductList = (params: ProductListParams) => {
  return Promise.resolve({
    list: [],
    total: 0,
  });
};

// 模拟组件
const ProductCard = {
  props: { product: Object as () => Product },
  template: '<div>Product: {{ product.name }}</div>',
};

const route = useRoute();
const router = useRouter();

// 响应式数据
const loading = ref(false);
const products = ref<Product[]>([]);
const categories = ref<Category[]>([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(20);
const selectedCategory = ref<number | undefined>();
const priceRange = ref<[number, number]>([0, 10000]);
const sortBy = ref('default');

// 加载数据
const loadProducts = async () => {
  loading.value = true;
  try {
    const result = await getProductList({
      page: currentPage.value,
      page_size: pageSize.value,
      category_id: selectedCategory.value,
      min_price: priceRange.value[0],
      max_price: priceRange.value[1],
      sort_by: sortBy.value,
      keyword: route.query.keyword as string,
    });

    products.value = result.list;
    total.value = result.total;
  } catch (error) {
    console.error('加载商品失败', error);
  } finally {
    loading.value = false;
  }
};

// 事件处理
const handlePriceChange = () => {
  currentPage.value = 1;
  loadProducts();
};

const handleSortChange = () => {
  currentPage.value = 1;
  loadProducts();
};

// 监听路由变化
watch(
  () => route.query,
  () => {
    loadProducts();
  }
);

// 生命周期
onMounted(() => {
  loadProducts();
});
</script>

<style scoped lang="scss">
.product-list {
  .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
  }

  .list-layout {
    display: flex;
    gap: 20px;
  }

  .list-sidebar {
    width: 200px;
    flex-shrink: 0;

    .filter-section {
      margin-bottom: 30px;

      h3 {
        margin-bottom: 15px;
        font-size: 16px;
        font-weight: 600;
      }

      .price-range-text {
        margin-top: 10px;
        text-align: center;
        color: #666;
        font-size: 14px;
      }
    }
  }

  .list-main {
    flex: 1;

    .list-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding: 15px;
      background-color: #fff;
      border-radius: 4px;

      .highlight {
        color: #1890ff;
        font-weight: 600;
      }
    }

    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .list-pagination {
      display: flex;
      justify-content: center;
      padding: 20px 0;
    }
  }
}
</style>
```

---

## 3. TypeScript 类型定义完善

### 3.1 组件 Props 类型定义

#### ✅ 正确：完整的类型定义

```typescript
// 1. 接口定义
interface BaseProps {
  /** 唯一标识 */
  id: number;
  /** 显示名称 */
  name: string;
  /** 描述信息 */
  description?: string;
  /** 状态 */
  status: 'active' | 'inactive' | 'pending';
  /** 创建时间 */
  createdAt: Date;
  /** 更新时间 */
  updatedAt?: Date;
}

// 2. 组件 Props 类型
interface ComponentProps {
  /** 基础数据 */
  data: BaseProps;
  /** 是否可编辑 */
  editable?: boolean;
  /** 是否加载中 */
  loading?: boolean;
  /** 点击事件回调 */
  onEdit?: (id: number) => void;
  /** 删除事件回调 */
  onDelete?: (id: number) => void;
}

// 3. 使用泛型
interface TableColumnType<T = any> {
  title: string;
  dataIndex: keyof T | string;
  key: string;
  width?: number;
  align?: 'left' | 'center' | 'right';
  customRender?: (args: {
    text: any;
    record: T;
    index: number;
  }) => any;
}
```

### 3.2 事件类型定义

#### ✅ 正确：完整的 emit 类型定义

```vue
<template>
  <a-modal
    v-model:open="isOpen"
    title="编辑"
    @ok="handleOk"
    @cancel="handleCancel"
  >
    <p>内容</p>
  </a-modal>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import type { MouseEvent } from 'vue';

interface Emits {
  (e: 'update:open', open: boolean): void;
  (e: 'save', data: BaseProps): void;
  (e: 'cancel'): void;
  (e: 'error', error: Error): void;
}

// Props 类型
interface Props {
  open: boolean;
  data?: BaseProps;
}

const props = withDefaults(defineProps<Props>(), {
  open: false,
});

const emit = defineEmits<Emits>();

const isOpen = ref(props.open);

// 监听 props 变化
watch(
  () => props.open,
  (newVal) => {
    isOpen.value = newVal;
  }
);

// 事件处理函数
const handleOk = (event: MouseEvent) => {
  emit('save', {
    id: props.data?.id || 0,
    name: props.data?.name || '',
    status: 'active',
    createdAt: new Date(),
  });
};

const handleCancel = (event: MouseEvent) => {
  emit('cancel');
};

// 同步状态
watch(isOpen, (newVal) => {
  emit('update:open', newVal);
});
</script>
```

### 3.3 Provide/Inject 类型定义

#### ✅ 正确：类型安全注入

```typescript
// 1. 定义注入键
import type { InjectionKey } from 'vue';

// 全局配置类型
interface GlobalConfig {
  apiBase: string;
  version: string;
  debug: boolean;
  theme: 'light' | 'dark';
}

const GlobalConfigKey = Symbol() as InjectionKey<GlobalConfig>;

// 用户上下文类型
interface UserContext {
  id: number;
  name: string;
  permissions: string[];
  tenantId?: number;
}

const UserContextKey = Symbol() as InjectionKey<UserContext>;

// 2. 父组件提供
import { provide, reactive } from 'vue';

// 提供全局配置
provide(GlobalConfigKey, {
  apiBase: 'https://api.example.com',
  version: '1.0.0',
  debug: true,
  theme: 'light',
});

// 提供用户上下文
provide(UserContextKey, reactive({
  id: 1,
  name: '张三',
  permissions: ['read', 'write'],
  tenantId: 100,
}));

// 3. 子组件注入
import { inject } from 'vue';

const config = inject(GlobalConfigKey);
if (!config) {
  throw new Error('GlobalConfig not provided');
}

const user = inject(UserContextKey);
if (!user) {
  throw new Error('UserContext not provided');
}

// 使用配置
console.log('API Base:', config.apiBase);
console.log('User:', user.name);
```

### 3.4 Store 类型定义

#### ✅ 正确：Pinia Store 完整类型化

```typescript
// stores/user.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useUserStore = defineStore('user', () => {
  // 状态类型
  interface UserState {
    currentUser: User | null;
    users: User[];
    loading: boolean;
    error: string | null;
  }

  // 状态
  const currentUser = ref<User | null>(null);
  const users = ref<User[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  // 计算属性
  const isLoggedIn = computed(() => currentUser.value !== null);
  const userCount = computed(() => users.value.length);
  const hasPermission = computed(() => (permission: string) => {
    return currentUser.value?.permissions.includes(permission) || false;
  });

  // 动作类型
  interface UserActions {
    login(credentials: LoginCredentials): Promise<User>;
    logout(): void;
    register(userData: RegisterData): Promise<User>;
    updateProfile(data: Partial<User>): Promise<User>;
    fetchUsers(): Promise<void>;
    deleteUser(id: number): Promise<void>;
  }

  // 动作
  const login = async (credentials: LoginCredentials): Promise<User> => {
    loading.value = true;
    error.value = null;
    try {
      const user = await api.login(credentials);
      currentUser.value = user;
      return user;
    } catch (err) {
      error.value = (err as Error).message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const logout = () => {
    currentUser.value = null;
    users.value = [];
  };

  const register = async (userData: RegisterData): Promise<User> => {
    loading.value = true;
    error.value = null;
    try {
      const user = await api.register(userData);
      return user;
    } catch (err) {
      error.value = (err as Error).message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const updateProfile = async (data: Partial<User>): Promise<User> => {
    if (!currentUser.value) {
      throw new Error('No user logged in');
    }

    loading.value = true;
    error.value = null;
    try {
      const updatedUser = await api.updateUser(currentUser.value.id, data);
      currentUser.value = updatedUser;
      return updatedUser;
    } catch (err) {
      error.value = (err as Error).message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const fetchUsers = async (): Promise<void> => {
    loading.value = true;
    error.value = null;
    try {
      users.value = await api.getUsers();
    } catch (err) {
      error.value = (err as Error).message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const deleteUser = async (id: number): Promise<void> => {
    loading.value = true;
    error.value = null;
    try {
      await api.deleteUser(id);
      users.value = users.value.filter(u => u.id !== id);
    } catch (err) {
      error.value = (err as Error).message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    currentUser,
    users,
    loading,
    error,
    isLoggedIn,
    userCount,
    hasPermission,
    login,
    logout,
    register,
    updateProfile,
    fetchUsers,
    deleteUser,
  };
});

// 类型定义
interface User {
  id: number;
  name: string;
  email: string;
  avatar?: string;
  permissions: string[];
  tenantId?: number;
  createdAt: Date;
  updatedAt?: Date;
}

interface LoginCredentials {
  email: string;
  password: string;
  tenantId?: number;
}

interface RegisterData {
  name: string;
  email: string;
  password: string;
  tenantId?: number;
}

// API 类型
interface UserApi {
  login(credentials: LoginCredentials): Promise<User>;
  register(data: RegisterData): Promise<User>;
  updateUser(id: number, data: Partial<User>): Promise<User>;
  getUsers(): Promise<User[]>;
  deleteUser(id: number): Promise<void>;
}

const api: UserApi = {
  login: async (credentials) => ({ id: 1, name: 'Test', email: 'test@example.com', permissions: [], createdAt: new Date() }),
  register: async (data) => ({ id: 1, name: data.name, email: data.email, permissions: [], createdAt: new Date() }),
  updateUser: async (id, data) => ({ id, ...data, permissions: [], createdAt: new Date() }),
  getUsers: async () => [],
  deleteUser: async (id) => {},
};
```

---

## 4. API 优化

### 4.1 请求封装类型安全

```typescript
// utils/request.ts
import axios, {
  AxiosInstance,
  AxiosRequestConfig,
  AxiosResponse,
  AxiosError,
} from 'axios';
import type { Ref } from 'vue';

// 响应类型
interface ApiResponse<T = any> {
  code: number;
  message: string;
  data: T;
  success: boolean;
  timestamp: number;
}

// 错误类型
interface ApiError {
  code: number;
  message: string;
  details?: any;
}

// 请求配置类型
interface RequestConfig extends AxiosRequestConfig {
  /** 显示加载状态 */
  showLoading?: boolean;
  /** 是否显示错误提示 */
  showError?: boolean;
  /** 重试次数 */
  retry?: number;
}

// 请求类
class Request {
  private instance: AxiosInstance;

  constructor(baseURL: string) {
    this.instance = axios.create({
      baseURL,
      timeout: 10000,
      headers: {
        'Content-Type': 'application/json',
      },
    });

    this.setupInterceptors();
  }

  private setupInterceptors() {
    // 请求拦截器
    this.instance.interceptors.request.use(
      (config) => {
        // 添加 token
        const token = localStorage.getItem('token');
        if (token) {
          config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
      },
      (error) => {
        return Promise.reject(error);
      }
    );

    // 响应拦截器
    this.instance.interceptors.response.use(
      (response) => {
        return response;
      },
      async (error: AxiosError) => {
        const originalRequest = error.config as RequestConfig & {
          _retry?: boolean;
        };

        // 401 处理
        if (error.response?.status === 401 && !originalRequest._retry) {
          originalRequest._retry = true;
          // 刷新 token
          try {
            await this.refreshToken();
            return this.instance(originalRequest);
          } catch (refreshError) {
            // 跳转到登录页
            window.location.href = '/login';
            return Promise.reject(refreshError);
          }
        }

        // 其他错误处理
        const apiError: ApiError = {
          code: error.response?.status || 500,
          message: error.message || '请求失败',
        };

        return Promise.reject(apiError);
      }
    );
  }

  private async refreshToken() {
    const refreshToken = localStorage.getItem('refreshToken');
    if (!refreshToken) {
      throw new Error('No refresh token');
    }
    // 实现刷新 token 的逻辑
  }

  // GET 请求
  async get<T = any>(
    url: string,
    config?: RequestConfig
  ): Promise<T> {
    const response: AxiosResponse<ApiResponse<T>> = await this.instance.get(
      url,
      config
    );
    return this.handleResponse(response);
  }

  // POST 请求
  async post<T = any>(
    url: string,
    data?: any,
    config?: RequestConfig
  ): Promise<T> {
    const response: AxiosResponse<ApiResponse<T>> = await this.instance.post(
      url,
      data,
      config
    );
    return this.handleResponse(response);
  }

  // PUT 请求
  async put<T = any>(
    url: string,
    data?: any,
    config?: RequestConfig
  ): Promise<T> {
    const response: AxiosResponse<ApiResponse<T>> = await this.instance.put(
      url,
      data,
      config
    );
    return this.handleResponse(response);
  }

  // DELETE 请求
  async delete<T = any>(
    url: string,
    config?: RequestConfig
  ): Promise<T> {
    const response: AxiosResponse<ApiResponse<T>> = await this.instance.delete(
      url,
      config
    );
    return this.handleResponse(response);
  }

  private handleResponse<T>(response: AxiosResponse<ApiResponse<T>>): T {
    const { code, message, data } = response.data;

    if (code !== 200) {
      throw new Error(message || '请求失败');
    }

    return data;
  }
}

// 创建请求实例
export const request = new Request('/api');

// 工具函数：处理错误
export const handleApiError = (error: any): ApiError => {
  if (error.code) {
    return error as ApiError;
  }
  return {
    code: 500,
    message: error.message || '未知错误',
  };
};
```

### 4.2 API 接口定义

```typescript
// api/user.ts
import { request } from '@/utils/request';
import type { User, LoginCredentials, RegisterData } from '@/types/user';

// 获取当前用户
export const getCurrentUser = (): Promise<User> => {
  return request.get('/user/current');
};

// 登录
export const login = (credentials: LoginCredentials): Promise<{
  token: string;
  refreshToken: string;
  user: User;
}> => {
  return request.post('/auth/login', credentials);
};

// 注册
export const register = (data: RegisterData): Promise<User> => {
  return request.post('/auth/register', data);
};

// 获取用户列表
export const getUserList = (params: {
  page?: number;
  pageSize?: number;
  keyword?: string;
}): Promise<{
  list: User[];
  total: number;
}> => {
  return request.get('/users', { params });
};

// 更新用户
export const updateUser = (id: number, data: Partial<User>): Promise<User> => {
  return request.put(`/users/${id}`, data);
};

// 删除用户
export const deleteUser = (id: number): Promise<void> => {
  return request.delete(`/users/${id}`);
};
```

---

## 5. 响应式数据处理

### 5.1 正确使用 ref 和 reactive

```typescript
import { ref, reactive, computed, watch, watchEffect } from 'vue';

// ✅ 基础类型使用 ref
const count = ref(0);
const name = ref('Alkaid');
const isLoading = ref(false);

// ✅ 对象使用 reactive
const user = reactive({
  id: 1,
  name: '张三',
  email: 'zhangsan@example.com',
  profile: {
    avatar: '',
    bio: '',
  },
});

// ✅ 数组使用 reactive
const items = reactive([
  { id: 1, name: 'Item 1' },
  { id: 2, name: 'Item 2' },
]);

// ✅ 使用 toRefs 保持响应式
import { toRefs } from 'vue';

const state = reactive({
  user: {
    id: 1,
    name: '张三',
  },
  loading: false,
});

const { user: userRef, loading: loadingRef } = toRefs(state);

// ✅ 使用 readonly 保护只读数据
const originalCount = ref(0);
const readonlyCount = readonly(originalCount);

// ❌ 错误：对象使用 ref
// const user = ref({ id: 1, name: '张三' });
// 应该使用 reactive
```

### 5.2 计算属性和侦听器

```typescript
// ✅ 计算属性
const firstName = ref('张');
const lastName = ref('三');

const fullName = computed(() => {
  return `${firstName.value} ${lastName.value}`;
});

// ✅ 带 setter 的计算属性
const fullNameComputed = computed({
  get() {
    return `${firstName.value} ${lastName.value}`;
  },
  set(value: string) {
    [firstName.value, lastName.value] = value.split(' ');
  },
});

// ✅ watch 侦听器
const count = ref(0);
const userId = ref<string | null>(null);

watch([count, userId], ([newCount, newUserId], [oldCount, oldUserId]) => {
  console.log('Count changed:', oldCount, '->', newCount);
  console.log('UserId changed:', oldUserId, '->', newUserId);
});

// ✅ watchEffect 立即执行
watchEffect(() => {
  console.log('User name is:', user.name);
  console.log('Count is:', count.value);
});

// ✅ 异步侦听器
watch(count, async (newVal, oldVal) => {
  if (newVal !== oldVal) {
    await fetchData(newVal);
  }
});
```

### 5.3 响应式依赖追踪

```typescript
// ✅ 在组件中使用
import { onMounted, onUnmounted } from 'vue';

export default {
  setup() {
    const windowWidth = ref(window.innerWidth);
    const windowHeight = ref(window.innerHeight);

    const handleResize = () => {
      windowWidth.value = window.innerWidth;
      windowHeight.value = window.innerHeight;
    };

    onMounted(() => {
      window.addEventListener('resize', handleResize);
    });

    onUnmounted(() => {
      window.removeEventListener('resize', handleResize);
    });

    // ✅ 使用 computed 优化性能
    const isMobile = computed(() => {
      return windowWidth.value < 768;
    });

    return {
      windowWidth,
      windowHeight,
      isMobile,
    };
  },
};
```

---

## 📝 代码示例检查清单

### 类型定义检查

- [ ] 所有组件 Props 都有完整的类型定义
- [ ] 所有 Emits 事件都有正确的类型约束
- [ ] 所有响应式数据都有正确的类型声明
- [ ] 所有工具函数都有明确的返回类型
- [ ] 所有 API 接口都有完整的类型定义

### 代码质量检查

- [ ] 使用 Vue 3 Composition API
- [ ] 使用 Ant Design Vue 4.x 组件
- [ ] 使用 TypeScript 严格模式
- [ ] 正确使用 ref 和 reactive
- [ ] 适当使用 computed 和 watch
- [ ] 所有事件处理函数都有正确的事件类型

### 最佳实践检查

- [ ] 使用可选链操作符 (`?.`)
- [ ] 使用空值合并操作符 (`??`)
- [ ] 使用 toRefs 保持对象响应性
- [ ] 使用 readonly 保护只读数据
- [ ] 使用 withDefaults 提供默认值
- [ ] 使用 defineProps 和 defineEmits

---

## 🔗 相关文档

### 技术指南
- [技术栈统一指南](tech-stack-unification-guide.md)
- [最佳实践指南](best-practices-guide.md)
- [文档结构优化指南](documentation-structure-optimization.md)

### 架构文档
- [架构细节深化指南](architecture-deepening-guide.md)
- [设计文档分析报告](alkaid-system-design-analysis-report.md)
- [优化实施总结报告](optimization-summary-report.md)

### 官方文档
- [Vue 3 官方文档](https://vuejs.org/)
- [Ant Design Vue 官方文档](https://antdv.com/)
- [TypeScript 官方文档](https://www.typescriptlang.org/)

---

**最后更新**：2025-11-01
**文档版本**：v1.0
**维护者**：AlkaidSYS 架构团队
