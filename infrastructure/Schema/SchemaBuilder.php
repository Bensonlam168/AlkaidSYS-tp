<?php

declare(strict_types=1);

namespace Infrastructure\Schema;

use Domain\Schema\Interfaces\SchemaBuilderInterface;
use think\facade\Db;
use think\facade\Log;
use think\facade\Env;
use think\Response;
use think\exception\HttpResponseException;

/**
 * Schema Builder Implementation | Schema构建器实现
 *
 * Implements runtime DDL operations using raw SQL for maximum flexibility.
 * 使用原始SQL实现运行时DDL操作，以获得最大的灵活性。
 *
 * @package Infrastructure\Schema
 */
class SchemaBuilder implements SchemaBuilderInterface
{
    /**
     * {@inheritDoc}
     *
     * Creates table using raw SQL for runtime execution.
     * 使用原始SQL创建表，用于运行时执行。
     */
    public function createTable(string $tableName, array $columns, array $indexes = [], string $engine = 'InnoDB', string $comment = ''): bool
    {
        $env = strtolower((string) Env::get('APP_ENV', 'production'));
        $traceId = $this->getTraceId();

        $this->assertCanRunDDL('create_table', $tableName);

        // Use raw SQL for runtime table creation | 使用原始SQL进行运行时表创建

        // Note: ThinkPHP's schema builder is usually used in migrations.
        // For runtime dynamic table creation, we might need to use raw SQL or the migration adapter logic.
        // However, ThinkPHP 8's Db::execute or direct SQL construction is often safer for dynamic runtime DDL
        // to avoid migration file generation overhead if we want immediate effect.
        // But let's try to use the Schema builder if possible, or fall back to raw SQL for better control over dynamic types.

        // A simple implementation using raw SQL for maximum flexibility with dynamic types
        // This is a simplified example. In production, we should use a robust query builder or the migration classes.

        // Let's use the standard migration-like syntax if possible, but since we are in runtime,
        // we might not want to generate migration files.
        // We will use raw SQL for this implementation to ensure immediate execution without file generation.

        $sql = "CREATE TABLE `{$tableName}` (";
        $defs = [];
        $pk = '';

        foreach ($columns as $name => $def) {
            $type = strtoupper($def['type']);
            $length = isset($def['length']) ? "({$def['length']})" : '';
            $nullable = isset($def['nullable']) && $def['nullable'] ? 'NULL' : 'NOT NULL';
            if (isset($def['default'])) {
                if (in_array(strtoupper($def['default']), ['CURRENT_TIMESTAMP', 'NULL'])) {
                    $default = "DEFAULT {$def['default']}";
                } else {
                    $default = "DEFAULT '{$def['default']}'";
                }
            } else {
                $default = '';
            }
            $autoInc = isset($def['auto_increment']) && $def['auto_increment'] ? 'AUTO_INCREMENT' : '';
            $colComment = isset($def['comment']) ? "COMMENT '{$def['comment']}'" : '';

            if (isset($def['primary']) && $def['primary']) {
                $pk = $name;
            }

            $defs[] = "`{$name}` {$type}{$length} {$nullable} {$default} {$autoInc} {$colComment}";
        }

        if ($pk) {
            $defs[] = "PRIMARY KEY (`{$pk}`)";
        }

        // Add indexes
        foreach ($indexes as $indexName => $indexDef) {
            $cols = implode('`, `', $indexDef['columns']);
            $unique = isset($indexDef['unique']) && $indexDef['unique'] ? 'UNIQUE' : '';
            $defs[] = "{$unique} KEY `{$indexName}` (`{$cols}`)";
        }

        $sql .= implode(', ', $defs);
        $sql .= ") ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COMMENT='{$comment}'";

        try {
            Db::execute($sql);

            Log::info('[SCHEMA_DDL_EXECUTED] Runtime DDL executed successfully', [
                'env'       => $env,
                'operation' => 'create_table',
                'table'     => $tableName,
                'trace_id'  => $traceId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[SCHEMA_DDL_FAILED] Runtime DDL execution failed', [
                'env'       => $env,
                'operation' => 'create_table',
                'table'     => $tableName,
                'trace_id'  => $traceId,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function dropTable(string $tableName): bool
    {
        $env = strtolower((string) Env::get('APP_ENV', 'production'));
        $traceId = $this->getTraceId();

        $this->assertCanRunDDL('drop_table', $tableName);

        try {
            Db::execute("DROP TABLE IF EXISTS `{$tableName}`");

            Log::info('[SCHEMA_DDL_EXECUTED] Runtime DDL executed successfully', [
                'env'       => $env,
                'operation' => 'drop_table',
                'table'     => $tableName,
                'trace_id'  => $traceId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[SCHEMA_DDL_FAILED] Runtime DDL execution failed', [
                'env'       => $env,
                'operation' => 'drop_table',
                'table'     => $tableName,
                'trace_id'  => $traceId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasTable(string $tableName): bool
    {
        $result = Db::query("SHOW TABLES LIKE '{$tableName}'");
        return !empty($result);
    }

    /**
     * {@inheritDoc}
     */
    public function addColumn(string $tableName, string $columnName, array $def): bool
    {
        $env = strtolower((string) Env::get('APP_ENV', 'production'));
        $traceId = $this->getTraceId();

        $this->assertCanRunDDL('add_column', $tableName, [
            'column' => $columnName,
        ]);

        $type = strtoupper($def['type']);
        $length = isset($def['length']) ? "({$def['length']})" : '';
        $nullable = isset($def['nullable']) && $def['nullable'] ? 'NULL' : 'NOT NULL';
        $default = isset($def['default']) ? "DEFAULT '{$def['default']}'" : '';
        $colComment = isset($def['comment']) ? "COMMENT '{$def['comment']}'" : '';

        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$type}{$length} {$nullable} {$default} {$colComment}";

        try {
            Db::execute($sql);

            Log::info('[SCHEMA_DDL_EXECUTED] Runtime DDL executed successfully', [
                'env'       => $env,
                'operation' => 'add_column',
                'table'     => $tableName,
                'column'    => $columnName,
                'trace_id'  => $traceId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[SCHEMA_DDL_FAILED] Runtime DDL execution failed', [
                'env'       => $env,
                'operation' => 'add_column',
                'table'     => $tableName,
                'column'    => $columnName,
                'trace_id'  => $traceId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function dropColumn(string $tableName, string $columnName): bool
    {
        $env = strtolower((string) Env::get('APP_ENV', 'production'));
        $traceId = $this->getTraceId();

        $this->assertCanRunDDL('drop_column', $tableName, [
            'column' => $columnName,
        ]);

        try {
            Db::execute("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`");

            Log::info('[SCHEMA_DDL_EXECUTED] Runtime DDL executed successfully', [
                'env'       => $env,
                'operation' => 'drop_column',
                'table'     => $tableName,
                'column'    => $columnName,
                'trace_id'  => $traceId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[SCHEMA_DDL_FAILED] Runtime DDL execution failed', [
                'env'       => $env,
                'operation' => 'drop_column',
                'table'     => $tableName,
                'column'    => $columnName,
                'trace_id'  => $traceId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $result = Db::query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return !empty($result);
    }

    /**
     * Assert whether runtime DDL can be executed in current environment.
     *
     * 环境策略：
     * - 默认仅 dev/local/testing 允许运行时 DDL；
     * - APP_ENV=production 时始终禁止运行时 DDL；
     * - 需要显式配置 ALLOW_RUNTIME_DDL=true 才会放行白名单环境中的 DDL。
     */
    protected function assertCanRunDDL(string $operation, string $tableName, array $context = []): void
    {
        $env = strtolower((string) Env::get('APP_ENV', 'production'));
        $allowedEnvsConfig = (string) Env::get('SCHEMA_RUNTIME_DDL_ALLOWED_ENVS', 'dev,local,testing');
        $allowedEnvs = array_filter(array_map('trim', explode(',', $allowedEnvsConfig)));
        $allowedEnvs = array_map('strtolower', $allowedEnvs);
        $allowFlag = (bool) Env::get('ALLOW_RUNTIME_DDL', false);

        $traceId = $this->getTraceId();

        $logContext = array_merge([
            'env'          => $env,
            'operation'    => $operation,
            'table'        => $tableName,
            'allowed_envs' => $allowedEnvs,
            'allow_flag'   => $allowFlag,
            'trace_id'     => $traceId,
        ], $context);

        Log::info('[SCHEMA_DDL_ATTEMPT] Runtime DDL attempt', $logContext);

        $isEnvAllowed = in_array($env, $allowedEnvs, true);

        $reason = null;
        if ($env === 'production') {
            $reason = 'production_environment';
        } elseif (!$isEnvAllowed) {
            $reason = 'env_not_in_whitelist';
        } elseif (!$allowFlag) {
            $reason = 'flag_disabled';
        }

        if ($reason !== null) {
            $logContext['reason'] = $reason;

            Log::warning('[SCHEMA_DDL_FORBIDDEN] Runtime DDL blocked by environment policy', $logContext);

            $responseBody = [
                'code'      => 5003,
                'message'   => 'Runtime DDL is not allowed in current environment',
                'data'      => [
                    'env'       => $env,
                    'operation' => $operation,
                    'table'     => $tableName,
                    'reason'    => $reason,
                ],
                'timestamp' => time(),
            ];

            if ($traceId) {
                $responseBody['trace_id'] = $traceId;
            }

            throw new HttpResponseException(Response::create($responseBody, 'json', 403));
        }
    }

    /**
     * Resolve current trace id from request if available.
     *
     * 尝试从当前请求中解析 trace_id，用于日志与错误响应。
     */
    protected function getTraceId(): ?string
    {
        try {
            if (function_exists('request')) {
                $request = request();
                if ($request) {
                    if (method_exists($request, 'traceId')) {
                        $value = $request->traceId();
                        if (is_string($value) && $value !== '') {
                            return $value;
                        }
                    }

                    $headerTrace = $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
                    if (is_string($headerTrace) && $headerTrace !== '') {
                        return $headerTrace;
                    }
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }
}
