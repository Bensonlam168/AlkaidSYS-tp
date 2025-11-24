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
    // Recommend using a scoped area; warn if scope is outside the suggested list
    'scope-enum': [
      1,
      'always',
      ['auth', 'api', 'db', 'lowcode', 'security', 'performance', 'deployment', 'testing', 'git'],
    ],
    // Require a non-empty scope so that `<type>(<scope>): <subject>` is enforced
    'scope-empty': [2, 'never'],
    // Subject must not be empty
    'subject-empty': [2, 'never'],
    // Limit full header length (including type and scope) to keep subjects concise
    'header-max-length': [2, 'always', 72],
  },
};

