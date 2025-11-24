# @alkaidsys/sdk 示例仓库与发布指引（REPO-GUIDE v0.1）

本指南说明如何搭建一个对外公开的 SDK 仓库与发布流水线（以 GitHub 为例），与设计文档 docs/sdk-builder/README.md 配套使用。

## 1. 仓库结构（Monorepo 或单包均可）

单包示例：
```
alkaidsys-sdk
├─ src/
│  ├─ index.ts        # 导出 createClient/AlkaidClient/QueryBuilder 类型
│  ├─ client.ts
│  ├─ builder.ts
│  ├─ http.ts
│  ├─ errors.ts
│  └─ types/api.d.ts  # 由 openapi-typescript 生成
├─ tsconfig.json
├─ package.json
├─ README.md
└─ .github/workflows/publish.yml
```

## 2. 初始化与依赖
```bash
mkdir alkaidsys-sdk && cd alkaidsys-sdk
npm init -y
npm i -D typescript tsup openapi-typescript @types/node
```

tsconfig.json：
```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "declaration": true,
    "outDir": "dist",
    "strict": true
  },
  "include": ["src"]
}
```

tsup 构建脚本（package.json scripts）：
```json
{
  "scripts": {
    "build": "tsup src/index.ts --format esm --dts --clean",
    "types": "openapi-typescript ./public/api-docs.json -o src/types/api.d.ts"
  }
}
```

## 3. 导出入口（src/index.ts）
```ts
export * from './client';
export * from './builder';
export * from './errors';
```

## 4. 版本与包元信息（package.json 要点）
```json
{
  "name": "@alkaidsys/sdk",
  "version": "1.0.0",
  "type": "module",
  "main": "dist/index.js",
  "types": "dist/index.d.ts",
  "exports": { ".": { "import": "./dist/index.js", "types": "./dist/index.d.ts" } },
  "sideEffects": false,
  "files": ["dist", "README.md"],
  "license": "MIT"
}
```

## 5. GitHub Actions 发布工作流（.github/workflows/publish.yml）
```yaml
name: Publish SDK
on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Version (e.g. 1.1.0)'
        required: true

jobs:
  publish:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          registry-url: 'https://registry.npmjs.org'
      - name: Install
        run: npm ci
      - name: Generate types
        run: npm run types
      - name: Build
        run: npm run build
      - name: Set version
        run: |
          npm version ${{ github.event.inputs.version }} --no-git-tag-version
      - name: Publish
        env:
          NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}
        run: npm publish --access public
```

> 将 NPM_TOKEN 存于仓库 Secrets。建议通过发布前置流程验证：`php think api:doc` 与 `redocly lint` 在后端仓库执行并将 `api-docs.json` 以 artifact 形式下发到 SDK 仓库。

## 6. 与后端 OpenAPI 的协作
- 建议在后端仓库的 CI 生成 `public/api-docs.json`，并使用“仓库 artifact + 接收器 Action”或“独立 artifacts 存储”方案将文件传递到 SDK 仓库
- SDK 仓库在 `npm run types` 中使用最新 `api-docs.json` 生成 `src/types/api.d.ts`

## 7. 示例标签与变更
- 次版本（MINOR）：新增非破坏性接口/字段 → `1.x.y → 1.(x+1).0`
- 主版本（MAJOR）：破坏性变更（如重命名/移除字段） → `x.y.z → (x+1).0.0`，并在 README 标注 Breaking Changes

## 8. 本地快速验证
```bash
npm run types
npm run build
node -e "import('./dist/index.js').then(m=>console.log(Object.keys(m)))"
```

## 9. 参考
- 设计文档：docs/sdk-builder/README.md
- OpenAPI 流水线：03-data-layer/10-api-design.md
