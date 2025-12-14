# AlkaidSYS 技术栈统一指南

> **文档版本**：v1.0
> **创建日期**：2025-11-01
> **最后更新**：2025-11-01
> **维护者**：架构团队

---

## 📋 目录

- [1. 技术栈概述](#1-技术栈概述)
- [2. 统一配置](#2-统一配置)
- [3. TypeScript 配置](#3-typescript-配置)
- [4. UI 框架统一](#4-ui-框架统一)
- [5. 迁移指南](#5-迁移指南)
- [6. 最佳实践](#6-最佳实践)

---

## 1. 技术栈概述

### 1.1 核心技术栈

| 层级 | 技术 | 版本 | 说明 |
|------|------|------|------|
| **前端框架** | Vue.js | `^3.5.17` | 统一使用 Vue 3 组合式 API |
| **构建工具** | Vite | `^7.1.2` | 快速构建和热更新 |
| **UI 框架** | Ant Design Vue | `^4.2.6` | 统一的企业级 UI 组件库 |
| **状态管理** | Pinia | `^3.0.3` | Vue 3 官方推荐状态管理 |
| **路由** | Vue Router | `^4.5.0` | Vue 3 官方路由 |
| **类型系统** | TypeScript | `^5.8.3` | 强制类型检查 |
| **开发语言** | PHP | `^8.0` | 后端服务层 |

### 1.2 版本锁定策略

```json
{
  "dependencies": {
    "vue": "3.5.17",
    "vite": "7.1.2",
    "ant-design-vue": "4.2.6",
    "pinia": "3.0.3",
    "vue-router": "4.5.0",
    "typescript": "5.8.3",
    "vue-tsc": "2.2.0"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "5.2.1",
    "@vitejs/plugin-vue-jsx": "4.1.1",
    "sass": "1.83.4",
    "autoprefixer": "10.4.20",
    "postcss": "8.4.49"
  }
}
```

---

## 2. 统一配置

### 2.1 package.json 配置模板

```json
{
  "name": "@alkaid/web",
  "version": "1.0.0",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc && vite build",
    "build:types": "vue-tsc --noEmit",
    "preview": "vite preview",
    "lint": "eslint . --ext .vue,.js,.jsx,.cjs,.mjs,.ts,.tsx,.cts,.mts --fix",
    "type-check": "vue-tsc --noEmit"
  },
  "dependencies": {
    "vue": "3.5.17",
    "vue-router": "4.5.0",
    "pinia": "3.0.3",
    "ant-design-vue": "4.2.6",
    "@ant-design/icons-vue": "7.0.1",
    "axios": "1.7.9",
    "@vueuse/core": "11.4.0",
    "dayjs": "1.11.13",
    "lodash-es": "4.17.21",
    "swiper": "11.1.15",
    "vue3-lazyload": "0.3.8"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "5.2.1",
    "@vitejs/plugin-vue-jsx": "4.1.1",
    "typescript": "5.8.3",
    "vite": "7.1.2",
    "vite-plugin-compression": "0.5.1",
    "vite-plugin-imagemin": "0.6.1",
    "vue-tsc": "2.2.0",
    "sass": "1.83.4",
    "autoprefixer": "10.4.20",
    "postcss": "8.4.49"
  }
}
```

### 2.2 Vite 配置

```typescript
// vite.config.ts
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  build: {
    target: 'esnext',
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor': ['vue', 'vue-router', 'pinia'],
          'antd': ['ant-design-vue', '@ant-design/icons-vue'],
        },
      },
    },
  },
  server: {
    port: 3000,
    host: '0.0.0.0',
  },
});
```

---

## 3. TypeScript 配置

### 3.1 tsconfig.json 严格模式配置

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "useDefineForClassFields": true,
    "lib": ["ES2022", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "allowSyntheticDefaultImports": true,
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "strictFunctionTypes": true,
    "strictBindCallApply": true,
    "strictPropertyInitialization": true,
    "noImplicitThis": true,
    "alwaysStrict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "exactOptionalPropertyTypes": true,
    "noImplicitReturns": true,
    "noFallthroughCasesInSwitch": true,
    "noUncheckedIndexedAccess": true,
    "noImplicitOverride": true,
    "noPropertyAccessFromIndexSignature": false,
    "moduleDetection": "force",
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "preserve",
    "allowJs": false,
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true,
    "baseUrl": ".",
    "paths": {
      "@/*": ["src/*"]
    },
    "types": ["vite/client", "vue/ref-macros"]
  },
  "include": [
    "src/**/*.ts",
    "src/**/*.d.ts",
    "src/**/*.tsx",
    "src/**/*.vue"
  ],
  "exclude": ["node_modules", "dist"]
}
```

### 3.2 Vue 类型声明

```typescript
// src/env.d.ts
/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}

// Vue 组件示例类型
interface ComponentExampleProps {
  title: string;
  count?: number;
}

interface ComponentExampleEmits {
  (e: 'update:count', value: number): void;
  (e: 'change', value: string): void;
}

// 全局组件类型
declare module 'vue' {
  export interface GlobalComponents {
    RouterLink: typeof import('vue-router').RouterLink;
    RouterView: typeof import('vue-router').RouterView;
  }
}
```

---

## 4. UI 框架统一

### 4.1 Ant Design Vue 4.x 正确用法

#### ✅ 正确：使用 options 属性（v3+）

```vue
<!-- 正确：使用 options -->
<template>
  <a-select
    v-model:value="selectedValue"
    :options="selectOptions"
    placeholder="请选择"
  />
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';

const selectOptions = computed(() => [
  { value: 'option1', label: '选项一' },
  { value: 'option2', label: '选项二' },
  { value: 'option3', label: '选项三' },
]);
</script>
```

#### ❌ 错误：使用 dataSource（已废弃）

```vue
<!-- 错误：使用 dataSource（v2 语法） -->
<template>
  <a-select
    v-model:value="selectedValue"
    :dataSource="selectOptions"
    placeholder="请选择"
  />
</template>
```

#### ✅ 正确：Modal 使用 open 属性（v3+）

```vue
<template>
  <a-modal
    v-model:open="isModalOpen"
    title="标题"
    @ok="handleOk"
  >
    <p>内容</p>
  </a-modal>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const isModalOpen = ref(false);
</script>
```

### 4.2 Form 组件最佳实践

#### ✅ 正确：使用 useForm Hook

```vue
<template>
  <a-form :model="formState" :rules="rules" ref="formRef">
    <a-form-item name="username" label="用户名">
      <a-input v-model:value="formState.username" />
    </a-form-item>
    <a-form-item name="email" label="邮箱">
      <a-input v-model:value="formState.email" />
    </a-form-item>
  </a-form>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue';
import type { FormInstance, FormRules } from 'ant-design-vue';

interface FormState {
  username: string;
  email: string;
}

const formRef = ref<FormInstance>();
const formState = reactive<FormState>({
  username: '',
  email: '',
});

const rules: FormRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 20, message: '用户名长度为3-20个字符', trigger: 'blur' },
  ],
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
  ],
};

