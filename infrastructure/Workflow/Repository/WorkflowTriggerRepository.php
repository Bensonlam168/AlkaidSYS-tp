<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流触发器仓储（`workflow_triggers`）
 *
 * ## 职责边界
 *
 * 只做「按租户读写触发器行」这一件事，**不含任何业务判断**：
 * - 「什么配置算合法」由引擎 `TriggerInterface::validateConfig()` 判断；
 * - 「启用/禁用、能否删除、webhook 放不放行」由
 *   `Infrastructure\Workflow\Service\WorkflowTriggerService` 判断。
 *
 * ## 表结构事实（`SHOW CREATE TABLE` 实测，勿凭旧 Mock 记忆）
 *
 * ```
 * UNIQUE KEY uk_tenant_name (tenant_id, name)
 * KEY idx_tenant / idx_definition / idx_type / idx_is_active
 * KEY idx_tenant_def_active (tenant_id, definition_id, is_active)
 * KEY idx_tenant_type_active (tenant_id, type, is_active)
 * FK definition_id -> workflow_definitions.id ON DELETE CASCADE
 * FK tenant_id     -> tenants.id              ON DELETE CASCADE
 * FK created_by    -> users.id                ON DELETE SET NULL
 * ```
 *
 * ⚠️ **不存在** `description` / `status` / `webhook_key` / `execution_count` /
 * `last_execution_at` / `last_execution_status` 这些列 —— 旧 Mock 的响应里有它们，
 * 但它们**从未落库**。本仓储的口径是：
 * - `status`（enabled/disabled）⇄ `is_active`（1/0），对外保留旧字段名，对内只有一列；
 * - `description` / `webhook_key` 这类宿主元数据存进 `config._alkaid_meta`（见 `META_KEY`），
 *   读时提升为顶层字段、写前合并回去；
 * - `execution_count` / `last_execution_*` **不落库**，由
 *   {@see WorkflowEventLogRepository::statsByTriggerIds()} 从事件表派生。
 *
 * ## 租户口径（红线）
 *
 * 除 `findByIdUnscoped()` 外，每个方法都把 `$tenantId` 作为**必填的显式参数**。
 * `findByIdUnscoped()` 只为一个目的存在：区分「不存在（404）」与「存在但属于别的租户（403）」。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowTriggerRepository
{
    /**
     * 表名（无前缀；前缀由连接配置决定，见 config/database.php）
     */
    protected string $table = 'workflow_triggers';

    /**
     * 对外状态：启用
     */
    public const STATUS_ENABLED = 'enabled';

    /**
     * 对外状态：禁用
     */
    public const STATUS_DISABLED = 'disabled';

    /**
     * 允许的对外状态取值域
     *
     * @var string[]
     */
    public const STATUSES = [self::STATUS_ENABLED, self::STATUS_DISABLED];

    /**
     * 触发器类型：手动
     */
    public const TYPE_MANUAL = 'manual';

    /**
     * 触发器类型：Webhook
     */
    public const TYPE_WEBHOOK = 'webhook';

    /**
     * 触发器类型：定时
     */
    public const TYPE_SCHEDULE = 'schedule';

    /**
     * 触发器类型：数据事件
     */
    public const TYPE_DATA_EVENT = 'data_event';

    /**
     * 触发器类型：消息事件（**只有列注释与引擎接口有，旧 Mock 未暴露**）
     *
     * 保留它是为了「库里已有的合法值不被判非法」：`type` 列注释写的是
     * `manual/webhook/schedule/data_event/message_event`，凭空少一个等于把既有数据判错。
     */
    public const TYPE_MESSAGE_EVENT = 'message_event';

    /**
     * 允许的触发器类型
     *
     * @var string[]
     */
    public const TYPES = [
        self::TYPE_MANUAL,
        self::TYPE_WEBHOOK,
        self::TYPE_SCHEDULE,
        self::TYPE_DATA_EVENT,
        self::TYPE_MESSAGE_EVENT,
    ];

    /**
     * 宿主元数据在 `config` 里的保留子键
     *
     * 为什么放在 `config` 里而不是加列：`workflow_triggers` 没有 `description` /
     * `webhook_key` 列，而加列需要一次迁移（本包不改迁移）。引擎的
     * `validateConfig()` 只校验 schema 里声明的键，未知键一律忽略 ——
     * 因此这个保留子键对引擎完全透明，`register()` / `shouldTrigger()` 都不受影响。
     *
     * 前导下划线是刻意的：与引擎 schema 可能声明的任何键都不会撞名。
     */
    public const META_KEY = '_alkaid_meta';

    /**
     * 元数据：描述
     */
    public const META_DESCRIPTION = 'description';

    /**
     * 元数据：webhook 访问密钥
     */
    public const META_WEBHOOK_KEY = 'webhook_key';

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 按 ID 无租户读取（**仅用于区分 404 / 403**）
     *
     * @param int $id 触发器ID
     * @return array<string, mixed>|null
     */
    public function findByIdUnscoped(int $id): ?array
    {
        $row = Db::name($this->table)->where('id', $id)->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 按 ID + 租户读取
     *
     * @param int $tenantId 租户ID
     * @param int $id       触发器ID
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        $row = Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 按 webhook key 查找触发器（**必须带租户**，否则等于跨租户入口）
     *
     * 为什么不在这里过滤 `is_active`：调用方需要区分
     * 「key 不存在（404）」与「key 存在但触发器被禁用（400 trigger_disabled）」。
     * 在仓储里过滤掉，两者都会变成 404，用户永远看不到「你只是忘了启用」。
     *
     * 也不过滤 `type`：key 只在 webhook 类型上生成，多一个条件只会掩盖数据异常。
     *
     * @param int    $tenantId 租户ID
     * @param string $key      webhook 访问密钥
     * @return array<string, mixed>|null
     */
    public function findByWebhookKey(int $tenantId, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $path = '$.' . self::META_KEY . '.' . self::META_WEBHOOK_KEY;

        $row = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(config, ?)) = ?', [$path, $key])
            ->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 列表查询（强制租户过滤 + 分页下推）
     *
     * @param int                  $tenantId 租户ID
     * @param array<string, mixed> $filters  definition_id / type / status / active / name
     * @param int                  $page     页码（>=1）
     * @param int                  $pageSize 每页条数（>=1）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function search(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        $definitionId = $filters['definition_id'] ?? null;

        if (is_scalar($definitionId) && ctype_digit((string) $definitionId)) {
            $query->where('definition_id', (int) $definitionId);
        }

        $type = $filters['type'] ?? null;

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        // `status` 是旧契约的对外字段名，落库只有 `is_active`
        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('is_active', $status === self::STATUS_ENABLED ? 1 : 0);
        }

        $active = $filters['active'] ?? null;

        if ($active !== null && $active !== '') {
            $query->where('is_active', $this->toBool($active) ? 1 : 0);
        }

        $keyword = $filters['name'] ?? null;

        if (is_string($keyword) && trim($keyword) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($keyword)) . '%';
            $query->whereLike('name', $like);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->order(['created_at' => 'desc', 'id' => 'desc'])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row): array => $this->normalize($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * 统计某定义下的触发器数
     *
     * @param int $tenantId     租户ID
     * @param int $definitionId 定义ID
     */
    public function countByDefinition(int $tenantId, int $definitionId): int
    {
        return (int) Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('definition_id', $definitionId)
            ->count();
    }

    /**
     * 该 (租户, 名称) 下是否已存在触发器
     *
     * @param int      $tenantId   租户ID
     * @param string   $name       触发器名
     * @param int|null $excludeId  排除的ID（更新时排除自己）
     */
    public function existsName(int $tenantId, string $name, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name);

        if ($excludeId !== null && $excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        return (bool) $query->count();
    }

    // =====================================================================
    // 写
    // =====================================================================

    /**
     * 插入一行触发器
     *
     * 并发下的「名称唯一」由表上的 `uk_tenant_name` 兜底：两个请求同时创建同名触发器时，
     * 后到的那个会撞唯一索引抛数据库异常，由服务层翻译成 409 —— 不是「先查后写」。
     *
     * @param array<string, mixed> $row 列值（`config` 可传数组，本方法负责编码）
     * @return int 自增主键
     */
    public function insert(array $row): int
    {
        $row = $this->encodeConfig($row);
        $row['created_at'] = $row['created_at'] ?? date('Y-m-d H:i:s');
        $row['updated_at'] = $row['updated_at'] ?? $row['created_at'];

        return (int) Db::name($this->table)->insertGetId($row);
    }

    /**
     * 按租户更新触发器列
     *
     * @param int                  $tenantId 租户ID
     * @param int                  $id       触发器ID
     * @param array<string, mixed> $data     待更新列（`config` 可传数组）
     * @return int 受影响行数（0 表示不存在 / 不属于该租户 / 值未变化）
     */
    public function updateForTenant(int $tenantId, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $data = $this->encodeConfig($data);
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        return (int) Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($data);
    }

    /**
     * 按租户删除一行触发器
     *
     * @return int 受影响行数
     */
    public function deleteForTenant(int $tenantId, int $id): int
    {
        return (int) Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    // =====================================================================
    // 归一化
    // =====================================================================

    /**
     * 行归一化
     *
     * 做三件事，**不改列名、不改语义**：
     * 1. `config` 解码成数组，并把保留子键 `_alkaid_meta` **摘掉**（对外的 `config` 只有引擎配置）；
     * 2. 把元数据提升为顶层 `description` / `webhook_key`，同时把完整元数据放进 `_meta`
     *    （`_meta` 供服务层回写时原样带走未知键，不会出现在任何响应里）；
     * 3. `is_active` 派生对外 `status`，数值列定型。
     *
     * @param array<string, mixed> $row 原始行
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $config = $this->decodeJson($row['config'] ?? null);
        $meta   = is_array($config[self::META_KEY] ?? null) ? $config[self::META_KEY] : [];

        unset($config[self::META_KEY]);

        $row['config']      = $config;
        $row['_meta']       = $meta;
        $row['description'] = isset($meta[self::META_DESCRIPTION]) && is_scalar($meta[self::META_DESCRIPTION])
            ? (string) $meta[self::META_DESCRIPTION]
            : '';
        $row['webhook_key'] = isset($meta[self::META_WEBHOOK_KEY])
            && is_scalar($meta[self::META_WEBHOOK_KEY])
            && (string) $meta[self::META_WEBHOOK_KEY] !== ''
                ? (string) $meta[self::META_WEBHOOK_KEY]
                : null;

        foreach (['id', 'definition_id', 'tenant_id'] as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (int) $row['is_active'] === 1 ? 1 : 0;
        }

        $row['created_by'] = isset($row['created_by']) && $row['created_by'] !== ''
            ? (int) $row['created_by']
            : null;

        $row['status'] = ($row['is_active'] ?? 0) === 1 ? self::STATUS_ENABLED : self::STATUS_DISABLED;

        return $row;
    }

    /**
     * 把 `config` 数组编码成 JSON 字符串
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function encodeConfig(array $row): array
    {
        if (!array_key_exists('config', $row)) {
            return $row;
        }

        $value = $row['config'];

        if ($value === null) {
            // `config` 是 json NOT NULL：没有配置也要写 `{}`，不能写 NULL
            $row['config'] = '{}';

            return $row;
        }

        if (is_string($value)) {
            return $row;
        }

        $row['config'] = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $row;
    }

    /**
     * 解码 json 列（字符串 / 数组 / null → 数组）
     *
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 把 true/false/1/0/'1'/'true' 归一成布尔
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
