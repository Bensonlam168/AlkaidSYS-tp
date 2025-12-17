# AlkaidSYS 前端架构

## 前端技术栈
AlkaidSYS 前端基于 **Vue Vben Admin 5.x** 构建，采用现代化的前端技术栈。

### 核心技术
- **框架**: Vue 3 (Composition API)
- **构建工具**: Vite
- **包管理器**: pnpm
- **语言**: TypeScript
- **状态管理**: Pinia
- **路由**: Vue Router 4
- **HTTP客户端**: Axios

### UI组件库支持
AlkaidSYS 支持多种UI组件库，开发者可以根据需求选择：

1. **Ant Design Vue** (`frontend/apps/web-antd`)
   - 企业级UI设计语言
   - 丰富的组件库
   - 完善的文档

2. **Element Plus** (`frontend/apps/web-ele`)
   - 基于Vue 3的组件库
   - 简洁易用
   - 中文友好

3. **Naive UI** (`frontend/apps/web-naive`)
   - 现代化设计
   - TypeScript友好
   - 性能优秀

4. **TDesign** (`frontend/apps/web-tdesign`)
   - 腾讯开源
   - 企业级设计
   - 多端适配

## 项目结构

### Monorepo架构
前端项目采用 Monorepo 架构，使用 pnpm workspace 管理：

```
frontend/
├── apps/                    # 应用目录
│   ├── web-antd/           # Ant Design版本
│   ├── web-ele/            # Element Plus版本
│   ├── web-naive/          # Naive UI版本
│   ├── web-tdesign/        # TDesign版本
│   └── backend-mock/       # Mock服务
├── packages/               # 公共包
│   ├── @core/             # 核心功能包
│   ├── @shared/           # 共享工具包
│   └── @types/            # 类型定义包
├── internal/              # 内部工具
├── docs/                  # 文档
├── scripts/               # 脚本
├── package.json           # 根配置
├── pnpm-workspace.yaml    # workspace配置
└── turbo.json            # Turbo配置
```

### 应用结构（以web-antd为例）
```
web-antd/
├── src/
│   ├── api/              # API接口
│   ├── assets/           # 静态资源
│   ├── components/       # 组件
│   ├── layouts/          # 布局
│   ├── router/           # 路由
│   ├── store/            # 状态管理
│   ├── styles/           # 样式
│   ├── utils/            # 工具函数
│   ├── views/            # 页面视图
│   ├── App.vue           # 根组件
│   └── main.ts           # 入口文件
├── public/               # 公共资源
├── index.html            # HTML模板
├── vite.config.ts        # Vite配置
└── package.json          # 应用配置
```

## 核心功能模块

### 1. 低代码表单设计器
**位置**: `src/views/lowcode/form-designer/`

**功能**:
- 可视化表单设计
- 拖拽式组件配置
- 实时预览
- 表单模板管理
- 表单发布

**组件**:
- `FormDesigner.vue` - 设计器主组件
- `ComponentPanel.vue` - 组件面板
- `PropertyPanel.vue` - 属性面板
- `PreviewPanel.vue` - 预览面板

### 2. 数据集合管理
**位置**: `src/views/lowcode/collection/`

**功能**:
- 集合列表
- 集合创建/编辑
- 字段管理
- 关系配置
- 数据预览

**页面**:
- `CollectionList.vue` - 集合列表
- `CollectionForm.vue` - 集合表单
- `FieldManager.vue` - 字段管理器
- `RelationshipConfig.vue` - 关系配置

### 3. 字段类型组件
**位置**: `src/components/lowcode/fields/`

**字段组件**:
- `StringField.vue` - 字符串输入
- `NumberField.vue` - 数字输入
- `DateField.vue` - 日期选择
- `SelectField.vue` - 下拉选择
- `RadioField.vue` - 单选框
- `CheckboxField.vue` - 复选框
- `TextareaField.vue` - 文本域
- `FileUpload.vue` - 文件上传
- `ImageUpload.vue` - 图片上传
- `RichEditor.vue` - 富文本编辑器

### 4. 用户与权限管理
**位置**: `src/views/system/`

**功能模块**:
- 用户管理 (`user/`)
- 角色管理 (`role/`)
- 权限管理 (`permission/`)
- 菜单管理 (`menu/`)

### 5. 租户管理
**位置**: `src/views/tenant/`

**功能**:
- 租户列表
- 租户创建/编辑
- 租户配置
- 租户切换

## API集成

### API模块组织
**位置**: `src/api/`

```
api/
├── auth.ts              # 认证相关
├── user.ts              # 用户管理
├── tenant.ts            # 租户管理
├── lowcode/
│   ├── collection.ts    # 集合管理
│   ├── field.ts         # 字段管理
│   ├── relationship.ts  # 关系管理
│   └── form.ts          # 表单管理
└── system/
    ├── role.ts          # 角色管理
    └── permission.ts    # 权限管理
```

### API请求封装
**文件**: `src/utils/request.ts`

**功能**:
- 统一请求配置
- 请求/响应拦截
- 错误处理
- Token自动注入
- 租户ID注入

**示例**:
```typescript
import { request } from '@/utils/request';

export const getCollectionList = (params) => {
  return request({
    url: '/api/lowcode/collection',
    method: 'GET',
    params
  });
};
```