const handleSubmit = async () => {
  try {
    await formRef.value?.validateFields();
    console.log('表单提交成功', formState);
  } catch (error) {
    console.log('表单验证失败', error);
  }
};
</script>
```

### 4.3 Table 组件最佳实践

```vue
<template>
  <a-table
    :data-source="tableData"
    :columns="columns"
    :row-key="record => record.id"
    :pagination="pagination"
    :loading="loading"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'action'">
        <a-space>
          <a-button type="link" @click="handleEdit(record)">
            编辑
          </a-button>
          <a-button type="link" danger @click="handleDelete(record)">
            删除
          </a-button>
        </a-space>
      </template>
    </template>
  </a-table>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue';
import type { TableColumnType } from 'ant-design-vue';

interface TableRecord {
  id: number;
  name: string;
  email: string;
  status: number;
}

const loading = ref(false);
const tableData = ref<TableRecord[]>([]);

const columns: TableColumnType[] = [
  {
    title: 'ID',
    dataIndex: 'id',
    key: 'id',
  },
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
  {
    title: '状态',
    dataIndex: 'status',
    key: 'status',
  },
  {
    title: '操作',
    key: 'action',
  },
];

const pagination = reactive({
  current: 1,
  pageSize: 20,
  total: 0,
  showSizeChanger: true,
  showQuickJumper: true,
  showTotal: (total: number, range: [number, number]) =>
    `显示 ${range[0]}-${range[1]} 条，共 ${total} 条`,
});

