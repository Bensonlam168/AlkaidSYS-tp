// Commitlint configuration for AlkaidSYS backend.
// CLI is installed and managed via `pnpm` (see root package.json `packageManager`).
module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    // Enforce allowed commit types for this project
    'type-enum': [
      2,
      'always',
      ['feat', 'fix', 'docs', 'refactor', 'test', 'chore', 'perf', 'style'],
    ],
    // Recommend using a scoped area; warn if scope is outside of suggested list
    // 建议使用的作用域；超出该列表仅告警（severity=1），不阻断提交。
    // 说明：列表已按项目实际模块结构补齐（system/event/plugin/permission/assets/docs），
    // 避免合法模块名（如 system、event）产生无意义告警。
    'scope-enum': [
      1,
      'always',
      [
        // 基础设施与通用
        'auth', 'api', 'db', 'config', 'deployment', 'ci', 'git', 'augment',
        'security', 'performance', 'testing', 'docs', 'assets',
        // 业务模块
        'lowcode', 'workflow', 'system', 'event', 'plugin', 'permission',
      ],
    ],
    // Enforce maximum header length
    'header-max-length': [2, 'always', 72],
    // Enforce minimum header length
    'header-min-length': [2, 'always', 10],
    // Require blank line between header and body
    'body-leading-blank': [1, 'always'],
    // Require blank line between body and footer
    'footer-leading-blank': [1, 'always'],
    // Enforce maximum line length
    'body-max-line-length': [2, 'always', 100],
  },
};