## 状态管理

### Store模块
**位置**: `src/store/`

**核心Store**:
- `user.ts` - 用户状态
- `tenant.ts` - 租户状态
- `permission.ts` - 权限状态
- `app.ts` - 应用状态
- `lowcode.ts` - 低代码状态

**示例**:
```typescript
// store/lowcode.ts
import { defineStore } from 'pinia';

export const useLowcodeStore = defineStore('lowcode', {
  state: () => ({
    collections: [],
    currentCollection: null,
    fields: []
  }),
  
  actions: {
    async fetchCollections() {
      // 获取集合列表
    },
    
    async createCollection(data) {
      // 创建集合
    }
  }
});
```

## 路由配置

### 路由结构
**文件**: `src/router/routes.ts`

**路由模块**:
```typescript
const routes = [
  {
    path: '/lowcode',
    name: 'Lowcode',
    component: Layout,
    meta: { title: '低代码', icon: 'code' },
    children: [
      {
        path: 'collection',
        name: 'Collection',
        component: () => import('@/views/lowcode/collection/index.vue'),
        meta: { title: '数据集合', permission: 'lowcode:collection:view' }
      },
      {
        path: 'form-designer',
        name: 'FormDesigner',
        component: () => import('@/views/lowcode/form-designer/index.vue'),
        meta: { title: '表单设计', permission: 'lowcode:form:design' }
      }
    ]
  }
];
```

### 路由守卫
**文件**: `src/router/guard.ts`

**功能**:
- 登录验证
- 权限检查
- 租户验证
- 页面标题设置
- 进度条控制

## 工具函数

### 常用工具
**位置**: `src/utils/`

**工具模块**:
- `request.ts` - HTTP请求
- `auth.ts` - 认证工具
- `storage.ts` - 本地存储
- `validate.ts` - 表单验证
- `format.ts` - 数据格式化
- `permission.ts` - 权限判断
- `tenant.ts` - 租户工具

## 样式系统

### 样式组织
**位置**: `src/styles/`

```
styles/
├── variables.scss       # 变量定义
├── mixins.scss         # 混入
├── common.scss         # 公共样式
├── theme/              # 主题
│   ├── light.scss      # 浅色主题
│   └── dark.scss       # 深色主题
└── components/         # 组件样式
```

### 主题切换
- 支持浅色/深色主题
- 支持自定义主题色
- 支持租户级别主题定制

## 国际化

### i18n配置
**位置**: `src/locales/`

```
locales/
├── zh-CN/              # 简体中文
│   ├── common.ts
│   ├── lowcode.ts
│   └── system.ts
├── en-US/              # 英文
│   ├── common.ts
│   ├── lowcode.ts
│   └── system.ts
└── index.ts            # 配置入口
```

## 构建与部署

### 开发环境
```bash
cd frontend
pnpm install
pnpm dev
```

### 生产构建
```bash
pnpm build
```

### 环境配置
- `.env.development` - 开发环境
- `.env.production` - 生产环境
- `.env.staging` - 预发布环境

### 环境变量
```
VITE_API_BASE_URL=http://localhost:8000
VITE_APP_TITLE=AlkaidSYS
VITE_TENANT_MODE=subdomain
```

## Mock服务

### Backend Mock
**位置**: `frontend/apps/backend-mock/`

**功能**:
- 模拟后端API
- 开发环境数据
- 接口测试

## 性能优化

### 优化策略
1. **代码分割**: 路由懒加载
2. **组件懒加载**: 按需加载组件
3. **Tree Shaking**: 移除未使用代码
4. **资源压缩**: Gzip/Brotli
5. **CDN加速**: 静态资源CDN
6. **缓存策略**: 浏览器缓存、HTTP缓存

### 打包优化
- Vite构建优化
- 依赖预构建
- 代码压缩
- 资源内联

## 开发规范

### 代码规范
- **ESLint**: 代码质量检查
- **Prettier**: 代码格式化
- **Stylelint**: 样式检查
- **Commitlint**: 提交信息规范

### 命名规范
- 组件：PascalCase
- 文件：kebab-case
- 变量/函数：camelCase
- 常量：UPPER_SNAKE_CASE

### Git工作流
- 使用 Conventional Commits
- 使用 Husky 进行Git钩子
- 使用 Lint-staged 进行提交前检查

## 测试

### 测试框架
- **Vitest**: 单元测试
- **Vue Test Utils**: 组件测试
- **Playwright**: E2E测试

### 测试配置
**文件**: `vitest.config.ts`, `vitest.workspace.ts`

## 最佳实践

### 组件开发
1. 使用 Composition API
2. 合理拆分组件
3. 使用 TypeScript 类型定义
4. 编写组件文档
5. 添加单元测试

### 状态管理
1. 按模块组织Store
2. 使用 Composition API 风格
3. 避免过度使用全局状态
4. 合理使用缓存

### 性能优化
1. 使用 v-memo 优化列表渲染
2. 使用 KeepAlive 缓存组件
3. 避免不必要的响应式数据
4. 使用虚拟滚动处理大列表
5. 图片懒加载

### 安全
1. XSS防护（输出转义）
2. CSRF Token验证
3. 敏感信息加密
4. 安全的路由配置