const handleEdit = (record: TableRecord) => {
  console.log('编辑', record);
};

const handleDelete = (record: TableRecord) => {
  console.log('删除', record);
};
</script>
```

---

## 5. 迁移指南

### 5.1 Element Plus → Ant Design Vue 迁移

| Element Plus | Ant Design Vue | 说明 |
|-------------|----------------|------|
| `el-input` | `a-input` | 输入框 |
| `el-button` | `a-button` | 按钮 |
| `el-table` | `a-table` | 表格 |
| `el-form` | `a-form` | 表单 |
| `el-select` | `a-select` | 选择器 |
| `el-dialog` | `a-modal` | 对话框 |
| `v-model:visible` | `v-model:open` | 控制可见性 |
| `data-source` | `options` | 数据源 |

### 5.2 Vue 3 组合式 API 迁移

#### ✅ 正确：使用 Composition API

```vue
<template>
  <div>{{ count }}</div>
  <button @click="increment">+1</button>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';

// 响应式数据
const count = ref(0);

// 计算属性
const doubleCount = computed(() => count.value * 2);

// 方法
const increment = () => {
  count.value++;
};

// 生命周期
onMounted(() => {
  console.log('组件已挂载');
});

onUnmounted(() => {
  console.log('组件已卸载');
});
</script>
```

#### ❌ 错误：使用 Options API

```vue
<template>
  <div>{{ count }}</div>
  <button @click="increment">+1</button>
</template>

<script>
export default {
  data() {
    return {
      count: 0,
    };
  },
  methods: {
    increment() {
      this.count++;
    },
  },
};
</script>
```

---

## 6. 最佳实践

### 6.1 类型安全

#### ✅ 严格类型定义

```typescript
// 1. 接口定义
interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
}

// 2. 组件 Props 类型
interface Props {
  user: User;
  editable?: boolean;
}

// 3. 组件 Emits 类型
interface Emits {
  (e: 'update:user', user: User): void;
  (e: 'delete', id: number): void;
}

// 4. 使用泛型约束
function createUser<T extends User>(userData: T): Promise<T> {
  return Promise.resolve(userData);
}
```

### 6.2 响应式数据

#### ✅ 正确使用 ref 和 reactive

```typescript
import { ref, reactive, computed, toRefs } from 'vue';

// 基础类型使用 ref
const count = ref(0);
const name = ref('Alkaid');

// 对象类型使用 reactive
const state = reactive({
  user: {
    id: 1,
    name: 'John',
    email: 'john@example.com',
  },
  loading: false,
});

// 解构使用 toRefs（保持响应式）
const { user, loading } = toRefs(state);

// 计算属性
const displayName = computed(() => user.value.name || 'Anonymous');

// 只读引用
const readonlyCount = readonly(count);
```

### 6.3 事件处理

#### ✅ TypeScript 类型安全

```vue
<template>
  <input
    type="text"
    :value="inputValue"
    @input="handleInput($event)"
    @change="handleChange($event)"
  />
  <button @click="handleClick">点击</button>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const inputValue = ref('');

// 事件处理函数类型定义
const handleInput = (event: Event) => {
  const target = event.target as HTMLInputElement;
  inputValue.value = target.value;
};

const handleChange = (event: Event) => {
  const target = event.target as HTMLInputElement;
  console.log('Change:', target.value);
};

const handleClick = (event: MouseEvent) => {
  console.log('Click:', event);
};
</script>
```

### 6.4 Provide/Inject 最佳实践

#### ✅ 类型安全注入

```typescript
// 1. 定义注入键
import type { InjectionKey } from 'vue';

interface GlobalConfig {
  apiBase: string;
  version: string;
}

