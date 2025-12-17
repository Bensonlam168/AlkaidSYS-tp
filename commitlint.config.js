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
    'scope-enum': [
      1,
      'always',
      ['auth', 'api', 'db', 'lowcode', 'workflow', 'security', 'performance', 'deployment', 'testing', 'git', 'augment'],
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

