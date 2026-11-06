<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流定义仓储（`workflow_definitions`）
 *
 * ## 职责边界
 *
 * 只做「按租户读写定义行」这一件事，**不含任何业务判断**：
 * - 「什么状态允许发布 / 归档 / 删除」由 `Infrastructure\Workflow\Service\WorkflowDefinitionService` 判断；
 * - 「定义体能不能被引擎执行」由 `WorkflowDefinitionValidator` 判断（本类只提供 `isStartable()` 这种
 *   **字段级**判定，不解析图）。
 *
 * ## 表结构事实（`SHOW CREATE TABLE` 核对，勿凭旧 Mock 记忆）
 *
 * ```
 * UNIQUE KEY uk_tenant_name_version (tenant_id, name, version)
 * KEY idx_tenant / idx_status / idx_type / idx_tenant_status_active / idx_tenant_type_category
 * FK tenant_id -> tenants.id            ON DELETE CASCADE
 * FK created_by / updated_by -> users.id ON DELETE SET NULL
 * ```
 *
 * **不存在** `published_at` 列（旧 Mock 响应里的 `published_at` 从未落库）。
 * 因此「一个 (tenant, name) 可以有多个版本」是**表结构本身就允许**的：
 * 唯一键是三元组，不是 `(tenant, name)`。
 *
 * ## 为什么保留「无租户」读
 *
 * `findByIdUnscoped()` 是**唯一**不带租户条件的读，它只为一个目的存在：
 * 区分「定义不存在（404）」与「定义存在但属于别的租户（403）」。
 * 直接把租户条件下推到查询里、查不到就 404，等于把「存在性」也藏起来 ——
 * 那会让安全测试无法区分「隔离生效」与「根本没这条数据」，
 * 也掩盖了跨租户访问这一事实。归属判断由调用方**立刻**完成，绝不在此类里做业务分支。
 *
 * ## 租户口径（红线）
 *
 * 除 `findByIdUnscoped()` 外，每个方法都把 `$tenantId` 作为**必填的显式参数**，
 * 没有默认值、没有「取当前上下文」的隐式回落 —— 隐式回落正是历史上「忘了带租户条件」的温床。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowDefinitionRepository
{
    /**
     * 表名（无前缀；前缀由连接配置决定，见 config/database.php）
     */
    protected string $table = 'workflow_definitions';

    /**
     * 状态：草稿（不可启动、可原地编辑）
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * 状态：已发布（图快照，不可原地编辑）
     */
    public const STATUS_PUBLISHED = 'published';

    /**
     * 状态：已归档（永久停用，只为在跑实例与审计保留）
     */
    public const STATUS_ARCHIVED = 'archived';

    /**
     * 允许通过 API 写入的状态取值域（与列注释 `draft/published/archived` 一致）
     *
     * @var string[]
     */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /**
     * 允许的定义类型
     *
     * 取值是**两处真相的并集**，刻意都收：
     * - `WorkflowDefinitionInterface::TYPE_*`（旧 Mock 校验的 4 个）：business / approval / automation / integration；
     * - 建表注释 `type` 声明的 `approval/automation/hybrid`（多出 hybrid）。
     * 只收其中一处就会把另一处已存在的合法值判成非法（`hybrid` 从未落过库，但列注释写着它）。
     *
     * @var string[]
     */
    public const TYPES = ['approval', 'business', 'automation', 'integration', 'hybrid'];

    /**
     * 定义名格式（沿用旧 Mock 的规则，别放宽）
     *
     * 名字是「工作流家族」的稳定标识（版本靠 `version` 区分），因此只允许
     * 字母开头的字母/数字/下划线 —— 不引入 `-` / `.`，避免它被当成路由变量或 SQL 标识符的一部分。
     */
    public const NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * json 列（读时解码成数组）
     *
     * @var string[]
     */
    public const JSON_COLUMNS = ['nodes_config', 'triggers_config', 'variables_schema'];

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 按 ID 无租户读取（**仅用于区分 404 / 403，以及实例启动前的可执行性判定**）
     *
     * @param int $id 定义ID
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
     * @param int $id       定义ID
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
     * 按 (租户, 名称, 版本) 读取（唯一键 `uk_tenant_name_version` 的直接命中）
     *
     * @return array<string, mixed>|null
     */
    public function findByNameVersion(int $tenantId, string $name, int $version): ?array
    {
        $row = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->where('version', $version)
            ->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 读取该 (租户, 名称) 下**最高版本的草稿**（没有则 null）
     *
     * 用途：对已发布版本执行 `PUT` 时复用已有的工作副本，而不是每次编辑都新增一版。
     * 不这样做的话，「编辑已发布流程」连按三次会留下 v2/v3/v4 三个空壳草稿。
     *
     * @return array<string, mixed>|null
     */
    public function findLatestDraft(int $tenantId, string $name): ?array
    {
        $row = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->where('status', self::STATUS_DRAFT)
            ->order('version', 'desc')
            ->find();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * 该 (租户, 名称) 下的最大版本号（没有任何行时返回 0）
     */
    public function maxVersion(int $tenantId, string $name): int
    {
        $max = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->max('version');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * 该 (租户, 名称) 下是否已存在任何版本
     */
    public function existsName(int $tenantId, string $name): bool
    {
        return (bool) Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->count();
    }

    /**
     * 列表查询（强制租户过滤 + 分页下推）
     *
     * @param int                  $tenantId 租户ID
     * @param array<string, mixed> $filters  status / type / category / name / active / definition_ids
     * @param int                  $page     页码（>=1）
     * @param int                  $pageSize 每页条数（>=1）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function search(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        $type = $filters['type'] ?? null;

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $category = $filters['category'] ?? null;

        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        // `name` 是旧契约里的「模糊搜索」，语义是「名字或显示名包含关键字」
        $keyword = $filters['name'] ?? null;

        if (is_string($keyword) && trim($keyword) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($keyword)) . '%';
            $query->where(function ($sub) use ($like): void {
                $sub->whereLike('name', $like)->whereOr('display_name', 'like', $like);
            });
        }

        // is_active 过滤：null = 不过滤（列表默认返回**全部版本**，由调用方按需收窄）
        $active = $filters['active'] ?? null;

        if ($active !== null && $active !== '') {
            $query->where('is_active', $this->toBool($active) ? 1 : 0);
        }

        $definitionIds = $filters['definition_ids'] ?? null;

        if (is_array($definitionIds) && $definitionIds !== []) {
            $query->whereIn('id', array_map('intval', $definitionIds));
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->order(['updated_at' => 'desc', 'id' => 'desc'])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row): array => $this->normalize($row), $rows),
            'total' => $total,
        ];
    }

    // =====================================================================
    // 写
    // =====================================================================

    /**
     * 插入一行定义
     *
     * ⚠️ 并发下的「名称唯一」由表上的 `uk_tenant_name_version` 兜底：
     * 两个请求同时创建同名定义时，后到的那个会撞唯一索引抛数据库异常，
     * 由服务层翻译成 409 —— **不是**「先查后写」（那在并发下必然漏）。
     *
     * @param array<string, mixed> $row 列值（json 列可传数组，本方法负责编码）
     * @return int 自增主键
     */
    public function insert(array $row): int
    {
        $row = $this->encodeJsonColumns($row);
        $row['created_at'] = $row['created_at'] ?? date('Y-m-d H:i:s');
        $row['updated_at'] = $row['updated_at'] ?? $row['created_at'];

        return (int) Db::name($this->table)->insertGetId($row);
    }

    /**
     * 按租户更新定义列
     *
     * @param int                  $tenantId 租户ID
     * @param int                  $id       定义ID
     * @param array<string, mixed> $data     待更新列（json 列可传数组）
     * @return int 受影响行数（0 表示不存在 / 不属于该租户 / 值未变化）
     */
    public function updateForTenant(int $tenantId, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $data = $this->encodeJsonColumns($data);
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        return (int) Db::name($this->table)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($data);
    }

    /**
     * 按租户删除一行定义
     *
     * ⚠️ `workflow_instances.definition_id` 是 `ON DELETE CASCADE`：
     * 物理删除一个**有实例**的定义会连带删掉实例、任务、轨迹。
     * 因此本方法只负责「删」，是否允许删由服务层判断（有实例 / 已发布一律拒绝）。
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

    /**
     * 把同家族（同租户同名）**除 $keepId 之外**的已发布版本置为「不可启动」
     *
     * 只改 `is_active`，**不改 `status` / `updated_at`**：
     * - 历史版本仍然是「已发布过的快照」，它在跑实例的 `definition_id` 还指着它，
     *   把它改成 archived 会让「这条实例到底基于哪版」失去解释；
     * - 行内容（图、状态、创建人）一个字节都没变，只有一个「是否可启动」的开关被扳下去，
     *   因此不伪造一次 `updated_at`（那会让「这版什么时候改的」变成谎话）。
     *
     * @return int 受影响行数
     */
    public function demotePublishedSiblings(int $tenantId, string $name, int $keepId): int
    {
        return (int) Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->where('status', self::STATUS_PUBLISHED)
            ->where('id', '<>', $keepId)
            ->where('is_active', 1)
            ->update(['is_active' => 0]);
    }

    // =====================================================================
    // 字段级判定（不解析图，图的可执行性由 WorkflowDefinitionValidator 负责）
    // =====================================================================

    /**
     * 定义体是否是「引擎可读结构」的**形状判定**（有 nodes 且非空、有 edges）
     *
     * 判据与引擎 `ProcessDefineEntity::hasEngineReadableContent()` 一致：
     * 只认 `{nodes: [...], edges: [...]}`。旧形态（`{start:{id,name}, ...}` 节点表）
     * 会被 `ModelParser::parse()` 静默解析成空模型，随后在 `$processModel->getStart()`
     * 处以 `Call to a member function on null` 崩成 500 —— 与其让它崩，不如在入口就明确拒绝。
     *
     * @param array<string, mixed> $definition 定义行
     */
    public static function isExecutable(array $definition): bool
    {
        $config = $definition['nodes_config'] ?? null;

        return is_array($config)
            && isset($config['nodes'], $config['edges'])
            && is_array($config['nodes'])
            && is_array($config['edges'])
            && $config['nodes'] !== [];
    }

    /**
     * 该定义行**能否用于启动新实例**
     *
     * 判据只有一条：`status === published && is_active === 1`。
     * 三个字段的组合语义见 `WorkflowDefinitionService` 的类注释（那是唯一权威口径）：
     * - `draft`    ：工作副本，不可启动；
     * - `published`+`is_active=1`：当前可启动的发布版本；
     * - `published`+`is_active=0`：已被新版本取代的历史发布版本，不可启动（但在跑实例不受影响）；
     * - `archived` ：已归档，永久停用。
     *
     * @param array<string, mixed> $definition 定义行
     */
    public static function isStartable(array $definition): bool
    {
        return (string) ($definition['status'] ?? '') === self::STATUS_PUBLISHED
            && (int) ($definition['is_active'] ?? 0) === 1;
    }

    /**
     * 行归一化：json 列解码 + 数值列定型
     *
     * 保留一个原则：**不改列名、不改语义**，只把「取回来是字符串」的列变成调用方期望的形态，
     * 避免调用方每次都要 `json_decode`（漏一处就是「图看起来是空的」）。
     *
     * @param array<string, mixed> $row 原始行
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $this->decodeJson($row[$column] ?? null);
        }

        foreach (['id', 'tenant_id', 'version'] as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (['created_by', 'updated_by'] as $column) {
            $row[$column] = isset($row[$column]) && $row[$column] !== '' ? (int) $row[$column] : null;
        }

        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (int) $row['is_active'] === 1 ? 1 : 0;
        }

        return $row;
    }

    /**
     * 把待写入的 json 列编码成 JSON 字符串（已是字符串则原样保留）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function encodeJsonColumns(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($value === null || is_string($value)) {
                continue;
            }

            $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $row;
    }

    /**
     * 解码 json 列（字符串 / 数组 / null → 数组或 null）
     *
     * 注意：`nodes_config` 为 `json DEFAULT NULL`，**空图与「没写」在这里都是 null/[]**，
     * 调用方（validator）必须把两者都当作「没有图」处理。
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
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