const GlobalConfigKey = Symbol() as InjectionKey<GlobalConfig>;

// 2. 父组件提供
import { provide } from 'vue';

provide(GlobalConfigKey, {
  apiBase: 'https://api.example.com',
  version: '1.0.0',
});

// 3. 子组件注入
import { inject } from 'vue';

const config = inject(GlobalConfigKey);
if (!config) {
  throw new Error('GlobalConfig not provided');
}

// 使用配置
console.log(config.apiBase);
```

### 6.5 全局状态管理

#### ✅ Pinia Store 类型安全

```typescript
// stores/user.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

interface User {
  id: number;
  name: string;
  email: string;
}

export const useUserStore = defineStore('user', () => {
  // 状态
  const currentUser = ref<User | null>(null);
  const users = ref<User[]>([]);

  // 计算属性
  const isLoggedIn = computed(() => currentUser.value !== null);
  const userCount = computed(() => users.value.length);

  // 动作
  const setUser = (user: User) => {
    currentUser.value = user;
  };

  const logout = () => {
    currentUser.value = null;
  };

  const addUser = (user: User) => {
    users.value.push(user);
  };

  return {
    currentUser,
    users,
    isLoggedIn,
    userCount,
    setUser,
    logout,
    addUser,
  };
});
```

### 6.6 API 错误处理

```typescript
// utils/request.ts
import type { AxiosError, AxiosResponse } from 'axios';

interface ApiResponse<T = any> {
  code: number;
  message: string;
  data: T;
}

export const request = async <T>(config: AxiosRequestConfig): Promise<T> => {
  try {
    const response: AxiosResponse<ApiResponse<T>> = await axios(config);
    if (response.data.code !== 200) {
      throw new Error(response.data.message || '请求失败');
    }
    return response.data.data;
  } catch (error) {
    const err = error as AxiosError;
    if (err.response) {
      // 服务器响应错误
      console.error('服务器错误:', err.response.status, err.response.data);
    } else if (err.request) {
      // 网络错误
      console.error('网络错误:', err.message);
    } else {
      // 其他错误
      console.error('请求错误:', err.message);
    }
    throw error;
  }
};
```

---

## 📝 检查清单

### 技术栈统一检查

- [ ] 所有项目使用统一的 Vue 版本（3.5.17）
- [ ] 所有项目使用统一的 Ant Design Vue 版本（4.2.6）
- [ ] 启用 TypeScript 严格模式
- [ ] 更新所有示例代码到正确的 API
- [ ] 移除 Element Plus 相关代码
- [ ] 统一使用 Composition API

### 代码质量检查

- [ ] 所有组件都有完整的类型定义
- [ ] 所有事件处理函数都有正确的事件类型
- [ ] 所有 props 都有类型约束
- [ ] 使用 ref 和 reactive 正确声明响应式数据
- [ ] Provide/Inject 使用类型安全
- [ ] Pinia Store 完整类型化

### 最佳实践检查

- [ ] 使用可选链操作符 (`?.`)
- [ ] 使用空值合并操作符 (`??`)
- [ ] 使用 `toRefs` 保持对象响应性
- [ ] 使用 `readonly` 保护只读数据
- [ ] 使用 `onMounted` 和 `onUnmounted` 正确管理生命周期
- [ ] 使用 `computed` 缓存计算结果

---

## 🔗 相关文档

### 官方文档
- [Vue 3 官方文档](https://vuejs.org/)
- [Ant Design Vue 官方文档](https://antdv.com/)
- [TypeScript 官方文档](https://www.typescriptlang.org/)
- [Pinia 文档](https://pinia.vuejs.org/)
- [Vite 文档](https://vitejs.dev/)

### 内部文档
- [代码示例更新指南](code-examples-updated.md)
- [文档结构优化指南](documentation-structure-optimization.md)
- [最佳实践指南](best-practices-guide.md)
- [架构细节深化指南](architecture-deepening-guide.md)
- [设计文档分析报告](alkaid-system-design-analysis-report.md)

---

**最后更新**：2025-11-01
**文档版本**：v1.0
**维护者**：AlkaidSYS 架构团队
